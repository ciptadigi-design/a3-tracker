# A3 Tracker Laravel/MySQL target foundation

Laravel 11 API foundation for the Hostinger target. Requires PHP 8.2+, Composer, PDO MySQL and MySQL 8/InnoDB. The app is intentionally unported: Supabase remains the reference DEV backend.

Local setup:

```sh
docker compose -f docker-compose.mysql.yml up -d
cd backend
cp .env.example .env
php artisan key:generate
composer install
php artisan migrate:fresh
php artisan serve
```

API routes are under `/api/v1`: `GET health`, `GET version`, `POST auth/login`, `POST auth/logout`, and authenticated `GET me`. Sanctum is configured for first-party cookie sessions; call the framework CSRF endpoint before login when using a browser SPA. Sessions/cache use database-compatible drivers; no Redis, queue worker, Node runtime, or Supabase connection is required by this target.

Run `php artisan test`. Production deployment uses `composer install --no-dev --optimize-autoloader`, cached config/routes, and controlled forward-only migrations. Never commit `.env`, credentials, dumps, or `vendor/`.
