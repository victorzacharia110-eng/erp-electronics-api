<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

# erp-electronics-api

ERP API for an electronics retail business. Laravel REST API with Sanctum auth, branch-scoped employees, inventory, orders, commissions, and a double-entry accounting module.

## Accounting module

All money movements are posted as double-entry journal entries. Every posting runs inside a DB transaction and **fails loudly** when required system accounts or cost prices are missing, instead of silently producing unbalanced books.

### System accounts

Seeded per owner via `Database\Seeders\AccountingSeeder` (and `2026_07_31_000004_seed_accounting_settings_and_system_accounts`):

| Code | Name | Notes |
|------|------|-------|
| 1020 | M-Pesa Account | Cash received / refunds |
| 1200 | Inventory | Stock value |
| 2500 | VAT Output | VAT collected (TRA) |
| 3010 | Owner's Capital | Opening stock journal (optional) |
| 3020 | Retained Earnings | Year-end close |
| 4010 | Sales Revenue | Net of VAT |
| 4020 | Shipping Revenue | Optional |
| 5010 | Cost of Goods Sold | Requires cost price on every item |
| 5100 | Inventory Adjustments | Write-offs / damage |
| 5110 | Commission Expense | Payouts / clawback |

### Settings

- `vat_rate` (decimal, default `18`) – VAT percentage.
- `prices_include_vat` (boolean, default `true`) – when true, sales prices include VAT and gross amounts are split as `net = gross * 100 / (100 + rate)`.
- `income_tax_rate` (decimal, default `30`) – reserved for tax estimates.

### Postings (App\Services\AccountingEntryService)

- `postSale` / `reverseSale` – revenue + VAT + COGS + shipping for paid/cancelled orders.
- `postReturn` – partial returns: refund, revenue/VAT/COGS reversal, inventory restock, commission adjustment.
- `postInventoryAdjustment` – journaled stock changes (adjustment/damage/opening).
- `createCommission` / `reverseCommissions` / `adjustCommissionForReturn` – commission accrual, clawback for cancelled or returned orders.
- `closeYear` – closes income/expense accounts to `3020 Retained Earnings`.

### Visibility rules

- **Employees** (`GET /api/accounting-issues`): actionable, branch-scoped counts only (unconfirmed payments, pending deliveries, missing cost prices, draft/voided entries, pending commissions, unbalanced trial balance, low stock). **No** P&L, balance sheet, equity, margins, or AI suggestions.
- **Owners** (`/api/accounts`, `/api/journal-entries`, `/api/reports/*`, `/api/reports/ai-suggestions`): full access, wrapped in `owner` middleware.

### Commands

- `php artisan accounting:generate-reports --year=2026 --month=7 --with-suggestions` – builds accounting reports and optionally refreshes AI suggestions.
- `php artisan accounting:close-year --year=2026` – year-end close to retained earnings.
- `php artisan subscription:deactivate-expired` – automatically deactivates owners whose trial/subscription has expired (sets `is_active=false`, status `expired`, `deactivation_reason='trial_expired'`). Reactivate or extend a trial from the superadmin dashboard (Owner Management → "Extend trial (30 days)") or via `POST /api/superadmin/owners/{id}/extend-trial`.

Scheduled in `routes/console.php` (monthly report generation with suggestions, yearly close, hourly subscription expiry check).

### Scheduler (cron)

The Laravel scheduler drives the scheduled tasks above, including the **hourly** `subscription:deactivate-expired` auto-deactivation. Add a single cron entry on the server (required for automatic trial expiry):

```cron
* * * * * cd /path/to/erp-electronics-api && php artisan schedule:run >> /dev/null 2>&1
```

Without this cron entry, scheduled commands never run — trigger the subscription check manually with `php artisan subscription:deactivate-expired`.

### API endpoints (auth: bearer token)

| Method | Endpoint | Access |
|--------|----------|--------|
| POST | `/api/orders/{orderId}/return` | employee/owner |
| GET | `/api/accounting-issues` | employee/owner |
| GET | `/api/accounts`, `/api/journal-entries`, `/api/reports/*` | owner |
| POST | `/api/reports/ai-suggestions` | owner |

## Rate limiting

Named limiters are registered in `AppServiceProvider` and enabled via `throttleApi()` in `bootstrap/app.php`:

| Limiter | Limit | Applied to |
|---------|-------|------------|
| `api` | 120/min (by user id, else IP) | all `/api/*` routes |
| `login` | 5/min (by IP) | `POST /auth/login` |
| `register` | 3/min and 10/day (by IP) | `POST /auth/register` |

Exceeding a limit returns HTTP 429 with `X-RateLimit-*` headers. Covered by `tests/Feature/RateLimitingTest.php`. Run the suite with `php artisan test`.

## Employee registration

`POST /api/employees` (owner only) registers an employee with the following required data:

- `name`, `email` (unique), `phone` (required).
- Identification — provide **either** `nida_number` **or** `voting_id_number` (at least one).
- `guarantors[]` — the Wadhamini form. At least one guarantor (`full_name`, `phone`, `relationship` required, `address` optional).
- `attachments[]` + parallel `document_types[]` (`contract`, `background_check`, `other`) — contracts and background-check documents. One multi-file field, PDF/JPG/PNG/DOC/DOCX, up to 20MB each.

Supporting endpoints (owner only):

| Method | Endpoint |
|--------|----------|
| GET/POST | `/api/employees/{user}/documents` |
| DELETE | `/api/employees/{user}/documents/{document}` |
| GET | `/api/employees/{user}/documents/{document}/download` |

### File storage (local + Laravel Cloud)

Files are stored through Laravel's filesystem abstraction on the **default disk** (`FILESYSTEM_DISK`):

- **Local development**: `FILESYSTEM_DISK=local` — files land in `storage/app/employee-documents`.
- **Hosted on Laravel Cloud**: provision a storage bucket; Laravel Cloud injects the `AWS_BUCKET`/`AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`/`AWS_DEFAULT_REGION` variables. Set `FILESYSTEM_DISK=s3` (Cloud does this automatically for buckets) and files are stored on the cloud bucket the same way (see the existing `s3` disk in `config/filesystems.php`).

No code changes are needed between environments — downloads stream through `/api/employees/{user}/documents/{document}/download`, so file URLs never leak backend paths.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
