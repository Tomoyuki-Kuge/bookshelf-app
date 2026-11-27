<?php

/** 共通HTTP障害の安全な応答・実ログとdailyハンドラーの日次切替・14日保持を検証する。 */

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

class CommonHttpFailureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        DB::beginTransaction();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Book::flushEventListeners();
        DB::rollBack();
        parent::tearDown();
    }

    public static function failures(): array
    {
        $cases = [];
        foreach ([false, true] as $debug) {
            foreach ([false, true] as $api) {
                foreach (['query', 'runtime'] as $kind) {
                    $cases[] = [$debug, $api, $kind];
                }
            }
        }

        return $cases;
    }

    /** T-12-8〜9：全体取消と既定画面/API契約、秘密非露出をdebug両設定で実出力から照合する。 */
    #[DataProvider('failures')]
    public function test_unexpected_failures_use_safe_daily_metadata_and_generic_500(bool $debug, bool $api, string $kind): void
    {
        $directory = sys_get_temp_dir().'/bookshelf-errors-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory);
        try {
            $logging = require config_path('logging.php');
            $this->assertSame(14, $logging['channels']['daily']['days']);
            $this->assertSame(['daily'], $logging['channels']['stack']['channels']);
            config(['app.debug' => $debug, 'logging.default' => 'daily', 'logging.channels.daily.path' => $directory.'/laravel.log']);
            Log::forgetChannel('daily');
            $user = User::factory()->create();
            $genre = Genre::factory()->create();
            $input = ['title' => '障害テスト', 'author' => '著者', 'isbn' => '0123456789012', 'published_date' => '2026-01-02', 'genres' => [$genre->id], 'user_id' => $user->id, 'description' => 'request-body-secret', 'password' => 'password-secret', '_token' => 'token-secret'];
            $exception = $kind === 'query'
                ? new QueryException('mysql', 'SQL-secret', ['password-secret', 'token-secret'], new \PDOException('database-secret'))
                : new \RuntimeException('SQL-secret password-secret token-secret database-secret request-body-secret');
            Book::saved(fn () => throw $exception);
            $before = $this->snapshot();
            $url = $api ? '/api/v1/books' : '/books';
            $response = $api ? $this->postJson($url, $input) : $this->actingAs($user)->post($url, $input);
            $response->assertStatus(500);
            $this->assertSame($before, $this->snapshot());
            $this->assertSame($debug, config('app.debug'));
            if ($api) {
                $response->assertExactJson(['message' => '処理に失敗しました。時間をおいて再度お試しください。']);
            } else {
                $this->assertSame(view('errors::500')->render(), $response->getContent());
                $response->assertSessionMissing('success');
            }
            $files = glob($directory.'/laravel-*.log');
            $this->assertCount(1, $files);
            $this->assertSame('laravel-'.date('Y-m-d').'.log', basename($files[0]));
            $log = File::get($files[0]);
            $this->assertSame(1, substr_count($log, 'アプリケーションで例外が発生しました。'));
            $this->assertMatchesRegularExpression('/"occurred_at":"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00"/', $log);
            $this->assertStringContainsString('"route":"'.($api ? 'api/v1/books' : 'books').'"', $log);
            $this->assertStringContainsString($kind === 'query' ? 'QueryException' : 'RuntimeException', $log);
            foreach (['SQL-secret', 'password-secret', 'token-secret', 'database-secret', 'request-body-secret', 'Stack trace', '"exception":'] as $secret) {
                $this->assertStringNotContainsString($secret, $log);
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        } finally {
            Log::forgetChannel('daily');
            File::deleteDirectory($directory);
        }
    }

    private function snapshot(): array
    {
        return collect(['users', 'books', 'genres', 'book_genre', 'reviews', 'favorites', 'review_likes'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    /** T-12-8：API構文400・メソッド405とWeb既定405を同じアプリで維持する。 */
    public function test_bad_json_and_unsupported_methods_keep_their_established_contracts(): void
    {
        $before = $this->snapshot();
        $this->call('POST', '/api/v1/books', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{invalid')
            ->assertStatus(400)->assertExactJson(['message' => 'JSONの形式が正しくありません。']);
        $this->patchJson('/api/v1/books/999999999')->assertStatus(405)->assertHeader('Allow')->assertExactJson(['message' => 'この操作方法には対応していません。']);
        $response = $this->get('/logout')->assertStatus(405)->assertHeader('Allow', 'POST');
        $response->assertSee('Method Not Allowed');
        $this->assertSame($before, $this->snapshot());
    }

    /** T-12-9：実dailyハンドラーの時計境界を模擬し、16日分から最新14日分だけを残す。 */
    public function test_daily_handler_switches_date_and_rotates_to_fourteen_latest_files(): void
    {
        $directory = sys_get_temp_dir().'/bookshelf-rotation-'.bin2hex(random_bytes(8));
        File::makeDirectory($directory);
        config(['logging.channels.daily.path' => $directory.'/laravel.log']);
        Log::forgetChannel('daily');
        try {
            $handler = Log::channel('daily')->getLogger()->getHandlers()[0];
            $this->assertInstanceOf(RotatingFileHandler::class, $handler);
            $today = new DateTimeImmutable('today');
            for ($days = 1; $days <= 15; $days++) {
                File::put($directory.'/laravel-'.$today->modify('-'.$days.' days')->format('Y-m-d').'.log', '確認用過去ログ');
            }
            $yesterdayPath = $directory.'/laravel-'.$today->modify('-1 day')->format('Y-m-d').'.log';
            // 実運用での日付経過を待たず、既存ハンドラーの前日末状態だけを再現する。
            (new ReflectionProperty($handler, 'url'))->setValue($handler, $yesterdayPath);
            (new ReflectionProperty($handler, 'nextRotation'))->setValue($handler, $today);
            (new ReflectionProperty($handler, 'mustRotate'))->setValue($handler, false);
            $handler->handle(new LogRecord($today->modify('-1 second'), 'testing', Level::Error, '前日確認'));
            $this->assertStringContainsString('前日確認', File::get($yesterdayPath));
            $this->assertFileDoesNotExist($directory.'/laravel-'.$today->format('Y-m-d').'.log');
            $handler->handle(new LogRecord($today->modify('+1 second'), 'testing', Level::Error, '当日確認'));
            $handler->close();
            $this->assertStringContainsString('当日確認', File::get($directory.'/laravel-'.$today->format('Y-m-d').'.log'));
            $this->assertStringNotContainsString('当日確認', File::get($yesterdayPath));
            // 通常のHTTP要求と同じく新しく作るdailyハンドラーで、初回当日出力時の保持を確認する。
            Log::forgetChannel('daily');
            File::cleanDirectory($directory);
            for ($days = 1; $days <= 15; $days++) {
                File::put($directory.'/laravel-'.$today->modify('-'.$days.' days')->format('Y-m-d').'.log', '確認用過去ログ');
            }
            Log::channel('daily')->error('保持確認');
            Log::channel('daily')->getLogger()->close();
            $actual = array_map('basename', glob($directory.'/laravel-*.log'));
            $expected = [];
            for ($days = 0; $days < 14; $days++) {
                $expected[] = 'laravel-'.$today->modify('-'.$days.' days')->format('Y-m-d').'.log';
            }
            sort($actual);
            sort($expected);
            $this->assertSame($expected, $actual);
        } finally {
            Log::forgetChannel('daily');
            File::deleteDirectory($directory);
        }
    }
}
