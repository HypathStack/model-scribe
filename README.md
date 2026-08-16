# ModelScribe ✍️

[![Latest Version on Packagist](https://img.shields.io/packagist/v/hypathbel/model-scribe.svg?style=flat-square)](https://packagist.org/packages/hypathbel/model-scribe)
[![Total Downloads](https://img.shields.io/packagist/dt/hypathbel/model-scribe.svg?style=flat-square)](https://packagist.org/packages/hypathbel/model-scribe)
[![License](https://img.shields.io/packagist/l/hypathbel/model-scribe.svg?style=flat-square)](https://packagist.org/packages/hypathbel/model-scribe)

**ModelScribe** is a powerful, driver-based audit log package for Laravel. It allows you to effortlessly record every change in your Eloquent models and route them exactly where they need to go: a database table, a flat file, multiple targets simultaneously (stack), or custom targets like ELK or Webhooks.

Unlike other packages, ModelScribe excels at **multi-table routing**, allowing you to logically separate logs for different business domains into different database tables or even different connections.

---

## ✨ Features

- **Eloquent Integration**: Simple trait-based setup.
- **Multi-Target Drivers**: Support for `database`, `file`, and `stack` (log to multiple places at once).
- **Multi-Table Routing**: Map different models to different log tables or database connections.
- **Deep Diffing**: Records `old` vs `new` attributes automatically.
- **Customizable Retention**: Choose between `permanent`, `days`-based, or `rotating` (keep N records per table).
- **Rich Context**: Automatically captures URL, IP Address, User Agent, and the authenticated "causer".
- **Batching**: Group related operations with a unique `batch_uuid`.
- **Developer Friendly**: Clean API, Facades, and a prune command.

---

## 🚀 Installation

Install the package via composer:

```bash
composer require hypathbel/model-scribe
```

Publish the configuration and migrations:

```bash
php artisan vendor:publish --tag="model-scribe-config"
php artisan vendor:publish --tag="model-scribe-migrations"
```

Run the migrations:

```bash
php artisan migrate
```

---

## ⚙️ Configuration

The `config/model-scribe.php` file allows you to define your drivers and their behavior.

### Database Driver & Multi-Table Stores

You can define multiple "stores" within the database driver. This is perfect for high-traffic apps that want to keep `order` logs and `invoice` logs in separate tables.

```php
'drivers' => [
    'database' => [
        'driver'     => 'database',
        'table'      => 'model_scribe_logs', // Default table
        'stores' => [
            'invoices' => [
                'table'      => 'invoice_logs',
                'connection' => 'audit_db', // Optional: use a different connection
            ],
            'orders' => [
                'table' => 'order_logs',
            ],
        ],
        'retention' => [
            'type' => 'days',
            'days' => 90,
        ],
    ],
],
```

Generate a migration for a new store:
```bash
php artisan model-scribe:make-table invoices
```

---

## 🛠️ Usage

### 1. Basic Auditing

Add the `HasAuditLog` trait to your Eloquent model.

```php
use HypathBel\ModelScribe\Traits\HasAuditLog;

class Product extends Model
{
    use HasAuditLog;
}
```

### 2. Customizing Events and Attributes

By default, ModelScribe logs `created`, `updated`, and `deleted`. You can customize this per model.

```php
class Order extends Model
{
    use HasAuditLog;

    // Only log specific events
    protected array $auditEvents = ['created', 'updated'];

    // Limit which attributes are recorded per event
    protected array $auditAttributes = [
        'updated' => ['status', 'total_price', 'shipping_address'],
    ];

    // Route to a specific store/table
    protected string $auditLogName = 'orders';

    // Add searchable tags to every log entry
    protected array $auditTags = ['warehouse-A', 'priority-high'];
}
```

### 3. Manual Logging

Sometimes you want to log actions that aren't tied to an Eloquent lifecycle.

```php
use HypathBel\ModelScribe\Facades\ModelScribe;
use HypathBel\ModelScribe\Enums\ScribeEvent;

ModelScribe::log(
    event: ScribeEvent::Custom,
    logName: 'system',
    description: 'User initiated a bulk export',
    properties: ['format' => 'csv', 'rows' => 1200],
    tags: ['export']
);
```

---

## 🧹 Maintenance (Pruning)

Keep your log tables lean. ModelScribe includes a prune command that respects the retention policy defined in your config.

```bash
# Prune the default driver
php artisan model-scribe:prune

# Prune a specific driver
php artisan model-scribe:prune --driver=file
```

---

## 🧪 Testing

```bash
composer test
```

---

## 📜 License

The MIT License (MIT). See [License File](LICENSE.md) for more information.
