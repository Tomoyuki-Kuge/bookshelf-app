<?php

/** 公開の書籍一覧・詳細と登録フォームに実データを渡す。 */

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Genre;
use Illuminate\Http\Request;
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
}
