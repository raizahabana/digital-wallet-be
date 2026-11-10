<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_issues_a_token_for_valid_credentials(): void
    {
        $user = User::factory()->create(); // factory password is "password"

        $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'token_type', 'expires_at']]);
    }

    public function test_it_rejects_a_wrong_password_with_a_problem_response(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('title', 'Invalid credentials');
    }

    public function test_it_validates_the_payload(): void
    {
        $this->postJson('/api/v1/auth/token', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_protected_routes_return_a_401_problem_without_a_token(): void
    {
        $this->getJson('/api/v1/wallet')
            ->assertStatus(401)
            ->assertJsonPath('title', 'Unauthenticated');
    }

    public function test_it_throttles_login_attempts_per_email_after_five_failures(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'wrong'])
                ->assertStatus(401);
        }

        $response = $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(429)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('title', 'Too many requests');

        $this->assertTrue($response->headers->has('Retry-After'));

        // Even the right password is blocked while the limit is active...
        $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(429);

        // ...but another account is not affected.
        $other = User::factory()->create();
        $this->postJson('/api/v1/auth/token', ['email' => $other->email, 'password' => 'password'])
            ->assertOk();
    }

    public function test_logout_revokes_the_token_that_made_the_request(): void
    {
        $user = User::factory()->create();

        $token = $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'password'])
            ->json('data.token');

        $this->assertSame(1, $user->tokens()->count());

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertSame(0, $user->tokens()->count());

        // The same token must no longer work.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/wallet')->assertStatus(401);
    }

    public function test_logout_only_revokes_the_current_device(): void
    {
        $user = User::factory()->create();

        $first = $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'password'])->json('data.token');
        $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'password'])->assertOk();

        $this->assertSame(2, $user->tokens()->count());

        $this->withToken($first)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertStatus(401);
    }
}
