<?php

namespace Tests\Architecture;

use Tests\TestCase;

class AdminDeploymentContractTest extends TestCase
{
    public function test_admin_image_is_an_independent_multistage_static_build(): void
    {
        $dockerfile = $this->read('Dockerfile.admin');

        $this->assertStringContainsString(
            'FROM node:20-alpine@sha256:fb4cd12c85ee03686f6af5362a0b0d56d50c58a04632e6c0fb8363f609372293 AS build',
            $dockerfile,
        );
        $this->assertStringContainsString('npm run admin:build', $dockerfile);
        $this->assertStringContainsString('vite.admin.config.ts', $dockerfile);
        $this->assertStringContainsString('COPY lang/ lang/', $dockerfile);
        $this->assertStringContainsString('ARG VITE_OPERATIONAL_URL', $dockerfile);
        $this->assertStringContainsString(
            'FROM nginx:1.27-alpine@sha256:65645c7bb6a0661892a8b03b89d0743208a18dd2f3f17a54ef4b76fb8e2f2a10 AS runtime',
            $dockerfile,
        );
        $this->assertStringContainsString("find dist-admin -type f -name '*.map' -delete", $dockerfile);
        $this->assertStringContainsString('rm -rf dist-admin/.vite', $dockerfile);
        $this->assertStringContainsString('COPY --from=build /app/dist-admin/ /usr/share/nginx/html/', $dockerfile);
        $this->assertStringNotContainsString('public/build', $dockerfile);
    }

    public function test_nginx_serves_the_spa_safely_and_proxies_same_origin_backend_requests(): void
    {
        $nginx = $this->read('deploy/admin-nginx.conf.template');

        $this->assertStringContainsString('try_files $uri $uri/ /index.html;', $nginx);
        $this->assertMatchesRegularExpression('/location = \/index\.html\s*\{[^}]*Cache-Control "no-store, no-cache, must-revalidate";/s', $nginx);
        $this->assertStringContainsString('location /assets/ {', $nginx);
        $this->assertStringContainsString('Cache-Control "public, max-age=31536000, immutable";', $nginx);
        $this->assertStringContainsString('location ^~ /.vite/ {', $nginx);
        $this->assertStringContainsString('location ~* \.map$ {', $nginx);

        $this->assertStringContainsString('map $http_x_forwarded_proto $upstream_forwarded_proto {', $nginx);
        $this->assertStringContainsString('map $http_host $upstream_host {', $nginx);
        $this->assertStringContainsString('~*^https$ https;', $nginx);
        $this->assertStringContainsString('~*^http$ http;', $nginx);
        $this->assertStringContainsString('default $scheme;', $nginx);
        $this->assertStringContainsString('default $host;', $nginx);

        foreach (['/api/', '/sanctum/'] as $path) {
            $this->assertStringContainsString("location {$path} {", $nginx);
        }
        $this->assertSame(2, substr_count($nginx, 'proxy_pass ${BACKEND_URL};'));

        foreach ([
            'proxy_set_header Host $upstream_host;',
            'proxy_set_header X-Forwarded-Host $upstream_host;',
            'proxy_set_header X-Forwarded-Proto $upstream_forwarded_proto;',
            'proxy_set_header X-Real-IP $remote_addr;',
            'proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;',
            'proxy_set_header Cookie $http_cookie;',
            'proxy_set_header X-Request-Id $upstream_request_id;',
            'proxy_set_header Upgrade $http_upgrade;',
            'proxy_set_header Connection $connection_upgrade;',
        ] as $header) {
            $this->assertStringContainsString($header, $nginx);
        }
    }

    public function test_runtime_substitution_is_restricted_to_the_backend_url(): void
    {
        $dockerfile = $this->read('Dockerfile.admin');

        $this->assertStringContainsString("envsubst '\$BACKEND_URL'", $dockerfile);
        $this->assertStringNotContainsString('envsubst <', $dockerfile);
        $this->assertStringContainsString("exec nginx -g 'daemon off;'", $dockerfile);
    }

    public function test_compose_exposes_a_separate_healthy_admin_service_without_changing_test_postgres(): void
    {
        $compose = $this->read('docker-compose.yml');

        $this->assertMatchesRegularExpression('/\n  admin:\n/', $compose);
        $this->assertStringContainsString('dockerfile: Dockerfile.admin', $compose);
        $this->assertStringContainsString('${ADMIN_PORT:-8080}:80', $compose);
        $this->assertStringContainsString('BACKEND_URL: ${BACKEND_URL:-http://app}', $compose);
        $this->assertStringContainsString('VITE_OPERATIONAL_URL: ${VITE_OPERATIONAL_URL:-http://localhost:8000}', $compose);
        $this->assertStringContainsString('http://localhost/healthz', $compose);
        $this->assertStringContainsString('"5433:5432"', $compose);
        $this->assertStringContainsString('max_locks_per_transaction=512', $compose);
    }

    public function test_environment_and_sanctum_defaults_document_the_local_and_secure_production_topology(): void
    {
        $environment = $this->read('.env.example');
        $sanctum = $this->read('config/sanctum.php');

        foreach ([
            'ADMIN_URL=http://localhost:8080',
            'VITE_OPERATIONAL_URL=http://localhost:8000',
            'BACKEND_URL=http://app',
            'ADMIN_PORT=8080',
            'localhost:8080',
            '127.0.0.1:8080',
            'SESSION_SECURE_COOKIE=false',
            'Production HTTPS deployments must set SESSION_SECURE_COOKIE=true',
        ] as $setting) {
            $this->assertStringContainsString($setting, $environment);
        }

        $this->assertStringContainsString('localhost:8080', $sanctum);
        $this->assertStringContainsString('127.0.0.1:8080', $sanctum);
    }

    public function test_ci_and_deploy_workflows_block_on_admin_quality_and_image_build(): void
    {
        $ci = $this->read('.github/workflows/ci.yml');
        $deploy = $this->read('.github/workflows/deploy.yml');

        $this->assertStringContainsString('run: npm run admin:quality', $ci);
        $this->assertStringContainsString('run: docker build -f Dockerfile.admin -t erada-admin:ci .', $ci);
        $this->assertStringNotContainsString("continue-on-error: true\n        run: npm run admin:quality", $ci);

        $testJob = strstr($deploy, '  # Job 2:', true);
        $this->assertIsString($testJob);
        $this->assertStringContainsString('run: npm run admin:quality', $testJob);
        $this->assertStringContainsString('run: docker build -f Dockerfile.admin -t erada-admin:deploy-check .', $testJob);
        $this->assertStringNotContainsString('continue-on-error:', $testJob);
    }

    private function read(string $relativePath): string
    {
        $path = base_path($relativePath);

        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        return $contents;
    }
}
