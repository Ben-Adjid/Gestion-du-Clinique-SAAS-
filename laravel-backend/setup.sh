#!/bin/bash

echo "🚀 Setting up ClinicPlatform Backend..."

# 1. Install dependencies
echo "📦 Installing PHP dependencies..."
composer install --no-interaction

# 2. Copy env file
echo "🔧 Setting up environment..."
cp .env.example .env
php artisan key:generate

# 3. Wait for database
echo "⏳ Waiting for database to be ready..."
sleep 5

# 4. Run migrations
echo "🗄️ Running database migrations..."
php artisan migrate:fresh --force

# 5. Seed database
echo "🌱 Seeding database with test data..."
php artisan db:seed --class=RoleSeeder --force
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan db:seed --class=ClinicSeeder --force
php artisan db:seed --class=ClinicUsersSeeder --force

# 6. Create storage links
echo "🔗 Creating storage links..."
php artisan storage:link 2>/dev/null || true

echo ""
echo "✅ Setup complete!"
echo ""
echo "📋 Test Credentials:"
echo "Email: admin.clinic1@example.com"
echo "Password: Password@12345"
echo ""
echo "🔗 API: http://localhost:8000"
echo "📱 Frontend: http://localhost:3000"
