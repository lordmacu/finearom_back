<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ProjectCategoryTypeCoherenceTest extends ProjectFieldsTestCase
{
    private int $personalCare;
    private int $homeCare;
    private int $shampoo;
    private int $detergente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->personalCare = DB::table('product_categories')->insertGetId([
            'name' => 'Personal Care', 'slug' => 'personal_care', 'active' => true,
        ]);
        $this->homeCare = DB::table('product_categories')->insertGetId([
            'name' => 'Home Care', 'slug' => 'home_care', 'active' => true,
        ]);

        $this->shampoo = DB::table('project_product_types')->insertGetId([
            'nombre' => 'Shampoo', 'product_category_id' => $this->personalCare, 'grupo' => 'Capilar', 'active' => true,
        ]);
        $this->detergente = DB::table('project_product_types')->insertGetId([
            'nombre' => 'Detergente líquido', 'product_category_id' => $this->homeCare, 'grupo' => 'Laundry', 'active' => true,
        ]);
    }

    private function project(): Project
    {
        return Project::create(['nombre' => 'Proyecto Coherencia', 'fecha_creacion' => today()]);
    }

    public function test_acepta_un_tipo_que_pertenece_a_la_categoria(): void
    {
        $project = $this->project();

        $this->putJson("/api/projects/{$project->id}", [
            'nombre'              => $project->nombre,
            'product_category_id' => $this->personalCare,
            'product_id'          => $this->shampoo,
        ])->assertOk();

        $project->refresh();
        $this->assertSame($this->shampoo, $project->product_id);
        $this->assertSame($this->personalCare, $project->product_category_id);
    }

    /**
     * El select del formulario ya filtra, pero un POST directo podría guardar
     * un shampoo en Home Care y dejar el proyecto incoherente.
     */
    public function test_rechaza_un_tipo_de_otra_categoria(): void
    {
        $project = $this->project();

        $this->putJson("/api/projects/{$project->id}", [
            'nombre'              => $project->nombre,
            'product_category_id' => $this->homeCare,
            'product_id'          => $this->shampoo,
        ])->assertStatus(422)->assertJsonValidationErrors('product_id');
    }

    public function test_permite_guardar_el_tipo_sin_categoria(): void
    {
        $project = $this->project();

        $this->putJson("/api/projects/{$project->id}", [
            'nombre'     => $project->nombre,
            'product_id' => $this->shampoo,
        ])->assertOk();
    }

    /** Cambiar de categoría sin cambiar el tipo debe fallar, no guardar basura. */
    public function test_cambiar_de_categoria_dejando_el_tipo_viejo_es_rechazado(): void
    {
        $project = $this->project();
        $project->update(['product_category_id' => $this->personalCare, 'product_id' => $this->shampoo]);

        $this->putJson("/api/projects/{$project->id}", [
            'nombre'              => $project->nombre,
            'product_category_id' => $this->homeCare,
            'product_id'          => $this->shampoo,
        ])->assertStatus(422);
    }
}
