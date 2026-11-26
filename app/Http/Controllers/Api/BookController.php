<?php

/** 公開書籍APIの取得・一括保存・物理削除を扱う。 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BookIndexRequest;
use App\Http\Requests\Api\BookSaveRequest;
use App\Http\Resources\BookCollection;
use App\Http\Resources\BookResource;
use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookController extends Controller
{
    /** 条件をページ分割前に適用し、適用中のクエリだけをリンクへ渡す。 */
    public function index(BookIndexRequest $request)
    {
        $input = $request->validated();
        $query = Book::with('genres')->withCount('reviews')->withAvg('reviews', 'rating');
        if (($input['keyword'] ?? '') !== '') {
            $keyword = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $input['keyword']).'%';
            $query->where(function ($query) use ($keyword) {
                $query->whereRaw("LOWER(title) COLLATE utf8mb4_bin LIKE LOWER(?) COLLATE utf8mb4_bin ESCAPE '!'", [$keyword])
                    ->orWhereRaw("LOWER(author) COLLATE utf8mb4_bin LIKE LOWER(?) COLLATE utf8mb4_bin ESCAPE '!'", [$keyword]);
            });
        }
        if (isset($input['genre_id'])) {
            $query->whereHas('genres', fn ($query) => $query->where('genres.id', $input['genre_id']));
        }
        $perPage = (int) ($input['per_page'] ?? 20);
        $page = (int) ($input['page'] ?? 1);
        $total = (clone $query)->count();
        // 範囲外はoffsetを計算せず、整数上限付近でも要求ページを維持する。
        $items = $page > max(1, (int) ceil($total / $perPage)) ? collect() : $query->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $perPage)->get();
        $books = new LengthAwarePaginator($items, $total, $perPage, $page, ['path' => $request->url()]);
        $books->appends(array_intersect_key($input, array_flip(['keyword', 'genre_id', 'per_page'])));

        return new BookCollection($books);
    }

    /** 対象書籍の全レビューと集計を公開する。 */
    public function show(Book $book)
    {
        return new BookResource($this->loadDetail($book));
    }

    /** 検証済みJSONから登録し201を返す。 */
    public function store(BookSaveRequest $request)
    {
        return (new BookResource($this->save($request)))->response()->setStatusCode(201);
    }

    /** 登録者を含む必須項目とジャンル集合を置換する。 */
    public function update(BookSaveRequest $request, Book $book)
    {
        return new BookResource($this->save($request, $book));
    }

    /** 本人Policyを適用せず、削除済み対象を再取得で拒否する。 */
    public function destroy(Book $book)
    {
        DB::transaction(function () use ($book) {
            Book::whereKey($book->id)->lockForUpdate()->firstOrFail()->delete();
        });

        return response()->noContent();
    }

    /** 一覧以外は全レビューと投稿者・集計を同じ形で読み込む。 */
    private function loadDetail(Book $book): Book
    {
        return $book->load(['genres', 'reviews' => fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id'), 'reviews.user'])->loadCount('reviews')->loadAvg('reviews', 'rating');
    }

    /** 既知の制約違反だけを項目エラーへ戻す。 */
    private function save(BookSaveRequest $request, ?Book $book = null): Book
    {
        try {
            $saved = DB::transaction(function () use ($request, $book) {
                $target = $book ? Book::whereKey($book->id)->lockForUpdate()->firstOrFail() : new Book;
                $input = $request->validated();
                if (! User::whereKey($input['user_id'])->sharedLock()->first(['id'])) {
                    throw ValidationException::withMessages(['user_id' => '指定したユーザーが存在しません。']);
                }
                // ID順の共有ロックで参照先の削除を保存完了まで待機させる。
                $existing = Genre::whereKey($input['genres'])->orderBy('id')->sharedLock()->pluck('id')->all();
                $this->checkGenres($input['genres'], $existing);
                $target->fill(array_diff_key($input, ['genres' => true]));
                $target->save();
                $target->genres()->sync($input['genres']);

                // ロック解放後の親削除と集計再取得の競合を避け、保存時点の応答を確定する。
                return $this->loadDetail($target);
            });
        } catch (QueryException $exception) {
            $message = $exception->errorInfo[2] ?? '';
            $code = $exception->errorInfo[1] ?? null;
            if ($code === 1062 && preg_match('/[\x27`](?:books\.)?books_isbn_unique[\x27`]/', $message)) {
                throw ValidationException::withMessages(['isbn' => 'そのISBNは既に使用されています。']);
            }
            if ($code === 1452 && str_contains($message, 'books_user_id_foreign')) {
                throw ValidationException::withMessages(['user_id' => '指定したユーザーが存在しません。']);
            }
            if ($code === 1452 && str_contains($message, 'book_genre_genre_id_foreign')) {
                $this->checkGenres($request->validated('genres'), Genre::whereKey($request->validated('genres'))->pluck('id')->all());
            }
            throw $exception;
        }

        return $saved;
    }

    /** 元の配列位置を保って消失した選択先を通知する。 */
    private function checkGenres(array $requested, array $existing): void
    {
        $errors = [];
        foreach ($requested as $index => $id) {
            if (! in_array($id, $existing, true)) {
                $errors['genres.'.$index] = '選択したジャンルが存在しません。';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
