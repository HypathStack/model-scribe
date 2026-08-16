# Changelog

All notable changes to `model-scribe` will be documented in this file.

## v1.0.0-beta - 2026-08-16

This is the first public beta release of **ModelScribe**: a driver-based,
multi-table audit log package for Laravel. Drop a single trait onto your
Eloquent models and every change is captured and routed exactly where you
need it.

### ✨ Features

- **Trait-based Eloquent integration** — add `HasAuditLog` to any model and your
  `created`, `updated`, `deleted`, and `restored` events are audited automatically.
- **Multi-target drivers** — `database`, `file`, and `stack` (fan-out a single
  entry to multiple drivers at the same time). The `DriverManager` is built to
  easily support custom targets such as ELK or webhooks.
- **Multi-table / multi-connection routing** — map log names to different tables
  and database connections, so logs for different business domains stay isolated.
- **Deep diffs** — automatic `old` vs `new` attribute comparison for updated
  records.
- **Event & attribute control** — configure per model which events and which
  attributes to capture (`$auditEvents`, `$auditAttributes`, `$auditTags`,
  `$auditDriver`, `$auditLogName`).
- **Rich request context** — captures URL, IP address, and User-Agent when
  available, plus the authenticated "causer" (`Auth::user()`).
- **Configurable retention** — `permanent`, `days`-based, or `rotating`
  (keep the latest N records per table), prunable via the
  `model-scribe:prune` command.
- **Guard-based routing** — route logs to specific stores based on the active
  authentication guard.

### 🚀 Installation

```bash
composer require hypathbel/model-scribe
php artisan vendor:publish --tag=model-scribe-migrations
php artisan migrate

```
### 📦 Usage

```bash
use HypathBel\ModelScribe\Traits\HasAuditLog;

class Invoice extends Model
{
    use HasAuditLog;

    protected array $auditEvents = ['created', 'updated'];
    protected array $auditTags  = ['billing'];
    protected string $auditLogName = 'invoices';
}

```
Publish the config to adjust drivers, stores, connections, and retention:

```bash
php artisan vendor:publish --tag=model-scribe-config

```
### ⚠️ Beta notice

- Public API may change before the stable 1.x release based on feedback.
- Custom-driver contracts (DriverInterface) are subject to refinement.
- Only database, file, and stack drivers are implemented in this beta.

### 🔒 Security & support

If you discover a security issue, please open a GitHub issue instead of a
public disclosure.

### 📄 License

The MIT License (MIT).
