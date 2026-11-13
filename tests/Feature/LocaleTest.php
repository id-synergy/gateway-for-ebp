<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Détection et bascule de langue : priorité cookie > Accept-Language > défaut app.
 * L'UI est servie en français par défaut (app.locale = fr).
 *
 * Note : le client de test Laravel envoie un Accept-Language « en » par défaut.
 * Les cas « sans préférence » / « français » le surchargent donc explicitement
 * pour refléter le vrai navigateur plutôt que cet artefact de test.
 */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_navigateur_francais_reste_en_francais(): void
    {
        $this->get('/login', ['Accept-Language' => 'fr-FR,fr;q=0.9']);
        $this->assertSame('fr', app()->getLocale());
    }

    public function test_un_navigateur_anglais_bascule_linterface_en_anglais(): void
    {
        $this->get('/login', ['Accept-Language' => 'en-US,en;q=0.9']);
        $this->assertSame('en', app()->getLocale());
    }

    public function test_une_langue_non_supportee_retombe_sur_le_defaut_fr(): void
    {
        $this->get('/login', ['Accept-Language' => 'de-DE,de;q=0.9']);
        $this->assertSame('fr', app()->getLocale());
    }

    public function test_le_cookie_a_priorite_sur_le_navigateur(): void
    {
        // Navigateur en anglais mais cookie « fr » (choix explicite du sélecteur) → fr.
        $this->withUnencryptedCookie('locale', 'fr')
            ->get('/login', ['Accept-Language' => 'en-US,en;q=0.9']);
        $this->assertSame('fr', app()->getLocale());
    }

    public function test_le_cookie_en_force_langlais_sur_un_navigateur_francais(): void
    {
        $this->withUnencryptedCookie('locale', 'en')
            ->get('/login', ['Accept-Language' => 'fr-FR,fr;q=0.9']);
        $this->assertSame('en', app()->getLocale());
    }

    public function test_un_cookie_invalide_est_ignore(): void
    {
        $this->withUnencryptedCookie('locale', 'zz')
            ->get('/login', ['Accept-Language' => 'en-US,en;q=0.9']);
        // Cookie hors liste ignoré → on suit le navigateur (en).
        $this->assertSame('en', app()->getLocale());
    }

    public function test_le_selecteur_pose_un_cookie_de_langue(): void
    {
        // Cookie non chiffré (exclu dans bootstrap/app.php) → assertion en clair.
        $this->get('/locale/en')->assertPlainCookie('locale', 'en');
        $this->get('/locale/fr')->assertPlainCookie('locale', 'fr');
    }

    public function test_le_selecteur_refuse_une_langue_inconnue(): void
    {
        $this->get('/locale/zz')->assertNotFound();
    }
}
