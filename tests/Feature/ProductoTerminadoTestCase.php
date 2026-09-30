<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Base para los tests del módulo Producto Terminado. Mismo criterio que
 * CorazonTestCase: esquema mínimo a mano sobre sqlite en memoria, nunca
 * RefreshDatabase (backend/.env apunta a DB_DATABASE=finearom_prod).
 * Incluye también las tablas necesarias para probar la cascada de costos
 * de RawMaterialController::updateCost (Tarea 4).
 */
abstract class ProductoTerminadoTestCase extends TestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        $this->buildSchema();

        $this->user = User::create([
            'name'     => 'Tester',
            'email'    => 'tester@finearom.co',
            'password' => bcrypt('secret'),
        ]);

        $this->givePermissions([
            'producto terminado list', 'producto terminado create',
            'producto terminado edit', 'producto terminado delete',
            'raw material list', 'raw material create', 'raw material edit', 'raw material delete',
        ]);
        $this->actingAs($this->user, 'sanctum');
    }

    protected function givePermissions(array $names): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->user->permissions()->detach();
        $this->user->unsetRelation('permissions');

        foreach ($names as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $this->user->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function materiaPrima(array $overrides = []): \App\Models\RawMaterial
    {
        return \App\Models\RawMaterial::create(array_merge([
            'codigo'  => 'MP-'.uniqid(),
            'nombre'  => 'Bergamota',
            'tipo'    => 'materia_prima',
            'unidad'  => 'kg',
            'activo'  => true,
        ], $overrides));
    }

    protected function corazon(array $overrides = []): \App\Models\RawMaterial
    {
        return \App\Models\RawMaterial::create(array_merge([
            'codigo'      => 'COR-'.uniqid(),
            'nombre'      => 'Corazón',
            'tipo'        => 'corazon',
            'unidad'      => 'kg',
            'descripcion' => 'x',
            'activo'      => false,
        ], $overrides));
    }

    private function buildSchema(): void
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

        Schema::create('raw_materials', function (Blueprint $t) {
            $t->id();
            $t->string('codigo', 100)->nullable();
            $t->string('nombre');
            $t->string('cas')->nullable();
            $t->text('descriptores')->nullable();
            $t->string('tipo');
            $t->string('unidad');
            $t->decimal('costo_unitario', 12, 4)->default(0);
            $t->decimal('stock_disponible', 12, 4)->default(0);
            $t->text('descripcion')->nullable();
            $t->string('proveedor')->nullable();
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });

        Schema::create('raw_material_price_history', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('raw_material_id');
            $t->decimal('costo_anterior', 12, 4);
            $t->decimal('costo_nuevo', 12, 4);
            $t->unsignedBigInteger('changed_by')->nullable();
            $t->timestamps();
        });

        Schema::create('corazon_formula_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('corazon_id');
            $t->unsignedBigInteger('raw_material_id');
            $t->decimal('porcentaje', 7, 4);
            $t->text('notas')->nullable();
            $t->timestamps();
        });

        Schema::create('reference_formula_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('finearom_reference_id');
            $t->unsignedBigInteger('raw_material_id');
            $t->decimal('porcentaje', 6, 4);
            $t->text('notas')->nullable();
            $t->timestamps();
        });

        Schema::create('productos_terminados', function (Blueprint $t) {
            $t->id();
            $t->string('consecutivo', 20)->nullable()->unique();
            $t->string('codigo', 100)->unique();
            $t->string('nombre');
            $t->decimal('costo_unitario', 12, 4)->default(0);
            $t->boolean('activo')->default(false);
            $t->timestamps();
        });

        Schema::create('producto_formula_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('producto_terminado_id');
            $t->unsignedBigInteger('raw_material_id');
            $t->decimal('porcentaje', 7, 4);
            $t->text('notas')->nullable();
            $t->timestamps();
        });
    }
}
