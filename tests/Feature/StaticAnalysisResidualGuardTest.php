<?php

namespace Tests\Feature;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class StaticAnalysisResidualGuardTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    /**
     * Explicit guard allowlist:
     * - Authorized API attachment download URLs are allowed; public storage URL helpers are not.
     * - 2FA `pending_token`, validation rule keys named `token`, and HttpOnly cookie assignment are allowed.
     * - Token model internals may keep transient `plainTextToken`; controller JSON responses may not serialize it.
     * - Existing `scripts/check-no-sqlite.sh` prose and this guard file are excluded from SQLite scans.
     * - Historic migration/config SQLite compatibility definitions are explicitly named below so this CI guard
     *   protects v1.2 regressions without breaking on pre-existing Laravel skeleton/legacy migration code.
     */
    private const IGNORED_DIRECTORIES = [
        '.git',
        '.planning',
        '.playwright-mcp',
        'bootstrap/cache',
        'node_modules',
        'storage',
        'vendor',
    ];

    private const PUBLIC_ATTACHMENT_PATTERNS = [
        '/Storage::url\s*\(/',
        '/asset\s*\(\s*[\'\"](?:storage|\/storage)/',
        '/url\s*\(\s*[\'\"]\/storage/',
        '/[\'\"]\/storage\//',
        '/temporaryUrl\s*\(/',
        '/Storage::disk\s*\(\s*[\'\"]public[\'\"]\s*\)\s*->\s*url\s*\(/',
    ];

    private const ATTACHMENT_OUTPUT_FILES = [
        'app/Modules/Shared/Http/Controllers/AttachmentController.php',
        'app/Modules/Shared/Http/Controllers/CommentController.php',
        'app/Modules/Shared/Http/Resources/AttachmentResource.php',
    ];

    private const TOKEN_OUTPUT_FILES = [
        'app/Modules/Core/Http/Controllers/AuthController.php',
        'app/Modules/Core/Http/Controllers/TwoFactorController.php',
    ];

    private const RAW_ELOQUENT_CONTROLLER_FILES = [
        'app/Modules/Core/Http/Controllers/AuthController.php',
        'app/Modules/Core/Http/Controllers/UserController.php',
        'app/Modules/OVR/Http/Controllers/IncidentReportController.php',
        'app/Modules/OVR/Http/Controllers/IncidentTypeController.php',
        'app/Modules/OVR/Http/Controllers/ReportCommentController.php',
        'app/Modules/Shared/Http/Controllers/AttachmentController.php',
        'app/Modules/Shared/Http/Controllers/CommentController.php',
        'app/Modules/Surveys/Http/Controllers/DataImportController.php',
    ];

    private const RAW_ELOQUENT_ALLOWED_LINES = [
        'app/Modules/OVR/Http/Controllers/IncidentReportController.php' => [
            'return $report;', // Route model binding callback returns a model internally; not a JSON response body.
        ],
        'app/Modules/Shared/Http/Controllers/CommentController.php' => [
            'return response()->json($comments);', // Sanitized comment array with authorized attachment download URLs.
        ],
        'app/Modules/Core/Http/Controllers/UserController.php' => [
            'return $user;', // Pagination collection transform callback, not a controller response.
            'return response()->json($users);', // Pre-existing scoped user index response; not part of Phase 11 fixes.
        ],
    ];

    private const SQLITE_LEGACY_ALLOWED_PATHS = [
        'config/database.php', // Laravel connection definition exists but pgsql remains the default.
        'database/migrations/2025_12_29_200000_add_performance_indexes.php',
        'database/migrations/2026_01_20_143244_add_performance_indexes.php',
        'database/migrations/2026_01_20_150539_remove_duplicate_indexes.php',
        'database/migrations/2026_01_20_152502_add_foreign_keys_to_projects_table.php',
    ];

    public function test_attachment_outputs_do_not_expose_public_storage_urls(): void
    {
        $violations = [];

        foreach (self::ATTACHMENT_OUTPUT_FILES as $relativePath) {
            $contents = $this->sourceWithoutPhpComments($relativePath);

            foreach (self::PUBLIC_ATTACHMENT_PATTERNS as $pattern) {
                if (preg_match($pattern, $contents)) {
                    $violations[] = "{$relativePath} matches {$pattern}";
                }
            }

            if ($relativePath !== 'app/Modules/Shared/Http/Controllers/AttachmentController.php') {
                $this->assertStringContainsString('/api/attachments/', $contents, "{$relativePath} should expose only authorized API download URLs.");
            }
        }

        $this->assertSame([], $violations, 'Public attachment URL helpers are forbidden: '.implode('; ', $violations));
    }

    public function test_auth_setup_and_invitation_responses_do_not_return_raw_tokens(): void
    {
        $violations = [];

        foreach (self::TOKEN_OUTPUT_FILES as $relativePath) {
            $contents = $this->sourceWithoutPhpComments($relativePath);
            $lines = preg_split('/\R/', $contents) ?: [];

            foreach ($lines as $lineNumber => $line) {
                $trimmed = trim($line);

                if ($this->isAllowedTokenLine($trimmed)) {
                    continue;
                }

                if (preg_match('/[\'\"]token[\'\"]\s*=>\s*\$(?:token|plainToken|plainTextToken|setupToken|invitationToken)\b/', $trimmed)
                    || preg_match('/[\'\"]token[\'\"]\s*=>\s*\$[A-Za-z_][A-Za-z0-9_]*->(?:token|plainTextToken)\b/', $trimmed)
                    || preg_match('/[\'\"]token[\'\"]\s*=>\s*Str::uuid\s*\(/', $trimmed)
                    || (str_contains($trimmed, 'plainTextToken') && str_contains($trimmed, 'response()->json'))) {
                    $violations[] = $relativePath.':'.($lineNumber + 1).' '.$trimmed;
                }
            }
        }

        $this->assertSame([], $violations, 'Raw token values must not be returned in JSON bodies: '.implode('; ', $violations));
    }

    public function test_deprecated_project_task_model_namespace_is_absent(): void
    {
        $violations = [];

        foreach ($this->phpFilesUnder(['app', 'tests', 'database']) as $relativePath) {
            if ($relativePath === 'tests/Feature/StaticAnalysisResidualGuardTest.php') {
                continue;
            }

            $haystack = str_replace('\\\\', '\\', $this->sourceWithoutPhpComments($relativePath));
            if (str_contains($haystack, 'App\\Modules\\Projects\\Models\\Task')) {
                $violations[] = $relativePath;
            }
        }

        $this->assertSame([], $violations, 'Use App\\Modules\\Tasks\\Models\\Task instead of deprecated Projects model: '.implode(', ', $violations));
    }

    public function test_postgresql_only_configuration_has_no_sqlite_shortcuts_or_raw_sqlite_patterns(): void
    {
        $violations = [];
        $scannedPaths = array_merge(
            $this->filesUnder(['config', 'tests', 'scripts'], ['php', 'xml', 'sh', 'env', 'example']),
            $this->phpFilesUnder(['database/migrations'])
        );

        foreach ($scannedPaths as $relativePath) {
            if ($this->isAllowedSqliteScanPath($relativePath)) {
                continue;
            }

            $contents = str_ends_with($relativePath, '.php')
                ? $this->sourceWithoutPhpComments($relativePath)
                : file_get_contents(base_path($relativePath));

            if ($contents === false) {
                continue;
            }

            foreach ($this->sqlitePatternsFor($relativePath) as $pattern) {
                if (preg_match($pattern, $contents)) {
                    $violations[] = "{$relativePath} matches {$pattern}";
                }
            }
        }

        $this->assertSame([], $violations, 'SQLite shortcuts/raw patterns are forbidden in active test/config/runtime paths: '.implode('; ', $violations));
    }

    public function test_ovr_incident_index_omits_patient_pii_from_summary_items(): void
    {
        $organization = Organization::factory()->create();
        $department = Department::factory()->create(['organization_id' => $organization->id]);
        $incidentType = IncidentType::create([
            'name' => 'Medication Error',
            'name_ar' => 'خطأ دوائي',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'department_id' => $department->id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability(
            $user,
            Capability::OVR_VIEW,
            'all',
            null,
            'super_admin',
            ['is_admin_role' => true, 'is_system' => true],
        );

        $report = IncidentReport::create([
            'organization_id' => $organization->id,
            'reporter_id' => $user->id,
            'reporter_name' => $user->name,
            'reporter_email' => 'reporter-pii@example.test',
            'reporter_department_id' => $department->id,
            'incident_datetime' => now(),
            'is_patient_related' => true,
            'patient_name' => 'Sensitive Patient',
            'patient_file_number' => 'PF-STATIC-001',
            'patient_gender' => 'female',
            'patient_dob' => '1985-03-12',
            'informed_authority' => false,
            'incident_type_id' => $incidentType->id,
            'incident_description' => 'Summary item may describe the incident without patient identifiers.',
            'actions_taken' => 'Initial action',
            'contributing_factors' => ['privacy'],
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::High,
            'status' => ReportStatus::Draft,
            'is_confidential' => false,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/ovr/incidents');

        $response->assertOk()
            ->assertJsonPath('data.0.report_number', $report->report_number)
            ->assertJsonMissingPath('data.0.incident_description')
            ->assertJsonMissingPath('data.0.patient_name')
            ->assertJsonMissingPath('data.0.patient_file_number')
            ->assertJsonMissingPath('data.0.patient_gender')
            ->assertJsonMissingPath('data.0.patient_dob')
            ->assertJsonMissingPath('data.0.reporter_email');
    }

    public function test_sensitive_controllers_do_not_return_raw_eloquent_responses(): void
    {
        $violations = [];

        foreach (self::RAW_ELOQUENT_CONTROLLER_FILES as $relativePath) {
            $contents = $this->sourceWithoutPhpComments($relativePath);
            $lines = preg_split('/\R/', $contents) ?: [];

            foreach ($lines as $lineNumber => $line) {
                $trimmed = trim($line);

                if ($this->isAllowedRawEloquentLine($relativePath, $trimmed)) {
                    continue;
                }

                if (preg_match('/return\s+response\(\)->json\(\s*\$(?:model|collection|query|user|users|project|projects|task|tasks|report|reports|invitation|invitations|comment|comments|attachment|attachments|import|imports)\s*[,\)]/', $trimmed)
                    || preg_match('/return\s+\$(?:model|collection|query|user|users|project|projects|task|tasks|report|reports|invitation|invitations|comment|comments|attachment|attachments|import|imports)\s*;/', $trimmed)
                    || preg_match('/return\s+\$[A-Za-z_][A-Za-z0-9_]*\s*->\s*paginate\s*\(/', $trimmed)) {
                    $violations[] = $relativePath.':'.($lineNumber + 1).' '.$trimmed;
                }
            }
        }

        $this->assertSame([], $violations, 'Sensitive controllers must use explicit resources or arrays, not raw Eloquent responses: '.implode('; ', $violations));
    }

    /**
     * @param  list<string>  $roots
     * @return list<string>
     */
    private function phpFilesUnder(array $roots): array
    {
        return $this->filesUnder($roots, ['php']);
    }

    /**
     * @param  list<string>  $roots
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function filesUnder(array $roots, array $extensions): array
    {
        $files = [];

        foreach ($roots as $root) {
            $absoluteRoot = base_path($root);
            if (! is_dir($absoluteRoot)) {
                continue;
            }

            // CATCH_GET_CHILD: skip subdirectories that vanish mid-scan (transient
            // framework/cache temp dirs created and removed by other tests during a
            // full-suite run) instead of throwing UnexpectedValueException. Scanning
            // real source files is unaffected; only ephemeral dirs are skipped.
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

                if ($this->isIgnoredPath($relativePath)) {
                    continue;
                }

                $extension = strtolower($file->getExtension());
                if ($extension === '' && str_contains($file->getFilename(), '.env')) {
                    $extension = 'env';
                }

                if (in_array($extension, $extensions, true)) {
                    $files[] = $relativePath;
                }
            }
        }

        sort($files);

        return $files;
    }

    private function sourceWithoutPhpComments(string $relativePath): string
    {
        $contents = file_get_contents(base_path($relativePath));
        $this->assertIsString($contents, "Unable to read {$relativePath}");

        $tokens = token_get_all($contents);
        $source = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $source .= $token[1];
            } else {
                $source .= $token;
            }
        }

        return $source;
    }

    private function isIgnoredPath(string $relativePath): bool
    {
        foreach (self::IGNORED_DIRECTORIES as $directory) {
            if ($relativePath === $directory || str_starts_with($relativePath, $directory.'/')) {
                return true;
            }
        }

        return false;
    }

    private function isAllowedTokenLine(string $line): bool
    {
        return $line === ''
            || str_contains($line, "'pending_token' =>")
            || str_contains($line, '"pending_token" =>')
            || preg_match('/[\'\"]token[\'\"]\s*=>\s*[\'\"]required\|string/', $line) === 1
            || preg_match('/\$token\s*=\s*\$user->createToken\([^)]*\)->plainTextToken;/', $line) === 1
            || str_contains($line, "'auth_token'")
            || str_contains($line, '"auth_token"')
            || str_contains($line, '$token,')
            || str_contains($line, '$invitation->getAcceptUrl()')
            || str_contains($line, "'url' => config('app.url').'/surveys/invitation/'.")
            || str_contains($line, "'token' => Str::uuid()->toString(),");
    }

    private function isAllowedSqliteScanPath(string $relativePath): bool
    {
        return $relativePath === 'tests/Feature/StaticAnalysisResidualGuardTest.php'
            || $relativePath === 'scripts/check-no-sqlite.sh'
            || in_array($relativePath, self::SQLITE_LEGACY_ALLOWED_PATHS, true);
    }

    /**
     * @return list<string>
     */
    private function sqlitePatternsFor(string $relativePath): array
    {
        if (str_starts_with($relativePath, 'database/migrations/')) {
            return [
                '/sqlite_master/i',
                '/sqlite_\w+\s*\(/i',
            ];
        }

        return [
            '/DB_CONNECTION\s*=\s*sqlite/i',
            '/:memory:/i',
            '/env\s*\(\s*[\'\"]DB_CONNECTION[\'\"]\s*,\s*[\'\"]sqlite[\'\"]\s*\)/i',
            '/database\.sqlite/i',
            '/[\'\"]driver[\'\"]\s*=>\s*[\'\"]sqlite[\'\"]/i',
        ];
    }

    private function isAllowedRawEloquentLine(string $relativePath, string $line): bool
    {
        return in_array($line, self::RAW_ELOQUENT_ALLOWED_LINES[$relativePath] ?? [], true);
    }
}
