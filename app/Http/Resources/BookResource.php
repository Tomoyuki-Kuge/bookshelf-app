<?php

/** 書籍APIの公開項目と型を一覧・詳細・保存応答で統一する。 */

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookResource extends JsonResource
{
    /** 個人情報や内部集計キーを公開せず、ロード済みレビューだけを追加する。 */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'title' => $this->title,
            'author' => $this->author,
            'isbn' => $this->isbn,
            'published_date' => $this->published_date,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'genres' => $this->genres->map(fn ($genre) => ['id' => (int) $genre->id, 'name' => $genre->name])->values(),
            'average_rating' => $this->reviews_avg_rating === null ? null : round((float) $this->reviews_avg_rating, 1),
            'review_count' => (int) $this->reviews_count,
            'reviews' => $this->whenLoaded('reviews', fn () => $this->reviews->map(fn ($review) => [
                'id' => (int) $review->id,
                'user_name' => $review->user->name,
                'rating' => (int) $review->rating,
                'comment' => $review->comment,
                'created_at' => $review->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ])->values()),
        ];
    }
}
