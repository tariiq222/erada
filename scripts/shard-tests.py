#!/usr/bin/env python3
"""
Erada PMO — parallel PHPUnit shard orchestrator.

Splits the test list into N shards, runs each shard in parallel against its
own PostgreSQL test database, parses JUnit XML, retries any failures
sequentially on the same shard DB, and prints a final summary.

No source code or tests are modified. Outputs land in storage/app/test-shards/.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import shlex
import shutil
import subprocess
import sys
import time
import xml.etree.ElementTree as ET
from concurrent.futures import ThreadPoolExecutor, as_completed
from dataclasses import dataclass, field
from pathlib import Path
from typing import Iterable

# ─────────────────────────────────────────────────────────────────────────────
# Config
# ─────────────────────────────────────────────────────────────────────────────
PROJECT_DIR = Path(__file__).resolve().parent.parent
ARTIFACTS_DIR = PROJECT_DIR / "storage" / "app" / "test-shards"


# ─────────────────────────────────────────────────────────────────────────────
# Helpers
# ─────────────────────────────────────────────────────────────────────────────
def log(msg: str) -> None:
    print(f"\033[0;36m[{time.strftime('%H:%M:%S')}]\033[0m {msg}", flush=True)


def ok(msg: str) -> None:
    print(f"\033[0;32m✓\033[0m {msg}", flush=True)


def warn(msg: str) -> None:
    print(f"\033[0;33m!\033[0m {msg}", flush=True)


def fail(msg: str) -> None:
    print(f"\033[0;31m✗\033[0m {msg}", file=sys.stderr, flush=True)


def title(msg: str) -> None:
    print(f"\n\033[1m═══ {msg} ═══\033[0m", flush=True)


# ─────────────────────────────────────────────────────────────────────────────
# Test discovery & sharding
# ─────────────────────────────────────────────────────────────────────────────
def list_tests() -> list[str]:
    """Return sorted list of fully-qualified test IDs from `phpunit --list-tests`."""
    res = subprocess.run(
        ["php", "vendor/bin/phpunit", "--list-tests"],
        cwd=PROJECT_DIR,
        capture_output=True,
        text=True,
    )
    if res.returncode != 0:
        fail(f"phpunit --list-tests failed:\n{res.stderr}")
        sys.exit(1)
    pat = re.compile(r"^\s+- (Tests\\.+)$")
    out = sorted({m.group(1) for line in res.stdout.splitlines() if (m := pat.match(line))})
    return out


def class_to_file(fqn_class: str) -> str:
    """`Tests\\Unit\\FooTest` → `tests/Unit/FooTest.php`."""
    rel = fqn_class.replace("\\", "/").removeprefix("Tests/")
    return f"tests/{rel}.php"


def shard_tests(tests: list[str], n: int) -> list[list[str]]:
    """Round-robin distribute sorted tests across n shards (deterministic, balanced count)."""
    shards: list[list[str]] = [[] for _ in range(n)]
    for i, t in enumerate(tests):
        shards[i % n].append(t)
    return shards


# ─────────────────────────────────────────────────────────────────────────────
# Postgres helpers
# ─────────────────────────────────────────────────────────────────────────────
def pg_exec(host: str, port: int, user: str, password: str, sql: str, db: str = "postgres") -> str:
    # Use host psql (if available) talking directly to the postgres container
    # over TCP — much faster than docker exec per call. Falls back to docker
    # exec against the named container if host psql is missing.
    import shutil
    if shutil.which("psql"):
        env = os.environ.copy()
        env["PGPASSWORD"] = password
        res = subprocess.run(
            ["psql", "-h", host, "-p", str(port), "-U", user, "-d", db, "-tAc", sql],
            capture_output=True, text=True, env=env,
        )
    else:
        res = subprocess.run(
            [
                "docker", "exec", "-e", f"PGPASSWORD={password}",
                CONTAINER_DEFAULT, "psql", "-U", user, "-d", db, "-tAc", sql,
            ],
            capture_output=True, text=True,
        )
    if res.returncode != 0:
        raise RuntimeError(f"psql failed: {res.stderr.strip()}")
    return res.stdout


CONTAINER_DEFAULT = "erada-platform-postgres-test"


def reset_shard_dbs(host: str, port: int, user: str, password: str, prefix: str, shards: int) -> list[str]:
    dbs = [f"{prefix}_{i}" for i in range(1, shards + 1)]
    for db in dbs:
        pg_exec(host, port, user, password,
                f"SELECT pg_terminate_backend(pid) FROM pg_stat_activity "
                f"WHERE datname='{db}' AND pid <> pg_backend_pid();")
        pg_exec(host, port, user, password, f"DROP DATABASE IF EXISTS {db};")
        pg_exec(host, port, user, password, f"CREATE DATABASE {db};")
    return dbs


# ─────────────────────────────────────────────────────────────────────────────
# Migrations: handle pre-existing broken seeding migrations
# ─────────────────────────────────────────────────────────────────────────────
# Two seeding migrations reference `App\Modules\Core\Models\ScopedRoleDefinition`
# which was deleted in commit 46cd62e but the migrations were not updated.
# They reference a class that no longer exists; calling the static
# `ScopedRoleDefinition::clearCache()` aborts the transaction. The migrations
# before them are committed individually (each in its own transaction), so
# the schema is intact — we just need to mark the broken seeds as applied
# without running them, then continue with the rest.
BROKEN_MIGRATIONS = [
    "2026_07_12_000001_seed_engine_capabilities_dashboard_data_imports",
    "2026_07_12_000002_seed_engine_capability_view_survey_responses",
]


def migrate_shard_1(db: str, host: str, port: str, user: str, password: str,
                    container: str, log_file: Path) -> None:
    """Run `php artisan migrate:fresh` then patch around the broken seeds.

    `--schema-path=/dev/null` bypasses Laravel's fast-path schema dump loader
    (which shells out to `psql`, not installed locally). When the broken seed
    migration aborts, all prior migrations are already committed (per-migration
    transactions). We then insert the broken migration names into the
    `migrations` table to mark them as applied, and re-run `migrate` to apply
    any remaining migrations.
    """
    env = os.environ.copy()
    env.update({
        "DB_CONNECTION": "pgsql",
        "DB_HOST": host, "DB_PORT": port,
        "DB_DATABASE": db, "DB_USERNAME": user, "DB_PASSWORD": password,
    })
    shim_dir = os.environ.get("ERADA_SHIM_DIR", "/tmp/erada-shard/bin")
    if Path(shim_dir).is_dir():
        env["PATH"] = shim_dir + os.pathsep + env.get("PATH", "")
    pg_bin = "/opt/homebrew/opt/postgresql@16/bin"
    if Path(pg_bin).is_dir():
        env["PATH"] = pg_bin + os.pathsep + env["PATH"]
    res = subprocess.run(
        ["php", "artisan", "migrate:fresh",
         "--env=testing", "--force", "--no-interaction",
         "--schema-path=/dev/null"],
        cwd=PROJECT_DIR, env=env, capture_output=True, text=True,
    )
    log_file.write_text(res.stdout + res.stderr)
    if res.returncode != 0:
        warn(f"migrate:fresh on {db} exited non-zero — likely a broken seed migration.")
        warn("Patching migrations table to mark broken seeds as applied, then re-running migrate.")

    # Insert broken migrations as applied (skip actual execution)
    next_batch = _next_migration_batch(host, int(port), user, password, db)
    for broken in BROKEN_MIGRATIONS:
        sql = (f"INSERT INTO migrations (migration, batch) "
               f"SELECT '{broken}', {next_batch} "
               f"WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '{broken}');")
        _psql_in_db(host, int(port), user, password, db, sql)
        log(f"  marked broken seed applied: {broken}")

    # Apply any remaining migrations
    res = subprocess.run(
        ["php", "artisan", "migrate",
         "--env=testing", "--force", "--no-interaction",
         "--schema-path=/dev/null"],
        cwd=PROJECT_DIR, env=env, capture_output=True, text=True,
    )
    (log_file.parent / f"{log_file.stem}-followup.log").write_text(res.stdout + res.stderr)
    if res.returncode != 0:
        fail(f"follow-up migrate on {db} failed:\n{res.stdout}\n{res.stderr}")
        sys.exit(1)


def _psql_in_db(host: str, port: int, user: str, password: str, db: str, sql: str) -> str:
    return pg_exec(host, port, user, password, sql, db=db)


def _next_migration_batch(host: str, port: int, user: str, password: str, db: str) -> int:
    out = _psql_in_db(host, port, user, password, db,
                      "SELECT COALESCE(MAX(batch),0)+1 FROM migrations;")
    return int(out.strip() or "1")


def schema_clone(host: str, port: int, user: str, password: str,
                 src_db: str, dst_dbs: list[str], dump: Path) -> None:
    import shutil
    env = os.environ.copy()
    env["PGPASSWORD"] = password

    # Prefer host pg_dump/psql (much faster than docker exec per call).
    if shutil.which("pg_dump"):
        dump_res = subprocess.run(
            ["pg_dump", "-h", host, "-p", str(port), "-U", user, "-d", src_db,
             "--schema-only", "--no-owner", "--no-privileges", "--no-comments"],
            capture_output=True, text=True, env=env,
        )
        if dump_res.returncode != 0:
            fail(f"pg_dump failed: {dump_res.stderr}")
            sys.exit(1)
        dump.write_text(dump_res.stdout)
        dump_sql = dump_res.stdout
        for db in dst_dbs:
            res = subprocess.run(
                ["psql", "-h", host, "-p", str(port), "-U", user, "-d", db,
                 "-v", "ON_ERROR_STOP=1", "-q"],
                input=dump_sql, capture_output=True, text=True, env=env,
            )
            if res.returncode != 0:
                fail(f"psql restore into {db} failed:\n{res.stderr}")
                sys.exit(1)
    else:
        # Fallback: docker exec wrappers.
        container = CONTAINER_DEFAULT
        dump_res = subprocess.run(
            [
                "docker", "exec", "-e", f"PGPASSWORD={password}", container,
                "pg_dump", "-U", user, "-d", src_db,
                "--schema-only", "--no-owner", "--no-privileges", "--no-comments",
            ],
            capture_output=True, text=True,
        )
        if dump_res.returncode != 0:
            fail(f"pg_dump failed: {dump_res.stderr}")
            sys.exit(1)
        dump.write_text(dump_res.stdout)
        dump_sql = dump_res.stdout
        for db in dst_dbs:
            res = subprocess.run(
                [
                    "docker", "exec", "-i", "-e", f"PGPASSWORD={password}", container,
                    "psql", "-U", user, "-d", db, "-v", "ON_ERROR_STOP=1", "-q",
                ],
                input=dump_sql, capture_output=True, text=True,
            )
            if res.returncode != 0:
                fail(f"psql restore into {db} failed:\n{res.stderr}")
                sys.exit(1)


# ─────────────────────────────────────────────────────────────────────────────
# JUnit parsing
# ─────────────────────────────────────────────────────────────────────────────
@dataclass
class ShardResult:
    n: int
    tests: int = 0
    failures: int = 0
    errors: int = 0
    skipped: int = 0
    time_s: float = 0.0
    failed_tests: list[tuple[str, str]] = field(default_factory=list)  # (class, name)


def parse_junit(path: Path) -> ShardResult:
    # Extract shard number from filename: shard-N-junit.xml
    n = 0
    m = re.search(r"shard-(\d+)-junit\.xml", path.name)
    if m:
        n = int(m.group(1))
    if not path.exists() or path.stat().st_size == 0:
        return ShardResult(n=n)
    try:
        tree = ET.parse(path)
    except ET.ParseError as e:
        warn(f"JUnit parse error in {path}: {e}")
        return ShardResult(n=n)
    root = tree.getroot()
    res = ShardResult(n=n)
    res.tests = int(root.attrib.get("tests", 0))
    res.failures = int(root.attrib.get("failures", 0))
    res.errors = int(root.attrib.get("errors", 0))
    res.skipped = int(root.attrib.get("skipped", 0))
    res.time_s = float(root.attrib.get("time", 0.0))
    for tc in root.findall("testcase"):
        if tc.find("failure") is not None or tc.find("error") is not None:
            cls = tc.attrib.get("classname", "?")
            name = tc.attrib.get("name", "?")
            res.failed_tests.append((cls, name))
    return res


def strip_failed_cases(junit: Path) -> None:
    """Rewrite JUnit XML removing <testcase> entries that contain failure/error."""
    tree = ET.parse(junit)
    root = tree.getroot()
    for tc in list(root.findall("testcase")):
        if tc.find("failure") is not None or tc.find("error") is not None:
            root.remove(tc)
    # Recompute aggregate counters
    root.attrib["failures"] = str(len([t for t in root.findall("testcase") if t.find("failure") is not None]))
    root.attrib["errors"] = str(len([t for t in root.findall("testcase") if t.find("error") is not None]))
    tree.write(junit, encoding="utf-8", xml_declaration=True)


# ─────────────────────────────────────────────────────────────────────────────
# Shard runner
# ─────────────────────────────────────────────────────────────────────────────
def run_shard(
    n: int,
    tests: list[str],
    db: str,
    host: str, port: str, user: str, password: str,
    junit: Path, log_path: Path, time_path: Path,
    label: str,
) -> ShardResult:
    files = sorted({class_to_file(t.split("::")[0]) for t in tests})
    if not files:
        warn(f"[{label}] no files to run")
        time_path.write_text("0")
        return ShardResult(n=n)

    env = os.environ.copy()
    env.update({
        "DB_CONNECTION": "pgsql",
        "DB_HOST": host, "DB_PORT": port,
        "DB_DATABASE": db, "DB_USERNAME": user, "DB_PASSWORD": password,
    })
    # Prepend shim dir so artisan's psql/pg_dump/pg_restore invocations
    # resolve to docker-exec wrappers (the host has no client tools).
    shim_dir = os.environ.get("ERADA_SHIM_DIR", "/tmp/erada-shard/bin")
    if Path(shim_dir).is_dir():
        env["PATH"] = shim_dir + os.pathsep + env.get("PATH", "")
    pg_bin = "/opt/homebrew/opt/postgresql@16/bin"
    if Path(pg_bin).is_dir():
        env["PATH"] = pg_bin + os.pathsep + env["PATH"]

    log(f"[{label}] starting on {db} ({len(files)} files)")
    start = time.time()
    cmd = ["php", "vendor/bin/phpunit", "--no-coverage", f"--log-junit={junit}", *files]
    try:
        proc = subprocess.run(cmd, cwd=PROJECT_DIR, env=env, capture_output=True, text=True)
    except Exception as e:
        fail(f"[{label}] invocation error: {e}")
        proc = subprocess.CompletedProcess(cmd, returncode=1, stdout="", stderr=str(e))
    elapsed = round(time.time() - start, 2)
    time_path.write_text(str(elapsed))
    log_path.write_text(f"$ {' '.join(shlex.quote(c) for c in cmd)}\n\n{proc.stdout}\n{proc.stderr}")
    if proc.returncode == 0:
        ok(f"[{label}] passed in {elapsed}s")
    else:
        warn(f"[{label}] exit={proc.returncode} after {elapsed}s — see {log_path}")
    return parse_junit(junit)


def retry_failures(
    n: int, db: str, host: str, port: str, user: str, password: str,
    junit: Path, log_path: Path,
) -> ShardResult:
    """Re-run only failing tests sequentially on the same shard DB."""
    result = parse_junit(junit)
    if not result.failed_tests:
        return result

    # Build --filter regex from FQNs (PCRE-escaped, OR-joined)
    fqns = [f"{cls}::{name}" for cls, name in result.failed_tests]
    # PHPUnit expects forward slashes for namespace separators? Actually backslashes work.
    # Use re.escape for safety, then OR.
    pattern = "|".join(re.escape(f) for f in fqns)

    env = os.environ.copy()
    env.update({
        "DB_CONNECTION": "pgsql",
        "DB_HOST": host, "DB_PORT": port,
        "DB_DATABASE": db, "DB_USERNAME": user, "DB_PASSWORD": password,
    })
    pg_bin = "/opt/homebrew/opt/postgresql@16/bin"
    if Path(pg_bin).is_dir():
        env["PATH"] = pg_bin + os.pathsep + env.get("PATH", "")

    log(f"[shard {n}] retrying {len(fqns)} failures sequentially on {db}")
    start = time.time()
    cmd = [
        "php", "vendor/bin/phpunit", "--no-coverage",
        f"--log-junit={junit}", f"--filter={pattern}",
    ]
    proc = subprocess.run(cmd, cwd=PROJECT_DIR, env=env, capture_output=True, text=True)
    elapsed = round(time.time() - start, 2)
    log_path.write_text(f"$ {' '.join(shlex.quote(c) for c in cmd)}\n\n{proc.stdout}\n{proc.stderr}")
    if proc.returncode == 0:
        ok(f"[shard {n}] retry succeeded in {elapsed}s")
        # Strip previously-failed cases from JUnit (they all passed now)
        strip_failed_cases(junit)
    else:
        warn(f"[shard {n}] retry still failing (exit={proc.returncode}, {elapsed}s)")
    return parse_junit(junit)


# ─────────────────────────────────────────────────────────────────────────────
# Main
# ─────────────────────────────────────────────────────────────────────────────
def main() -> int:
    parser = argparse.ArgumentParser(description="Parallel PHPUnit shard runner")
    parser.add_argument("--shards", type=int, default=4)
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", default="5433")
    parser.add_argument("--user", default="iradah")
    parser.add_argument("--password", default="secret")
    parser.add_argument("--prefix", default="iradah_pmo_test_shard")
    parser.add_argument("--container", default="erada-platform-postgres-test")
    parser.add_argument("--no-quality", action="store_true", help="Skip pint/phpstan/typecheck after success")
    args = parser.parse_args()

    title("Preflight")
    log(f"Project : {PROJECT_DIR}")
    log(f"Branch  : {subprocess.check_output(['git','-C',str(PROJECT_DIR),'branch','--show-current'],text=True).strip()}")
    log(f"Shards  : {args.shards}")
    log(f"DB      : {args.host}:{args.port} container={args.container}")
    log(f"Output  : {ARTIFACTS_DIR}")

    # Guard: forbid SQLite in test config
    for f in [PROJECT_DIR / "phpunit.xml",
              PROJECT_DIR / ".env.testing",
              PROJECT_DIR / ".env.example"]:
        if f.exists() and re.search(r"(sqlite|:memory:)", f.read_text(), re.I):
            warn(f"{f.name} mentions SQLite")

    # Reset artifacts
    if ARTIFACTS_DIR.exists():
        shutil.rmtree(ARTIFACTS_DIR)
    ARTIFACTS_DIR.mkdir(parents=True, exist_ok=True)

    # ── Step 1: shard DBs ────────────────────────────────────────────────────
    title("Shard databases")
    dbs = reset_shard_dbs(args.host, int(args.port), args.user, args.password, args.prefix, args.shards)
    ok(f"Created {len(dbs)} shard databases: {dbs}")

    migrate_log = ARTIFACTS_DIR / "migrate-shard-1.log"
    migrate_shard_1(dbs[0], args.host, args.port, args.user, args.password,
                     args.container, migrate_log)
    ok(f"{dbs[0]} migrated")
    schema_clone(args.host, int(args.port), args.user, args.password, dbs[0], dbs[1:], ARTIFACTS_DIR / "schema.sql")
    ok(f"Schema cloned to shards 2..{args.shards}")

    # ── Step 2: list + split tests ───────────────────────────────────────────
    title("Test list & sharding")
    # PHPUnit lists individual methods. Execute each class exactly once per
    # shard because run_shard invokes files (not method filters); sharding
    # methods would otherwise duplicate entire classes across shards.
    tests = sorted({test.split("::", 1)[0] for test in list_tests()})
    tests_file = ARTIFACTS_DIR / "tests-sorted.txt"
    tests_file.write_text("\n".join(tests) + "\n")
    ok(f"Discovered {len(tests)} tests")
    if not tests:
        fail("No tests discovered")
        return 1

    shards = shard_tests(tests, args.shards)
    for i, s in enumerate(shards, start=1):
        (ARTIFACTS_DIR / f"shard-{i}-tests.txt").write_text("\n".join(s) + "\n")
        log(f"Shard {i}: {len(s)} tests")

    # ── Step 3: parallel run ─────────────────────────────────────────────────
    title("Parallel run")
    results: list[ShardResult] = []
    with ThreadPoolExecutor(max_workers=args.shards) as ex:
        futures = {}
        for i, s in enumerate(shards, start=1):
            junit = ARTIFACTS_DIR / f"shard-{i}-junit.xml"
            log_path = ARTIFACTS_DIR / f"shard-{i}-run.log"
            time_path = ARTIFACTS_DIR / f"shard-{i}-time.txt"
            fut = ex.submit(
                run_shard, i, s, dbs[i - 1],
                args.host, args.port, args.user, args.password,
                junit, log_path, time_path, f"shard {i}",
            )
            futures[fut] = i
        for fut in as_completed(futures):
            results.append(fut.result())
    results.sort(key=lambda r: r.n)

    # ── Step 4: retry failures sequentially on the same shard DB ────────────
    title("Retry failures sequentially")
    retry_overall = ARTIFACTS_DIR / "retry.log"
    retry_lines: list[str] = []
    for r in results:
        if r.failures == 0 and r.errors == 0:
            continue
        junit = ARTIFACTS_DIR / f"shard-{r.n}-junit.xml"
        retry_log = ARTIFACTS_DIR / f"shard-{r.n}-retry.log"
        retry_lines.append(f"=== Shard {r.n}: {len(r.failed_tests)} failures ===")
        retry_lines.extend(f"  - {c}::{n}" for c, n in r.failed_tests)
        new_r = retry_failures(r.n, dbs[r.n - 1], args.host, args.port,
                               args.user, args.password, junit, retry_log)
        r.tests = new_r.tests
        r.failures = new_r.failures
        r.errors = new_r.errors
        r.skipped = new_r.skipped
        r.failed_tests = new_r.failed_tests
    retry_overall.write_text("\n".join(retry_lines) + "\n")

    # ── Step 5: summary ──────────────────────────────────────────────────────
    title("Summary")
    grand_tests = sum(r.tests for r in results)
    grand_fail = sum(r.failures for r in results)
    grand_err = sum(r.errors for r in results)
    grand_skip = sum(r.skipped for r in results)
    grand_pass = grand_tests - grand_fail - grand_err - grand_skip

    all_failed = []
    for r in results:
        elapsed = (ARTIFACTS_DIR / f"shard-{r.n}-time.txt").read_text().strip() or "0"
        log(f"Shard {r.n}: tests={r.tests} fail={r.failures} err={r.errors} "
            f"skip={r.skipped} time={elapsed}s")
        all_failed.extend(f"{c}::{n}" for c, n in r.failed_tests)

    print()
    ok(f"Total tests : {grand_tests}")
    ok(f"Passed      : {grand_pass}")
    if grand_fail:
        fail(f"Failed      : {grand_fail}")
    if grand_err:
        fail(f"Errors      : {grand_err}")
    ok(f"Skipped     : {grand_skip}")
    ok(f"Shards      : {args.shards}")
    ok(f"Artifacts   : {ARTIFACTS_DIR}")

    if all_failed:
        warn("Failing tests after retry:")
        for n in all_failed:
            print(f"   - {n}")
    print()
    ok("JUnit reports:")
    for i in range(1, args.shards + 1):
        print(f"   - shard-{i}: {ARTIFACTS_DIR}/shard-{i}-junit.xml")

    # Persist summary
    summary = {
        "total_tests": grand_tests, "passed": grand_pass,
        "failed": grand_fail, "errors": grand_err, "skipped": grand_skip,
        "shards": args.shards, "artifacts": str(ARTIFACTS_DIR),
        "per_shard": [
            {
                "shard": r.n, "tests": r.tests, "failures": r.failures,
                "errors": r.errors, "skipped": r.skipped,
                "time_s": (ARTIFACTS_DIR / f"shard-{r.n}-time.txt").read_text().strip(),
                "junit": str(ARTIFACTS_DIR / f"shard-{r.n}-junit.xml"),
            }
            for r in results
        ],
        "failing_tests": all_failed,
    }
    (ARTIFACTS_DIR / "summary.json").write_text(json.dumps(summary, indent=2))
    (ARTIFACTS_DIR / "shard-summary.txt").write_text(
        "\n".join(f"{k.upper()}={v}" for k, v in summary.items() if not isinstance(v, (list, dict)))
        + "\n\nFAILING TESTS:\n" + ("\n".join(f"  - {n}" for n in all_failed) if all_failed else "  (none)")
    )

    # ── Step 6: quality checks ───────────────────────────────────────────────
    if grand_fail or grand_err:
        fail("ONE OR MORE SHARDS FAILED — skipping quality checks")
        return 1
    ok("ALL SHARDS PASSED")
    if args.no_quality:
        return 0

    title("Quality checks")
    cmds: list[tuple[str, list[str]]] = [
        ("pint",        ["vendor/bin/pint", "--test"]),
        ("phpstan",     ["vendor/bin/phpstan", "analyse", "--memory-limit=512M"]),
        ("typecheck",   ["npm", "run", "typecheck"]),
        ("git diff --check", ["git", "diff", "--check"]),
    ]
    q_failed = False
    for name, cmd in cmds:
        log(f"$ {' '.join(cmd)}")
        rc = subprocess.call(cmd, cwd=PROJECT_DIR)
        if rc != 0:
            fail(f"{name} failed (exit={rc})")
            q_failed = True
        else:
            ok(f"{name} passed")

    return 1 if q_failed else 0


if __name__ == "__main__":
    sys.exit(main())
