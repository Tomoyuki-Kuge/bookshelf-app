<?php

/**
 * 書籍へのレビューと投稿者・いいねの関連を管理する。
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 評価とコメントを持つレビューのモデル。
 */
class Review extends Model
{
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = ['user_id', 'book_id', 'rating', 'comment'];

    protected $casts = ['rating' => 'integer'];

    /**
     * レビューを投稿した利用者を取得する。
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * レビューの対象書籍を取得する。
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * レビューにいいねした利用者と登録日時を取得する。
     */
    public function likedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'review_likes')->withTimestamps();
    }
}
