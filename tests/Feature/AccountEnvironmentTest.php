<?php

namespace Tests\Feature;

use App\Models\EbpAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_avec_environnement_preproduction(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]))->post('/admin/comptes', [
            'label' => 'Préprod',
            'client_id' => 'cid', 'client_secret' => 'cs', 'subscription_key_primary' => 'k',
            'environment' => 'preproduction',
        ])->assertRedirect();

        $account = EbpAccount::where('label', 'Préprod')->first();
        $this->assertSame('preproduction', $account->environment);
    }

    public function test_api_base_url_depend_de_lenvironnement(): void
    {
        $prod = EbpAccount::factory()->create(['environment' => 'production']);
        $preprod = EbpAccount::factory()->create(['environment' => 'preproduction']);

        $this->assertSame(config('ebp.api_base_urls.production'), $prod->apiBaseUrl());
        $this->assertSame(config('ebp.api_base_urls.preproduction'), $preprod->apiBaseUrl());
        $this->assertNotSame($prod->apiBaseUrl(), $preprod->apiBaseUrl());
    }
}
