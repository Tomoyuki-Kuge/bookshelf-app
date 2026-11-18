<?php

/**
 * 書籍管理の6業務テーブルと一意制約・外部キーを追加する。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 業務テーブルの作成と依存順に沿った削除を定義する。
 */
return new class extends Migration
{
    /**
     * 既存の利用者・認証補助テーブルを保持して業務テーブルを作成する。
     */
    public function up(): void
    {
        Schema::create('genres', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->collation('utf8mb4_bin')->unique();
            $table->timestamps(0);
        });
        // 表示名を保持し、全半角を区別したまま英字大小の重複を防ぐ。
        DB::statement('CREATE UNIQUE INDEX genres_name_lower_unique ON genres ((LOWER(name) COLLATE utf8mb4_bin))');

        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->restrictOnUpdate();
            $table->string('title', 255);
            $table->string('author', 255);
            $table->string('isbn', 13)->unique();
            $table->date('published_date');
            $table->string('image_url', 255)->nullable();
            $table->text('description')->nullable();
            $table->timestamps(0);
        });

        Schema::create('book_genre', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete()->restrictOnUpdate();
            $table->foreignId('genre_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->timestamps(0);
            $table->unique(['book_id', 'genre_id']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->restrictOnUpdate();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete()->restrictOnUpdate();
            $table->integer('rating');
            $table->text('comment');
            $table->timestamps(0);
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->restrictOnUpdate();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete()->restrictOnUpdate();
            $table->timestamps(0);
            $table->unique(['user_id', 'book_id']);
        });

        Schema::create('review_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->restrictOnUpdate();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete()->restrictOnUpdate();
            $table->timestamps(0);
            $table->unique(['user_id', 'review_id']);
        });
    }

    /**
     * 参照する子テーブルから順に追加した業務テーブルを削除する。
     */
    public function down(): void
    {
        Schema::dropIfExists('review_likes');
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('book_genre');
        Schema::dropIfExists('books');
        Schema::dropIfExists('genres');
    }
};
