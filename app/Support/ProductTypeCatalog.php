<?php

namespace App\Support;

/**
 * Catálogo de tipos de producto por categoría.
 *
 * `tipos()` es la lista que mercadeo definió (73 tipos, 3 categorías).
 * `legacy()` mapea los 46 tipos heredados que ya viven en
 * `project_product_types` y que 8.328 proyectos referencian: ninguno se borra,
 * cada uno recibe categoría y grupo. Los que corresponden a un tipo de la lista
 * nueva se fusionan (se renombran) para no ofrecer el mismo producto dos veces.
 */
class ProductTypeCatalog
{
    public const PERSONAL_CARE = 'personal_care';
    public const HOME_CARE     = 'home_care';
    public const AIR_CARE      = 'air_care';
    public const FINE_FRAGRANCE = 'fine_fragrance';

    /** Compara nombres ignorando tildes, mayúsculas y espacios repetidos. */
    public static function normalizar(string $nombre): string
    {
        $sinTildes = strtr(
            mb_strtolower(trim($nombre)),
            ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']
        );

        return preg_replace('/\s+/', ' ', $sinTildes);
    }

    /** @return array<int, array{nombre: string, categoria: string, grupo: ?string}> */
    public static function tipos(): array
    {
        return array_merge(
            self::conCategoria(self::PERSONAL_CARE, 'Corporal', [
                'Jabón líquido', 'Jabón de tocador', 'Jabón espumoso', 'Antibacterial',
                'Crema', 'Mantequilla corporal', 'Protector solar', 'Cera depiladora',
                'Crema depiladora', 'Gel de ducha', 'Sales de baño', 'Espuma de baño',
                'Jabón íntimo', 'Exfoliante', 'Desodorante', 'Body mist', 'Cera',
                'Desodorante pies',
            ]),
            self::conCategoria(self::PERSONAL_CARE, 'Capilar', [
                'Shampoo', 'Acondicionador', 'Crema para peinar', 'Aceite capilar',
                'Hair mist', 'Termoprotector', 'Gel fijador', 'Spray fijador', 'Silicona',
            ]),
            self::conCategoria(self::PERSONAL_CARE, 'Facial', [
                'After shave', 'Desmaquillante', 'Agua micelar facial', 'Tónico facial',
                'Serum facial', 'Crema facial', 'Mascarilla facial', 'Protector solar facial',
                'Shampoo seco',
            ]),
            self::conCategoria(self::HOME_CARE, 'Laundry', [
                'Detergente líquido', 'Detergente polvo', 'Suavizante', 'Perfumador de telas',
                'Quita manchas polvo', 'Quita manchas líquido', 'Pre planchado',
                'Jabón de lavar en barra', 'Blanqueador', 'Perlas perfumadas',
                'Sales perfumadas', 'Spray refrescante de telas',
            ]),
            self::conCategoria(self::HOME_CARE, 'Limpieza', [
                'APC', 'Cloro líquido', 'Cloro en gel', 'Limpiador pisos especializados',
                'Cera', 'Limpiador baños', 'Gel inodoro', 'Pastillas sanitario',
                'Limpiador antisarro', 'Limpiador de vidrios y espejos', 'Lavaloza líquido',
                'Lavaloza crema', 'Desengrasante', 'Polvo abrasivo', 'Shampoo tapicería',
                'APC mascotas',
            ]),
            self::conCategoria(self::AIR_CARE, null, [
                'Ambientador en aerosol', 'Difusor de varitas', 'Difusor eléctrico',
                'Gel ambientador', 'Microdifusor', 'Neutralizador de olores',
                'Eliminador de malos olores', 'Spray para ambientes',
                'Neutralizador olores baño',
            ]),
        );
    }

    /**
     * Los 46 heredados. `fusionar_con` no vacío significa que ese registro ES
     * un tipo de la lista nueva y se renombra; el resto conserva su nombre.
     *
     * @return array<int, array{nombre: string, categoria: ?string, grupo: ?string, fusionar_con: ?string}>
     */
    public static function legacy(): array
    {
        $pc = self::PERSONAL_CARE;
        $hc = self::HOME_CARE;
        $ac = self::AIR_CARE;
        $ff = self::FINE_FRAGRANCE;

        return [
            // ── Fusiones: mismo producto con otro nombre ──────────────────
            ['nombre' => 'GEL DE DUCHA',         'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => 'Gel de ducha'],
            ['nombre' => 'DESODORANTE',          'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => 'Desodorante'],
            ['nombre' => 'JABÓN DE TOCADOR',     'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => 'Jabón de tocador'],
            ['nombre' => 'JABÓN LÍQUIDO',        'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => 'Jabón líquido'],
            ['nombre' => 'ANTIBACTERIAL',        'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => 'Antibacterial'],
            ['nombre' => 'EXFOLIANTE',           'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => 'Exfoliante'],
            ['nombre' => 'PROTECTOR SOLAR',      'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => 'Protector solar'],
            ['nombre' => 'CERA DE DEPILACIÓN',   'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => 'Cera depiladora'],
            ['nombre' => 'ACONDICIONADOR',       'categoria' => $pc, 'grupo' => 'Capilar',  'fusionar_con' => 'Acondicionador'],
            ['nombre' => 'SHAMPOO',              'categoria' => $pc, 'grupo' => 'Capilar',  'fusionar_con' => 'Shampoo'],
            ['nombre' => 'CREMA FACIAL',         'categoria' => $pc, 'grupo' => 'Facial',   'fusionar_con' => 'Crema facial'],
            ['nombre' => 'TÓNICO FACIAL',        'categoria' => $pc, 'grupo' => 'Facial',   'fusionar_con' => 'Tónico facial'],
            ['nombre' => 'SÉRUM',                'categoria' => $pc, 'grupo' => 'Facial',   'fusionar_con' => 'Serum facial'],
            ['nombre' => 'MASCARILLA',           'categoria' => $pc, 'grupo' => 'Facial',   'fusionar_con' => 'Mascarilla facial'],
            ['nombre' => 'DETERGENTE LÍQUIDO',   'categoria' => $hc, 'grupo' => 'Laundry',  'fusionar_con' => 'Detergente líquido'],
            ['nombre' => 'DETERGENTE EN POLVO',  'categoria' => $hc, 'grupo' => 'Laundry',  'fusionar_con' => 'Detergente polvo'],
            ['nombre' => 'SUAVIZANTE DE ROPA',   'categoria' => $hc, 'grupo' => 'Laundry',  'fusionar_con' => 'Suavizante'],
            ['nombre' => 'JABÓN EN BARRA ROPA',  'categoria' => $hc, 'grupo' => 'Laundry',  'fusionar_con' => 'Jabón de lavar en barra'],
            ['nombre' => 'LAVALOZA LÍQUIDO',     'categoria' => $hc, 'grupo' => 'Limpieza', 'fusionar_con' => 'Lavaloza líquido'],
            ['nombre' => 'LAVALOZA CREMA',       'categoria' => $hc, 'grupo' => 'Limpieza', 'fusionar_con' => 'Lavaloza crema'],

            // ── Sin equivalente en la lista nueva: conservan su nombre ────
            ['nombre' => 'AMBIENTADOR',          'categoria' => $ac, 'grupo' => null,       'fusionar_con' => null],
            ['nombre' => 'LIMPIAPISOS',          'categoria' => $hc, 'grupo' => 'Limpieza', 'fusionar_con' => null],
            ['nombre' => 'HIPOCLORITO',          'categoria' => $hc, 'grupo' => 'Limpieza', 'fusionar_con' => null],
            ['nombre' => 'CERAS POLIMÉRICAS',    'categoria' => $hc, 'grupo' => 'Limpieza', 'fusionar_con' => null],
            ['nombre' => 'TALCO',                'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => null],
            ['nombre' => 'TALCO CORPORAL',       'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => null],
            ['nombre' => 'CREMA CORPORAL',       'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => null],
            ['nombre' => 'ESPUMA DE AFEITAR',    'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => null],
            ['nombre' => 'ACEITES',              'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => null],
            ['nombre' => 'LOCIÓN CORPORAL',      'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => null],
            ['nombre' => 'VASELINA',             'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => null],
            ['nombre' => 'CREMA DE MANOS',       'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => null],
            ['nombre' => 'JABÓN EN BARRA',       'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => null],
            ['nombre' => 'GEL ANTIBACTERIAL',    'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => null],
            ['nombre' => 'QUITA ESMALTE',        'categoria' => $pc, 'grupo' => 'Corporal', 'fusionar_con' => null],
            ['nombre' => 'TRATAMIENTO CAPILAR',  'categoria' => $pc, 'grupo' => 'Capilar',  'fusionar_con' => null],
            ['nombre' => 'GEL CAPILAR',          'categoria' => $pc, 'grupo' => 'Capilar',  'fusionar_con' => null],
            ['nombre' => 'CERA CABELLO',         'categoria' => $pc, 'grupo' => 'Capilar',  'fusionar_con' => null],
            ['nombre' => 'POLVO FACIAL',         'categoria' => $pc, 'grupo' => 'Facial',   'fusionar_con' => null],
            ['nombre' => 'LABIAL',               'categoria' => $pc, 'grupo' => 'Facial',   'fusionar_con' => null],
            ['nombre' => 'LOCIÓN FACIAL',        'categoria' => $pc, 'grupo' => 'Facial',   'fusionar_con' => null],
            ['nombre' => 'SPLASH',               'categoria' => $ff, 'grupo' => null,       'fusionar_con' => null],
            ['nombre' => 'COLONIA',              'categoria' => $ff, 'grupo' => null,       'fusionar_con' => null],
            ['nombre' => 'PERFUME',              'categoria' => $ff, 'grupo' => null,       'fusionar_con' => null],
            ['nombre' => 'ACEITE ESENCIAL',      'categoria' => $ff, 'grupo' => null,       'fusionar_con' => null],
            ['nombre' => 'OTRO',                 'categoria' => null, 'grupo' => null,      'fusionar_con' => null],
        ];
    }

    /** @return array<int, array{nombre: string, categoria: string, grupo: ?string}> */
    private static function conCategoria(string $categoria, ?string $grupo, array $nombres): array
    {
        return array_map(
            fn ($nombre) => ['nombre' => $nombre, 'categoria' => $categoria, 'grupo' => $grupo],
            $nombres
        );
    }
}
