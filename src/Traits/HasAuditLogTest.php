<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ─────────────────────────────────────────
 * TRAIT HasActivityLog
 * ─────────────────────────────────────────
 *
 * Trait usado para monitorizar la actividad del modelo causada por los usuarios autenticados.
 * Agrega logging automático de eventos (created, updated, deleted) a cualquier modelo que lo use, con configuración flexible por modelo.
 */
trait HasActivityLog
{
    // ─────────────────────────────────────────
    // CONFIGURACIÓN POR MODELO (sobreescribir)
    // ─────────────────────────────────────────

    /**
     * Eventos que se loguean automáticamente.
     * Por defecto los pone todos (created, updated, deleted).
     * Sobreescribe en tu modelo si quieres menos eventos.
     */
    protected static function loggableEvents(): array
    {
        return ['created', 'updated', 'deleted'];
    }

    /**
     * Atributos que se trackean en updated.
     *
     * ⚠️ Si está vacío, trackea TODOS.
     */
    protected function loggableAttributes(): array
    {
        return $this->logAttributes ?? [];
    }

    /**
     * Nombre del log (aparece en log_name).
     * Por defecto el nombre del modelo, pero se puede personalizar.
     */
    protected function logNameAttribute(): string
    {
        return isset($this->logName) && $this->logName ? $this->logName : class_basename($this);
    }

    /**
     * Mapa guard => clase ActivityLog.
     * Sobreescribe en cada modelo para personalizar.
     * Aquí definimos la tabla donde guardaremos los logs.
     * TO DO - si a futuro se quisiera, se podría escalar para en vez de devolver la tabla, devolver el driver
     * donde guardar (ELK, file, database, etc) y así no depender solo de tablas. Pero el core aqui es la tabla.
     *
     * Ejemplo:
     *   return [
     *       'admin' => AdminActivityLog::class,
     *       'web'   => WebActivityLog::class,
     *   ];
     */
    protected function activityModelsMap(): array
    {
        return $this->activityModels ?? config('activitylog.log_models', []);
    }

    /**
     * Method eventsAttributesMap
     * Si quieres configurar atributos específicos por evento, sobreescribe este método en tu modelo.
     * Servirá para devolver diferentes atributos según el evento (created, updated, deleted).
     */
    protected function eventsAttributesMap(string $event): array
    {
        return match ($event) {
            'created' => $this->loggableWhenCreatedAttributes ?? $this->loggableAttributes(),
            'updated' => $this->loggableWhenUpdatedAttributes ?? $this->loggableAttributes(),
            'deleted' => $this->loggableWhenDeletedAttributes ?? $this->loggableAttributes()
        };
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * NUEVOS MÉTODOS PARA PERSONALIZACIÓN POR GUARD
     * ═══════════════════════════════════════════════════════════
     *
     * Esto será útil cuando necesitemos separar qué se loguea según el punto de entrada
     * (ej: backoffice loguea el fichero de factura pero eina no cuando un internal_payment es creado).
     */

    /**
     * Atributos a loguear según el guard activo (globales para todos los eventos).
     * Sobrescribe en el modelo para lógica por guard.
     *
     * Ejemplo:
     *   return [
     *       'admin' => ['id', 'name', 'email', 'secret_field'],
     *       'web'   => ['id', 'name'],
     *   ];
     */
    protected function getAttributesPerGuard(): array
    {
        return isset($this->loggableAttributesPerGuard) ? $this->loggableAttributesPerGuard : [];
    }

    /**
     * Method getAttributesPerEvent
     * Si quieres configurar atributos específicos por evento, sobreescribe este método en tu modelo.
     * Servirá para devolver diferentes atributos según el evento (created, updated, deleted).
     */
    protected function getAttributesPerEvent(string $event): array
    {
        return match ($event) {
            'created' => $this->loggableWhenCreatedAttributes ?? $this->loggableAttributes(),
            'updated' => $this->loggableWhenUpdatedAttributes ?? $this->loggableAttributes(),
            'deleted' => $this->loggableWhenDeletedAttributes ?? $this->loggableAttributes(),
            default => $this->loggableAttributes(),
        };
    }

    /**
     * Atributos a loguear por guard y por evento específico.
     * Tiene prioridad sobre loggableAttributesPerGuard() y loggableAttributes().
     *
     * Ejemplo:
     *   return [
     *       'created' => [
     *           'admin' => ['id', 'name', 'email'],
     *           'web'   => ['id', 'name'],
     *       ],
     *       'updated' => [
     *           'admin' => ['password', 'role_id'],
     *       ],
     *   ];
     */
    protected function getAttributesEventsMapPerGuard(): array
    {
        return isset($this->eventsAttributesMapPerGuard) ? $this->eventsAttributesMapPerGuard : [];
    }

    // ─────────────────────────────────────────
    // BOOT (registro automático de eventos)
    // ─────────────────────────────────────────

    protected static function bootHasActivityLog(): void
    {
        $events = static::loggableEvents();

        if (in_array('created', $events)) {
            static::created(fn (Model $model) => $model->logActivity('created'));
        }

        if (in_array('updated', $events)) {
            static::updated(function (Model $model) {
                if ($model->hasLoggableChanges('updated')) {
                    $model->logActivity('updated');
                }
            });
        }

        if (in_array('deleted', $events)) {
            static::deleted(fn (Model $model) => $model->logActivity('deleted'));
        }
    }

    // ─────────────────────────────────────────
    // MÉTODO PRINCIPAL
    // ─────────────────────────────────────────

    /**
     * Loguea un evento. Puedes llamarlo manualmente también:
     *   $user->logActivity('password_changed', ['extra' => 'dato']);
     *
     * @param  string  $event  -> evento que loguea
     * @param  array  $extraProperties  -> propiedades customizadas que se quieran añadir
     */
    public function logActivity(string $event, array $extraProperties = []): void
    {
        $guard = $this->resolveActiveGuard();
        $modelClass = $this->resolveActivityModel($guard);

        if (! $modelClass) {
            return; // No hay tabla configurada para este guard, se ignora -> NO LOGUEA
        }

        // Resolvemos user (causer)
        $causer = $this->resolveUser($guard);

        // Resolvemos propiedades que han cambiado
        $properties = array_merge(
            $this->buildProperties($event),
            $extraProperties
        );

        try {

            $modelClass::create([
                'log_name' => $this->logNameAttribute(),
                'description' => $this->buildDescription($event, $guard),
                'subject_type' => static::class,
                'subject_id' => $this->getKey(),
                'causer_type' => $causer ? get_class($causer) : 'system',
                'causer_id' => $causer ? $causer->getKey() : null,
                'event' => $event,
                'properties' => $properties,
                'created_at' => now(),
            ]);

        } catch (Throwable $th) {
            Log::error('Error al loguear actividad: '.$th->getMessage(), [
                'model' => static::class,
                'event' => $event,
                'guard' => $guard,
            ]);
            if (env('APP_ENV') == 'local') {
                throw $th;
            }
        }

    }

    // ─────────────────────────────────────────
    // HELPERS INTERNOS (metodos auxiliares para construir el log, resolver guard, usuario, etc)
    // ─────────────────────────────────────────

    /**
     * Method buildProperties
     * Construye el array de propiedades a guardar en el log según el evento.
     * - Created: snapshot del modelo después de la creación.
     * - Updated: Si only_dirty -> solo los atributos que han cambiado con su valor old y new. Si no, todos los definidos o todos.
     * - Deleted: snapshot del modelo antes de la eliminación.
     */
    private function buildProperties(string $event): array
    {
        return match ($event) {
            'created' => [
                'attributes' => $this->getLoggableSnapshot($event),
            ],
            'updated' => [
                'attributes' => $this->getLoggableChanges($event),
            ],
            'deleted' => [
                'attributes' => $this->getLoggableSnapshot($event),
            ],
            default => [],
        };
    }

    private function buildDescription(string $event, ?string $guard): string
    {
        $model = class_basename($this);
        $actor = $this->resolveUser($guard)?->name ?? 'Sistema';
        $id = $this->getKey();

        return match ($event) {
            'created' => "{$actor} creó {$model} #{$id}",
            'updated' => "{$actor} actualizó {$model} #{$id}",
            'deleted' => "{$actor} eliminó {$model} #{$id}",
            default => "{$actor} realizó '{$event}' en {$model} #{$id}",
        };
    }

    /**
     * Method getLoggableSnapshot
     * Devuelve una foto del estado actual del modelo en db
     * Si hay atributos específicos en loggableAttributes, devuelve solo esos, sino devuelve todo el modelo.
     */
    private function getLoggableSnapshot(?string $event = null): array
    {
        $attrs = $event != null ? $this->eventsAttributesMap($event) : $this->loggableAttributes();

        return empty($attrs)
            ? $this->getAttributes()
            : collect($this->getAttributes())->only($attrs)->all();
    }

    /**
     * Method getLoggableOriginal
     * Devuelve una foto del estado original del modelo antes de la actualización (solo para updated)
     * Si hay atributos específicos en loggableAttributes, devuelve solo esos, sino devuelve todo el modelo.
     */
    private function getLoggableOriginal(): array
    {
        $attrs = $this->loggableAttributes();

        return empty($attrs)
            ? $this->getOriginal()
            : collect($this->getOriginal())->only($attrs)->all();
    }

    /**
     * Method getLoggableChanges
     * Devuelve solo los atributos que han cambiado con su valor old y new.
     */
    private function getLoggableChanges(?string $event = null): array
    {
        $onlySaveDirty = $this->onlySaveDirty ?? config('activitylog.save_only_dirty', true);
        $attrs = $this->resolveLoggableAttributes($event, $this->resolveActiveGuard());
        // $attrs   = $event != null ? $this->eventsAttributesMap($event) : $this->loggableAttributes();

        if ($onlySaveDirty) {
            $dirty = empty($attrs) ? array_keys($this->getDirty()) : $attrs;
        } else {
            $dirty = empty($attrs) ? array_keys($this->getAttributes()) : $attrs;
        }

        $changes = [];

        foreach ($dirty as $attr) {
            if ($onlySaveDirty && $this->isDirty($attr)) {
                $changes[$attr] = [
                    'old' => $this->getOriginal($attr),
                    'new' => $this->getAttribute($attr),
                ];
            }
            // else {
            //     $changes[$attr] = [
            //         'old' => $this->getOriginal($attr),
            //         'new' => $this->getAttribute($attr),
            //     ];
            // }
        }

        return $changes;
    }

    /**
     * Method hasLoggableChanges
     * Determina si el modelo tiene cambios que loguear según la configuración de atributos logueables.
     */
    private function hasLoggableChanges(?string $event = null): bool
    {
        $attrs = $event != null ? $this->eventsAttributesMap($event) : $this->loggableAttributes();

        if (empty($attrs)) {
            return $this->isDirty();
        }

        foreach ($attrs as $attr) {
            if ($this->isDirty($attr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Method resolveLoggableAttributes
     *
     * @param  ?string  $event  [explicite description]
     * @param  ?string  $guard  [explicite description]
     */
    private function resolveLoggableAttributes(?string $event = null, ?string $guard = null): array
    {
        // Prioridad: attributesEventsMapPerGuard > loggableAttributesPerGuard > eventsAttributesMap > loggableAttributes
        if ($event) {
            $mapPerEventAndGuard = $this->getAttributesEventsMapPerGuard();
            if ($mapPerEventAndGuard && isset($mapPerEventAndGuard[$event]) && $guard && isset($mapPerEventAndGuard[$event][$guard])) {
                return $mapPerEventAndGuard[$event][$guard];
            }
        }

        if ($guard) {
            $attrsPerGuard = $this->getAttributesPerGuard();
            if ($attrsPerGuard && isset($attrsPerGuard[$guard])) {
                return $attrsPerGuard[$guard];
            }
        }

        if ($event) {
            return $this->getAttributesPerEvent($event);
        }

        return $this->loggableAttributes();
    }

    /**
     * Resuelve qué clase de ActivityLog usar según el guard activo.
     * Fallback: primera del mapa, o null si el mapa está vacío.
     */
    private function resolveActivityModel(?string $guard): ?string
    {
        if (! $guard) {
            return null;
        }

        $map = $this->activityModelsMap();
        // if (!$guard) return $map['default'] ?? null; //-> para casos de system sin guard (ej: crons) devolvemos el default si existe, sino null

        if (empty($map)) {
            return null;
        }

        // Si hay guard activo y está en el mapa, úsalo
        if ($guard && array_key_exists($guard, $map)) {
            return $map[$guard];
        }

        // Fallback: primera clase del mapa (ej: guard de consola/schedule)
        return array_values($map)[0];
    }

    /**
     * Method resolveActiveGuard
     * Resuelve cuál es el guard activo actualmente. Si no hay ninguno, devuelve null.
     */
    private function resolveActiveGuard(): ?string
    {
        return Auth::getDefaultDriver();
    }

    /**
     * Method resolveUser
     * Resuelve el usuario autenticado del guard dado. Si no hay guard o usuario, devuelve null.
     */
    private function resolveUser(?string $guard): ?Model
    {
        if (! $guard) {
            return null;
        }

        return Auth::guard($guard)->user();
    }

    // ─────────────────────────────────────────
    // RELACIÓN PÚBLICA (leer logs del modelo)
    // ─────────────────────────────────────────

    /**
     * Obtiene todos los logs de este modelo de todas las tablas configuradas.
     */
    // public function activityLogs(): Collection
    // {
    //     $all = collect();

    //     foreach ($this->activityModelsMap() as $modelClass) {
    //         $logs = $modelClass::where('subject_type', static::class)
    //             ->where('subject_id', $this->getKey())
    //             ->orderByDesc('created_at')
    //             ->get();

    //         $all = $all->merge($logs);
    //     }

    //     return $all->sortByDesc('created_at')->values();
    // }

    public function activityLogs(): MorphMany
    {
        $map = $this->activityModelsMap();
        $guard = $this->resolveActiveGuard();
        $class = $map[$guard] ?? array_values($map)[0] ?? null;

        abort_unless($class, 500, "No hay modelo de log para el guard '{$guard}'");

        return $this->morphMany($class, 'subject');
    }
}
