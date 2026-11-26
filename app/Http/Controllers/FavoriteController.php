<?php

/** 認証利用者のお気に入り一覧と、書籍単位で直列化した追加・解除を扱う。 */

namespace App\Http\Controllers;

use App\Models\Book;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FavoriteController extends Controller
{
    /** 自分の登録分を追加日時順にページ分割する。 */
    public function index(Request $request): View
    {
        return view('favorites.index', ['books' => $request->user()->favoriteBooks()
            ->orderByDesc('favorites.created_at')->paginate(10)]);
    }

    /** 認証情報と経路だけで登録状態を切り替え、確定後の件数で戻り先を補正する。 */
    public function toggle(Request $request, Book $book): RedirectResponse
    {
        $user = $request->user();
        $userId = $user->id;
        DB::transaction(function () use ($userId, $book) {
            $current = Book::whereKey($book->id)->lockForUpdate()->firstOrFail();
            // ロック待機後に最初の読み取りスナップショットを作り、未登録範囲はロックしない。
            $favorite = DB::table('favorites')->where('user_id', $userId)->where('book_id', $current->id)->first();
            if ($favorite) {
                $current->favoritedByUsers()->detach($userId);
            } else {
                $current->favoritedByUsers()->attach($userId);
            }
        });

        $page = $this->sourcePage($request);
        if ($page === null) {
            return redirect()->route('books.show', $book);
        }
        $lastPage = max(1, (int) ceil($user->favoriteBooks()->count() / 10));

        return redirect()->route('favorites.index', ['page' => min($page, $lastPage)]);
    }

    /** 一覧の同一オリジンだけを認め、単一の正整数page以外は1へ戻す。 */
    private function sourcePage(Request $request): ?int
    {
        $referer = parse_url($request->headers->get('referer', ''));
        $index = parse_url(route('favorites.index'));
        if (! is_array($referer) || ! isset($referer['scheme'], $referer['host'])
            || strtolower($referer['scheme']) !== strtolower($index['scheme'])
            || strtolower($referer['host']) !== strtolower($index['host'])
            || ($referer['port'] ?? (strtolower($referer['scheme']) === 'https' ? 443 : 80)) !== ($index['port'] ?? ($index['scheme'] === 'https' ? 443 : 80))
            || ($referer['path'] ?? '') !== $index['path']) {
            return null;
        }
        $pages = [];
        foreach (explode('&', $referer['query'] ?? '') as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $key = urldecode($key);
            if ($key === 'page' || str_starts_with($key, 'page[')) {
                $pages[] = $key === 'page' ? urldecode($value) : '';
            }
        }
        if (count($pages) !== 1 || ! preg_match('/\A[1-9][0-9]*\z/D', $pages[0])) {
            return 1;
        }

        return filter_var($pages[0], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    }
}
