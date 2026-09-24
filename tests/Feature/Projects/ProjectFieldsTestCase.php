<?php

namespace Tests\Feature\Projects;

use App\Models\User;
use App\Support\MarketingVariantPermissions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

/**
 * Base para los tests de los campos nuevos del módulo de Proyectos.
 *
 * El suite de migraciones no es auto-contenido (la tabla `clients` se crea
 * fuera de migrations), así que se arma a mano el esquema mínimo sobre sqlite
 * en memoria, incluidas las tablas de Spatie para poder ejercitar permisos
 * reales.
 */
abstract class ProjectFieldsTestCase extends TestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'filesystems.disks.local.root' => storage_path('framework/testing/disks/local'),
        ]);

        $this->buildSchema();
        $this->buildPermissionSchema();

        $this->user = User::create([
            'name'     => 'Tester',
            'email'    => 'tester@finearom.co',
            'password' => bcrypt('secret'),
        ]);

        $this->givePermissions([
            'project list', 'project edit', 'project create', 'project send creation', 'config edit',
            MarketingVariantPermissions::COMMERCIAL,
            MarketingVariantPermissions::TECHNICAL,
        ]);
        $this->actingAs($this->user, 'sanctum');
    }

    /** Reasigna el set exacto de permisos del usuario de pruebas. */
    protected function givePermissions(array $names): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->user->permissions()->detach();
        // El detach crudo no limpia la relación "permissions" ya cacheada en el
        // modelo: sin este unset, el próximo givePermissionTo() calcula su diff
        // contra esa caché vieja y puede no re-adjuntar un permiso que ya estaba.
        $this->user->unsetRelation('permissions');

        foreach ($names as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $this->user->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function buildPermissionSchema(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();
        });

        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();
        });

        Schema::create('model_has_permissions', function (Blueprint $t) {
            $t->unsignedBigInteger('permission_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
        });

        Schema::create('model_has_roles', function (Blueprint $t) {
            $t->unsignedBigInteger('role_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
        });

        Schema::create('role_has_permissions', function (Blueprint $t) {
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('role_id');
        });
    }

    private function buildSchema(): void
    {
        Schema::create('envelope_types', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('category', 100)->nullable();
            $t->string('photo_path')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('project_envelope_type', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('envelope_type_id');
            $t->timestamps();
        });

        Schema::create('product_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('project_product_types', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
            $t->integer('categoria')->nullable();
            $t->unsignedBigInteger('product_category_id')->nullable();
            $t->string('grupo')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
            $t->unsignedBigInteger('client_id')->nullable();
            $t->unsignedBigInteger('prospect_id')->nullable();
            $t->string('nombre_prospecto')->nullable();
            $t->string('email_prospecto')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('product_category_id')->nullable();
            $t->string('tipo')->nullable();
            $t->decimal('rango_min', 12, 2)->nullable();
            $t->decimal('rango_max', 12, 2)->nullable();
            $t->decimal('volumen', 12, 2)->nullable();
            $t->decimal('potencial_anual_usd', 12, 2)->nullable();
            $t->decimal('potencial_anual_kg', 12, 2)->nullable();
            $t->decimal('precio', 12, 2)->nullable();
            $t->decimal('dosis', 8, 2)->nullable();
            $t->decimal('costo_perfumacion_especifico', 12, 2)->nullable();
            $t->decimal('costo_perfumacion_tonelada', 12, 2)->nullable();
            $t->string('tipo_etiquetado')->nullable();
            $t->integer('max_variantes')->nullable();
            $t->boolean('base_cliente')->default(false);
            $t->boolean('proactivo')->default(false);
            $t->boolean('homologacion')->default(false);
            $t->string('tipo_homologacion', 20)->nullable();
            $t->string('tipo_desarrollo', 30)->nullable();
            $t->string('area_aplicacion', 30)->nullable();
            $t->boolean('seleccion_envase_aplicacion')->default(false);
            $t->boolean('requiere_piramides')->default(false);
            $t->string('area_evaluaciones', 30)->nullable();
            $t->boolean('internacional')->default(false);
            $t->string('tipo_producto')->nullable();
            $t->decimal('trm', 12, 2)->nullable();
            $t->decimal('factor', 10, 4)->default(1); // igual que producción: NOT NULL DEFAULT 1
            $t->date('fecha_requerida')->nullable();
            $t->date('fecha_creacion')->nullable();
            $t->date('fecha_calculada')->nullable();
            $t->date('fecha_entrega')->nullable();
            $t->string('ejecutivo')->nullable();
            $t->unsignedBigInteger('ejecutivo_id')->nullable();
            $t->unsignedBigInteger('desarrollador_id')->nullable();
            $t->string('estado_externo')->nullable();
            $t->string('estado_interno')->nullable();
            $t->boolean('estado_desarrollo')->default(false);
            $t->date('fecha_externo')->nullable();
            $t->string('ejecutivo_externo')->nullable();
            $t->string('razon_perdida', 500)->nullable();
            $t->date('fecha_desarrollo')->nullable();
            $t->string('ejecutivo_desarrollo')->nullable();
            $t->boolean('estado_laboratorio')->default(false);
            $t->date('fecha_laboratorio')->nullable();
            $t->string('ejecutivo_laboratorio')->nullable();
            $t->boolean('estado_mercadeo')->default(false);
            $t->date('fecha_mercadeo')->nullable();
            $t->string('ejecutivo_mercadeo')->nullable();
            $t->boolean('estado_calidad')->default(false);
            $t->date('fecha_calidad')->nullable();
            $t->string('ejecutivo_calidad')->nullable();
            $t->boolean('estado_especiales')->default(false);
            $t->date('fecha_especiales')->nullable();
            $t->string('ejecutivo_especiales')->nullable();
            $t->boolean('estado_evaluaciones')->default(false);
            $t->date('fecha_evaluaciones')->nullable();
            $t->string('ejecutivo_evaluaciones')->nullable();
            $t->integer('dias_diferencia')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('project_samples', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->decimal('cantidad', 10, 2)->nullable();
            $t->integer('cantidad_copias')->nullable();
            $t->text('observaciones')->nullable();
            $t->timestamps();
        });

        // dosis es decimal(10,2) en la migración real: se replica igual a propósito.
        Schema::create('project_applications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->text('notas_entrega')->nullable();
            $t->decimal('dosis', 10, 2)->nullable();
            $t->integer('cantidad_aplicacion')->nullable();
            $t->text('observaciones')->nullable();
            $t->timestamps();
        });

        Schema::create('project_evaluations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->json('tipos')->nullable();
            $t->unsignedBigInteger('benchmark_reference_id')->nullable();
            $t->string('metodologia')->nullable();
            $t->text('observacion')->nullable();
            $t->text('notas_entrega')->nullable();
            $t->text('bench_text')->nullable();
            $t->string('bench_image')->nullable();
            $t->timestamps();
        });

        Schema::create('project_marketing', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->text('notas_entrega')->nullable();
            $t->json('marketing')->nullable();
            $t->json('calidad')->nullable();
            $t->text('obs_marketing')->nullable();
            $t->text('obs_calidad')->nullable();
            $t->string('marca')->nullable();
            $t->json('logo_marca')->nullable();
            $t->string('variante')->nullable();
            $t->string('tipo_aplicacion')->nullable();
            $t->string('tipo_envase')->nullable();
            $t->string('packaging')->nullable();
            $t->text('claims')->nullable();
            $t->text('benchmark_links')->nullable();
            $t->json('benchmark_examples')->nullable();
            $t->json('catalog_etiquetas')->nullable();
            $t->json('catalog_piramides')->nullable();
            $t->json('lista_presentaciones')->nullable();
            $t->text('descripcion_detallada')->nullable();
            $t->date('fecha_entrega_marketing')->nullable();
            $t->timestamps();
        });

        // Relaciones que las respuestas del controller cargan aunque el test no las use.
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->string('client_name')->nullable();
            $t->string('client_type')->nullable();
            $t->timestamps();
        });

        Schema::create('project_variants', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->string('nombre')->nullable();
            $t->string('categoria', 100)->nullable();
            $t->text('observaciones')->nullable();
            $t->text('descripcion')->nullable();
            $t->unsignedBigInteger('benchmark_reference_id')->nullable();
            $t->text('benchmark_descripcion')->nullable();
            $t->string('benchmark_imagen', 500)->nullable();
            $t->timestamps();
        });

        Schema::create('project_proposals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('variant_id');
            $t->unsignedBigInteger('finearom_reference_id')->nullable();
            $t->decimal('total_propuesta', 12, 2)->nullable();
            $t->decimal('total_propuesta_cop', 14, 2)->nullable();
            $t->boolean('definitiva')->default(false);
            $t->timestamps();
        });

        Schema::create('project_fragrances', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->timestamps();
        });

        // La cargan las relaciones de ProjectController::show aunque el test no las use.
        Schema::create('project_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->timestamps();
        });

        // La escribe ProjectWorkflowService::deliver al notificar la entrega.
        Schema::create('project_notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('project_id')->nullable();
            $t->string('tipo', 50);
            $t->string('titulo');
            $t->text('mensaje')->nullable();
            $t->json('data')->nullable();
            $t->timestamp('leida_at')->nullable();
            $t->timestamps();
        });

        Schema::create('project_marketing_variants', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('project_variant_id')->nullable();
            $t->string('nombre', 255)->nullable();
            $t->text('claims')->nullable();
            $t->string('color_etiqueta', 50)->nullable();
            $t->integer('orden')->default(0);
            $t->timestamps();
        });

        Schema::create('project_marketing_variant_references', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('variant_id');
            $t->string('referencia', 200)->nullable();
            $t->string('codigo', 100)->nullable();
            $t->string('aplicacion', 200)->nullable();
            $t->decimal('dosis', 8, 2)->nullable();
            $t->decimal('precio', 12, 2)->nullable();
            $t->integer('orden')->default(0);
            $t->timestamps();
        });

        Schema::create('project_potential_references', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('reference_id')->unique();
            $t->decimal('kg_anio', 12, 2)->nullable();
            $t->date('fecha_primer_despacho')->nullable();
            $t->decimal('venta_anio_usd', 14, 2)->nullable();
            $t->string('frecuencia_compra', 20)->nullable();
            $t->text('seguimiento')->nullable();
            $t->string('estado', 20)->default('abierto');
            $t->string('probabilidad', 10)->nullable();
            $t->timestamps();
        });

        Schema::create('project_files', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->string('nombre_original');
            $t->string('nombre_storage');
            $t->string('path');
            $t->string('mime_type');
            $t->bigInteger('size');
            $t->string('categoria')->nullable();
            $t->unsignedBigInteger('delivery_log_id')->nullable();
            $t->string('ejecutivo');
            $t->timestamps();
        });

        Schema::create('project_area_delivery_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->string('area', 30);
            $t->string('tipo', 20);
            $t->longText('notas')->nullable();
            $t->string('ejecutivo')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
        });

        Schema::create('project_area_deliveries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->string('area', 30);
            $t->text('notas_entrega')->nullable();
            $t->timestamps();
        });

        // La consulta ProjectTimeService::calculate() en cada store/update/updateMarketing.
        Schema::create('holidays', function (Blueprint $t) {
            $t->id();
            $t->date('date')->unique();
            $t->string('nombre', 150);
            $t->timestamps();
        });
    }
}
