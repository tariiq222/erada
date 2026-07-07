<?php

declare(strict_types=1);

/**
 * Composer script: check-task-model.
 *
 * Guard: fail (exit 1) if any *.php file under app/, tests/, or database/
 * still references the deprecated App\Modules\Projects\Models\Task FQN.
 *
 * The deprecated Task model was removed in v1.1. Use
 * App\Modules\Tasks\Models\Task instead.
 *
 * This script intentionally mirrors the same rules as
 * Tests\Feature\StaticAnalysisResidualGuardTest::test_deprecated_project_task_model_namespace_is_absent
 * so the script and the PHPUnit guard agree on what is/isn't a violation.
 *
 * - PHP comments are stripped before scanning so documentation-only mentions
 *   of the deprecated FQN in phpdoc/inline comments do not trip the guard.
 * - tests/Feature/StaticAnalysisResidualGuardTest.php is excluded because it
 *   carries the deprecated FQN as the guard's own search needle and would
 *   otherwise self-trigger.
 * - Escaped backslashes ("\\") inside PHP source strings are collapsed to a
 *   single backslash before matching, so the same needle catches both the
 *   raw form and the runtime form ('App\\Modules\\Projects\\Models\\Task').
 */
$projectRoot = dirname(__DIR__);
$roots = ['app', 'tests', 'database'];
$deprecatedFqcn = 'App\\Modules\\Projects\\Models\\Task';
$excludedRelativePaths = [
    'tests/Feature/StaticAnalysisResidualGuardTest.php',
];
$violations = [];

foreach ($roots as $root) {
    $absoluteRoot = $projectRoot.DIRECTORY_SEPARATOR.$root;
    if (! is_dir($absoluteRoot)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absoluteRoot, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $absolutePath = $file->getPathname();
        $relativePath = str_replace(
            $projectRoot.DIRECTORY_SEPARATOR,
            '',
            $absolutePath
        );
        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

        if (in_array($relativePath, $excludedRelativePaths, true)) {
            continue;
        }

        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            continue;
        }

        $tokens = token_get_all($contents);
        $source = '';
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $source .= is_array($token) ? $token[1] : $token;
        }

        $lines = preg_split('/\R/', $source) ?: [];
        foreach ($lines as $lineNumber => $line) {
            $normalized = str_replace('\\\\', '\\', $line);
            if (str_contains($normalized, $deprecatedFqcn)) {
                $violations[] = sprintf(
                    '%s:%d: %s',
                    $relativePath,
                    $lineNumber + 1,
                    $line
                );
            }
        }
    }
}

if ($violations !== []) {
    foreach ($violations as $violation) {
        echo $violation.PHP_EOL;
    }
    fwrite(STDERR, sprintf(
        'ERROR: %d reference(s) to deprecated App\\Modules\\Projects\\Models\\Task found. Use App\\Modules\\Tasks\\Models\\Task instead.'.PHP_EOL,
        count($violations)
    ));
    exit(1);
}

echo 'OK: No deprecated Task model references found.'.PHP_EOL;
exit(0);
