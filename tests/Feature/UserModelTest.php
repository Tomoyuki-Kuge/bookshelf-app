<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    public function test_user_attributes_are_fillable_cast_and_hidden(): void
    {
        $user = new User([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
        ]);
        $user->email_verified_at = '2026-01-01 00:00:00';
        $user->remember_token = 'test-token';

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertSame('2026-01-01', $user->email_verified_at->toDateString());
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
    }
}
