<?php

/** 共通ジャンル名の検証と安全なフォーム再表示を扱う。 */

namespace App\Http\Requests;

use App\Models\Genre;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;

class GenreRequest extends FormRequest
{
    /** 認証済みの全会員による共通ジャンル管理を許可する。 */
    public function authorize(): bool
    {
        return true;
    }

    /** DBの式索引と同じ比較で、自身を除く重複を検証する。 */
    public function rules(): array
    {
        return ['name' => ['bail', 'required', 'string', 'max:255', function ($attribute, $value, $fail) {
            $query = Genre::whereRaw('LOWER(name) COLLATE utf8mb4_bin = LOWER(?) COLLATE utf8mb4_bin', [$value]);
            if ($this->route('genre')) {
                $query->whereKeyNot($this->route('genre')->id);
            }
            if ($query->exists()) {
                $fail(self::duplicateMessage());
            }
        }]];
    }

    /** Q-19で暫定採用された項目別文言を返す。 */
    public function messages(): array
    {
        return ['name.required' => 'ジャンル名は必須です。', 'name.string' => 'ジャンル名は文字列で入力してください。', 'name.max' => 'ジャンル名は255文字以内で入力してください。'];
    }

    /** 保存競合にも共通の重複文言を使用する。 */
    public static function duplicateMessage(): string
    {
        return 'そのジャンル名は既に使用されています。';
    }

    /** 文字列だけを正規化して検証する。 */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /** 不正な型を旧入力に残さず、内部の元フォームへ戻す。 */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException($this->invalidResponse($validator->errors()->toArray()));
    }

    /** 検証時と保存競合時の戻り先・旧入力を揃える。 */
    public function invalidResponse(array $errors): RedirectResponse
    {
        $genre = $this->route('genre');

        return redirect()->route($genre ? 'genres.edit' : 'genres.create', $genre ? [$genre] : [])
            ->withErrors($errors)->withInput(['name' => is_string($this->input('name')) ? $this->input('name') : '']);
    }
}
