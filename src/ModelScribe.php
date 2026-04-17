<?php

namespace HypathBel\ModelScribe;
// TO DO cambiar a HypathStack\ModelScribe

use HypathBel\ModelScribe\DTOs\LogEntry;
use HypathBel\ModelScribe\Enums\ScribeEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Main class backing the ModelScribe facade.
 * Can also be used directly via dependency injection.
 */
class ModelScribe
{
    public function __construct(protected DriverManager $manager) {}

    /**
     * Manually log an arbitrary event — useful outside of Eloquent observers.
     */
    public function log(
        ScribeEvent|string $event,
        string $logName = 'default',
        ?string $description = null,
        ?Model $subject = null,
        ?Model $causer = null,
        array $properties = [],
        array $tags = [],
        ?string $driver = null,
    ): void {
        if (is_string($event)) {
            $event = ScribeEvent::from($event);
        }

        $entry = new LogEntry(
            event: $event,
            logName: $logName,
            description: $description,
            subject: $subject,
            causer: $causer,
            properties: $properties,
            tags: $tags,
        );

        $this->manager->driver($driver)->log($entry);
    }

    /**
     * Prune stale records from all configured drivers.
     */
    public function prune(?string $driver = null): int
    {
        return $this->manager->driver($driver)->prune();
    }

    /**
     * Access the underlying driver manager to register custom drivers.
     */
    public function getDriverManager(): DriverManager
    {
        return $this->manager;
    }
}
