# Testing ModelScribe

Guide to running the test suite and code-quality tooling for this package
from the terminal.

## Requirements

- **PHP ≥ 8.3** (the package requires `^8.3`)
- **Composer**
- **SQLite** with the `pdo_sqlite` (and optionally `sqlite3`) extension loaded
  — tests run against an in-memory SQLite database.
- **cURL / zlib** extensions (used by the transitive dev tooling; present on
  most standard PHP builds).

### Checking your PHP extensions

```bash
php -m | grep -i -E 'sqlite|pdo_sqlite'
```

If `pdo_sqlite` is missing, tests will fail with errors like
`could not find driver` or `Class "PDO" not found`. Install it, e.g. on Debian/Ubuntu:

```bash
sudo apt install php8.3-sqlite3
```

or enable it in your `php.ini`:

```ini
extension=pdo_sqlite
extension=sqlite3
```

> **Note for this development machine:** SQLite is not installed system-wide
> here. The extensions are loaded on demand from `/tmp/opencode/ext`, so all
> test commands below use the extra `-d` flags. On any machine with SQLite
> installed normally, omit those flags and run `vendor/bin/pest` directly.

## Setup

```bash
composer install
```

This runs `composer run prepare` (via the `post-autoload-dump` hook), which
executes `testbench package:discover` so the test harness can find the package
in its own repository.

## Running the test suite

```bash
# Via composer (recommended)
composer test

# Directly via Pest
vendor/bin/pest
```

On this machine (no system SQLite), use:

```bash
php -d extension_dir=/tmp/opencode/ext -d opcache.enable_cli=0 \
  -d extension=sqlite3.so -d extension=pdo_sqlite.so vendor/bin/pest
```

### Running a subset

```bash
# One file
vendor/bin/pest tests/HasAuditLogTest.php

# One test by name
vendor/bin/pest tests/PruneLogsTest.php --filter="rotating"

# A directory
vendor/bin/pest tests
```

### CI-friendly output

```bash
vendor/bin/pest --ci
```

This enables parallel mode, compact output, and fails the run on the first
failure. The `phpunit.xml.dist` configuration already enables
`failOnRisky`/`failOnWarning` and random execution order, so the suite is
order-independent by design.

## Static analysis (PHPStan)

```bash
composer analyse        # vendor/bin/phpstan analyse --no-progress
```

PHPStan runs at level 5 and also checks `config/` and `database/`. It does
**not** need SQLite. New code must keep this command clean — do not silence
errors with inline ignores or baseline entries.

## Code style (Pint)

```bash
composer format         # vendor/bin/pint — formats the code
vendor/bin/pint --test  # dry run: fails if anything is not formatted
```

## Test layout

| File | Coverage |
|------|----------|
| `tests/HasAuditLogTest.php` | Trait-driven auditing: event filtering, attribute lists (flat and per-event), log-name routing, tags, request context capture |
| `tests/ManualLoggingTest.php` | `ModelScribe::log()` against `database`, `stack` and `file` drivers, custom events, log-name routing |
| `tests/PruneLogsTest.php` | Retention policies (`days`, `rotating`, `permanent`) and the `model-scribe:prune` command |
| `tests/TestCase.php` | Testbench base: in-memory SQLite, package migrations auto-loaded |

## Before you finish

```bash
composer test      # green
composer analyse   # no errors
vendor/bin/pint --test   # formatted
```
