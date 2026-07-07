<?php

namespace Tests\Feature;

use App\Support\ProductionReadiness\ProductionReadinessChecklist;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductionReadinessChecklistTest extends TestCase
{
    /**
     * Top-level config namespaces this test class mutates. Every test that
     * calls `config([...])` or `putenv(...)` mutates the live Laravel
     * configuration / process environment. Without explicit restoration, those
     * mutations leak across test classes that run later in the same suite
     * (for example, polluting the DB host used by HTTP feature tests and
     * surfacing as 503 / QueryException). Snapshot/restore covers every key
     * the class touches so later tests see the same baseline Laravel booted
     * with.
     *
     * @var list<string>
     */
    private const MUTATED_CONFIG_NAMESPACES = [
        'app',
        'session',
        'sanctum',
        'cors',
        'cache',
        'queue',
        'mail',
    ];

    /**
     * Process-level environment variables this class mutates via putenv().
     * These bypass Laravel's config repository entirely, so they must be
     * captured and cleared in addition to the config snapshot.
     *
     * @var list<string>
     */
    private const MUTATED_ENV_VARS = [
        'TRUSTED_PROXIES',
        'TRUSTED_HEADERS',
    ];

    /**
     * @var array<string, mixed>
     */
    private array $originalConfig = [];

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvVars = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureMutableConfig();
        $this->captureMutableEnvVars();
    }

    protected function tearDown(): void
    {
        $this->restoreMutableConfig();
        $this->restoreMutableEnvVars();

        parent::tearDown();
    }

    /**
     * Snapshot every top-level config namespace this class mutates so we can
     * restore it after the test. We use config()->all() to read the live
     * repository before the body of the test touches anything, then overwrite
     * with those exact values in tearDown(). Capturing in setUp() (rather than
     * tearDown()) is intentional: the body of each test in this class mutates
     * the live repository, so a tearDown() capture would record the polluted
     * state as the "original".
     */
    private function captureMutableConfig(): void
    {
        $repository = config();

        foreach (self::MUTATED_CONFIG_NAMESPACES as $namespace) {
            $this->originalConfig[$namespace] = $repository->get($namespace);
        }
    }

    private function restoreMutableConfig(): void
    {
        if ($this->originalConfig === []) {
            return;
        }

        $repository = config();

        foreach ($this->originalConfig as $namespace => $value) {
            if ($value === null) {
                $repository->set($namespace, null);

                continue;
            }

            $repository->set($namespace, $value);
        }

        $this->originalConfig = [];
    }

    /**
     * Snapshot every process-level env var this class mutates so we can
     * restore (or unset) it after the test. putenv('NAME') with no value
     * unsets the variable; we record whether the variable was set originally
     * to decide between restoring its prior value or unsetting it.
     */
    private function captureMutableEnvVars(): void
    {
        foreach (self::MUTATED_ENV_VARS as $name) {
            $previous = getenv($name);

            $this->originalEnvVars[$name] = $previous === false ? false : (string) $previous;
        }
    }

    private function restoreMutableEnvVars(): void
    {
        foreach ($this->originalEnvVars as $name => $previous) {
            if ($previous === false) {
                putenv($name);

                continue;
            }

            putenv("{$name}={$previous}");
        }

        $this->originalEnvVars = [];
    }

    #[Test]
    public function complete_production_safe_fixture_passes_all_assertions(): void
    {
        $result = (new ProductionReadinessChecklist)->evaluate($this->safeProductionSnapshot());

        $this->assertTrue($result['passed']);
        $this->assertFalse($result['skipped']);
        $this->assertSame([], $result['failures']);
    }

    #[Test]
    public function env_example_defaults_forced_to_production_fail_with_actionable_messages(): void
    {
        $result = (new ProductionReadinessChecklist)->evaluate($this->snapshotFromEnvExample());
        $messages = implode("\n", $result['failures']);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('APP_URL', $messages);
        $this->assertStringContainsString('SESSION_ENCRYPT', $messages);
        $this->assertStringContainsString('SANCTUM_STATEFUL_DOMAINS', $messages);
        $this->assertStringContainsString('CORS allowed_origins', $messages);
        $this->assertStringContainsString('SCHEDULER_DEPLOYED', $messages);
    }

    #[Test]
    public function dev_and_test_environments_skip_by_default_unless_forced(): void
    {
        $snapshot = $this->safeProductionSnapshot([
            'app' => ['env' => 'testing', 'debug' => true, 'url' => 'http://localhost'],
        ]);

        $skipped = (new ProductionReadinessChecklist)->evaluate($snapshot);
        $forced = (new ProductionReadinessChecklist)->evaluate($snapshot, force: true);

        $this->assertTrue($skipped['passed']);
        $this->assertTrue($skipped['skipped']);
        $this->assertFalse($forced['passed']);
        $this->assertFalse($forced['skipped']);
    }

    #[Test]
    public function production_fails_for_unsafe_session_cookie_configuration(): void
    {
        $result = (new ProductionReadinessChecklist)->evaluate($this->safeProductionSnapshot([
            'session' => [
                'secure' => false,
                'encrypt' => false,
                'http_only' => false,
                'same_site' => 'none',
                'driver' => 'file',
            ],
        ]));
        $messages = implode("\n", $result['failures']);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE', $messages);
        $this->assertStringContainsString('SESSION_ENCRYPT', $messages);
        $this->assertStringContainsString('SESSION_HTTP_ONLY', $messages);
        $this->assertStringContainsString('SESSION_DRIVER', $messages);
        $this->assertStringContainsString('SESSION_SAME_SITE=none', $messages);
    }

    #[Test]
    public function production_fails_for_unsafe_cross_service_deployment_configuration(): void
    {
        $result = (new ProductionReadinessChecklist)->evaluate($this->safeProductionSnapshot([
            'sanctum' => ['stateful' => ['localhost:5173', 'app.example.com']],
            'cors' => [
                'allowed_origins' => ['*', 'http://localhost:5173', 'http://app.example.com'],
                'allowed_origins_patterns' => [],
                'supports_credentials' => true,
            ],
            'cache' => ['default' => 'file'],
            'queue' => [
                'default' => 'redis',
                'connections' => ['redis' => ['driver' => 'redis', 'after_commit' => false]],
            ],
            'mail' => [
                'default' => 'log',
                'from' => ['address' => 'hello@example.com'],
            ],
            'trusted_proxies' => '',
            'scheduler_deployed' => false,
        ]));
        $messages = implode("\n", $result['failures']);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('SANCTUM_STATEFUL_DOMAINS', $messages);
        $this->assertStringContainsString('CORS allowed_origins/allowed_origins_patterns', $messages);
        $this->assertStringContainsString('CORS allowed_origins must not include localhost', $messages);
        $this->assertStringContainsString('CORS allowed_origins must be HTTPS', $messages);
        $this->assertStringContainsString('CACHE_STORE', $messages);
        $this->assertStringContainsString('after_commit', $messages);
        $this->assertStringContainsString('MAIL_MAILER', $messages);
        $this->assertStringContainsString('MAIL_FROM_ADDRESS', $messages);
        $this->assertStringContainsString('TRUSTED_PROXIES', $messages);
        $this->assertStringContainsString('SCHEDULER_DEPLOYED', $messages);
    }

    #[Test]
    public function production_fails_for_sync_queue_connection(): void
    {
        $result = (new ProductionReadinessChecklist)->evaluate($this->safeProductionSnapshot([
            'queue' => [
                'default' => 'sync',
                'connections' => ['sync' => ['driver' => 'sync']],
            ],
        ]));

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('QUEUE_CONNECTION', implode("\n", $result['failures']));
    }

    #[Test]
    public function scheduler_assertions_require_registered_commands_and_locks(): void
    {
        $metadata = ProductionReadinessChecklist::scheduleMetadataFromRoutes(base_path('routes/console.php'));

        foreach (ProductionReadinessChecklist::REQUIRED_SCHEDULED_COMMANDS as $command) {
            $this->assertArrayHasKey($command, $metadata);
            $this->assertTrue($metadata[$command]['withoutOverlapping'], $command.' missing withoutOverlapping()');
            $this->assertTrue($metadata[$command]['onOneServer'], $command.' missing onOneServer()');
        }

        $result = (new ProductionReadinessChecklist)->evaluate($this->safeProductionSnapshot([
            'schedules' => [
                'surveys:expire' => ['withoutOverlapping' => false, 'onOneServer' => false],
            ],
        ]));

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('withoutOverlapping', implode("\n", $result['failures']));
        $this->assertStringContainsString('onOneServer', implode("\n", $result['failures']));
    }

    #[Test]
    public function command_skips_non_production_by_default(): void
    {
        config(['app.env' => 'testing']);

        $exitCode = Artisan::call('production:check-readiness');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('skipped', Artisan::output());
    }

    #[Test]
    public function command_fails_for_forced_unsafe_non_production_configuration(): void
    {
        config([
            'app.env' => 'testing',
            'app.debug' => true,
            'app.url' => 'http://localhost',
            'app.key' => '',
            'session.secure' => false,
            'session.encrypt' => false,
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'mail.default' => 'array',
        ]);

        $exitCode = Artisan::call('production:check-readiness', ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Production readiness check failed', $output);
        $this->assertStringContainsString('APP_ENV', $output);
    }

    #[Test]
    public function command_passes_for_safe_production_configuration(): void
    {
        $safe = $this->safeProductionSnapshot();
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => $safe['app']['key'],
            'app.url' => $safe['app']['url'],
            'session' => $safe['session'],
            'sanctum.stateful' => $safe['sanctum']['stateful'],
            'cors' => $safe['cors'],
            'cache.default' => 'redis',
            'queue' => $safe['queue'],
            'mail' => $safe['mail'],
        ]);
        putenv('TRUSTED_PROXIES=10.0.0.0/8');

        try {
            $exitCode = Artisan::call('production:check-readiness', ['--scheduler-confirmed' => true]);
        } finally {
            putenv('TRUSTED_PROXIES');
        }

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Production readiness check passed', Artisan::output());
    }

    #[Test]
    public function checklist_snapshot_represents_trusted_proxy_inputs(): void
    {
        putenv('TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12');
        putenv('TRUSTED_HEADERS=x-forwarded-all');

        try {
            $snapshot = ProductionReadinessChecklist::snapshotFromLaravel(true);
        } finally {
            putenv('TRUSTED_PROXIES');
            putenv('TRUSTED_HEADERS');
        }

        $this->assertSame('10.0.0.0/8,172.16.0.0/12', $snapshot['trusted_proxies']);
        $this->assertSame('x-forwarded-all', $snapshot['trusted_headers']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function safeProductionSnapshot(array $overrides = []): array
    {
        $snapshot = [
            'app' => [
                'env' => 'production',
                'debug' => false,
                'key' => 'base64:'.str_repeat('a', 44),
                'url' => 'https://pmo.example.test',
            ],
            'session' => [
                'driver' => 'database',
                'secure' => true,
                'encrypt' => true,
                'http_only' => true,
                'same_site' => 'lax',
            ],
            'sanctum' => [
                'stateful' => ['pmo.example.test', 'admin.example.test'],
            ],
            'cors' => [
                'allowed_origins' => ['https://pmo.example.test', 'https://admin.example.test'],
                'allowed_origins_patterns' => [],
                'supports_credentials' => true,
            ],
            'cache' => ['default' => 'redis'],
            'queue' => [
                'default' => 'redis',
                'connections' => ['redis' => ['driver' => 'redis', 'after_commit' => true]],
            ],
            'mail' => [
                'default' => 'smtp',
                'mailers' => [
                    'smtp' => [
                        'host' => 'smtp.example.test',
                        'username' => 'user',
                        'password' => 'pass',
                    ],
                ],
                'from' => ['address' => 'noreply@example.test'],
            ],
            'trusted_proxies' => '10.0.0.0/8',
            'trusted_headers' => 'x-forwarded-all',
            'scheduler_deployed' => true,
            'schedules' => array_fill_keys(
                ProductionReadinessChecklist::REQUIRED_SCHEDULED_COMMANDS,
                ['withoutOverlapping' => true, 'onOneServer' => true]
            ),
        ];

        return array_replace_recursive($snapshot, $overrides);
    }

    /** @return array<string, mixed> */
    private function snapshotFromEnvExample(): array
    {
        $env = [];
        foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $env[$key] = trim($value, ' "');
        }

        $queue = $env['QUEUE_CONNECTION'] ?? 'sync';

        return $this->safeProductionSnapshot([
            'app' => [
                'env' => 'production',
                'debug' => filter_var($env['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
                'key' => $env['APP_KEY'] ?? '',
                'url' => $env['APP_URL'] ?? '',
            ],
            'session' => [
                'driver' => $env['SESSION_DRIVER'] ?? 'file',
                'secure' => false,
                'encrypt' => filter_var($env['SESSION_ENCRYPT'] ?? false, FILTER_VALIDATE_BOOL),
                'http_only' => true,
                'same_site' => 'lax',
            ],
            'sanctum' => ['stateful' => ['localhost', '127.0.0.1:8000']],
            'cors' => [
                'allowed_origins' => [$env['APP_URL'] ?? 'http://localhost'],
                'allowed_origins_patterns' => [],
                'supports_credentials' => true,
            ],
            'cache' => ['default' => $env['CACHE_STORE'] ?? 'file'],
            'queue' => [
                'default' => $queue,
                'connections' => [$queue => ['driver' => $queue, 'after_commit' => true]],
            ],
            'mail' => [
                'default' => $env['MAIL_MAILER'] ?? 'log',
                'from' => ['address' => $env['MAIL_FROM_ADDRESS'] ?? 'hello@example.com'],
            ],
            'trusted_proxies' => '',
            'scheduler_deployed' => false,
        ]);
    }
}
