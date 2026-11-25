<?php

/** 認証利用者のレビューいいねを、親子のロックで直列化して切り替える。 */

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Review;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewLikeController extends Controller
{
    /** 経路と認証情報だけを使い、現在の登録状態に応じて追加・解除する。 */
    public function __invoke(Request $request, Review $review): RedirectResponse
    {
        $userId = $request->user()->id;
        DB::transaction(function () use ($userId, $review) {
            Book::whereKey($review->book_id)->sharedLock()->firstOrFail();
            $lockedReview = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();
            // 最初の非ロックSELECTをレビューのロック後に置き、先行操作の確定値を読む。
            // 未登録の組にギャップロックを掛けず、別レビューへの追加と相互待機しない。
            $like = DB::table('review_likes')->where('user_id', $userId)
                ->where('review_id', $lockedReview->id)->first();
            if ($like) {
                $lockedReview->likedByUsers()->detach($userId);
            } else {
                $lockedReview->likedByUsers()->attach($userId);
            }
        });

        return redirect()->route('books.show', $review->book_id);
    }
}
