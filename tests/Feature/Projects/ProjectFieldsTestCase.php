<?php

namespace Tests\Feature\Projects;

use App\Models\User;
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

        $this->givePermissions(['project list', 'project edit', 'project create', 'config edit']);
        $this->actingAs($this->user, 'sanctum');
    }

    /** Reasigna el set exacto de permisos del usuario de pruebas. */
    protected function givePermissions(array $names): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->user->permissions()->detach();

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
            $t->decimal('precio', 12, 2)->nullable();
            $t->decimal('dosis', 8, 2)->nullable();
            $t->decimal('costo_perfumacion_especifico', 12, 2)->nullable();
            $t->decimal('costo_perfumacion_tonelada', 12, 2)->nullable();
            $t->string('tipo_etiquetado')->nullable();
            $t->unsignedBigInteger('envelope_type_id')->nullable();
            $t->integer('max_variantes')->nullable();
            $t->boolean('base_cliente')->default(false);
            $t->boolean('proactivo')->default(false);
            $t->boolean('homologacion')->default(false);
            $t->boolean('internacional')->default(false);
            $t->string('tipo_producto')->nullable();
            $t->decimal('trm', 12, 2)->nullable();
            $t->decimal('factor', 12, 4)->nullable();
            $t->date('fecha_requerida')->nullable();
            $t->date('fecha_creacion')->nullable();
            $t->date('fecha_calculada')->nullable();
            $t->date('fecha_entrega')->nullable();
            $t->string('ejecutivo')->nullable();
            $t->unsignedBigInteger('ejecutivo_id')->nullable();
            $t->string('estado_externo')->nullable();
            $t->string('estado_interno')->nullable();
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
            $t->text('bench_text')->nullable();
            $t->string('bench_image')->nullable();
            $t->timestamps();
        });

        Schema::create('project_marketing', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->json('marketing')->nullable();
            $t->json('calidad')->nullable();
            $t->text('obs_marketing')->nullable();
            $t->text('obs_calidad')->nullable();
            $t->string('marca')->nullable();
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

        Schema::create('project_marketing_variants', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id');
            $t->string('referencia', 200)->nullable();
            $t->string('codigo', 100)->nullable();
            $t->string('aplicacion', 200)->nullable();
            $t->decimal('dosis', 8, 2)->nullable();
            $t->string('color_etiqueta', 50)->nullable();
            $t->text('claims')->nullable();
            $t->timestamps();
        });
    }
}
