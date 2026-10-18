@echo off
setlocal enabledelayedexpansion

echo.
echo 🚀 Setting up ClinicPlatform Backend...
echo.

REM 1. Install dependencies
echo 📦 Installing PHP dependencies...
call composer install --no-interaction
if errorlevel 1 goto error

REM 2. Copy env file
echo 🔧 Setting up environment...
if exist .env (
    echo .env already exists, skipping...
) else (
    copy .env.example .env
)

REM 3. Generate key
php artisan key:generate

REM 4. Wait for database
echo ⏳ Waiting for database to be ready...
timeout /t 5

REM 5. Run migrations
echo 🗄️ Running database migrations...
php artisan migrate:fresh --force
if errorlevel 1 goto error

REM 6. Seed database
echo 🌱 Seeding database with test data...
php artisan db:seed --class=RoleSeeder --force
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan db:seed --class=ClinicSeeder --force
php artisan db:seed --class=ClinicUsersSeeder --force

REM 7. Create storage links
echo 🔗 Creating storage links...
php artisan storage:link 2>nul

echo.
echo ✅ Setup complete!
echo.
echo 📋 Test Credentials:
echo Email: admin.clinic1@example.com
echo Password: Password@12345
echo.
echo 🔗 API: http://localhost:8000
echo 📱 Frontend: http://localhost:3000
echo.

pause
exit /b 0

:error
echo.
echo ❌ Setup failed! Check the errors above.
pause
exit /b 1
