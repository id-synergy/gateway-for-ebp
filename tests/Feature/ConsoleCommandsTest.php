<?php

namespace Tests\Feature;

use App\Models\GatewayJob;
use App\Models\User;
use App\Services\Logging\RequestLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Couvre les commandes artisan du socle (déploiement et maintenance) :
 * gateway:create-admin, ebp:logs:purge, gateway:test-mail.
 */
class ConsoleCommandsTest extends TestCase
{
    use RefreshDatabase;

    // --- gateway:create-admin ---

    public function test_create_admin_cree_un_administrateur_actif(): void
    {
        $this->artisan('gateway:create-admin', [
            '--name' => 'Alice',
            '--email' => 'alice@example.com',
            '--password' => 'motdepasse-solide',
        ])->assertSuccessful();

        $user = User::where('email', 'alice@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('Alice', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->is_default_contact);
    }

    public function test_create_admin_marque_le_contact_par_defaut(): void
    {
        $this->artisan('gateway:create-admin', [
            '--name' => 'Bob',
            '--email' => 'bob@example.com',
            '--password' => 'motdepasse-solide',
            '--default-contact' => true,
        ])->assertSuccessful();

        $this->assertTrue(User::where('email', 'bob@example.com')->first()->is_default_contact);
    }

    public function test_create_admin_met_a_jour_un_compte_existant(): void
    {
        User::factory()->create(['email' => 'carol@example.com', 'name' => 'Ancien']);

        $this->artisan('gateway:create-admin', [
            '--name' => 'Nouveau',
            '--email' => 'carol@example.com',
            '--password' => 'motdepasse-solide',
        ])->assertSuccessful();

        $this->assertSame(1, User::where('email', 'carol@example.com')->count());
        $this->assertSame('Nouveau', User::where('email', 'carol@example.com')->first()->name);
    }

    public function test_create_admin_refuse_un_mot_de_passe_trop_court(): void
    {
        $this->artisan('gateway:create-admin', [
            '--name' => 'Dan',
            '--email' => 'dan@example.com',
            '--password' => 'court',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'dan@example.com']);
    }

    public function test_create_admin_refuse_un_email_invalide(): void
    {
        $this->artisan('gateway:create-admin', [
            '--name' => 'Eve',
            '--email' => 'pas-un-email',
            '--password' => 'motdepasse-solide',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }

    // --- ebp:logs:purge ---

    public function test_purge_supprime_logs_et_corps_au_dela_de_la_retention(): void
    {
        Storage::fake(RequestLogger::DISK);
        config(['ebp.retention.logs_days' => 360]);

        // Log ancien avec corps en fichier : doit être purgé (méta + fichier).
        $old = app(RequestLogger::class)->record(
            ['method' => 'GET', 'ebp_endpoint' => 'gescom/api/v1/Clients', 'occurred_at' => now()->subDays(400)],
            '{"old":true}',
        );
        $oldPath = $old->response_body_path;
        $this->assertNotNull($oldPath);
        Storage::disk(RequestLogger::DISK)->assertExists($oldPath);

        // Log récent : doit rester.
        $recent = app(RequestLogger::class)->record(
            ['method' => 'GET', 'ebp_endpoint' => 'gescom/api/v1/Items', 'occurred_at' => now()->subDays(5)],
            '{"recent":true}',
        );

        $this->artisan('ebp:logs:purge')->assertSuccessful();

        $this->assertDatabaseMissing('request_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('request_logs', ['id' => $recent->id]);
        Storage::disk(RequestLogger::DISK)->assertMissing($oldPath);
    }

    public function test_purge_supprime_les_resultats_de_jobs_anciens(): void
    {
        Storage::fake(RequestLogger::DISK);
        config(['ebp.retention.job_results_days' => 360]);

        $oldJob = GatewayJob::create([
            'public_id' => Str::uuid(), 'type' => 'simple', 'status' => 'done',
            'finished_at' => now()->subDays(400),
        ]);
        $recentJob = GatewayJob::create([
            'public_id' => Str::uuid(), 'type' => 'simple', 'status' => 'done',
            'finished_at' => now()->subDays(5),
        ]);

        $this->artisan('ebp:logs:purge')->assertSuccessful();

        $this->assertDatabaseMissing('gateway_jobs', ['id' => $oldJob->id]);
        $this->assertDatabaseHas('gateway_jobs', ['id' => $recentJob->id]);
    }

    // --- gateway:test-mail ---

    public function test_test_mail_envoie_un_message_au_destinataire(): void
    {
        // Mail::raw n'est pas un Mailable : on capte l'événement d'envoi du transport.
        Event::fake([MessageSent::class]);
        config(['mail.default' => 'array']); // transport en mémoire, aucun envoi réseau

        $this->artisan('gateway:test-mail', ['to' => 'dest@example.com'])->assertSuccessful();

        Event::assertDispatched(MessageSent::class, fn (MessageSent $e) => in_array(
            'dest@example.com',
            array_map(fn ($a) => $a->getAddress(), $e->message->getTo()),
            true,
        ));
    }
}
