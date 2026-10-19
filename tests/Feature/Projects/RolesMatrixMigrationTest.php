<?php

namespace Tests\Feature\Projects;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Matriz de roles revisada por el negocio (2026-10-19) y creación del rol Regulatoria. */
class RolesMatrixMigrationTest extends ProjectFieldsTestCase
{
    private function migrar(): void
    {
        (require database_path('migrations/2026_10_19_100000_apply_roles_matrix_proyectos.php'))->up();
    }

    private function rol(string $nombre, array $permisos = []): Role
    {
        $r = Role::create(['name' => $nombre, 'guard_name' => 'web']);
        foreach ($permisos as $p) {
            $r->givePermissionTo(Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']));
        }

        return $r;
    }

    private function tiene(string $rol, string $permiso): bool
    {
        return Role::where('name', $rol)->first()->permissions()->where('name', $permiso)->exists();
    }

    public function test_crea_el_rol_regulatoria_con_sus_seis_permisos(): void
    {
        $this->migrar();

        $permisos = Role::where('name', 'Regulatoria')->first()->permissions()->pluck('name')->sort()->values()->all();
        $this->assertSame([
            'fine fragrance list', 'finearom reference list', 'producto terminado list',
            'project deliver', 'project list', 'raw material list',
        ], $permisos);
    }

    public function test_aplica_los_cambios_y_respeta_el_resto(): void
    {
        $this->rol('Desarrollo', ['project list', 'project assign engineer', 'project deliver']);
        $this->rol('Director de Investigación y Desarrollo', ['project list', 'project delete']);
        $this->rol('Marketing', ['project list']);
        $this->rol('Comercial', ['project list', 'project edit']);

        $this->migrar();

        // Desarrollo pierde asignar ingeniero y gana materias primas y producto terminado completos
        $this->assertFalse($this->tiene('Desarrollo', 'project assign engineer'));
        $this->assertTrue($this->tiene('Desarrollo', 'raw material edit'));
        $this->assertTrue($this->tiene('Desarrollo', 'producto terminado create'));
        $this->assertTrue($this->tiene('Desarrollo', 'project deliver'), 'lo que no se menciona se conserva');

        // El director asigna ingeniero, ve el factor y conserva eliminar
        $this->assertTrue($this->tiene('Director de Investigación y Desarrollo', 'project assign engineer'));
        $this->assertTrue($this->tiene('Director de Investigación y Desarrollo', 'project factor view'));
        $this->assertTrue($this->tiene('Director de Investigación y Desarrollo', 'project delete'));

        $this->assertTrue($this->tiene('Marketing', 'project deliver'));
        $this->assertTrue($this->tiene('Comercial', 'project external status'));
        $this->assertTrue($this->tiene('Comercial', 'project potential edit'));
        $this->assertTrue($this->tiene('Comercial', 'project edit'), 'lo que no se menciona se conserva');
    }

    public function test_es_idempotente_y_tolera_roles_inexistentes(): void
    {
        $this->rol('Desarrollo', ['project assign engineer']);

        $this->migrar();
        $this->migrar();

        $this->assertSame(1, Role::where('name', 'Regulatoria')->count());
        $this->assertFalse($this->tiene('Desarrollo', 'project assign engineer'));
    }
}
