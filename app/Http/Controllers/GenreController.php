<?php

/** 共通ジャンルの表示・登録・更新と紐付きを保護した削除を扱う。 */

namespace App\Http\Controllers;

use App\Http\Requests\GenreRequest;
use App\Models\Genre;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class GenreController extends Controller
{
    /** 全ジャンルと紐付き件数をID順に表示する。 */
    public function index(): View
    {
        return view('genres.index', ['genres' => Genre::withCount('books')->orderBy('id')->get()]);
    }

    /** 空の登録フォームを表示する。 */
    public function create(): View
    {
        return view('genres.create');
    }

    /** 対象に紐付く書籍だけを日時降順でページ分割する。 */
    public function show(Genre $genre): View
    {
        return view('genres.show', ['genre' => $genre, 'books' => $genre->books()->with('genres')->orderByDesc('created_at')->paginate(10)]);
    }

    /** 現在の保存名を編集フォームへ渡す。 */
    public function edit(Genre $genre): View
    {
        return view('genres.edit', compact('genre'));
    }

    /** 検証済みの名前を登録し、一意制約による競合だけを入力エラーにする。 */
    public function store(GenreRequest $request): RedirectResponse
    {
        try {
            Genre::create($request->safe()->only('name'));
        } catch (QueryException $exception) {
            return $this->redirectForDuplicateNameOrThrow($request, $exception);
        }

        return redirect()->route('genres.index')->with('success', 'ジャンルを登録しました。');
    }

    /** 保存直前に対象をロックして再確認し、削除済みの再生成を防ぐ。 */
    public function update(GenreRequest $request, Genre $genre): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $genre) {
                $current = Genre::whereKey($genre->id)->lockForUpdate()->firstOrFail();
                $current->update($request->safe()->only('name'));
            });
        } catch (QueryException $exception) {
            return $this->redirectForDuplicateNameOrThrow($request, $exception);
        }

        return redirect()->route('genres.index')->with('success', 'ジャンルを更新しました。');
    }

    /** 事前確認とRESTRICTで紐付きを保護し、通常のDB障害は隠さない。 */
    public function destroy(Genre $genre): RedirectResponse
    {
        $message = 'このジャンルには書籍が紐付いているため削除できません。';
        if ($genre->books()->exists()) {
            return redirect()->route('genres.index')->with('error', $message);
        }
        try {
            $genre->delete();
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1451 || ! str_contains($exception->errorInfo[2] ?? '', 'book_genre_genre_id_foreign')) {
                throw $exception;
            }

            return redirect()->route('genres.index')->with('error', $message);
        }

        return redirect()->route('genres.index')->with('success', 'ジャンルを削除しました。');
    }

    /** ジャンル名の既知の一意索引違反だけを重複入力として扱う。 */
    private function redirectForDuplicateNameOrThrow(GenreRequest $request, QueryException $exception): RedirectResponse
    {
        if (($exception->errorInfo[1] ?? null) !== 1062 || ! preg_match('/[\x27`](?:genres\.)?genres_name_(?:lower_)?unique[\x27`]/', $exception->errorInfo[2] ?? '')) {
            throw $exception;
        }

        return $request->invalidResponse(['name' => GenreRequest::duplicateMessage()]);
    }
}
