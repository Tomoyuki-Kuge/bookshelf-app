<?php

/** レビューの入力検証と型安全なフォーム復元を扱う。 */

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ReviewRequest extends FormRequest
{
    /** 経路の認証・本人認可を通過した入力を検証する。 */
    public function authorize(): bool
    {
        return true;
    }

    /** 評価とコメントの確定した入力条件を適用する。 */
    public function rules(): array
    {
        return [
            'rating' => ['bail', 'required', function ($attribute, $value, $fail) {
                // Laravelの整数判定が受理する真偽値・浮動小数を除外する。
                if (! is_int($value) && ! is_string($value)) {
                    $fail('評価は1〜5の整数で入力してください。');
                }
            }, 'integer', 'between:1,5'],
            'comment' => ['bail', 'required', 'string', 'max:1000'],
        ];
    }

    /** Q-19のQA 行27で正式確定したレビューの日本語文言を返す。 */
    public function messages(): array
    {
        return [
            'rating.required' => '評価は必須です。',
            'rating.integer' => '評価は1〜5の整数で入力してください。',
            'rating.between' => '評価は1〜5の整数で入力してください。',
            'comment.required' => 'コメントは必須です。',
            'comment.string' => 'コメントは文字列で入力してください。',
            'comment.max' => 'コメントは1000文字以内で入力してください。',
        ];
    }

    /** 外部Refererに依存せず、未入力も既存値へ復活させずに戻す。 */
    protected function failedValidation(Validator $validator): void
    {
        $review = $this->route('review');
        $rating = $this->input('rating');
        $input = [
            'rating' => is_string($rating) || is_int($rating) ? $rating : '',
            'comment' => is_string($this->input('comment')) ? $this->input('comment') : '',
        ];

        throw new HttpResponseException(
            redirect()->route($review ? 'reviews.edit' : 'books.show', $review ?? $this->route('book'))
                ->withErrors($validator)->withInput($input)
        );
    }
}
