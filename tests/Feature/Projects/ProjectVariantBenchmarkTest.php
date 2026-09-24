<?php

namespace Tests\Feature\Projects;

use App\Models\ProjectVariant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * El benchmark de cada variante es una imagen + descripción propias.
 */
class ProjectVariantBenchmarkTest extends ProjectMailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_crear_variante_con_imagen_y_descripcion_de_benchmark(): void
    {
        $project = $this->project();

        $id = $this->post("/api/projects/{$project->id}/variants", [
            'nombre'                => 'Lavanda',
            'descripcion'           => 'Floral fresca',
            'benchmark_descripcion' => 'Similar a la marca líder',
            'benchmark_imagen'      => UploadedFile::fake()->image('bench.png'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $v = ProjectVariant::find($id);
        $this->assertSame('Similar a la marca líder', $v->benchmark_descripcion);
        Storage::disk('local')->assertExists($v->benchmark_imagen);

        $this->get("/api/projects/{$project->id}/variants/{$id}/benchmark-image")->assertOk();
    }

    public function test_reemplazar_y_quitar_la_imagen_borra_el_archivo_anterior(): void
    {
        $project = $this->project();
        $id = $this->post("/api/projects/{$project->id}/variants", [
            'nombre' => 'Coco', 'benchmark_imagen' => UploadedFile::fake()->image('a.png'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $primera = ProjectVariant::find($id)->benchmark_imagen;

        // Reemplazo (multipart con _method=PUT, como el frontend)
        $this->post("/api/projects/{$project->id}/variants/{$id}", [
            '_method' => 'PUT', 'nombre' => 'Coco', 'benchmark_imagen' => UploadedFile::fake()->image('b.png'),
        ], ['Accept' => 'application/json'])->assertOk();
        $segunda = ProjectVariant::find($id)->benchmark_imagen;
        Storage::disk('local')->assertMissing($primera);
        Storage::disk('local')->assertExists($segunda);

        // Quitar
        $this->putJson("/api/projects/{$project->id}/variants/{$id}", ['nombre' => 'Coco', 'remove_benchmark_imagen' => true])->assertOk();
        $this->assertNull(ProjectVariant::find($id)->benchmark_imagen);
        Storage::disk('local')->assertMissing($segunda);
    }

    public function test_ya_no_se_guardan_categoria_observaciones_ni_referencia_de_benchmark(): void
    {
        $project = $this->project();

        $id = $this->postJson("/api/projects/{$project->id}/variants", [
            'nombre' => 'Menta', 'categoria' => 'Home Care', 'observaciones' => 'x', 'benchmark_reference_id' => 5,
        ])->assertCreated()->json('data.id');

        $v = ProjectVariant::find($id);
        $this->assertNull($v->categoria);
        $this->assertNull($v->observaciones);
        $this->assertNull($v->benchmark_reference_id);
    }

    public function test_el_correo_muestra_la_descripcion_del_benchmark(): void
    {
        \App\Models\Process::create(['name' => 'Lab', 'email' => 'lab@finearom.co', 'process_type' => 'proyectos']);
        $this->template('project_created', 'Nuevo proyecto #|project_id|');
        $project = $this->project();
        ProjectVariant::create(['project_id' => $project->id, 'nombre' => 'Lavanda', 'benchmark_descripcion' => 'Como la líder', 'benchmark_imagen' => 'x.png', 'categoria' => 'Home Care']);

        $this->postJson("/api/projects/{$project->id}/send-creation")->assertOk();

        $html = $this->sentMessages()->last()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('Benchmark: Como la líder', $html);
        $this->assertStringContainsString('(con imagen de benchmark)', $html);
        $this->assertStringNotContainsString('Home Care', $html);
    }
}
