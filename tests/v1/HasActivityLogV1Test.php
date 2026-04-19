<?php

namespace Tests\v1;

use App\Traits\HasActivityLog;
use HypathBel\ModelScribe\Tests\TestCase;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;

// ============================================
// MODELOS DE PRUEBA
// ============================================

// Modelo base de ActivityLog con company_id
class TestActivityLog extends Model
{
    protected $table = 'test_activity_logs';

    protected $fillable = [
        'company_id', 'log_name', 'description', 'subject_type', 'subject_id',
        'causer_type', 'causer_id', 'event', 'properties', 'created_at',
    ];

    protected $casts = [
        'properties' => 'array',
    ];
}

// Modelo de Company para pruebas
class TestCompany extends Model
{
    protected $table = 'test_companies';

    protected $fillable = ['name', 'code'];
}

// Modelo de prueba estándar
class TestModel extends Model
{
    use HasActivityLog;

    protected $table = 'test_models';

    protected $fillable = ['name', 'email', 'status', 'description', 'company_id', 'secret_field'];

    // Configuración básica
    protected $logAttributes = ['name', 'email'];

    protected $logName = 'test_log';

    protected $activityModels = [
        'web' => TestActivityLog::class,
        'api' => TestActivityLog::class,
        'admin' => TestActivityLog::class,
        'default' => TestActivityLog::class,
    ];

    protected $onlySaveDirty = true;

    // Configuración específica por evento
    protected $loggableWhenCreatedAttributes = ['name', 'email', 'status'];

    protected $loggableWhenUpdatedAttributes = ['name', 'email'];

    protected $loggableWhenDeletedAttributes = ['name'];

    // Relación con company
    public function company()
    {
        return $this->belongsTo(TestCompany::class);
    }
}

// Modelo con configuración por guard
class TestModelWithGuardConfig extends TestModel
{
    protected $loggableAttributesPerGuard = [
        'admin' => ['id', 'name', 'email', 'status', 'secret_field'],
        'web' => ['id', 'name', 'email'],
        'api' => ['id', 'name'],
    ];

    protected $eventsAttributesMapPerGuard = [
        'created' => [
            'admin' => ['id', 'name', 'email', 'status', 'created_at'],
            'web' => ['id', 'name', 'email'],
        ],
        'updated' => [
            'admin' => ['name', 'email', 'status', 'secret_field'],
            'web' => ['name', 'email'],
        ],
    ];
}

// Modelo sin configuración de atributos (trackea todo)
class TestModelFullTracking extends TestModel
{
    protected $logAttributes = [];
}

// Modelo de usuario para pruebas
class TestUser extends Model
{
    protected $table = 'test_users';

    protected $fillable = ['name', 'email', 'company_id'];
}

class HasActivityLogV1Test extends TestCase
{
    private TestModel $model;

    private TestUser $user;

    private TestCompany $company;

    protected function setUp(): void
    {
        parent::setUp();

        // Override the database connection to use SQLite for this test
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        // Configurar un guard simple para testing
        Config::set('auth.guards.admin', [
            'driver' => 'session',
            'provider' => 'backoffice_users',
        ]);

        Config::set('auth.providers.backoffice_users', [
            'driver' => 'eloquent',
            'model' => User::class,
        ]);

        User::resolveRelationUsing('factory', function () {
            return User::new();
        });

        $this->createTestTables();

        // Crear compañía de prueba
        $this->company = TestCompany::create([
            'name' => 'Test Company',
            'code' => 'TEST001',
        ]);

        // Crear usuario de prueba
        $this->user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'company_id' => $this->company->id,
        ]);

        $this->setupAuthMocks();
    }

    protected function tearDown(): void
    {
        $this->dropTestTables();
        parent::tearDown();
    }

    private function setupAuthMocks(): void
    {
        Auth::shouldReceive('getDefaultDriver')->andReturn('web');
        Auth::shouldReceive('guard')->with('web')->andReturnSelf();
        Auth::shouldReceive('guard->user')->andReturn($this->user);
    }

    private function createTestTables(): void
    {
        // Tabla de compañías
        Schema::create('test_companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->timestamps();
        });

        // Tabla principal de prueba
        Schema::create('test_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('status')->default('active');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('secret_field')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('test_companies');
        });

        // Tabla de activity logs
        Schema::create('test_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('log_name');
            $table->string('description');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('causer_type')->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();
            $table->string('event');
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('company_id');
        });

        // Tabla de usuarios
        Schema::create('test_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamps();
        });
    }

    protected function mockAuthManager(?string $defaultGuard = 'web'): void
    {
        // Mock del guard
        $guardMock = Mockery::mock(Guard::class);
        $guardMock->shouldReceive('user')
            ->zeroOrMoreTimes()
            ->andReturn(null);
        $guardMock->shouldReceive('check')
            ->zeroOrMoreTimes()
            ->andReturn(false);
        $guardMock->shouldReceive('guest')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        // Mock del AuthManager
        $authMock = Mockery::mock(AuthManager::class);

        // Configurar todos los métodos que pueda necesitar tu código
        $authMock->shouldReceive('getDefaultDriver')
            ->zeroOrMoreTimes()
            ->andReturn($defaultGuard);

        $authMock->shouldReceive('setDefaultDriver')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($guard) use ($authMock, &$defaultGuard) {
                $defaultGuard = $guard;
                $authMock->shouldReceive('getDefaultDriver')
                    ->zeroOrMoreTimes()
                    ->andReturn($guard);
            });

        $authMock->shouldReceive('guard')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($guard = null) use ($guardMock) {
                return $guardMock;
            });

        $authMock->shouldReceive('shouldUse')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($guard) use ($authMock, &$defaultGuard) {
                $defaultGuard = $guard;
                $authMock->shouldReceive('getDefaultDriver')
                    ->zeroOrMoreTimes()
                    ->andReturn($guard);
            });

        $authMock->shouldReceive('driver')
            ->zeroOrMoreTimes()
            ->andReturn($guardMock);

        // Reemplazar en el contenedor
        app()->instance('auth', $authMock);
        Facade::clearResolvedInstance('auth');
    }

    private function dropTestTables(): void
    {
        Schema::dropIfExists('test_activity_logs');
        Schema::dropIfExists('test_models');
        Schema::dropIfExists('test_users');
        Schema::dropIfExists('test_companies');
    }

    // ============================================
    // PRUEBAS DE FUNCIONALIDAD BÁSICA
    // ============================================

    /**
     * @test
     *
     * @group unit
     */
    public function test_created_event_logs_with_company_id()
    {
        $model = TestModel::create([
            'name' => 'Test Name',
            'email' => 'test@example.com',
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);

        $log = TestActivityLog::where('subject_id', $model->id)->first();

        $this->assertNotNull($log);
        $this->assertEquals($this->company->id, $log->company_id);
        $this->assertEquals('created', $log->event);
        $this->assertEquals('test_log', $log->log_name);
    }

    /**
     * @test
     *
     * @group unit
     */
    public function test_company_id_resolved_from_relationship()
    {
        $model = TestModel::create([
            'name' => 'Test',
            'email' => 'test@example.com',
        ]);

        // Asignar company mediante relación
        $model->company()->associate($this->company);
        $model->save();

        // Forzar otro log
        $model->update(['name' => 'Updated Name']);

        $log = TestActivityLog::where('subject_id', $model->id)
            ->where('event', 'updated')
            ->first();

        $this->assertEquals($this->company->id, $log->company_id);
    }

    /**
     * @test
     *
     * @group unit
     */
    public function test_loggable_attributes_per_guard()
    {
        $model = new TestModelWithGuardConfig;
        $model->forceFill([
            'name' => 'Test',
            'email' => 'test@example.com',
            'status' => 'active',
            'secret_field' => 'secret',
        ]);
        $model->save();

        // Probar con guard 'web'
        Auth::shouldReceive('getDefaultDriver')->andReturn('web');

        $model->update(['name' => 'Updated Web']);

        $log = TestActivityLog::where('subject_id', $model->id)
            ->latest()
            ->first();

        $properties = $log->properties;

        // Web solo debe tener 'name' y 'email', no 'secret_field'
        $this->assertArrayHasKey('name', $properties['attributes']);
        $this->assertArrayNotHasKey('secret_field', $properties['attributes']);
    }

    /**
     * @test
     *
     * @group unit
     */
    public function test_events_attributes_map_per_guard()
    {
        $model = new TestModelWithGuardConfig;
        $model->forceFill([
            'name' => 'Test',
            'email' => 'test@example.com',
            'status' => 'pending',
            'secret_field' => 'secret',
        ]);
        $model->save();

        // Probar con guard 'web' para created
        Auth::shouldReceive('getDefaultDriver')->andReturn('web');
        $log = TestActivityLog::where('subject_id', $model->id)
            ->where('event', 'created')
            ->first();
        $properties = $log->properties;

        // Admin debe tener todos los campos en created
        $this->assertArrayHasKey('name', $properties['attributes']);
        $this->assertArrayHasKey('email', $properties['attributes']);
        $this->assertArrayNotHasKey('status', $properties['attributes']);

        // Probar con guard 'admin' para created
        $this->mockAuthManager('admin');
        Auth::shouldUse('admin');

        // Ahora sí debería funcionar
        $this->assertEquals('admin', Auth::getDefaultDriver());

        Log::build([
            'driver' => 'single',
            'path' => storage_path('logs/has_activity_log_test_v2.log'),
        ])->info('1', ['auth_guard' => Auth::getDefaultDriver()]);

        $newModel = new TestModelWithGuardConfig;
        $newModel->forceFill([
            'name' => 'Admin Test',
            'email' => 'admin@example.com',
            'status' => 'active',
            'secret_field' => 'admin_secret',
        ]);
        $newModel->save();

        $log = TestActivityLog::where('subject_id', $newModel->id)->where('event', 'created')->first();
        $properties = $log->properties;

        // Admin debe tener todos los campos en created
        $this->assertArrayHasKey('name', $properties['attributes']);
        $this->assertArrayHasKey('email', $properties['attributes']);
        $this->assertArrayHasKey('status', $properties['attributes']);
        $this->assertArrayHasKey('created_at', $properties['attributes']);
    }

    /**
     * @test
     *
     * @group unit
     */
    public function test_priority_of_attribute_resolution()
    {
        // Prioridad: eventsAttributesMapPerGuard > loggableAttributesPerGuard > eventsAttributesMap > loggableAttributes

        $model = new class extends TestModel
        {
            protected $logAttributes = ['name']; // Menor prioridad

            protected $loggableWhenCreatedAttributes = ['name', 'email']; // Prioridad media

            protected $loggableAttributesPerGuard = ['web' => ['name']]; // Alta prioridad

            protected $eventsAttributesMapPerGuard = [
                'created' => ['web' => ['id', 'name']], // Máxima prioridad
            ];
        };

        Auth::shouldReceive('getDefaultDriver')->andReturn('web');

        $model->forceFill([
            'name' => 'Priority Test',
            'email' => 'test@example.com',
        ]);
        $model->save();

        $log = TestActivityLog::where('subject_id', $model->id)->first();
        $properties = $log->properties;

        // Solo debe tener 'id' y 'name' por la máxima prioridad
        $this->assertArrayHasKey('id', $properties['attributes']);
        $this->assertArrayHasKey('name', $properties['attributes']);
        $this->assertArrayNotHasKey('email', $properties['attributes']);
    }

    /**
     * @test
     *
     * @group unit
     */
    public function test_system_user_when_no_guard()
    {
        $this->mockAuthManager(null); // Sin guard
        $this->assertEquals(null, Auth::getDefaultDriver());
        Log::build([
            'driver' => 'single',
            'path' => storage_path('logs/has_activity_log_test_v2.log'),
        ])->info('Testing system user logging', ['auth_guard' => Auth::getDefaultDriver()]);

        $model = TestModel::create([
            'name' => 'System Test',
            'email' => 'system@example.com',
        ]);

        $log = TestActivityLog::where('subject_id', $model->id)->first();

        $this->assertEquals('system', $log->causer_type);
        $this->assertNull($log->causer_id);
        $this->assertStringContainsString('Sistema creó', $log->description);
    }

    /**
     * @test
     *
     * @group unit
     */
    public function test_logging_fallback_when_guard_not_in_map()
    {
        Auth::shouldReceive('getDefaultDriver')->andReturn('unknown_guard');

        $model = TestModel::create([
            'name' => 'Fallback Test',
            'email' => 'fallback@example.com',
        ]);

        // Debería usar el primer modelo del mapa como fallback
        $log = TestActivityLog::where('subject_id', $model->id)->first();

        $this->assertNotNull($log);
        $this->assertEquals('created', $log->event);
    }

    /**
     * @test
     *
     * @group unit
     */
    public function test_no_logging_when_no_activity_model_configured()
    {
        $model = new class extends TestModel
        {
            protected function activityModelsMap(): array
            {
                return []; // Sin modelos configurados
            }
        };

        $model->forceFill([
            'name' => 'No Log Test',
            'email' => 'nolog@example.com',
        ]);
        $model->save();

        $log = TestActivityLog::where('subject_type', get_class($model))->first();

        $this->assertNull($log);
    }

    /**
     * @test
     *
     * @group unit
     */
    public function test_activity_logs_relationship_returns_null_when_no_model()
    {
        $model = new class extends TestModel
        {
            protected function activityModelsMap(): array
            {
                return [];
            }
        };

        $model->forceFill(['name' => 'Test', 'email' => 'test@example.com']);
        $model->save();

        $this->assertNull($model->activityLogs());
    }

    /**
     * @test
     *
     * @group unit
     */
    public function test_activity_logs_relationship_works_with_valid_guard()
    {
        $model = TestModel::create([
            'name' => 'Relationship Test',
            'email' => 'rel@example.com',
        ]);

        $model->update(['name' => 'Updated']);

        $logs = $model->activityLogs();

        $this->assertInstanceOf(MorphMany::class, $logs);
        $this->assertEquals(2, $logs->count());
    }

    /**
     * @test
     *
     * @group unit
     */
    public function test_custom_description_for_non_standard_events()
    {
        $model = TestModel::create([
            'name' => 'Custom Event',
            'email' => 'custom@example.com',
        ]);

        $model->logActivity('password_changed', ['reason' => 'security']);

        $log = TestActivityLog::where('event', 'password_changed')->first();

        $this->assertStringContainsString('realizó \'password_changed\'', $log->description);
        $this->assertEquals('security', $log->properties['reason']);
    }

    /**
     * @test
     *
     * @group unit
     */
    public function test_exception_handling_in_production_environment()
    {
        // Simular entorno production
        $originalEnv = app()->environment();
        app()->detectEnvironment(fn () => 'production');

        // Forzar error creando modelo con tabla inexistente
        $model = new class extends TestModel
        {
            protected function resolveActivityModel(?string $guard): ?string
            {
                throw new \Exception('Database error simulation');
            }
        };

        $model->forceFill(['name' => 'Test', 'email' => 'test@example.com']);

        // No debería lanzar excepción en producción
        try {
            $model->save();
            $this->assertTrue(true);
        } catch (\Exception $e) {
            $this->fail('Should not throw exception in production');
        }

        // Restaurar entorno
        app()->detectEnvironment(fn () => $originalEnv);
    }

    // ============================================
    // PRUEBAS DE ESTRÉS Y RENDIMIENTO
    // ============================================

    /**
     * @test
     *
     * @group stress
     */
    public function stress_test_massive_operations_with_company_scoping()
    {
        $companies = [];
        for ($i = 0; $i < 5; $i++) {
            $companies[] = TestCompany::create([
                'name' => "Company {$i}",
                'code' => "CODE{$i}",
            ]);
        }

        $iterations = 100;
        $startTime = microtime(true);
        $memoryStart = memory_get_usage();

        for ($i = 0; $i < $iterations; $i++) {
            $company = $companies[$i % 5];

            $model = TestModel::create([
                'name' => "Model {$i}",
                'email' => "model{$i}@example.com",
                'company_id' => $company->id,
            ]);

            $model->update(['name' => "Updated {$i}"]);
            $model->delete();
        }

        $executionTime = microtime(true) - $startTime;
        $memoryUsage = memory_get_usage() - $memoryStart;

        // Verificar que los logs tienen el company_id correcto
        $logsByCompany = TestActivityLog::select('company_id')
            ->selectRaw('count(*) as total')
            ->groupBy('company_id')
            ->get();

        $this->assertEquals(5, $logsByCompany->count());
        foreach ($logsByCompany as $log) {
            $this->assertEquals(60, $log->total); // 100 iteraciones * 3 eventos / 5 companies = 60
        }

        $this->assertLessThan(10, $executionTime);
        $this->assertLessThan(100 * 1024 * 1024, $memoryUsage);

        echo "\nMassive Ops with Company Scoping - Time: {$executionTime}s, Memory: "
            .round($memoryUsage / 1024 / 1024, 2)."MB\n";
    }

    /**
     * @test
     *
     * @group stress
     */
    public function stress_test_attribute_resolution_performance()
    {
        $model = new TestModelWithGuardConfig;
        $iterations = 1000;

        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $guard = ['web', 'api', 'admin'][$i % 3];
            Auth::shouldReceive('getDefaultDriver')->andReturn($guard);

            $testModel = clone $model;
            $testModel->forceFill([
                'name' => "Test {$i}",
                'email' => "test{$i}@example.com",
                'status' => 'active',
                'secret_field' => 'secret',
            ]);
            $testModel->save();

            if ($i % 2 === 0) {
                $testModel->update(['name' => "Updated {$i}"]);
            }
        }

        $executionTime = microtime(true) - $startTime;
        $logsCount = TestActivityLog::count();

        $expectedLogs = $iterations + floor($iterations / 2); // creates + updates
        $this->assertEquals($expectedLogs, $logsCount);
        $this->assertLessThan(15, $executionTime);

        echo "\nAttribute Resolution Performance - {$iterations} ops in {$executionTime}s\n";
    }

    /**
     * @test
     *
     * @group stress
     */
    public function stress_test_concurrent_guard_switching()
    {
        $guards = ['web', 'api', 'admin', 'unknown'];
        $iterations = 200;
        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $guard = $guards[$i % 4];
            Auth::shouldReceive('getDefaultDriver')->andReturn($guard);

            if ($guard !== 'unknown') {
                Auth::shouldReceive('guard')->with($guard)->andReturnSelf();
                Auth::shouldReceive('guard->user')->andReturn($this->user);
            }

            $model = TestModel::create([
                'name' => "Guard Test {$i}",
                'email' => "guard{$i}@example.com",
            ]);

            if ($guard !== 'unknown') {
                $model->update(['name' => "Guard Updated {$i}"]);
            }
        }

        $executionTime = microtime(true) - $startTime;

        // Los logs con guard 'unknown' deberían fallback al primer modelo
        $logsCount = TestActivityLog::count();
        $this->assertGreaterThan(0, $logsCount);
        $this->assertLessThan(8, $executionTime);

        echo "\nGuard Switching Stress - {$iterations} operations in {$executionTime}s\n";
    }

    /**
     * @test
     *
     * @group stress
     */
    public function stress_test_large_payload_with_company_data()
    {
        $model = TestModel::create([
            'name' => 'Large Payload Test',
            'email' => 'large@example.com',
            'company_id' => $this->company->id,
        ]);

        // Crear payload de 500KB
        $largeData = [];
        for ($i = 0; $i < 500; $i++) {
            $largeData["field_{$i}"] = str_repeat('x', 100);
        }

        $startTime = microtime(true);
        $memoryStart = memory_get_usage();

        for ($i = 0; $i < 10; $i++) {
            $model->logActivity('large_event', $largeData);
        }

        $executionTime = microtime(true) - $startTime;
        $memoryUsage = memory_get_usage() - $memoryStart;

        $logs = TestActivityLog::where('subject_id', $model->id)
            ->where('event', 'large_event')
            ->get();

        $this->assertCount(10, $logs);
        foreach ($logs as $log) {
            $this->assertEquals($this->company->id, $log->company_id);
        }

        $this->assertLessThan(5, $executionTime);
        $this->assertLessThan(50 * 1024 * 1024, $memoryUsage);

        echo "\nLarge Payload with Company - Time: {$executionTime}s, Memory: "
            .round($memoryUsage / 1024 / 1024, 2)."MB\n";
    }

    /**
     * @test
     *
     * @group stress
     */
    public function stress_test_memory_leak_with_per_guard_configs()
    {
        $iterations = 500;
        $memorySamples = [];

        for ($i = 0; $i < $iterations; $i++) {
            $guard = ['web', 'api', 'admin'][$i % 3];
            Auth::shouldReceive('getDefaultDriver')->andReturn($guard);

            $model = new TestModelWithGuardConfig;
            $model->forceFill([
                'name' => "Memory Test {$i}",
                'email' => "memory{$i}@example.com",
                'status' => 'active',
                'secret_field' => 'secret_value',
            ]);
            $model->save();

            if ($i % 3 === 0) {
                $model->update(['name' => "Memory Updated {$i}"]);
            }

            if ($i % 50 === 0) {
                $memorySamples[] = memory_get_usage();
            }
        }

        if (count($memorySamples) > 1) {
            $memoryIncrease = end($memorySamples) - $memorySamples[0];
            $this->assertLessThan(10 * 1024 * 1024, $memoryIncrease,
                'Memory increase should be less than 10MB, actual: '
                .round($memoryIncrease / 1024 / 1024, 2).'MB');
        }

        echo "\nMemory Leak Test - Final memory: "
            .round(end($memorySamples) / 1024 / 1024, 2)."MB\n";
    }

    /**
     * @test
     *
     * @group stress
     */
    public function stress_test_bulk_retrieval_with_companies()
    {
        // Crear 10 compañías con 50 modelos cada una
        $companies = [];
        for ($i = 0; $i < 10; $i++) {
            $company = TestCompany::create([
                'name' => "Bulk Company {$i}",
                'code' => "BULK{$i}",
            ]);
            $companies[] = $company;

            for ($j = 0; $j < 50; $j++) {
                $model = TestModel::create([
                    'name' => "Model {$i}_{$j}",
                    'email' => "model{$i}_{$j}@example.com",
                    'company_id' => $company->id,
                ]);

                $model->update(['status' => 'processed']);
            }
        }

        $startTime = microtime(true);

        // Recuperar logs por compañía
        foreach ($companies as $company) {
            $logs = TestActivityLog::where('company_id', $company->id)->get();
            $this->assertGreaterThan(0, $logs->count());
        }

        $retrievalTime = microtime(true) - $startTime;

        $totalLogs = TestActivityLog::count();
        $expectedLogs = 10 * 50 * 2; // 10 companies * 50 models * 2 events (create + update)

        $this->assertEquals($expectedLogs, $totalLogs);
        $this->assertLessThan(2, $retrievalTime);

        echo "\nBulk Retrieval - {$totalLogs} logs retrieved in {$retrievalTime}s\n";
    }

    /**
     * @test
     *
     * @group stress
     */
    public function edge_case_empty_attributes_with_guard_config()
    {
        $model = new class extends TestModel
        {
            protected $loggableAttributesPerGuard = [
                'web' => [], // Vacío debería trackear todos
                'api' => ['name'], // Específico
            ];
        };

        // Probar con guard 'web' (vacío = trackear todo)
        Auth::shouldReceive('getDefaultDriver')->andReturn('web');

        $model->forceFill([
            'name' => 'Web Test',
            'email' => 'web@example.com',
            'status' => 'active',
        ]);
        $model->save();

        $log = TestActivityLog::where('subject_id', $model->id)->first();
        $properties = $log->properties;

        // Debería tener todos los atributos
        $this->assertArrayHasKey('name', $properties['attributes']);
        $this->assertArrayHasKey('email', $properties['attributes']);
        $this->assertArrayHasKey('status', $properties['attributes']);

        TestActivityLog::truncate();

        // Probar con guard 'api' (solo name)
        Auth::shouldReceive('getDefaultDriver')->andReturn('api');

        $model2 = new class extends TestModel
        {
            protected $loggableAttributesPerGuard = [
                'web' => [],
                'api' => ['name'],
            ];
        };

        $model2->forceFill([
            'name' => 'API Test',
            'email' => 'api@example.com',
            'status' => 'inactive',
        ]);
        $model2->save();

        $log2 = TestActivityLog::where('subject_id', $model2->id)->first();
        $properties2 = $log2->properties;

        // Solo debe tener 'name'
        $this->assertArrayHasKey('name', $properties2['attributes']);
        $this->assertArrayNotHasKey('email', $properties2['attributes']);
        $this->assertArrayNotHasKey('status', $properties2['attributes']);
    }

    /**
     * @test
     *
     * @group stress
     */
    public function edge_case_multiple_companies_relationship()
    {
        // Modelo con múltiples compañías (many-to-many)
        $modelWithManyCompanies = new class extends TestModel
        {
            protected $table = 'test_models';

            public function companies()
            {
                return $this->belongsToMany(TestCompany::class, 'model_company');
            }

            protected function resolveCompanyId(): ?int
            {
                if ($this->companies->isNotEmpty()) {
                    return $this->companies->first()->id;
                }

                return null;
            }
        };

        // Crear tabla pivot
        Schema::create('model_company', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('test_model_id');
            $table->unsignedBigInteger('test_company_id');
        });

        $company2 = TestCompany::create(['name' => 'Company 2', 'code' => 'C2']);

        $modelWithManyCompanies->forceFill([
            'name' => 'Multi Company Test',
            'email' => 'multi@example.com',
        ]);
        $modelWithManyCompanies->save();

        // Adjuntar compañías
        $modelWithManyCompanies->companies()->attach([$this->company->id, $company2->id]);
        $modelWithManyCompanies->load('companies');

        // Forzar log
        $modelWithManyCompanies->update(['name' => 'Updated Multi']);

        $log = TestActivityLog::where('subject_id', $modelWithManyCompanies->id)
            ->latest()
            ->first();

        // Debería tomar la primera compañía
        $this->assertEquals($this->company->id, $log->company_id);

        Schema::dropIfExists('model_company');
    }

    /**
     * @test
     *
     * @group stress
     */
    public function performance_test_different_priority_levels()
    {
        $configurations = [
            'basic' => new TestModel,
            'per_guard' => new TestModelWithGuardConfig,
            'full_custom' => new class extends TestModel
            {
                protected $loggableAttributesPerGuard = [
                    'web' => ['name', 'email', 'status'],
                ];

                protected $eventsAttributesMapPerGuard = [
                    'created' => ['web' => ['name', 'email', 'created_at']],
                    'updated' => ['web' => ['name', 'status']],
                ];
            },
        ];

        $results = [];

        foreach ($configurations as $name => $configModel) {
            TestActivityLog::truncate();
            $startTime = microtime(true);

            for ($i = 0; $i < 100; $i++) {
                $model = clone $configModel;
                $model->forceFill([
                    'name' => "Test {$i}",
                    'email' => "test{$i}@example.com",
                    'status' => 'active',
                ]);
                $model->save();
                $model->update(['status' => 'completed']);
            }

            $results[$name] = microtime(true) - $startTime;
        }

        echo "\nPerformance by Configuration:\n";
        foreach ($results as $name => $time) {
            echo "  {$name}: {$time}s\n";
        }

        // Las configuraciones más complejas no deberían ser mucho más lentas
        $this->assertLessThan($results['basic'] * 1.5, $results['per_guard']);
        $this->assertLessThan($results['basic'] * 2, $results['full_custom']);
    }
}
