<?php

/** 公開APIの書籍JSONと厳密な整数・配列型を検証する。 */

namespace App\Http\Requests\Api;

use App\Http\Requests\BookRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookSaveRequest extends FormRequest
{
    /** 公開APIに本人制限を加えない。 */
    public function authorize(): bool
    {
        return true;
    }

    /** クエリでJSON本文の必須項目を補わせない。 */
    public function validationData(): array
    {
        $input = $this->json()->all();
        $original = $this->attributes->get('original_api_json');
        if (is_object($original) && isset($original->genres) && is_object($original->genres)) {
            $input['genres'] = $original->genres;
        }

        return $input;
    }

    /** 正規化前の型を使い、数値文字列・小数・真偽値を拒否する。 */
    public function rules(): array
    {
        $original = $this->attributes->get('original_api_json');
        $integer = fn ($attribute, $value, $fail) => is_int(data_get($original, $attribute)) ?: $fail($attribute === 'user_id' ? 'ユーザーIDは整数で指定してください。' : 'ジャンルIDは整数で指定してください。');

        return [
            'user_id' => ['bail', 'required', $integer, 'exists:users,id'],
            'title' => ['bail', 'required', 'string', 'max:255'],
            'author' => ['bail', 'required', 'string', 'max:255'],
            'isbn' => ['bail', 'required', 'string', 'size:13', 'regex:/\A[0-9]+\z/D', Rule::unique('books', 'isbn')->ignore($this->route('book')?->id)],
            'published_date' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:5000'],
            'image_url' => ['bail', 'nullable', 'string', 'max:255', 'url:http,https'],
            'genres' => ['bail', 'required', function ($attribute, $value, $fail) use ($original) {
                if (! is_array($original->genres ?? null)) {
                    $fail('ジャンルは配列で指定してください。');
                }
            }, 'array', 'min:1'],
            'genres.*' => ['bail', $integer, 'distinct:strict', 'exists:genres,id'],
        ];
    }

    /** Webと共通の暫定文言へAPI固有条件を加える。 */
    public function messages(): array
    {
        return array_merge((new BookRequest)->messages(), [
            'user_id.required' => 'ユーザーIDは必須です。',
            'user_id.exists' => '指定したユーザーが存在しません。',
            'published_date.date_format' => '出版日は有効な日付で入力してください。',
        ]);
    }
}
