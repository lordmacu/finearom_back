<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

try {
    echo "Conectando a la base de datos...\n";
    $pdo = DB::connection()->getPdo();
    echo "✓ Conexión exitosa\n\n";
    
    echo "Consultando permisos...\n";
    $permissions = \App\Models\Permission::all();
    echo "✓ Total de permisos: " . $permissions->count() . "\n\n";
    
    if ($permissions->count() > 0) {
        echo "Primeros 5 permisos:\n";
        foreach ($permissions->take(5) as $permission) {
            echo "  - ID: {$permission->id}, Nombre: {$permission->name}, Guard: {$permission->guard_name}\n";
        }
    }
    
    echo "\n✓ Todo funciona correctamente!\n";
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
