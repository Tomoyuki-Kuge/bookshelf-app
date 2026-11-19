<?php

/** Fortifyのログイン入力を既存フォームの条件に合わせる。 */

namespace App\Http\Requests;

class LoginRequest extends \Laravel\Fortify\Http\Requests\LoginRequest
{
    protected function prepareForValidation(): void
    {
        $this->replace($this->only('email', 'password'));
    }

    /** 画面設計の入力条件を定義する。 */
    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email'], 'password' => ['required', 'string']];
    }

    /** フォームの項目別日本語エラーを返す。 */
    public function messages(): array
    {
        return [
            'email.required' => 'メールアドレスは必須です。', 'email.string' => 'メールアドレスの形式が正しくありません。', 'email.email' => 'メールアドレスの形式が正しくありません。',
            'password.required' => 'パスワードは必須です。', 'password.string' => 'パスワードは文字列で入力してください。',
        ];
    }
}
