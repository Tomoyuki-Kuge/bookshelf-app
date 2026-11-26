<?php

/** 独立プロセスと同期イベントでAPI保存の競合・参照先保護を検証する。 */

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\ObservesConcurrentSql;
use Tests\TestCase;

class PublicBooksApiConcurrencyTest extends TestCase
{
    use ObservesConcurrentSql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        config(['database.connections.api_race' => config('database.connections.mysql')]);
    }

    /** 子プロセスも公開testing設定だけを読み、待機上限を設定する。 */
    private function bootstrap(): string
    {
        return <<<'CHILD'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'testing') { exit(2); }
Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
CHILD;
    }

    private function fixtures(): array
    {
        $user = User::factory()->make();
        $user->setConnection('api_race')->save();
        $genre = new Genre(['name' => 'API並行'.bin2hex(random_bytes(8))]);
        $genre->setConnection('api_race')->save();
        $book = Book::factory()->make(['user_id' => $user->id]);
        $book->setConnection('api_race')->save();

        return [$user, $genre, $book];
    }

    private function snapshot(): array
    {
        return collect(['users', 'books', 'genres', 'book_genre', 'reviews', 'favorites', 'review_likes'])->mapWithKeys(fn ($table) => [$table => DB::connection('api_race')->table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public static function raceCases(): array
    {
        return [['update'], ['delete'], ['isbn'], ['genre'], ['user'], ['owner']];
    }

    /** T-11-24：検証完了を同期して後勝ち・対象消失・参照消失・一意競合を実並行で確認する。 */
    #[DataProvider('raceCases')]
    public function test_concurrent_save_after_validation(string $case): void
    {
        $connection = DB::connection('api_race');
        $baseline = $this->snapshot();
        [$user, $genre, $book] = $this->fixtures();
        $referenceUser = $case === 'user' ? User::factory()->make() : $user;
        if ($case === 'user') {
            $referenceUser->setConnection('api_race')->save();
        }
        $isbn = '088'.str_pad((string) $book->id, 10, '0', STR_PAD_LEFT);
        $script = $this->bootstrap().<<<'CHILD'
$app->afterResolving(App\Http\Requests\Api\BookSaveRequest::class, function () {
 echo "ready\n"; fflush(STDOUT);
 if (trim(fgets(STDIN)) !== 'save') { exit(3); }
});
$body = ['user_id' => (int)$argv[3], 'title' => 'API後保存', 'author' => '並行著者', 'isbn' => $argv[4], 'published_date' => '2099-01-02', 'genres' => [(int)$argv[2]]];
$request = Illuminate\Http\Request::create('/api/v1/books/'.$argv[1], 'PUT', [], [], [], ['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'], json_encode($body));
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
echo json_encode(['status'=>$response->getStatusCode(),'body'=>json_decode($response->getContent(),true)],JSON_UNESCAPED_UNICODE)."\n";
CHILD;
        $stream = new InputStream;
        $process = new Process([PHP_BINARY, '-r', $script, (string) $book->id, (string) $genre->id, (string) $referenceUser->id, $isbn], base_path(), ['APP_ENV' => 'testing'], $stream, 12);
        try {
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, "ready\n")), $process->getErrorOutput());
            $connection->beginTransaction();
            match ($case) {
                'update' => $connection->table('books')->where('id', $book->id)->update(['title' => '先保存']),
                'delete' => $connection->table('books')->where('id', $book->id)->delete(),
                'isbn' => $connection->table('books')->insert(['user_id' => $user->id, 'title' => '競合ISBN', 'author' => '著者', 'isbn' => $isbn, 'published_date' => '2026-01-01']),
                'genre' => $connection->table('genres')->where('id', $genre->id)->delete(),
                'user' => $connection->table('users')->where('id', $referenceUser->id)->delete(),
                'owner' => $connection->table('users')->where('id', $user->id)->delete(),
            };
            if (in_array($case, ['genre', 'user', 'owner'], true)) {
                $connection->commit();
            }
            $beforeRefusal = $this->snapshot();
            $stream->write("save\n");
            if ($connection->transactionLevel()) {
                $this->waitForSqlInProcessList($connection, $process, $case === 'isbn' ? 'update `books`' : 'for update');
                $connection->commit();
            }
            $stream->close();
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            $lines = explode("\n", trim($process->getOutput()));
            $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(match ($case) {
                'delete', 'owner' => 404, 'update' => 200, default => 422
            }, $result['status']);
            if ($case === 'update') {
                $saved = $connection->table('books')->where('id', $book->id)->first();
                $this->assertSame('API後保存', $saved->title);
                $this->assertSame($isbn, $saved->isbn);
                $this->assertSame([$genre->id], $connection->table('book_genre')->where('book_id', $book->id)->pluck('genre_id')->all());
            } else {
                $this->assertSame($beforeRefusal, $this->snapshot());
                if (in_array($case, ['genre', 'isbn', 'user'], true)) {
                    $this->assertArrayHasKey(match ($case) {
                        'genre' => 'genres.0', 'user' => 'user_id', default => 'isbn'
                    }, $result['body']['errors']);
                }
            }
        } finally {
            try {
                if ($connection->transactionLevel()) {
                    $connection->rollBack();
                }
                if ($process->isRunning()) {
                    $process->stop(0);
                }
                $stream->close();
                // 起動・同期に失敗しても、追加した参照ユーザーを含めて除去する。
                $userIds = array_unique([$user->id, $referenceUser->id]);
                $connection->table('users')->whereIn('id', $userIds)->delete();
                $connection->table('genres')->where('id', $genre->id)->delete();
                $this->assertSame($baseline, $this->snapshot());
            } finally {
                // 保存値の比較が失敗した場合も検証用接続を破棄する。
                DB::purge('api_race');
            }
        }
    }

    public static function referenceCases(): array
    {
        return [['users'], ['genres']];
    }

    /** T-11-24：共有ロック取得を同期し、参照先削除が保存完了まで待機することをSQLで観測する。 */
    #[DataProvider('referenceCases')]
    public function test_shared_references_are_protected_until_commit(string $table): void
    {
        $connection = DB::connection('api_race');
        $baseline = $this->snapshot();
        [$user, $genre, $book] = $this->fixtures();
        $script = $this->bootstrap().<<<'CHILD'
App\Models\Book::saving(function () {
 echo "locked\n"; fflush(STDOUT);
 if (trim(fgets(STDIN)) !== 'commit') { exit(3); }
});
$body = ['user_id'=>(int)$argv[3],'title'=>'参照保護','author'=>'著者','isbn'=>$argv[4],'published_date'=>'2099-01-02','genres'=>[(int)$argv[2]]];
$request=Illuminate\Http\Request::create('/api/v1/books/'.$argv[1],'PUT',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'],json_encode($body));
$response=$app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
echo $response->getStatusCode()."\n";
CHILD;
        $deleteScript = $this->bootstrap().<<<'CHILD'
try {
 Illuminate\Support\Facades\DB::table($argv[1])->where('id',(int)$argv[2])->delete();
 echo "deleted\n";
} catch (Illuminate\Database\QueryException $e) {
 echo 'constraint:'.($e->errorInfo[1]??0)."\n";
}
CHILD;
        $stream = new InputStream;
        $save = new Process([PHP_BINARY, '-r', $script, (string) $book->id, (string) $genre->id, (string) $user->id, $book->isbn], base_path(), ['APP_ENV' => 'testing'], $stream, 12);
        $delete = new Process([PHP_BINARY, '-r', $deleteScript, $table, (string) ($table === 'users' ? $user->id : $genre->id)], base_path(), ['APP_ENV' => 'testing'], null, 12);
        try {
            $save->start();
            $this->assertTrue($save->waitUntil(fn ($type, $output) => str_contains($output, "locked\n")), $save->getErrorOutput());
            $delete->start();
            $this->waitForSqlInProcessList($connection, $delete, 'delete from `'.$table.'`');
            $stream->write("commit\n");
            $stream->close();
            $this->assertSame(0, $save->wait(), $save->getErrorOutput());
            $this->assertStringContainsString('200', $save->getOutput(), $save->getErrorOutput());
            $this->assertSame(0, $delete->wait(), $delete->getErrorOutput());
            if ($table === 'genres') {
                $this->assertStringContainsString('constraint:1451', $delete->getOutput());
                $this->assertTrue($connection->table('genres')->where('id', $genre->id)->exists());
                $this->assertTrue($connection->table('book_genre')->where('book_id', $book->id)->where('genre_id', $genre->id)->exists());
            } else {
                $this->assertStringContainsString('deleted', $delete->getOutput());
                $this->assertFalse($connection->table('books')->where('id', $book->id)->exists());
            }
        } finally {
            foreach ([$save, $delete] as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            $stream->close();
            $connection->table('users')->where('id', $user->id)->delete();
            $connection->table('genres')->where('id', $genre->id)->delete();
            try {
                $this->assertSame($baseline, $this->snapshot());
            } finally {
                DB::purge('api_race');
            }
        }
    }
}
