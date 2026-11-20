<?php

/** 初期データのBookSeederの責務を実行する。 */

namespace Database\Seeders;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BookSeeder extends Seeder
{
    public const BOOKS = [
        ['吾輩は猫である', '夏目漱石', '9784101010014', '1905-01-01', ['小説'], '猫の視点を通して人間社会を描いた夏目漱石の小説。'],
        ['人を動かす', 'D・カーネギー', '9784422100524', '1936-10-01', ['ビジネス', '自己啓発'], '人との関わり方と信頼関係を築く考え方を紹介する一冊。'],
        ['リーダブルコード', 'Dustin Boswell', '9784873115658', '2012-06-23', ['技術書'], '読み手に意図が伝わるコードを書くための工夫を解説する技術書。'],
        ['7つの習慣', 'スティーブン・R・コヴィー', '9784863940246', '2013-08-30', ['ビジネス', '自己啓発'], '日々の行動と人間関係を見直すための七つの習慣を紹介する一冊。'],
        ['坊っちゃん', '夏目漱石', '9784101010021', '1906-04-01', ['小説'], '新任教師を主人公に、学校で起こる騒動を描いた夏目漱石の小説。'],
        ['サピエンス全史', 'ユヴァル・ノア・ハラリ', '9784309226712', '2016-09-08', ['歴史', '科学'], '人類の歩みを歴史と科学の観点からたどる一冊。'],
        ['Clean Code', 'Robert C. Martin', '9784048930598', '2017-12-18', ['技術書'], '保守しやすいコードの考え方と実践例を紹介する技術書。'],
        ['嫌われる勇気', '岸見一郎・古賀史健', '9784478025819', '2013-12-13', ['自己啓発'], '対話形式でアドラー心理学の考え方を紹介する一冊。'],
        ['火花', '又吉直樹', '9784163902302', '2015-03-11', ['小説'], 'お笑いの世界で生きる若者の交流と葛藤を描いた小説。'],
        ['FACTFULNESS', 'ハンス・ロスリング', '9784822289607', '2019-01-11', ['ビジネス', '科学'], 'データをもとに世界を捉えるための考え方を紹介する一冊。'],
        ['コンテナ物語', 'マルク・レビンソン', '9784822251468', '2007-01-18', ['ビジネス', '歴史'], 'コンテナ輸送が物流と世界経済にもたらした変化をたどる一冊。'],
    ];

    /** 書籍本体の既存値を保ち、ジャンル集合は指定どおり同期する。 */
    public function run(): void
    {
        DB::transaction(function () {
            $owner = User::first();
            SeedReferences::require($owner !== null, self::class, '利用者1名以上');
            $genres = Genre::whereIn('name', GenreSeeder::NAMES)->get()->keyBy('name');
            foreach (GenreSeeder::NAMES as $name) {
                SeedReferences::require($genres->has($name), self::class, 'ジャンル '.$name);
            }
            foreach (self::BOOKS as $index => [$title, $author, $isbn, $publishedDate, $names, $description]) {
                $book = Book::firstOrCreate(['isbn' => $isbn], [
                    'user_id' => $owner->id, 'title' => $title, 'author' => $author,
                    'published_date' => $publishedDate, 'description' => $description,
                    'image_url' => 'https://placehold.co/200x300/e2e8f0/475569?text='.($index + 1),
                ]);
                $book->genres()->sync(array_map(fn ($name) => $genres[$name]->id, $names));
            }
        });
    }
}
