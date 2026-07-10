<?php

namespace Tests\Feature\Shared;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Http\Resources\ActivityLogResource;
use App\Modules\Shared\Models\ActivityLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Phase CFA-11 — IP / UA redaction upgrade at the JSON resource surface.
 *
 * The redaction is mandatory for every actor — the cluster_auditor role
 * does NOT widen the raw surface (auditing the existence of an action
 * does not require the originating address). The shape is:
 *   - ip_address  → /24 CIDR for IPv4, /48 CIDR for IPv6,
 *                   unknown:<sha256-12> for unparseable input.
 *   - user_agent  → browser family (Edge / Opera / Firefox / Chrome /
 *                   Safari / curl / wget / Postman / Insomnia / bot /
 *                   other). NO version, NO OS details, NO device fingerprint.
 *
 * Other fields (action / description / model_label / etc.) are preserved.
 * The pre-existing in-JSON redaction of `token|password|secret|email|
 * authorization|cookie|header|ip[_-]?address|user[_-]?agent|patient_*|
 * reporter_*` keys inside old_values / new_values / metadata is also
 * preserved byte-for-byte.
 */
class ClusterTreeActivityLogRedactionTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_ip_v4_redacted_to_cidr_24(): void
    {
        $resource = $this->resourceForIp('203.0.113.42');

        $this->assertSame('203.0.113.0/24', $resource['ip_address']);
    }

    public function test_ip_v6_redacted_to_cidr_48(): void
    {
        $resource = $this->resourceForIp('2001:0db8:85a3:0000:0000:8a2e:0370:7334');

        // /48 ⇒ first 3 groups (zero-padded hex)
        $this->assertSame('2001:0db8:85a3::/48', $resource['ip_address']);
    }

    public function test_unparseable_ip_redacted_to_family_hash(): void
    {
        $resource = $this->resourceForIp('not-an-ip');

        $this->assertMatchesRegularExpression('/^unknown:[a-f0-9]{12}$/', $resource['ip_address']);
    }

    public function test_empty_ip_returns_null(): void
    {
        $resource = $this->resourceForIp('');

        $this->assertNull($resource['ip_address']);
    }

    public function test_chrome_user_agent_redacted_to_family(): void
    {
        $resource = $this->resourceForUa(
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0.0.0 Safari/537.36'
        );

        $this->assertSame('Chrome', $resource['user_agent']);
    }

    public function test_firefox_user_agent_redacted_to_family(): void
    {
        $resource = $this->resourceForUa('Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0');

        $this->assertSame('Firefox', $resource['user_agent']);
    }

    public function test_safari_user_agent_redacted_to_family(): void
    {
        $resource = $this->resourceForUa(
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15'
        );

        $this->assertSame('Safari', $resource['user_agent']);
    }

    public function test_curl_user_agent_redacted_to_family(): void
    {
        $resource = $this->resourceForUa('curl/8.4.0');

        $this->assertSame('curl', $resource['user_agent']);
    }

    public function test_googlebot_user_agent_redacted_to_family(): void
    {
        $resource = $this->resourceForUa('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');

        $this->assertSame('bot', $resource['user_agent']);
    }

    public function test_unknown_user_agent_redacted_to_other(): void
    {
        $resource = $this->resourceForUa('Some Random Client/1.2.3 (no fingerprints here)');

        $this->assertSame('other', $resource['user_agent']);
    }

    public function test_raw_user_agent_version_stripped(): void
    {
        // Make sure no version / OS detail leaks.
        $raw = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0.0.0 Safari/537.36';
        $resource = $this->resourceForUa($raw);

        $this->assertSame('Chrome', $resource['user_agent']);
        $this->assertStringNotContainsString('118.0.0.0', $resource['user_agent']);
        $this->assertStringNotContainsString('Windows', $resource['user_agent']);
        $this->assertStringNotContainsString('Win64', $resource['user_agent']);
    }

    public function test_other_fields_preserved_through_resource(): void
    {
        $org = Organization::factory()->create();
        $log = ActivityLog::create([
            'action' => 'cfa11_preserved_fields',
            'description' => 'preserved shape',
            'loggable_type' => User::class,
            'loggable_id' => 999,
            'organization_id' => $org->id,
            'ip_address' => '198.51.100.7',
            'user_agent' => 'Mozilla/5.0 Chrome/119.0.0.0 Safari/537.36',
            'role' => 'admin',
            'reason' => 'redaction probe',
        ]);

        $resource = (new ActivityLogResource($log))->resolve(request());

        $this->assertSame($log->id, $resource['id']);
        $this->assertSame('cfa11_preserved_fields', $resource['action']);
        $this->assertSame('preserved shape', $resource['description']);
        $this->assertSame('User', $resource['loggable_type']);
        $this->assertSame(999, $resource['loggable_id']);
        $this->assertSame('admin', $resource['role']);
        $this->assertSame('redaction probe', $resource['reason']);
        // Redaction applied.
        $this->assertSame('198.51.100.0/24', $resource['ip_address']);
        $this->assertSame('Chrome', $resource['user_agent']);
    }

    public function test_existing_in_json_redaction_preserved(): void
    {
        $org = Organization::factory()->create();
        $log = ActivityLog::create([
            'action' => 'cfa11_in_json_preserved',
            'description' => 'in-JSON redaction must remain',
            'loggable_type' => User::class,
            'loggable_id' => 999,
            'organization_id' => $org->id,
            'old_values' => [
                'name' => 'Safe old name',
                'token' => 'raw-token',
            ],
            'new_values' => [
                'email' => 'secret@example.test',
            ],
            'metadata' => [
                'authorization' => 'Bearer raw-secret',
            ],
        ]);

        $resource = (new ActivityLogResource($log))->resolve(request());

        $this->assertSame('Safe old name', $resource['old_values']['name']);
        $this->assertSame('[REDACTED]', $resource['old_values']['token']);
        $this->assertSame('[REDACTED]', $resource['new_values']['email']);
        $this->assertSame('[REDACTED]', $resource['metadata']['authorization']);
    }

    // ──────────────────────────────────────────────────────────────
    // helpers
    // ──────────────────────────────────────────────────────────────

    private function resourceForIp(?string $ip): array
    {
        $org = Organization::factory()->create();
        $log = ActivityLog::create([
            'action' => 'cfa11_redaction_probe',
            'description' => 'ip redaction',
            'loggable_type' => User::class,
            'loggable_id' => 1,
            'organization_id' => $org->id,
            'ip_address' => $ip,
        ]);

        return (new ActivityLogResource($log))->resolve(request());
    }

    private function resourceForUa(?string $ua): array
    {
        $org = Organization::factory()->create();
        $log = ActivityLog::create([
            'action' => 'cfa11_redaction_probe',
            'description' => 'ua redaction',
            'loggable_type' => User::class,
            'loggable_id' => 1,
            'organization_id' => $org->id,
            'user_agent' => $ua,
        ]);

        return (new ActivityLogResource($log))->resolve(request());
    }
}
