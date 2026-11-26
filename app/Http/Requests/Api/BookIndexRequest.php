<?php

/** 公開書籍一覧のクエリだけを検証する。 */

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class BookIndexRequest extends FormRequest
{
    /** 公開APIに本人制限を加えない。 */
    public function authorize(): bool
    {
        return true;
    }

    /** 本文を検索条件へ混ぜない。 */
    public function validationData(): array
    {
        return $this->query->all();
    }

    /** URL上の整数表現はJSON整数の型検証とは分ける。 */
    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:255'],
            'genre_id' => ['bail', 'sometimes', 'integer', 'exists:genres,id'],
            'page' => ['bail', 'sometimes', 'integer', 'min:1'],
            'per_page' => ['bail', 'sometimes', 'integer', 'between:1,100'],
        ];
    }

    /** 確定・暫定採用された日本語エラーを対応付ける。 */
    public function messages(): array
    {
        return [
            'keyword.string' => '検索キーワードは文字列で入力してください。',
            'keyword.max' => '検索キーワードは255文字以内で入力してください。',
            'genre_id.integer' => 'ジャンルIDは整数で指定してください。',
            'genre_id.exists' => '指定したジャンルが存在しません。',
            'page.*' => 'ページ番号は1以上の整数で指定してください。',
            'per_page.*' => '1ページの件数は1〜100の整数で指定してください。',
        ];
    }
}
