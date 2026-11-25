<?php

/** 公開の書籍一覧・詳細と登録フォームに実データを渡す。 */

namespace App\Http\Controllers;

use App\Http\Requests\BookRequest;
use App\Models\Book;
use App\Models\Genre;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BookController extends Controller
{
    /** 全書籍を登録日時とIDの降順でページ分割する。 */
    public function index(): View
    {
        return view('books.index', ['books' => Book::with('genres')->withAvg('reviews', 'rating')->orderByDesc('created_at')->orderByDesc('id')->paginate(10)]);
    }

    /** 対象書籍の全レビューと表示に必要な関連・利用者の状態を取得する。 */
    public function show(Request $request, Book $book): View
    {
        $book->load([
            'genres',
            'reviews' => fn ($query) => $query->orderByDesc('created_at'),
            'reviews.user',
            'reviews.likedByUsers',
        ]);
        $request->user()?->load(['favoriteBooks', 'likedReviews']);

        return view('books.show', ['book' => $book]);
    }

    /** 入力から利用者または既存画面の応答を作る。 */
    public function create(): View
    {
        return view('books.create', ['genres' => Genre::orderBy('name')->orderBy('id')->get()]);
    }

    /** 現在の書籍項目とジャンル集合を編集フォームへ渡す。 */
    public function edit(Book $book): View
    {
        $book->load('genres');

        return view('books.edit', ['book' => $book, 'genres' => Genre::orderBy('name')->orderBy('id')->get()]);
    }

    /** 認証利用者を作成者として書籍とジャンルを原子的に登録する。 */
    public function store(BookRequest $request): RedirectResponse
    {
        return $this->saveBook($request);
    }

    /** ロック後にも本人権限を確認して書籍とジャンルを置き換える。 */
    public function update(BookRequest $request, Book $book): RedirectResponse
    {
        return $this->saveBook($request, $book);
    }

    /** 関連行のCASCADEを使い、本人の書籍を物理削除する。 */
    public function destroy(Book $book): RedirectResponse
    {
        DB::transaction(function () use ($book) {
            $current = Book::whereKey($book->id)->lockForUpdate()->firstOrFail();
            $this->authorize('delete', $current);
            $current->delete();
        });

        return redirect()->route('books.index')->with('success', '書籍を削除しました。');
    }

    /** 保存失敗を全体取消しし、原因を特定できる入力競合だけをフォームへ戻す。 */
    private function saveBook(BookRequest $request, ?Book $book = null): RedirectResponse
    {
        try {
            $saved = DB::transaction(function () use ($request, $book) {
                $current = $book ? Book::whereKey($book->id)->lockForUpdate()->firstOrFail() : new Book;
                if ($book) {
                    $this->authorize('update', $current);
                } else {
                    $current->user_id = $request->user()->id;
                }
                $ids = $request->validated('genres');
                // 選択先を共有ロックし、確認後の削除による部分保存も防ぐ。
                if (Genre::whereKey($ids)->sharedLock()->get(['id'])->count() !== count($ids)) {
                    throw ValidationException::withMessages(['genres' => BookRequest::missingGenreMessage()]);
                }
                $attributes = $request->safe()->except('genres');
                $attributes['published_date'] = Carbon::parse($attributes['published_date'])->format('Y-m-d');
                $current->fill($attributes);
                $current->save();
                $current->genres()->sync($ids);

                return $current;
            });
        } catch (ValidationException $exception) {
            return $request->invalidResponse($exception->errors());
        } catch (QueryException $exception) {
            $detail = $exception->errorInfo[2] ?? '';
            if (($exception->errorInfo[1] ?? null) === 1062 && preg_match('/[\x27`](?:books\.)?books_isbn_unique[\x27`]/', $detail)) {
                return $request->invalidResponse(['isbn' => BookRequest::duplicateMessage()]);
            }
            if (($exception->errorInfo[1] ?? null) === 1452 && str_contains($detail, 'book_genre_genre_id_foreign')) {
                return $request->invalidResponse(['genres' => BookRequest::missingGenreMessage()]);
            }
            throw $exception;
        }

        return redirect()->route('books.show', $saved)->with('success', $book ? '書籍情報を更新しました。' : '書籍を登録しました。');
    }
}
