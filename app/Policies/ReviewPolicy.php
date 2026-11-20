<?php

/** レビューの投稿者本人の操作だけを許可する。 */

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

class ReviewPolicy
{
    /** 保存済みの投稿者IDで更新権限を判定する。 */
    public function update(User $user, Review $model): bool
    {
        return $user->id === $model->user_id;
    }

    /** 保存済みの投稿者IDで削除権限を判定する。 */
    public function delete(User $user, Review $model): bool
    {
        return $user->id === $model->user_id;
    }
}
