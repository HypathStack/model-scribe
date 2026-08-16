<?php

namespace HypathBel\ModelScribe\Traits;

use HypathBel\ModelScribe\Observers\ModelScribeObserver;

/**
 * Drop this trait into any Eloquent model to enable audit logging.
 *
 * ── Minimal usage ──────────────────────────────────────────────────────────
 *
 *   class Invoice extends Model
 *   {
 *       use HasAuditLog;
 *   }
 *
 * ── Full customisation ─────────────────────────────────────────────────────
 *
 *   class Invoice extends Model
 *   {
 *       use HasAuditLog;
 *
 *       // Only log these events
 *       protected array $auditEvents = ['created', 'updated'];
 *
 *       // Per-event attribute lists (null / '*' = all)
 *       protected array $auditAttributes = [
 *           'created' => ['amount', 'status', 'user_id'],
 *           'updated' => ['amount', 'status'],
 *       ];
 *
 *       // Route to a different driver
 *       protected ?string $auditDriver = 'file';
 *
 *       // Route to a specific table / log name
 *       protected string $auditLogName = 'invoices';
 *
 *       // Extra tags stored alongside each entry
 *       protected array $auditTags = ['billing', 'finance'];
 *   }
 *
 * @property array<string> $auditEvents Eloquent events to capture.
 * @property array|string $auditAttributes Attributes to capture, keyed by event.
 * @property string|null $auditDriver Driver name override (null = default).
 * @property string $auditLogName Log name / table routing key.
 * @property array<string> $auditTags Extra tags stored with each entry.
 */
trait HasAuditLog
{
    // ── Boot ─────────────────────────────────────────────────────────────────

    public static function bootHasAuditLog(): void
    {
        static::whenBooted(function (): void {
            static::observe(ModelScribeObserver::class);
        });
    }

    // ── Accessors for the observer ───────────────────────────────────────────

    public function getAuditEvents(): array
    {
        return $this->auditEvents ?? ['created', 'updated', 'deleted'];
    }

    public function getAuditDriver(): ?string
    {
        return $this->auditDriver ?? null;
    }

    public function getAuditLogName(): string
    {
        return $this->auditLogName ?? 'default';
    }

    public function getAuditTags(): array
    {
        return $this->auditTags ?? [];
    }

    /**
     * Returns the list of attributes to capture for a given event,
     * or null to capture all attributes.
     *
     * @return string[]|null
     */
    public function getAuditableAttributes(string $event): ?array
    {
        $auditAttributes = $this->auditAttributes ?? [];

        if (empty($auditAttributes)) {
            return null; // log everything
        }

        // If it's a flat list (not keyed by event name), apply to all events
        if (isset($auditAttributes[0])) {
            return $auditAttributes === ['*'] ? null : $auditAttributes;
        }

        $perEvent = $auditAttributes[$event] ?? '*';

        return $perEvent === '*' ? null : (array) $perEvent;
    }
}
