<?php

namespace HypathBel\ModelScribe\Observers;

use HypathBel\ModelScribe\DriverManager;
use HypathBel\ModelScribe\DTOs\LogEntry;
use HypathBel\ModelScribe\Enums\ScribeEvent;
use HypathBel\ModelScribe\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ModelScribeObserver
{
    public function __construct(protected DriverManager $manager) {}

    // ── Eloquent hooks ───────────────────────────────────────────────────────

    public function created(Model $model): void
    {
        $this->handle($model, ScribeEvent::Created);
    }

    public function updated(Model $model): void
    {
        $this->handle($model, ScribeEvent::Updated);
    }

    public function deleted(Model $model): void
    {
        $this->handle($model, ScribeEvent::Deleted);
    }

    public function restored(Model $model): void
    {
        $this->handle($model, ScribeEvent::Restored);
    }

    // ── Core ─────────────────────────────────────────────────────────────────

    protected function handle(Model $model, ScribeEvent $event): void
    {
        // Only act on models using the trait
        if (! in_array(HasAuditLog::class, class_uses_recursive($model), true)) {
            return;
        }

        $auditEvents = $model->getAuditEvents();

        if (! in_array($event->value, $auditEvents, true)) {
            return;
        }

        $properties = $this->buildProperties($model, $event);
        $causer = $this->resolveCauser();

        [$url, $ipAddress, $userAgent] = $this->requestContext();

        $entry = new LogEntry(
            event: $event,
            logName: $model->getAuditLogName(),
            description: null,
            subject: $model,
            causer: $causer,
            properties: $properties,
            tags: $model->getAuditTags(),
            url: $url,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );

        $logName = $this->resolveLogName($model);
        $driverName = $model->getAuditDriver();
        $this->manager->driver($driverName)->log($entry->withLogName($logName));
    }

    /**
     * Resolve the current HTTP request context when running inside a request.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    protected function requestContext(): array
    {
        if (! config('model-scribe.capture_request_context', true)) {
            return [null, null, null];
        }

        $request = app()->bound('request') ? app('request') : null;

        if (! $request instanceof Request) {
            return [null, null, null];
        }

        return [$request->fullUrl(), $request->ip(), $request->userAgent()];
    }

    protected function buildProperties(Model $model, ScribeEvent $event): array
    {
        $auditable = $model->getAuditableAttributes($event->value);

        if ($event === ScribeEvent::Updated) {
            $dirty = $model->getDirty();
            $old = [];
            $new = [];

            foreach ($dirty as $key => $newValue) {
                if ($auditable !== null && ! in_array($key, $auditable, true)) {
                    continue;
                }

                $old[$key] = $model->getOriginal($key);
                $new[$key] = $newValue;
            }

            return ['old' => $old, 'attributes' => $new];
        }

        if ($event === ScribeEvent::Deleted) {
            $attrs = $auditable
                ? array_intersect_key($model->getAttributes(), array_flip($auditable))
                : $model->getAttributes();

            return ['old' => $attrs, 'attributes' => []];
        }

        // Created / Restored
        $attrs = $auditable
            ? array_intersect_key($model->getAttributes(), array_flip($auditable))
            : $model->getAttributes();

        return ['old' => [], 'attributes' => $attrs];
    }

    protected function resolveCauser(): ?Model
    {
        $guard = config('model-scribe.auth_guard');

        /** @var Model|null $user */
        $user = $guard ? Auth::guard($guard)->user() : Auth::user();

        return $user instanceof Model ? $user : null;
    }

    protected function resolveLogName(Model $model): string
    {
        $logName = $model->getAuditLogName();

        if ($logName !== 'default') {
            return $logName;
        }

        $guardMapping = config('model-scribe.guard_stores', []);

        foreach ($guardMapping as $guard => $mappedLogName) {
            if (Auth::guard($guard)->check()) {
                return $mappedLogName;
            }
        }

        return $logName;
    }
}
