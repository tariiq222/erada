<?php

namespace Tests;

use App\Modules\Core\Authorization\AccessDecision;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\DisablesCsrfForTesting;
use Tests\Support\CanonicalAuthorizationFixtures;

abstract class TestCase extends BaseTestCase
{
    use CanonicalAuthorizationFixtures, DisablesCsrfForTesting;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $method = strtoupper($method);

        if (
            app()->environment('testing')
            && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && ! isset($server['HTTP_X_SKIP_CSRF'])
            && ! isset($server['HTTP_X_XSRF_TOKEN'])
            && ! isset($server['HTTP_X_CSRF_TOKEN'])
            && ! array_key_exists('_token', $parameters)
        ) {
            $server['HTTP_X_SKIP_CSRF'] = '1';
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // AccessDecision memoizes scoped roles / scope chains in static arrays for
        // the lifetime of the process. Tests share one process, so reset the engine
        // cache per test to guarantee no role/department state leaks between cases.
        AccessDecision::flushCache();

        if (method_exists($this, 'withoutVite')) {
            $this->withoutVite();
        }

        $this->disableCsrfForTesting();

        $uses = array_flip(class_uses_recursive(static::class));
        if (isset($uses[RefreshDatabase::class]) && Schema::hasTable('authorization_roles')) {
            $this->seed(RolesAndPermissionsSeeder::class);
        }
    }
}
