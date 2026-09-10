# JPopular — Backend API

Laravel 12 REST API for the JPopular business management system (electric scooters, batteries, and related products).

Architecture and business decisions live in `ARCHITECTURE-V1.md` (one level up, outside this repo). Treat that document as the source of truth.

**Current state:** Authentication + Admin/Manager RBAC, and Catalog (categories,
brands, products). Inventory, invoicing, customers and payments are later stages.

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

## Seeding

Development demo data and production seeding are **structurally** separated —
not merely by convention.

```
DatabaseSeeder
├─ RolePermissionSeeder      always      roles + the 37 permissions
└─ DevelopmentSeeder         local only  ← refuses to run elsewhere
   ├─ DemoUserSeeder                     demo admin + manager logins
   └─ DemoCatalogSeeder                  3 categories, 3 brands, 8 products
```

Two independent guards: `DatabaseSeeder` only calls `DevelopmentSeeder` when
`app()->environment()` is `local`, `testing`, or `development`, **and**
`DevelopmentSeeder` (plus each demo seeder) throws if invoked directly outside
one. Forgetting is not enough to seed demo data into production.

### Local development

```bash
php artisan migrate:fresh --seed
php artisan storage:link        # once, for product images on the public disk
```

Demo logins (**local only, weak on purpose**):

| Role | Email | Password |
|---|---|---|
| Admin | `admin@jpopular.in` | `Admin@1234` |
| Manager | `manager@jpopular.in` | `Manager@1234` |

These are fixtures, like a factory default. They deliberately fail the API's own
password policy, which is why the demo seeder writes them through the model
rather than the validated API path — and why they can never reach production.

Demo products carry obviously fake SKUs (`DEMO-…`), varied GST rates (5/18/28%)
and **zero stock** — opening stock belongs to the Inventory stage.

### Production

Two explicit steps. No user account is ever created automatically.

```bash
# 1. system reference data only (roles + permissions, no users, no demo data)
php artisan db:seed --force

# 2. the first administrator, interactively
php artisan app:create-admin
```

`app:create-admin` asks for name, email, and password (twice, **input hidden**).
It validates the email and enforces the same password policy as the API (min 12
characters, letters and numbers, not present in a public breach corpus), marks
the user active, assigns the Admin role, and revokes any existing tokens. The
password is never printed, never logged, and never accepted as a CLI argument —
the command refuses to run non-interactively so it cannot land in shell history.

If the email already exists the command offers to reset that account's password
and grant it the Admin role, after an explicit confirmation.

**Never commit a real password.** `.env` is git-ignored; `.env.example` holds no
credentials. This repository is public.

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

## Catalog

`categories`, `brands` and `products` follow standard REST under `/api/v1`, with
`DELETE` meaning **archive** (soft delete). Products additionally expose
`PUT /products/{product}/status`.

Two rules worth knowing before touching this code:

- **`current_stock` is not writable through the catalog API.** It is absent from
  the product's `$fillable`, from `ManageProduct::WRITABLE`, and from both
  product Form Requests. Stock is written only by the Inventory domain, inside a
  transaction with the product row locked. Products always start at zero.
- **`purchase_price` is omitted from API responses** for any caller lacking
  `products.view_purchase_price` — the key is absent, not null, so margin never
  crosses the wire. Hiding it in the frontend would not be sufficient.

Product list filters: `search` (name, SKU, model), `category_id`, `brand_id`,
`is_active`, `per_page`. SKUs are unique across active **and** archived products
(case-insensitively), because they appear on historical invoices.

Product images use the local `public` disk. Uploads are validated by real MIME
type (JPEG/PNG/WebP only — **SVG is refused**, it can carry script), capped at
2 MB, and stored under a generated filename, so a client can never influence the
path. Only a relative path is persisted; responses expose a URL.

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

## CI/CD

Two separate GitHub Actions workflows. CI never deploys; deployment never runs
outside `main`.

### Triggers

| Workflow | Runs on | Does |
|---|---|---|
| `.github/workflows/ci.yml` | PRs targeting `develop` or `main`, pushes to `develop` | tests, MySQL migration check, Pint |
| `.github/workflows/deploy.yml` | **pushes to `main`** (plus manual `workflow_dispatch`) | re-verifies, then deploys to the VPS |

Pushes to `develop` and `feature/*`, and pull requests, are validation only —
they can never reach production.

### Branch strategy

```
feature/*  ─PR─▶  develop  ─PR─▶  main  ──▶  automatic production deploy
                  (CI only)              (CI + deploy)
```

### CI details

The test suite runs on SQLite in-memory (see `phpunit.xml`), so CI also migrates
against a real **MySQL 8** service container. That is not redundant: the products
migration adds CHECK constraints on MySQL only, and a SQLite run can never prove
that DDL is valid. CI asserts at least 5 CHECK constraints exist on `products`.

All CI credentials are throwaway values matching the ephemeral service
container. CI references **no secrets at all**.

### Production deployment

Runs only after the `verify` job (tests + migrations + Pint) passes, so a broken
`main` is not deployed. Concurrency group `jpopular-backend-production` with
`cancel-in-progress: false` — a deploy is never interrupted half-way; a second
push queues behind the first.

On the VPS, inside `/var/www/jpopular/jpopular-backend`:

1. `php artisan down` (maintenance mode)
2. `git fetch --prune origin main`, `git checkout main`, `git reset --hard origin/main`
3. `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`
4. `php artisan migrate --force`
5. `php artisan storage:link` (only if missing)
6. `php artisan optimize`
7. `chmod -R ug+rwX storage bootstrap/cache`
8. `php artisan up`
9. health check against `https://api.jpopular.in/api/health` from the runner

**What deployment never does:** create or modify `.env`, regenerate `APP_KEY`,
run `migrate:fresh` / `db:wipe` / any rollback, run development or demo seeders,
run `app:create-admin`, or run `git clean`. `git reset --hard` rewrites tracked
files only, so `.env`, `storage/` contents and uploaded product images survive
untouched.

Maintenance mode is lifted by an `EXIT` trap that preserves the original exit
code, so a failed migration or dependency install both **fails the workflow
visibly** and **leaves the app serving traffic**.

> Because the reset is deterministic, any manual edit to a *tracked* file on the
> server is discarded on the next deploy. That is intentional — the server always
> matches `origin/main`.

### Required GitHub secrets

Set these on the repository (or on a `production` environment, which the deploy
job targets so you can add required reviewers).

| Secret | Required | Purpose |
|---|---|---|
| `VPS_HOST` | yes | server hostname or IP |
| `VPS_USER` | yes | deployment user (e.g. `deploy`) |
| `VPS_SSH_KEY` | yes | **private** key for that user, PEM, full contents |
| `VPS_PORT` | no | SSH port; defaults to `22` |
| `VPS_SSH_KNOWN_HOSTS` | recommended | `ssh-keyscan` output, to pin the host key instead of trusting on first use |

Never store the production `.env`, database credentials, `APP_KEY`, or admin
credentials as secrets — deployment does not need them.

### Production paths

| | |
|---|---|
| Backend | `/var/www/jpopular/jpopular-backend` |
| API URL | `https://api.jpopular.in` |
| Health | `https://api.jpopular.in/api/health` |

### Rollback

Deployment is a plain checkout, not a symlinked release, so rollback is a manual
git operation. **A code rollback is not a database rollback** — reverting
migrations is a separate, deliberate decision and is never automated.

```bash
ssh deploy@<host>
cd /var/www/jpopular/jpopular-backend

git log --oneline -10                  # find the last known-good commit
php artisan down
git reset --hard <good-sha>
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan optimize
php artisan up

curl -fsS https://api.jpopular.in/api/health
```

If the bad deploy included a migration, decide explicitly whether the schema
change is backward compatible. If it is, leave it. If it is not, write a new
forward migration — do not blind-`rollback` a production database.
