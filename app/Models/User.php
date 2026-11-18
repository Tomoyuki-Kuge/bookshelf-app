<?php

/**
 * 利用者の認証情報と書籍・レビューへの関連を管理する。
 */

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * 利用者の認証と投稿・登録データを扱うモデル。
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $dateFormat = 'Y-m-d H:i:s';

    /**
     * 一括代入を許可する利用者情報。
     *
     * @var array<int, string>
     */
    protected $fillable = ['name', 'email', 'password'];

    /**
     * 配列・JSONへ公開しない認証情報。
     *
     * @var array<int, string>
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * 確認日時とパスワード保存時の変換。
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * 利用者が登録した書籍を取得する。
     */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }

    /**
     * 利用者が投稿したレビューを取得する。
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * お気に入り書籍と登録日時を取得する。
     */
    public function favoriteBooks(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'favorites')->withTimestamps();
    }

    /**
     * いいねしたレビューと登録日時を取得する。
     */
    public function likedReviews(): BelongsToMany
    {
        return $this->belongsToMany(Review::class, 'review_likes')->withTimestamps();
    }
}
