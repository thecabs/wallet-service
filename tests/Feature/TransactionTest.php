<?php
namespace Tests\Feature;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class TransactionTest extends TestCase
{
    use RefreshDatabase;
    public function test_user_can_credit_wallet()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'solde' => 100]);
        $this->actingAs($user);
        $response = $this->postJson("/api/wallets/{$wallet->id}/credit", [
            'amount' => 50,
            'reference' => 'CREDIT_123',
            'description' => 'Recharge de portefeuille'
        ]);
        $response->assertStatus(201)
            ->assertJson(['message' => 'Crédit effectué']);
        $this->assertEquals(150, $wallet->fresh()->solde);
    }
    public function test_user_can_debit_wallet()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'solde' => 100]);
        $this->actingAs($user);
        $response = $this->postJson("/api/wallets/{$wallet->id}/debit", [
            'amount' => 30,
            'reference' => 'DEBIT_456',
            'description' => 'Paiement marchand'
        ]);
        $response->assertStatus(201)
            ->assertJson(['message' => 'Débit effectué']);
        $this->assertEquals(70, $wallet->fresh()->solde);
    }
}