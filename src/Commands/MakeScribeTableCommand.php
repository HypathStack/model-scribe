<?php

namespace HypathBel\ModelScribe\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeScribeTableCommand extends Command
{
    public $signature = 'model-scribe:make-table
                            {name : The store name (e.g. invoices, orders)}
                            {--table= : Override the table name (default: {name}_scribe_logs)}';

    public $description = 'Create a migration for an additional ModelScribe log table (store).';

    public function handle(Filesystem $files): int
    {
        $name = Str::snake($this->argument('name'));
        $table = $this->option('table') ?: "{$name}_scribe_logs";

        $stubPath = __DIR__.'/../../database/migrations/create_model_scribe_logs_table.php';

        if (! $files->exists($stubPath)) {
            $this->components->error("Migration stub not found at [{$stubPath}].");

            return self::FAILURE;
        }

        $stub = $files->get($stubPath);

        // Replace the table name in the stub — the store migration uses a
        // hard-coded table name instead of reading it from config.
        $stub = str_replace(
            "config('model-scribe.drivers.database.table', 'model_scribe_logs')",
            "'{$table}'",
            $stub
        );

        $filename = date('Y_m_d_His')."_create_{$table}_table.php";
        $targetDir = database_path('migrations');
        $target = "{$targetDir}/{$filename}";

        $files->put($target, $stub);

        $this->components->info("Migration created: [{$target}]");
        $this->components->warn(
            "Remember to add the store to your config/model-scribe.php:\n".
            "  'stores' => [\n".
            "      '{$name}' => ['table' => '{$table}'],\n".
            '  ],'
        );

        return self::SUCCESS;
    }
}
