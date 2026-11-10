<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class WalletProvisioningTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    public function test_creating_a_user_creates_an_empty_usd_wallet(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->wallet);
        $this->assertSame('0.00', $user->wallet->balance);
        $this->assertSame('USD', $user->wallet->currency);
        $this->assertSame(1, Wallet::where('user_id', $user->id)->count());
    }

    public function test_a_brand_new_user_can_use_the_wallet_endpoints_straight_away(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('data.balance', '0.00');
        $this->postJson('/api/v1/wallet/deposit', ['amount' => '5.00'])->assertStatus(201);
        $this->postJson('/api/v1/wallet/statements')->assertStatus(202);
    }

    public function test_the_backfill_migration_repairs_users_that_have_no_wallet(): void
    {
        $orphan = User::factory()->create();
        $orphan->wallet()->delete();
        $funded = $this->userWithWallet('42.00');

        (require database_path('migrations/2026_11_10_020000_backfill_wallets_for_existing_users.php'))->up();

        $this->assertSame('0.00', $orphan->wallet()->first()->balance);
        $this->assertSame('42.00', $this->balanceOf($funded), 'Existing wallets must not be touched.');
        $this->assertSame(2, Wallet::count());
    }

    public function test_a_missing_wallet_returns_a_generic_404_that_does_not_leak_model_names(): void
    {
        $user = User::factory()->create();
        $user->wallet()->delete();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/wallet')
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('title', 'Not found')
            ->assertJsonPath('detail', 'The requested resource was not found.');
    }
}
