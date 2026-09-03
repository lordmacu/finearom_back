<?php

use App\Support\ProductTypeSynchronizer;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Asigna categoría y grupo a los tipos heredados y siembra la lista de
     * mercadeo. No borra ningún tipo: los 8.328 proyectos y las 405 filas de
     * time_applications que los referencian quedan intactos.
     */
    public function up(): void
    {
        (new ProductTypeSynchronizer())->run();
    }

    public function down(): void
    {
        // Sin vuelta atrás: revertir implicaría adivinar qué tipos existían
        // antes y con qué nombre. El rollback del esquema (la migración
        // anterior) borra las columnas, que es lo que hay que deshacer.
    }
};
