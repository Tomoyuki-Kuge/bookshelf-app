<?php

/** 認証に必要な機能と既存Web画面の接続だけを設定する。 */
return [
    'guard' => 'web', 'passwords' => 'users', 'username' => 'email', 'email' => 'email',
    'home' => '/', 'prefix' => '', 'domain' => null, 'middleware' => ['web'],
    'views' => true, 'features' => [], 'limiters' => ['login' => null],
    'lowercase_usernames' => false,
];
