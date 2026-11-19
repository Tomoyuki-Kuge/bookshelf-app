<?php

/** 会員登録の入力条件と日本語エラーを定義する。 */

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    /** ゲスト向けフォームの入力検証を許可する。 */
    public function authorize(): bool
    {
        return true;
    }

    /** 登録に必要な項目だけを検証する。 */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'password_confirmation' => ['required_with:password', 'same:password'],
        ];
    }

    /** フォームの項目別日本語エラーを返す。 */
    public function messages(): array
    {
        return [
            'name.required' => 'お名前は必須です。', 'name.string' => 'お名前は文字列で入力してください。', 'name.max' => 'お名前は255文字以内で入力してください。',
            'email.required' => 'メールアドレスは必須です。', 'email.string' => 'メールアドレスの形式が正しくありません。', 'email.email' => 'メールアドレスの形式が正しくありません。', 'email.max' => 'メールアドレスは255文字以内で入力してください。', 'email.unique' => 'そのメールアドレスは既に使用されています。',
            'password.required' => 'パスワードは必須です。', 'password.string' => 'パスワードは文字列で入力してください。', 'password.min' => 'パスワードは8文字以上で入力してください。',
            'password_confirmation.same' => '確認入力と一致しません', 'password_confirmation.required_with' => '確認入力と一致しません',
        ];
    }

    /** 空の確認欄も一致検証へ通す。 */
    protected function prepareForValidation(): void
    {
        $this->replace($this->only('name', 'email', 'password') + ['password_confirmation' => $this->input('password_confirmation') ?? '']);
    }
}
