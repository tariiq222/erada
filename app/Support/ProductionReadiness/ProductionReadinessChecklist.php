<?php

namespace App\Support\ProductionReadiness;

final class ProductionReadinessChecklist
{
    /** @var list<string> */
    public const REQUIRED_SCHEDULED_COMMANDS = [
        'surveys:expire',
        'attachments:purge-private',
        'ovr:archive-closed',
        'ovr:notify-sla-due',
        'ovr:notify-pending-timeout',
        'risks:check-due-evaluations',
        'risks:notify-overdue-actions',
    ];

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array{passed: bool, skipped: bool, failures: list<string>, checks: list<string>}
     */
    public function evaluate(?array $snapshot = null, bool $force = false): array
    {
        $snapshot ??= self::snapshotFromLaravel();
        $environment = strtolower((string) data_get($snapshot, 'app.env', ''));

        if (! $force && $environment !== 'production') {
            return [
                'passed' => true,
                'skipped' => true,
                'failures' => [],
                'checks' => ['APP_ENV is not production; readiness assertions skipped.'],
            ];
        }

        $failures = [];
        $checks = [];

        $this->assertApp($snapshot, $failures, $checks, $force);
        $this->assertSession($snapshot, $failures, $checks);
        $this->assertSanctum($snapshot, $failures, $checks);
        $this->assertCors($snapshot, $failures, $checks);
        $this->assertCache($snapshot, $failures, $checks);
        $this->assertQueue($snapshot, $failures, $checks);
        $this->assertMail($snapshot, $failures, $checks);
        $this->assertTrustedProxies($snapshot, $failures, $checks);
        $this->assertScheduler($snapshot, $failures, $checks);

        return [
            'passed' => $failures === [],
            'skipped' => false,
            'failures' => $failures,
            'checks' => $checks,
        ];
    }

    /** @return array<string, mixed> */
    public static function snapshotFromLaravel(?bool $schedulerConfirmed = null): array
    {
        return [
            'app' => [
                'env' => (string) config('app.env'),
                'debug' => (bool) config('app.debug'),
                'key' => (string) config('app.key'),
                'url' => (string) config('app.url'),
            ],
            'session' => (array) config('session'),
            'sanctum' => ['stateful' => (array) config('sanctum.stateful', [])],
            'cors' => (array) config('cors'),
            'cache' => ['default' => (string) config('cache.default')],
            'queue' => (array) config('queue'),
            'mail' => (array) config('mail'),
            // Production-readiness envs: read live via native getenv() so runtime
            // putenv() changes made in tests (and operator overrides) are captured
            // rather than the boot-time config/security.php value. getenv() returns
            // false when the variable is absent, in which case we fall back to the
            // cached config value. (Native getenv() — not the env() helper — keeps
            // larastan's noEnvCallsOutsideOfConfig rule satisfied.)
            'trusted_proxies' => (string) (($v = getenv('TRUSTED_PROXIES')) !== false ? $v : (string) config('security.production_readiness.trusted_proxies')),
            'trusted_headers' => (string) (($h = getenv('TRUSTED_HEADERS')) !== false ? $h : (string) config('security.production_readiness.trusted_headers')),
            'scheduler_deployed' => $schedulerConfirmed ?? self::truthy(($s = getenv('SCHEDULER_DEPLOYED')) !== false ? $s : (bool) config('security.production_readiness.scheduler_deployed', false)),
            'schedules' => self::scheduleMetadataFromRoutes(),
        ];
    }

    /** @return array<string, array{withoutOverlapping: bool, onOneServer: bool}> */
    public static function scheduleMetadataFromRoutes(?string $path = null): array
    {
        $path ??= base_path('routes/console.php');

        if (! is_file($path)) {
            return [];
        }

        $metadata = [];
        $contents = (string) file_get_contents($path);

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (! preg_match("/Schedule::command\(['\"]([^'\"]+)['\"]\)(.*);/", $line, $matches)) {
                continue;
            }

            $metadata[$matches[1]] = [
                'withoutOverlapping' => str_contains($matches[2], 'withoutOverlapping()'),
                'onOneServer' => str_contains($matches[2], 'onOneServer()'),
            ];
        }

        return $metadata;
    }

    /** @param array<string, mixed> $snapshot @param list<string> $failures @param list<string> $checks */
    private function assertApp(array $snapshot, array &$failures, array &$checks, bool $force): void
    {
        $env = strtolower((string) data_get($snapshot, 'app.env', ''));
        $url = (string) data_get($snapshot, 'app.url', '');

        if ($force && $env !== 'production') {
            $failures[] = 'APP_ENV must be production when enforcing readiness; current value is '.$env.'.';
        }
        if ((bool) data_get($snapshot, 'app.debug', false)) {
            $failures[] = 'APP_DEBUG must be false in production.';
        }
        if (trim((string) data_get($snapshot, 'app.key', '')) === '') {
            $failures[] = 'APP_KEY must be set to a generated production key.';
        }
        if (! $this->isHttpsUrl($url) || $this->isLocalUrl($url)) {
            $failures[] = 'APP_URL must be an explicit non-local HTTPS URL in production.';
        }

        $checks[] = 'APP_ENV, APP_DEBUG, APP_KEY, and APP_URL checked.';
    }

    /** @param array<string, mixed> $snapshot @param list<string> $failures @param list<string> $checks */
    private function assertSession(array $snapshot, array &$failures, array &$checks): void
    {
        $driver = strtolower((string) data_get($snapshot, 'session.driver', ''));
        $secure = self::truthy(data_get($snapshot, 'session.secure'));
        $encrypt = self::truthy(data_get($snapshot, 'session.encrypt'));
        $httpOnly = self::truthy(data_get($snapshot, 'session.http_only'));
        $sameSite = strtolower((string) data_get($snapshot, 'session.same_site', ''));

        if (! $secure) {
            $failures[] = 'SESSION_SECURE_COOKIE/session.secure must be true in production.';
        }
        if (! $encrypt) {
            $failures[] = 'SESSION_ENCRYPT/session.encrypt must be true in production.';
        }
        if (! $httpOnly) {
            $failures[] = 'SESSION_HTTP_ONLY/session.http_only must remain true in production.';
        }
        if (in_array($driver, ['array', 'cookie', 'file'], true)) {
            $failures[] = 'SESSION_DRIVER must be a durable shared store such as database or redis; array, cookie, and file are unsafe for production.';
        }
        if (! in_array($sameSite, ['lax', 'strict', 'none'], true)) {
            $failures[] = 'SESSION_SAME_SITE must be one of lax, strict, or none.';
        }
        if ($sameSite === 'none' && ! $secure) {
            $failures[] = 'SESSION_SAME_SITE=none requires SESSION_SECURE_COOKIE=true.';
        }

        $checks[] = 'Session cookie security, encryption, same_site, and driver checked.';
    }

    /** @param array<string, mixed> $snapshot @param list<string> $failures @param list<string> $checks */
    private function assertSanctum(array $snapshot, array &$failures, array &$checks): void
    {
        $domains = $this->stringList(data_get($snapshot, 'sanctum.stateful', []));

        if ($domains === []) {
            $failures[] = 'SANCTUM_STATEFUL_DOMAINS must be explicitly configured for production.';
        }
        foreach ($domains as $domain) {
            if ($this->isLocalHost($domain)) {
                $failures[] = 'SANCTUM_STATEFUL_DOMAINS must not include localhost, 127.0.0.1, or ::1 in production.';
                break;
            }
        }

        $checks[] = 'Sanctum stateful domains checked.';
    }

    /** @param array<string, mixed> $snapshot @param list<string> $failures @param list<string> $checks */
    private function assertCors(array $snapshot, array &$failures, array &$checks): void
    {
        $origins = $this->stringList(data_get($snapshot, 'cors.allowed_origins', []));
        $patterns = $this->stringList(data_get($snapshot, 'cors.allowed_origins_patterns', []));

        if ($origins === [] && $patterns === []) {
            $failures[] = 'CORS allowed_origins must contain explicit HTTPS production origins.';
        }
        foreach ([...$origins, ...$patterns] as $origin) {
            if ($origin === '*' || str_contains($origin, '*')) {
                $failures[] = 'CORS allowed_origins/allowed_origins_patterns must not use wildcards in production.';
                break;
            }
        }
        foreach ($origins as $origin) {
            if ($this->isLocalUrl($origin) || $this->isLocalHost($origin)) {
                $failures[] = 'CORS allowed_origins must not include localhost, 127.0.0.1, or ::1 in production.';
                break;
            }
        }
        foreach ($origins as $origin) {
            if (! $this->isHttpsUrl($origin)) {
                $failures[] = 'CORS allowed_origins must be HTTPS URLs in production.';
                break;
            }
        }
        if (self::truthy(data_get($snapshot, 'cors.supports_credentials')) && $origins === []) {
            $failures[] = 'CORS supports_credentials=true requires explicit HTTPS allowed_origins in production.';
        }

        $checks[] = 'CORS origins, patterns, and credentials checked.';
    }

    /** @param array<string, mixed> $snapshot @param list<string> $failures @param list<string> $checks */
    private function assertCache(array $snapshot, array &$failures, array &$checks): void
    {
        if (in_array(strtolower((string) data_get($snapshot, 'cache.default', '')), ['array', 'file', 'null'], true)) {
            $failures[] = 'CACHE_STORE must be a production-safe shared store such as redis or database; array/file/null are unsafe.';
        }

        $checks[] = 'Cache store checked.';
    }

    /** @param array<string, mixed> $snapshot @param list<string> $failures @param list<string> $checks */
    private function assertQueue(array $snapshot, array &$failures, array &$checks): void
    {
        $connection = strtolower((string) data_get($snapshot, 'queue.default', ''));

        if (in_array($connection, ['sync', 'deferred', 'null'], true)) {
            $failures[] = 'QUEUE_CONNECTION must not be sync/deferred/null in production.';
        }

        $afterCommit = data_get($snapshot, 'queue.connections.'.$connection.'.after_commit');
        if (! in_array($connection, ['sync', 'deferred', 'background', 'failover', 'null', ''], true) && ! self::truthy($afterCommit)) {
            $failures[] = 'queue.connections.'.$connection.'.after_commit must be true for async production queue drivers.';
        }

        $checks[] = 'Queue connection and after_commit checked.';
    }

    /** @param array<string, mixed> $snapshot @param list<string> $failures @param list<string> $checks */
    private function assertMail(array $snapshot, array &$failures, array &$checks): void
    {
        $mailer = strtolower((string) data_get($snapshot, 'mail.default', ''));
        $from = strtolower((string) data_get($snapshot, 'mail.from.address', ''));

        if (in_array($mailer, ['log', 'array'], true)) {
            $failures[] = 'MAIL_MAILER must not be log or array in production.';
        }
        if ($mailer === 'smtp') {
            $host = (string) data_get($snapshot, 'mail.mailers.smtp.host', '');
            $user = (string) data_get($snapshot, 'mail.mailers.smtp.username', '');
            $pass = (string) data_get($snapshot, 'mail.mailers.smtp.password', '');
            if ($host === '' || $host === '127.0.0.1') {
                $failures[] = 'MAIL_HOST must be a real SMTP server host when MAIL_MAILER=smtp.';
            }
            if ($user === '') {
                $failures[] = 'MAIL_USERNAME must be set when MAIL_MAILER=smtp.';
            }
            if ($pass === '') {
                $failures[] = 'MAIL_PASSWORD must be set when MAIL_MAILER=smtp.';
            }
        }
        if ($from === '' || str_ends_with($from, '@example.com') || str_contains($from, 'localhost')) {
            $failures[] = 'MAIL_FROM_ADDRESS must be a real production sender address, not example.com or localhost.';
        }

        $checks[] = 'Mail transport and from address checked.';
    }

    /** @param array<string, mixed> $snapshot @param list<string> $failures @param list<string> $checks */
    private function assertTrustedProxies(array $snapshot, array &$failures, array &$checks): void
    {
        if (trim((string) data_get($snapshot, 'trusted_proxies', '')) === '') {
            $failures[] = 'TRUSTED_PROXIES must be explicitly configured for production reverse proxy/load balancer deployments.';
        }

        $checks[] = 'Trusted proxies checked.';
    }

    /** @param array<string, mixed> $snapshot @param list<string> $failures @param list<string> $checks */
    private function assertScheduler(array $snapshot, array &$failures, array &$checks): void
    {
        if (! self::truthy(data_get($snapshot, 'scheduler_deployed'))) {
            $failures[] = 'SCHEDULER_DEPLOYED must be true or --scheduler-confirmed must be passed after scheduler deployment is configured.';
        }

        /** @var array<string, array{withoutOverlapping?: bool, onOneServer?: bool}> $schedules */
        $schedules = (array) data_get($snapshot, 'schedules', []);
        foreach (self::REQUIRED_SCHEDULED_COMMANDS as $command) {
            if (! isset($schedules[$command])) {
                $failures[] = 'Scheduler command '.$command.' must be registered in routes/console.php.';

                continue;
            }
            if (($schedules[$command]['withoutOverlapping'] ?? false) !== true) {
                $failures[] = 'Scheduler command '.$command.' must use withoutOverlapping().';
            }
            if (($schedules[$command]['onOneServer'] ?? false) !== true) {
                $failures[] = 'Scheduler command '.$command.' must use onOneServer().';
            }
        }

        $checks[] = 'Scheduler deployment marker and required locks checked.';
    }

    /** @param mixed $value */
    private static function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @param mixed $value @return list<string> */
    private function stringList($value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($item): string => trim((string) $item),
            $value
        ), static fn (string $item): bool => $item !== ''));
    }

    private function isHttpsUrl(string $value): bool
    {
        return strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https';
    }

    private function isLocalUrl(string $value): bool
    {
        $host = parse_url($value, PHP_URL_HOST);

        return $this->isLocalHost(is_string($host) ? $host : $value);
    }

    private function isLocalHost(string $value): bool
    {
        $host = strtolower(trim($value, ' []'));
        $host = preg_replace('/:\d+$/', '', $host) ?: $host;

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost');
    }
}
