<?php

namespace Tests\Unit;

use App\Models\IpEntry;
use App\Services\Security\IpAccessControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IpAccessControlTest extends TestCase
{
    use RefreshDatabase;

    private IpAccessControl $ipAccess;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ipAccess = app(IpAccessControl::class);
    }

    public function test_statut_dune_ip_absente_est_neutral(): void
    {
        $this->assertSame('neutral', $this->ipAccess->statusFor('203.0.113.1'));
        $this->assertFalse($this->ipAccess->isAllowed('203.0.113.1'));
        $this->assertFalse($this->ipAccess->isBlocked('203.0.113.1'));
    }

    public function test_ip_allowed_et_blocked(): void
    {
        IpEntry::create(['ip' => '203.0.113.2', 'status' => 'allowed']);
        IpEntry::create(['ip' => '203.0.113.3', 'status' => 'blocked']);

        $this->assertTrue($this->ipAccess->isAllowed('203.0.113.2'));
        $this->assertTrue($this->ipAccess->isBlocked('203.0.113.3'));
    }

    public function test_les_boucles_locales_ne_sont_jamais_bloquees(): void
    {
        IpEntry::create(['ip' => '127.0.0.1', 'status' => 'blocked']);
        IpEntry::create(['ip' => '::1', 'status' => 'blocked']);

        $this->assertFalse($this->ipAccess->isBlocked('127.0.0.1'));
        $this->assertFalse($this->ipAccess->isBlocked('::1'));
    }

    public function test_le_statut_est_mis_en_cache_et_invalide_par_forget(): void
    {
        IpEntry::create(['ip' => '203.0.113.4', 'status' => 'allowed']);
        $this->assertTrue($this->ipAccess->isAllowed('203.0.113.4'));

        // Mutation directe en DB (contourne le modèle) : le cache garde l'ancienne valeur.
        IpEntry::where('ip', '203.0.113.4')->update(['status' => 'blocked']);
        $this->assertTrue($this->ipAccess->isAllowed('203.0.113.4'));

        // Après invalidation, la nouvelle valeur est lue.
        $this->ipAccess->forget('203.0.113.4');
        $this->assertFalse($this->ipAccess->isAllowed('203.0.113.4'));
        $this->assertTrue($this->ipAccess->isBlocked('203.0.113.4'));
    }

    public function test_ip_nulle_ou_vide_est_neutral(): void
    {
        $this->assertSame('neutral', $this->ipAccess->statusFor(null));
        $this->assertSame('neutral', $this->ipAccess->statusFor(''));
    }
}
