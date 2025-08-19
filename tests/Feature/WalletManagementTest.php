<?php
namespace Tests\Feature;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class WalletManagementTest extends TestCase
{
    use RefreshDatabase;
    public function test_user_can_create_wallet()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $response = $this->postJson('/api/wallets', [
            'currency' => 'XAF'
        ]);
        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'wallet' => ['id', 'user_id', 'devise', 'solde', 'statut']
            ]);
    }
    public function test_user_can_close_wallet()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);
        $response = $this->putJson("/api/wallets/{$wallet->id}/close");
        $response->assertStatus(200)
            ->assertJson(['message' => 'Portefeuille fermé']);
        $this->assertEquals('fermé', $wallet->fresh()->statut);
    }
}