<?php

// Script para ver permisos y menús dinámicos
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== PERMISOS EN LA BASE DE DATOS ===\n\n";

$permissions = DB::table('permissions')
    ->select('id', 'name')
    ->orderBy('name')
    ->get();

echo "Total de permisos: " . $permissions->count() . "\n\n";

foreach ($permissions as $permission) {
    echo sprintf("%-5d %s\n", $permission->id, $permission->name);
}

echo "\n\n=== MENÚS EN LA BASE DE DATOS ===\n\n";

$menus = DB::table('menus')
    ->select('id', 'name', 'link', 'icon')
    ->orderBy('id')
    ->get();

echo "Total de menús: " . $menus->count() . "\n\n";

foreach ($menus as $menu) {
    echo sprintf("ID: %-3d | %-30s | Link: %-30s\n", 
        $menu->id, 
        $menu->name, 
        $menu->link ?? 'N/A'
    );
    
    // Buscar items de este menú
    $items = DB::table('menu_items')
        ->where('menu_id', $menu->id)
        ->select('id', 'name', 'link')
        ->get();
    
    if ($items->count() > 0) {
        echo "  Sub-items:\n";
        foreach ($items as $item) {
            echo sprintf("    ├─ %-30s | Link: %s\n", $item->name, $item->link ?? 'N/A');
        }
    }
    echo "\n";
}

echo "\n=== JSON OUTPUT ===\n\n";
echo "PERMISSIONS:\n";
echo json_encode($permissions->pluck('name')->toArray(), JSON_PRETTY_PRINT);
echo "\n\n";

echo "MENUS:\n";
echo json_encode($menus->toArray(), JSON_PRETTY_PRINT);
