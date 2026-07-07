<?php

namespace Tests\Unit;

use App\Modules\RiskManagement\Enums\RiskActionStatus;
use App\Modules\RiskManagement\Enums\RiskActionType;
use App\Modules\RiskManagement\Enums\RiskAlertType;
use App\Modules\RiskManagement\Enums\RiskLevel;
use App\Modules\RiskManagement\Enums\RiskResponseType;
use App\Modules\RiskManagement\Enums\RiskStatus;
use App\Modules\RiskManagement\Enums\RiskType;
use PHPUnit\Framework\TestCase;

/**
 * Pins the RiskManagement migration string columns and PHP enums to one
 * canonical set of allowed values. Pure unit test: reads the migration
 * sources as text (no database, no application bootstrap).
 */
class RiskEnumParityTest extends TestCase
{
    /**
     * Canonical allowed-value lists (order-sensitive), per the
     * risk-management plan.
     *
     * @var array<class-string, list<string>>
     */
    private const CANONICAL_VALUES = [
        RiskType::class => ['operational', 'clinical', 'financial', 'technical', 'compliance', 'reputational'],
        RiskStatus::class => ['open', 'treating', 'closed', 'accepted'],
        RiskLevel::class => ['low', 'medium', 'high', 'critical'],
        RiskResponseType::class => ['avoid', 'mitigate', 'transfer', 'accept'],
        RiskActionType::class => ['preventive', 'corrective'],
        RiskActionStatus::class => ['pending', 'in_progress', 'completed', 'blocked', 'cancelled'],
        RiskAlertType::class => ['review_due', 'level_escalated', 'action_overdue'],
    ];

    /**
     * Enum-like columns declared by each RiskManagement migration, mapped to
     * the PHP enum that governs their allowed values.
     *
     * @var array<string, array<string, class-string>>
     */
    private const MIGRATION_ENUM_COLUMNS = [
        '2026_06_09_000001_create_risks_table.php' => [
            'type' => RiskType::class,
            'status' => RiskStatus::class,
            'current_level' => RiskLevel::class,
            'response_type' => RiskResponseType::class,
        ],
        '2026_06_09_000002_create_risk_assessments_table.php' => [
            'level' => RiskLevel::class,
            'residual_level' => RiskLevel::class,
        ],
        '2026_06_09_000003_create_risk_actions_table.php' => [
            'type' => RiskActionType::class,
            'status' => RiskActionStatus::class,
        ],
        '2026_06_09_000004_create_risk_action_updates_table.php' => [
            'status' => RiskActionStatus::class,
        ],
        '2026_06_09_000005_create_risk_status_changes_table.php' => [
            'from_status' => RiskStatus::class,
            'to_status' => RiskStatus::class,
        ],
        '2026_06_09_000006_create_risk_alerts_table.php' => [
            'type' => RiskAlertType::class,
        ],
    ];

    public function test_each_enum_matches_canonical_value_list_exactly(): void
    {
        foreach (self::CANONICAL_VALUES as $enumClass => $expected) {
            $this->assertSame(
                $expected,
                array_column($enumClass::cases(), 'value'),
                "{$enumClass} cases drifted from the canonical allowed-value list."
            );
        }
    }

    public function test_migration_enum_columns_match_php_enums(): void
    {
        foreach (self::MIGRATION_ENUM_COLUMNS as $file => $columns) {
            $path = $this->migrationPath($file);
            $this->assertFileExists($path, "Missing RiskManagement migration: {$file}");

            $source = (string) file_get_contents($path);

            foreach ($columns as $column => $enumClass) {
                $this->assertStringContainsString(
                    "'{$column}'",
                    $source,
                    "Column '{$column}' is not declared in {$file}."
                );

                $enumValues = array_column($enumClass::cases(), 'value');

                // Every mapped column MUST carry an explicit PostgreSQL CHECK
                // constraint (<table>_<column>_check) whose allowed-value list
                // matches the PHP enum exactly, in order.
                $declared = $this->extractCheckConstraintValues($source, $this->tableName($file), $column);

                $this->assertNotNull(
                    $declared,
                    "Column '{$column}' in {$file} is missing its CHECK constraint "
                        ."'{$this->tableName($file)}_{$column}_check' (CHECK ({$column} IN (...)))."
                );

                $this->assertSame(
                    $enumValues,
                    $declared,
                    "CHECK constraint values for '{$column}' in {$file} drifted from {$enumClass}."
                );

                // Any default value on the column must be a valid enum value.
                $default = $this->extractDefaultValue($source, $column);

                if ($default !== null) {
                    $this->assertContains(
                        $default,
                        $enumValues,
                        "Default '{$default}' for '{$column}' in {$file} is not a {$enumClass} value."
                    );
                }
            }
        }
    }

    private function migrationPath(string $file): string
    {
        return dirname(__DIR__, 2).'/database/migrations/risk_management/'.$file;
    }

    /**
     * Derives the table name from a `*_create_<table>_table.php` migration
     * file name.
     */
    private function tableName(string $file): string
    {
        if (! preg_match('/_create_(.+)_table\.php$/', $file, $match)) {
            $this->fail("Cannot derive table name from migration file name: {$file}");
        }

        return $match[1];
    }

    /**
     * Extracts the allowed-value string list pinned by the column's explicit
     * `<table>_<column>_check CHECK (<column> IN (...))` constraint.
     *
     * @return list<string>|null null when the column has no CHECK constraint
     */
    private function extractCheckConstraintValues(string $source, string $table, string $column): ?array
    {
        $quotedTable = preg_quote($table, '/');
        $quotedColumn = preg_quote($column, '/');

        $pattern = "/ADD\\s+CONSTRAINT\\s+{$quotedTable}_{$quotedColumn}_check\\s+"
            ."CHECK\\s*\\(\\s*{$quotedColumn}\\s+IN\\s*\\(([^)]*)\\)\\s*\\)/i";

        if (preg_match($pattern, $source, $match)) {
            return $this->quotedStrings($match[1]);
        }

        return null;
    }

    private function extractDefaultValue(string $source, string $column): ?string
    {
        $quoted = preg_quote($column, '/');

        if (preg_match("/'{$quoted}'[^;]*?->default\\(\\s*'([^']*)'\\s*\\)/", $source, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function quotedStrings(string $fragment): array
    {
        preg_match_all("/'([^']*)'/", $fragment, $matches);

        return $matches[1];
    }
}
