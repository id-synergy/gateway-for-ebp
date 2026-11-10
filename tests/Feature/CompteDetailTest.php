<?php

namespace Tests\Feature;

use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompteDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_detail_dun_compte_est_affiche(): void
    {
        $account = EbpAccount::factory()->create(['label' => 'Compte Détail']);
        EbpFolder::factory()->create(['ebp_account_id' => $account->id, 'folder_id' => '3691']);

        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->get("/admin/comptes/{$account->id}")
            ->assertInertia(fn ($page) => $page->component('Admin/CompteDetail')
                ->where('account.label', 'Compte Détail')
                ->has('folders', 1));
    }
}
