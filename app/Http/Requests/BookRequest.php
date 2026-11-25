<?php

/** 書籍の項目検証と型安全なフォーム入力復元を扱う。 */

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;

class BookRequest extends FormRequest
{
    /** 経路の認証・Policy確認後に入力を検証する。 */
    public function authorize(): bool
    {
        return true;
    }

    /** 確定した書籍入力条件を定義する。 */
    public function rules(): array
    {
        return [
            'title' => ['bail', 'required', 'string', 'max:255'],
            'author' => ['bail', 'required', 'string', 'max:255'],
            'isbn' => ['bail', 'required', 'string', 'size:13', 'regex:/\A[0-9]+\z/D', Rule::unique('books', 'isbn')->ignore($this->route('book')?->id)],
            'published_date' => ['bail', 'required', 'string', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
            'image_url' => ['bail', 'nullable', 'string', 'max:255', 'url:http,https'],
            'genres' => ['bail', 'required', 'array', 'min:1'],
            'genres.*' => ['bail', 'integer', 'distinct', 'exists:genres,id'],
        ];
    }

    /** Q-19で暫定採用された日本語文言を欄へ対応付ける。 */
    public function messages(): array
    {
        $messages = [];
        foreach (['title' => 'タイトル', 'author' => '著者', 'isbn' => 'ISBN', 'published_date' => '出版日', 'description' => '説明', 'image_url' => '画像URL'] as $field => $label) {
            $messages[$field.'.required'] = $label.'は必須です。';
            $messages[$field.'.string'] = $label.'は文字列で入力してください。';
            $messages[$field.'.max'] = $label.'は:max文字以内で入力してください。';
        }

        return array_merge($messages, [
            'isbn.size' => 'ISBNは13桁で入力してください。',
            'isbn.regex' => 'ISBNは半角数字で入力してください。',
            'isbn.unique' => self::duplicateMessage(),
            'published_date.date' => '出版日は有効な日付で入力してください。',
            'image_url.url' => '画像URLはHTTPまたはHTTPSのURLで入力してください。',
            'genres.required' => 'ジャンルを1つ以上選択してください。',
            'genres.min' => 'ジャンルを1つ以上選択してください。',
            'genres.array' => 'ジャンルは配列で指定してください。',
            'genres.*.integer' => 'ジャンルIDは整数で指定してください。',
            'genres.*.distinct' => '同じジャンルを重複して選択できません。',
            'genres.*.exists' => self::missingGenreMessage(),
        ]);
    }

    /** 検証後の競合でも同じ文言を使用する。 */
    public static function duplicateMessage(): string
    {
        return 'そのISBNは既に使用されています。';
    }

    /** 保存前に消失した選択先を入力エラーとして扱う。 */
    public static function missingGenreMessage(): string
    {
        return '選択したジャンルが存在しません。';
    }

    /** 全解除を既存選択へ戻さないよう明示する。 */
    protected function prepareForValidation(): void
    {
        if (! $this->exists('genres')) {
            $this->merge(['genres' => []]);
        }
    }

    /** 任意のRefererに依存せず元フォームへ戻す。 */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException($this->invalidResponse($validator->errors()->toArray()));
    }

    /** 安全な旧入力と欄下エラーを元フォームへ戻す。 */
    public function invalidResponse(array $errors): RedirectResponse
    {
        $book = $this->route('book');

        return redirect()->route($book ? 'books.edit' : 'books.create', $book ? [$book] : [])->withErrors($errors)->withInput($this->oldInputForRedirect());
    }

    /** 不正型や秘密情報を旧入力へ残さず、チェック欄には一次元配列だけを戻す。 */
    private function oldInputForRedirect(): array
    {
        $input = [];
        foreach (['title', 'author', 'isbn', 'published_date', 'description', 'image_url'] as $field) {
            $input[$field] = is_string($this->input($field)) ? $this->input($field) : '';
        }
        $genreInput = $this->input('genres');
        $input['genres'] = is_array($genreInput)
            ? array_values(array_filter($genreInput, fn ($genreId) => is_int($genreId) || is_string($genreId)))
            : [];

        return $input;
    }
}
