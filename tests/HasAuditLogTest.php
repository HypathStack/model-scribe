<?php

use HypathBel\ModelScribe\Enums\ScribeEvent;
use HypathBel\ModelScribe\Models\ScribeLog;
use HypathBel\ModelScribe\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

// ── Inline stub models ──────────────────────────────────────────────────────

class Order extends Model
{
    use HasAuditLog;

    protected $table = 'orders';

    protected $guarded = [];

    public $timestamps = true;
}

class Invoice extends Model
{
    use HasAuditLog;

    protected $table = 'invoices';

    protected $guarded = [];

    public $timestamps = true;

    protected array $auditEvents = ['created', 'updated'];

    protected array $auditAttributes = [
        'updated' => ['amount', 'status'],
    ];

    protected string $auditLogName = 'invoices_log';

    protected array $auditTags = ['billing'];
}

class TotalOnly extends Model
{
    use HasAuditLog;

    protected $table = 'orders';

    protected $guarded = [];

    public $timestamps = true;

    protected array $auditAttributes = ['total'];
}

// ── Helpers ─────────────────────────────────────────────────────────────────

function createOrdersTable(): void
{
    if (! Schema::hasTable('orders')) {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('pending');
            $table->decimal('total', 10, 2)->default(0);
            $table->timestamps();
        });
    }
}

function createInvoicesTable(): void
{
    if (! Schema::hasTable('invoices')) {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('status')->default('draft');
            $table->string('reference')->nullable();
            $table->timestamps();
        });
    }
}

// ── Tests ───────────────────────────────────────────────────────────────────

beforeEach(function () {
    createOrdersTable();
    createInvoicesTable();
});

it('logs a created event when a model is saved', function () {
    $order = Order::create(['status' => 'pending', 'total' => 100]);

    $log = ScribeLog::first();

    expect($log)->not->toBeNull()
        ->and($log->event)->toBe(ScribeEvent::Created->value)
        ->and($log->subject_type)->toBe(Order::class)
        ->and((int) $log->subject_id)->toBe((int) $order->id)
        ->and($log->properties['attributes']['status'])->toBe('pending');
});

it('logs an updated event with old and new values', function () {
    $order = Order::create(['status' => 'pending', 'total' => 100]);
    ScribeLog::query()->delete(); // Reset — only interested in the update

    $order->update(['status' => 'shipped', 'total' => 150]);

    $log = ScribeLog::first();

    expect($log)->not->toBeNull()
        ->and($log->event)->toBe(ScribeEvent::Updated->value)
        ->and($log->properties['old']['status'])->toBe('pending')
        ->and($log->properties['attributes']['status'])->toBe('shipped');
});

it('logs a deleted event', function () {
    $order = Order::create(['status' => 'pending', 'total' => 50]);
    ScribeLog::query()->delete(); // Reset

    $order->delete();

    $log = ScribeLog::first();

    expect($log)->not->toBeNull()
        ->and($log->event)->toBe(ScribeEvent::Deleted->value)
        ->and($log->properties['old']['status'])->toBe('pending')
        ->and($log->properties['attributes'])->toBe([]);
});

it('does not log events that are not in $auditEvents', function () {
    // Invoice only listens to created and updated — not deleted
    $invoice = Invoice::create(['amount' => 500, 'status' => 'draft']);
    ScribeLog::query()->delete(); // Reset

    $invoice->delete();

    expect(ScribeLog::count())->toBe(0);
});

it('respects per-event $auditAttributes filtering', function () {
    $invoice = Invoice::create(['amount' => 500, 'status' => 'draft', 'reference' => 'REF-001']);
    ScribeLog::query()->delete(); // Reset

    $invoice->update(['amount' => 750, 'status' => 'sent', 'reference' => 'REF-002']);

    $log = ScribeLog::first();

    // 'reference' is NOT in the auditable attributes for 'updated'
    expect($log)->not->toBeNull()
        ->and(array_key_exists('amount', $log->properties['attributes']))->toBeTrue()
        ->and(array_key_exists('status', $log->properties['attributes']))->toBeTrue()
        ->and(array_key_exists('reference', $log->properties['attributes']))->toBeFalse();
});

it('applies a flat attribute list to every event', function () {
    $order = TotalOnly::create(['status' => 'new', 'total' => 42]);

    $log = ScribeLog::first();

    expect($log)->not->toBeNull()
        ->and(array_key_exists('total', $log->properties['attributes']))->toBeTrue()
        ->and(array_key_exists('status', $log->properties['attributes']))->toBeFalse();
});

it('routes to the correct log_name', function () {
    Invoice::create(['amount' => 100, 'status' => 'draft']);

    $log = ScribeLog::first();

    expect($log->log_name)->toBe('invoices_log');
});

it('stores audit tags', function () {
    Invoice::create(['amount' => 100, 'status' => 'draft']);

    $log = ScribeLog::first();

    expect($log->tags)->toBe(['billing']);
});

it('captures request context when a request is bound', function () {
    config(['model-scribe.capture_request_context' => true]);
    app()->instance('request', Request::create('/invoices/1', 'GET'));

    Order::create(['status' => 'new', 'total' => 10]);

    $log = ScribeLog::first();

    expect($log)->not->toBeNull()
        ->and($log->url)->toContain('/invoices/1')
        ->and($log->ip_address)->toBe('127.0.0.1');
});

it('skips request context when capture is disabled', function () {
    config(['model-scribe.capture_request_context' => false]);

    Order::create(['status' => 'new', 'total' => 10]);

    $log = ScribeLog::first();

    expect($log)->not->toBeNull()
        ->and(array_key_exists('url', $log->getAttributes()))->toBeTrue()
        ->and($log->url)->toBeNull()
        ->and($log->ip_address)->toBeNull()
        ->and($log->user_agent)->toBeNull();
});
