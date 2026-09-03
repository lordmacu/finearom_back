<?php

namespace Tests\Unit;

use App\Support\ProductTypeCatalog;
use PHPUnit\Framework\TestCase;

class ProductTypeCatalogDataTest extends TestCase
{
    public function test_tiene_los_73_tipos_de_la_lista(): void
    {
        $this->assertCount(73, ProductTypeCatalog::tipos());
    }

    public function test_cada_tipo_tiene_categoria(): void
    {
        foreach (ProductTypeCatalog::tipos() as $tipo) {
            $this->assertNotEmpty($tipo['categoria'], "'{$tipo['nombre']}' quedó sin categoría");
        }
    }

    public function test_el_reparto_por_categoria_y_grupo_coincide_con_la_hoja(): void
    {
        $conteo = [];
        foreach (ProductTypeCatalog::tipos() as $tipo) {
            $clave = $tipo['categoria'] . '/' . ($tipo['grupo'] ?? '-');
            $conteo[$clave] = ($conteo[$clave] ?? 0) + 1;
        }

        $this->assertSame([
            'personal_care/Corporal' => 18,
            'personal_care/Capilar'  => 9,
            'personal_care/Facial'   => 9,
            'home_care/Laundry'      => 12,
            'home_care/Limpieza'     => 16,
            'air_care/-'             => 9,
        ], $conteo);
    }

    /** Un mismo nombre no puede repetirse dentro de la misma categoría. */
    public function test_no_hay_nombres_duplicados_por_categoria(): void
    {
        $vistos = [];
        foreach (ProductTypeCatalog::tipos() as $tipo) {
            $clave = $tipo['categoria'] . '|' . ProductTypeCatalog::normalizar($tipo['nombre']);
            $this->assertArrayNotHasKey($clave, $vistos, "Duplicado: {$tipo['nombre']} en {$tipo['categoria']}");
            $vistos[$clave] = true;
        }
    }

    public function test_los_46_legacy_estan_todos_mapeados(): void
    {
        $this->assertCount(46, ProductTypeCatalog::legacy());
    }

    /** Si un legacy se fusiona, el nombre destino debe existir en la lista nueva. */
    public function test_las_fusiones_apuntan_a_un_tipo_de_la_lista(): void
    {
        $nuevos = array_map(
            fn ($t) => ProductTypeCatalog::normalizar($t['nombre']),
            ProductTypeCatalog::tipos()
        );

        foreach (ProductTypeCatalog::legacy() as $legacy) {
            if (empty($legacy['fusionar_con'])) {
                continue;
            }
            $this->assertContains(
                ProductTypeCatalog::normalizar($legacy['fusionar_con']),
                $nuevos,
                "'{$legacy['nombre']}' se fusiona con '{$legacy['fusionar_con']}', que no está en la lista nueva"
            );
        }
    }

    public function test_normalizar_ignora_tildes_y_mayusculas(): void
    {
        $this->assertSame(
            ProductTypeCatalog::normalizar('JABÓN LÍQUIDO'),
            ProductTypeCatalog::normalizar('jabon liquido')
        );
    }
}
