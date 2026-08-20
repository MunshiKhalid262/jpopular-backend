# JPopular — Backend API

Laravel 12 REST API for the JPopular business management system (electric scooters, batteries, and related products).

Architecture and business decisions live in `ARCHITECTURE-V1.md` (one level up, outside this repo). Treat that document as the source of truth.

**Current state:** Authentication + Admin/Manager RBAC. No business modules yet.

## Requirements

- PHP 8.4
- Composer 2
- MySQL 8

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Then set the database block in `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=jpopular
DB_USERNAME=your_local_user
DB_PASSWORD=your_local_password
```

Create the schema and seed roles, permissions, and the first Admin:

```bash
php artisan migrate --seed
```

Serve the API:

```bash
composer serve      # php artisan serve --host=0.0.0.0 --port=8000
```

> `composer serve` binds `0.0.0.0` deliberately. Plain `php artisan serve` binds
> `127.0.0.1`, which is unreachable from a Windows browser when Laravel runs
> inside WSL2 in NAT mode.

## Staff account seeding (local *and* production)

`StaffUserSeeder` provisions the Admin and Manager accounts. Credentials come
from the environment only — never from a tracked file.

| Variable | Purpose |
|---|---|
| `SEED_ADMIN_NAME` / `SEED_MANAGER_NAME` | Display names |
| `SEED_ADMIN_EMAIL` / `SEED_MANAGER_EMAIL` | Login emails (default `admin@jpopular.in`, `manager@jpopular.in`) |
| `SEED_ADMIN_PASSWORD` / `SEED_MANAGER_PASSWORD` | Passwords. **Leave blank and a strong one is generated and printed once.** |

```bash
php artisan db:seed                                  # roles, permissions, staff
php artisan db:seed --class=StaffUserSeeder          # staff only
```

**Safe to run on every deploy.** The seeder is idempotent:

- a missing account is created;
- an existing account is restored/reactivated and its role re-synced, but its
  **password is never overwritten** — a redeploy cannot reset a password an
  operator has since changed;
- a password that fails the API's own policy (min 12 characters, letters and
  numbers, not present in a public breach corpus) is **refused with an error**
  rather than creating an account that could not later change its own password;
- a blank password produces a random 24-character one, printed once — so a
  forgotten variable can never yield a predictable credential.

### Production

Set the four `SEED_*` variables in the production environment (or leave the
passwords blank and capture the generated ones from the deploy log), then run
`php artisan db:seed --force`.

**Never commit a real password.** `.env` is git-ignored; `.env.example` holds
blank placeholders only. This repository is public.

## Authentication

Token-based Sanctum auth, consumed by the Next.js frontend through its
server-side BFF. The frontend stores the token in an httpOnly cookie, so it
never reaches browser JavaScript. See `ARCHITECTURE-V1.md` §3.4.

| Method | Endpoint | Notes |
|---|---|---|
| POST | `/api/v1/auth/login` | Public. Throttled 5/min per IP **and** per email. Returns the token once. |
| POST | `/api/v1/auth/logout` | Revokes the calling token only. |
| GET | `/api/v1/auth/me` | Identity, roles, effective permissions. |
| PUT | `/api/v1/auth/password` | Revokes other sessions, keeps the current one. |

Login is refused for invalid credentials, inactive accounts, and soft-deleted
accounts — all with an identical message, so the endpoint cannot be used to
discover which accounts exist.

`SANCTUM_TOKEN_EXPIRATION` (minutes, default `720`) controls token lifetime.

## Authorization

Roles are only collections of permissions. **Always authorize against a
permission**, never a role name:

```php
$user->can(PermissionName::UsersManage->value);   // yes
$user->hasRole('admin');                          // no
```

`App\Enums\PermissionName` is the single source of truth for the 37 V1
permissions; `RolePermissionSeeder` syncs the database to it. Adding a third
role therefore needs no controller or policy changes.

Permissions the matrix marks *grantable* (`products.create`, `products.update`,
`products.view_purchase_price`, `inventory.purchase`) are **not** part of the
Manager role — grant them to an individual manager instead.

## API conventions

Success:

```json
{ "success": true, "data": {}, "meta": { "pagination": {} } }
```

Error:

```json
{ "success": false, "message": "...", "errors": {}, "code": "MACHINE_CODE" }
```

Status codes: `401` unauthenticated, `403` no permission, `404` not found,
`409` business-rule conflict (carries a `code` such as `LAST_ACTIVE_ADMIN`),
`422` validation, `429` throttled.

## Tests

```bash
php artisan test          # SQLite in-memory, no MySQL needed
./vendor/bin/pint         # formatting
```

## Layout

```
app/
├─ Domain/Identity/       Actions + AdminGuard (business rules live here)
├─ Enums/                 PermissionName, RoleName
├─ Http/
│  ├─ Controllers/Api/V1/ thin: validate -> Action -> Resource
│  ├─ Requests/           validation
│  ├─ Resources/          explicit response field lists
│  └─ Middleware/         ForceJsonResponse, EnsureUserIsActive
├─ Models/                Eloquent models
├─ Policies/              authorization
└─ Support/ApiResponse    the response envelope
routes/api/               one file per module
```
