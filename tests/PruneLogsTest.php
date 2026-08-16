<?php

use HypathBel\ModelScribe\Models\ScribeLog;
use HypathBel\ModelScribe\ModelScribe;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('model_scribe_logs')->truncate();
});

it('prunes records older than the configured days', function () {
    // Insert 5 old records (61 days ago) and 2 fresh ones
    $oldDate = now()->subDays(61)->toDateTimeString();
    for ($i = 0; $i < 5; $i++) {
        DB::table('model_scribe_logs')->insert([
            'log_name' => 'default',
            'event' => 'created',
            'created_at' => $oldDate,
            'updated_at' => $oldDate,
        ]);
    }

    $freshDate = now()->toDateTimeString();
    for ($i = 0; $i < 2; $i++) {
        DB::table('model_scribe_logs')->insert([
            'log_name' => 'default',
            'event' => 'updated',
            'created_at' => $freshDate,
            'updated_at' => $freshDate,
        ]);
    }

    config([
        'model-scribe.drivers.database.retention.type' => 'days',
        'model-scribe.drivers.database.retention.days' => 60,
    ]);

    $deleted = app(ModelScribe::class)->prune('database');

    expect($deleted)->toBe(5)
        ->and(ScribeLog::count())->toBe(2);
});

it('keeps only the latest records for the rotating retention', function () {
    for ($i = 0; $i < 520; $i++) {
        DB::table('model_scribe_logs')->insert([
            'log_name' => 'default',
            'event' => 'updated',
            'created_at' => now()->subMinutes(520 - $i),
            'updated_at' => now()->subMinutes(520 - $i),
        ]);
    }

    config([
        'model-scribe.drivers.database.retention.type' => 'rotating',
        'model-scribe.drivers.database.retention.keep' => 500,
    ]);

    $deleted = app(ModelScribe::class)->prune('database');

    expect($deleted)->toBe(20)
        ->and(ScribeLog::count())->toBe(500);
});

it('does not prune anything when retention is permanent', function () {
    $oldDate = now()->subDays(999)->toDateTimeString();
    DB::table('model_scribe_logs')->insert([
        'log_name' => 'default',
        'event' => 'created',
        'created_at' => $oldDate,
        'updated_at' => $oldDate,
    ]);

    config(['model-scribe.drivers.database.retention.type' => 'permanent']);

    $deleted = app(ModelScribe::class)->prune('database');

    expect($deleted)->toBe(0)
        ->and(ScribeLog::count())->toBe(1);
});

it('prune command outputs the number of deleted records', function () {
    config([
        'model-scribe.drivers.database.retention.type' => 'days',
        'model-scribe.drivers.database.retention.days' => 1,
    ]);

    $oldDate = now()->subDays(5)->toDateTimeString();
    DB::table('model_scribe_logs')->insert([
        'log_name' => 'default',
        'event' => 'deleted',
        'created_at' => $oldDate,
        'updated_at' => $oldDate,
    ]);

    $this->artisan('model-scribe:prune')
        ->assertSuccessful()
        ->expectsOutputToContain('1');
});
