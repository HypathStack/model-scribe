<?php

namespace HypathBel\ModelScribe\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void log(\HypathBel\ModelScribe\Enums\ScribeEvent|string $event, string $logName = 'default', ?string $description = null, ?\Illuminate\Database\Eloquent\Model $subject = null, ?\Illuminate\Database\Eloquent\Model $causer = null, array $properties = [], array $tags = [], ?string $driver = null)
 * @method static int prune(?string $driver = null)
 * @method static \HypathBel\ModelScribe\DriverManager getDriverManager()
 *
 * @see \HypathBel\ModelScribe\ModelScribe
 */
class ModelScribe extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \HypathBel\ModelScribe\ModelScribe::class;
    }
}
