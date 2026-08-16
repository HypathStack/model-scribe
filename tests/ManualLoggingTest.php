<?php

use HypathBel\ModelScribe\Enums\ScribeEvent;
use HypathBel\ModelScribe\Facades\ModelScribe;
use HypathBel\ModelScribe\Models\ScribeLog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    if (! Schema::hasTable('system_scribe_logs')) {
        Schema::create('system_scribe_logs', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->default('default');
            $table->string('event');
            $table->text('description')->nullable();
            $table->nullableMorphs('causer');
            $table->nullableMorphs('subject');
            $table->json('properties')->nullable();
            $table->text('url')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();
            $table->index('log_name');
            $table->index('event');
            $table->index('batch_uuid');
        });
    }

    config([
        'model-scribe.drivers.database.stores' => [
            'system' => ['table' => 'system_scribe_logs'],
        ],
    ]);
});

it('manually logs a custom enum event to the routed store', function () {
    ModelScribe::log(
        event: ScribeEvent::Custom,
        logName: 'system',
        description: 'manual entry',
        properties: ['x' => 1],
        tags: ['abc'],
    );

    $log = DB::table('system_scribe_logs')->first();

    expect($log)->not->toBeNull()
        ->and($log->event)->toBe(ScribeEvent::Custom->value)
        ->and($log->log_name)->toBe('system')
        ->and($log->description)->toBe('manual entry')
        ->and(json_decode($log->properties, true))->toBe(['x' => 1])
        ->and(json_decode($log->tags, true))->toBe(['abc'])
        ->and($log->batch_uuid)->not->toBeNull();
});

it('falls back to the custom event for arbitrary event strings', function () {
    ModelScribe::log(event: 'password_changed', logName: 'system');

    $log = DB::table('system_scribe_logs')->first();

    expect($log->event)->toBe(ScribeEvent::Custom->value);
});

it('writes unmapped log names to the global default table', function () {
    ModelScribe::log(event: 'export', logName: 'unmatched');

    $log = ScribeLog::first();

    expect($log)->not->toBeNull()
        ->and($log->log_name)->toBe('unmatched')
        ->and($log->event)->toBe(ScribeEvent::Custom->value);
});

it('forwards entries through the stack driver', function () {
    config(['model-scribe.drivers.stack.drivers' => ['database']]);

    ModelScribe::log(event: ScribeEvent::Custom, logName: 'stacked');

    expect(ScribeLog::count())->toBe(1);
});

it('writes through the file driver using the configured channel', function () {
    $path = sys_get_temp_dir().'/model-scribe-file-driver.log';
    @unlink($path);

    config([
        'model-scribe.drivers.file.channel' => 'scribe_test',
        'logging.channels.scribe_test' => [
            'driver' => 'single',
            'path' => $path,
        ],
    ]);

    ModelScribe::log(event: ScribeEvent::Updated, logName: 'filey', description: 'hello-file', driver: 'file');

    expect(file_get_contents($path))->toContain('hello-file');
});
