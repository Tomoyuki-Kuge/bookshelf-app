<?php

/** 認証利用者によるレビューの投稿・本人編集・物理削除を扱う。 */

namespace App\Http\Controllers;

use App\Http\Requests\ReviewRequest;
use App\Models\Book;
use App\Models\Review;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReviewController extends Controller
{
    /** 投稿先と投稿者を信頼できる経路・認証情報から設定する。 */
    public function store(ReviewRequest $request, Book $book): RedirectResponse
    {
        DB::transaction(function () use ($request, $book) {
            $parent = Book::whereKey($book->id)->sharedLock()->firstOrFail();
            $review = new Review($request->validated());
            $review->user_id = $request->user()->id;
            $review->book_id = $parent->id;
            $review->save();
        });

        return redirect()->route('books.show', $book)->with('success', 'レビューを登録しました。');
    }

    /** 本人のレビューと対象書籍を編集画面へ渡す。 */
    public function edit(Review $review): View
    {
        $review->load('book');

        return view('reviews.edit', ['review' => $review]);
    }

    /** 親から子の順でロックし、存在・本人権限を再確認して更新する。 */
    public function update(ReviewRequest $request, Review $review): RedirectResponse
    {
        DB::transaction(function () use ($request, $review) {
            Book::whereKey($review->book_id)->sharedLock()->firstOrFail();
            $lockedReview = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();
            $this->authorize('update', $lockedReview);
            $lockedReview->fill($request->validated())->save();
        });

        return redirect()->route('books.show', $review->book_id)->with('success', 'レビューを更新しました。');
    }

    /** 本人のレビューと既存CASCADEによるそのいいねだけを削除する。 */
    public function destroy(Review $review): RedirectResponse
    {
        DB::transaction(function () use ($review) {
            Book::whereKey($review->book_id)->sharedLock()->firstOrFail();
            $lockedReview = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();
            $this->authorize('delete', $lockedReview);
            $lockedReview->delete();
        });

        return redirect()->route('books.show', $review->book_id)->with('success', 'レビューを削除しました。');
    }
}
