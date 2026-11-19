<?php

/** 接続段階の書籍一覧と登録フォームに実データを渡す。 */

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Genre;
use Illuminate\View\View;

class BookController extends Controller
{
    /** 全書籍を登録日時とIDの降順でページ分割する。 */
    public function index(): View
    {
        return view('books.index', ['books' => Book::with('genres')->withAvg('reviews', 'rating')->orderByDesc('created_at')->orderByDesc('id')->paginate(10)]);
    }

    /** 入力から利用者または既存画面の応答を作る。 */
    public function create(): View
    {
        return view('books.create', ['genres' => Genre::orderBy('name')->orderBy('id')->get()]);
    }
}
