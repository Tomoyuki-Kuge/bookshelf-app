<?php

/** レビューのある書籍を正確な平均順で集計し、公開ランキングへ渡す。 */

namespace App\Http\Controllers;

use App\Models\Book;
use App\Support\RankingOrder;
use Brick\Math\BigRational;
use Illuminate\View\View;

class RankingController extends Controller
{
    /** 書籍情報と集計を同じSELECTで取得し、全候補の比較後に10冊を選ぶ。 */
    public function index(): View
    {
        $rankedBooks = Book::query()
            ->join('reviews', 'reviews.book_id', '=', 'books.id')
            ->select('books.id', 'books.title', 'books.author', 'books.image_url')
            ->selectRaw('SUM(reviews.rating) AS rating_sum, COUNT(reviews.id) AS reviews_count')
            ->groupBy('books.id', 'books.title', 'books.author', 'books.image_url')
            ->get()->sort(new RankingOrder)->take(10)->values();

        foreach ($rankedBooks as $book) {
            // DBのAVGを経由せず、表示時の二重丸めを避ける。
            $book->reviews_avg_rating = BigRational::of((string) $book->rating_sum)
                ->dividedBy((string) $book->reviews_count)->toFloat();
        }

        return view('ranking.index', compact('rankedBooks'));
    }
}
