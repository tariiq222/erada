<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminViteDevProxyContractTest extends TestCase
{
    #[Test]
    public function admin_vite_bypasses_only_source_module_extensions_and_proxies_real_api_requests(): void
    {
        $config = file_get_contents(base_path('vite.admin.config.ts'));

        $this->assertIsString($config);
        $this->assertStringContainsString("'/api': {", $config);
        $this->assertStringContainsString("target: 'http://127.0.0.1:8000'", $config);
        $this->assertStringContainsString('bypass(request)', $config);
        $this->assertStringContainsString("return pathname;", $config);
        $this->assertStringContainsString('const viteSourceModulePath = /\\.(?:[cm]?[jt]sx?|map)$/;', $config);
        $this->assertStringNotContainsString('sec-fetch-dest', strtolower($config));
    }
}
