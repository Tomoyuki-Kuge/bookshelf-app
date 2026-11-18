<?php

/**
 * 共通ジャンルと書籍との紐付けを管理する。
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 全利用者で共有するジャンルのモデル。
 */
class Genre extends Model
{
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = ['name'];

    /**
     * ジャンルに紐付く書籍と紐付け日時を取得する。
     */
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class)->withTimestamps();
    }
}
