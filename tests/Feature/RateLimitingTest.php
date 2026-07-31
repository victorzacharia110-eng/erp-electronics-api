<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_endpoint_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'unknown@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_register_endpoint_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/register', [
                'name' => 'Test User',
                'email' => "rate-limit-{$i}@example.com",
                'phone' => '0712345678',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])->assertStatus(201);
        }

        $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'rate-limit-3@example.com',
            'phone' => '0712345678',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertStatus(429);
    }
}
