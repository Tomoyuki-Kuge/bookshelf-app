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
            'email.required' => 'メールアドレスを入力してください', 'email.string' => 'メールアドレスはメール形式で入力してください', 'email.email' => 'メールアドレスはメール形式で入力してください',
            'password.required' => 'パスワードを入力してください', 'password.string' => 'パスワードは文字列で入力してください。',
        ];
    }
}
