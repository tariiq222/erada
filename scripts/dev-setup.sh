#!/bin/bash
# ═══════════════════════════════════════════════════════════════
# Script: dev-setup.sh
# Purpose: Setup development environment with PostgreSQL
# ═══════════════════════════════════════════════════════════════

set -e

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo "🚀 Setting up development environment..."

# ═══════════════════════════════════════════════════════════════
# Step 1: Start PostgreSQL containers
# ═══════════════════════════════════════════════════════════════
echo ""
echo "📦 Starting PostgreSQL containers..."
docker compose up -d postgres postgres-test redis

# ═══════════════════════════════════════════════════════════════
# Step 2: Wait for PostgreSQL to be ready
# ═══════════════════════════════════════════════════════════════
echo ""
echo "⏳ Waiting for PostgreSQL to be ready..."
until docker compose exec -T postgres pg_isready -U iradah -d iradah_pmo > /dev/null 2>&1; do
    echo -n "."
    sleep 1
done
echo -e " ${GREEN}Ready!${NC}"

# ═══════════════════════════════════════════════════════════════
# Step 3: Copy .env if not exists
# ═══════════════════════════════════════════════════════════════
if [ ! -f .env ]; then
    echo ""
    echo "📝 Creating .env file..."
    cp .env.example .env
    php artisan key:generate
fi

# ═══════════════════════════════════════════════════════════════
# Step 4: Run migrations
# ═══════════════════════════════════════════════════════════════
echo ""
echo "🗃️  Running migrations..."
php artisan migrate --force

# ═══════════════════════════════════════════════════════════════
# Step 5: Seed database (optional)
# ═══════════════════════════════════════════════════════════════
read -p "Do you want to seed the database? (y/N) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo "🌱 Seeding database..."
    php artisan db:seed
fi

# ═══════════════════════════════════════════════════════════════
# Step 6: Reset demo account passwords to 'password'
# ═══════════════════════════════════════════════════════════════
echo ""
echo "🔑 Resetting demo account passwords..."
php artisan users:reset-demo-passwords || true

# ═══════════════════════════════════════════════════════════════
# Done!
# ═══════════════════════════════════════════════════════════════
echo ""
echo -e "${GREEN}✅ Development environment is ready!${NC}"
echo ""
echo "Available commands:"
echo "  php artisan serve     - Start Laravel dev server"
echo "  npm run dev           - Start Vite dev server"
echo "  composer dev          - Start all dev services"
echo ""
echo "Database connection:"
echo "  Host: 127.0.0.1"
echo "  Port: 5432"
echo "  Database: iradah_pmo"
echo "  Username: iradah"
echo "  Password: secret"
