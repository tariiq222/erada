#!/bin/bash
# ═══════════════════════════════════════════════════════════════
# Script: test-setup.sh
# Purpose: Setup and run tests with PostgreSQL
# ═══════════════════════════════════════════════════════════════

set -e

GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'

echo "🧪 Setting up test environment..."

# ═══════════════════════════════════════════════════════════════
# Step 1: Ensure test database container is running
# ═══════════════════════════════════════════════════════════════
echo ""
echo "📦 Starting test database..."
docker compose up -d postgres-test

# ═══════════════════════════════════════════════════════════════
# Step 2: Wait for PostgreSQL test instance
# ═══════════════════════════════════════════════════════════════
echo "⏳ Waiting for test database..."
RETRIES=30
until docker compose exec -T postgres-test pg_isready -U iradah -d iradah_pmo_test > /dev/null 2>&1 || [ $RETRIES -eq 0 ]; do
    echo -n "."
    sleep 1
    RETRIES=$((RETRIES - 1))
done

if [ $RETRIES -eq 0 ]; then
    echo -e " ${RED}FAILED${NC}"
    echo "❌ Test database not ready after 30 seconds"
    exit 1
fi
echo -e " ${GREEN}Ready!${NC}"

# ═══════════════════════════════════════════════════════════════
# Step 3: Run migrations on test database
# ═══════════════════════════════════════════════════════════════
echo ""
echo "🗃️  Running migrations on test database..."
echo "🔌 Terminating active test database sessions..."
POSTGRES_TEST_CONTAINER=$(docker compose ps -q postgres-test)
if [ -n "$POSTGRES_TEST_CONTAINER" ]; then
    docker exec -i "$POSTGRES_TEST_CONTAINER" psql -U iradah -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='iradah_pmo_test' AND pid <> pg_backend_pid();" || true
    sleep 2
else
    echo "⚠️  postgres-test container not found; skipping session termination"
fi
php artisan migrate:fresh --env=testing --force

# ═══════════════════════════════════════════════════════════════
# Step 4: Run tests
# ═══════════════════════════════════════════════════════════════
echo ""
echo "🧪 Running tests..."
php artisan test "$@"
