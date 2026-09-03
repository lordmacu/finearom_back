<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectEvaluation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class EvaluationBenchTest extends ProjectFieldsTestCase
{
    private function project(): Project
    {
        return Project::create(['nombre' => 'Proyecto Bench', 'fecha_creacion' => today()]);
    }

    public function test_guarda_bench_text_y_tipos(): void
    {
        $project = $this->project();

        $this->putJson("/api/projects/{$project->id}/evaluation", [
            'tipos'      => ['Estabilidad', 'Panel sensorial'],
            'bench_text' => 'Comparado contra referencia comercial',
        ])->assertOk();

        $eval = ProjectEvaluation::where('project_id', $project->id)->first();
        $this->assertSame(['Estabilidad', 'Panel sensorial'], $eval->tipos);
        $this->assertSame('Comparado contra referencia comercial', $eval->bench_text);
    }

    /**
     * El tab manda el formulario con imagen como multipart usando method
     * spoofing (_method=PUT) y `tipos[]`, que es lo único que PHP parsea.
     */
    public function test_guarda_la_imagen_del_bench_por_multipart_con_method_spoofing(): void
    {
        $project = $this->project();

        $this->post("/api/projects/{$project->id}/evaluation", [
            '_method'     => 'PUT',
            'tipos'       => ['Estabilidad'],
            'bench_text'  => 'con imagen',
            'bench_image' => UploadedFile::fake()->image('bench.png'),
        ])->assertOk();

        $eval = ProjectEvaluation::where('project_id', $project->id)->first();
        $this->assertSame(['Estabilidad'], $eval->tipos);
        $this->assertNotNull($eval->bench_image);
        Storage::disk('local')->assertExists($eval->bench_image);
    }

    public function test_remove_bench_image_borra_el_archivo_y_limpia_la_columna(): void
    {
        $project = $this->project();
        Storage::disk('local')->put("evaluation-bench/{$project->id}/vieja.png", 'x');
        ProjectEvaluation::create([
            'project_id'  => $project->id,
            'bench_image' => "evaluation-bench/{$project->id}/vieja.png",
        ]);

        $this->putJson("/api/projects/{$project->id}/evaluation", [
            'remove_bench_image' => true,
        ])->assertOk();

        $this->assertNull(ProjectEvaluation::where('project_id', $project->id)->first()->bench_image);
        Storage::disk('local')->assertMissing("evaluation-bench/{$project->id}/vieja.png");
    }

    /**
     * Guardar sólo el texto no debe borrar la imagen ya cargada.
     */
    public function test_guardar_sin_tocar_la_imagen_la_conserva(): void
    {
        $project = $this->project();
        ProjectEvaluation::create([
            'project_id'  => $project->id,
            'bench_image' => "evaluation-bench/{$project->id}/vieja.png",
        ]);

        $this->putJson("/api/projects/{$project->id}/evaluation", [
            'bench_text' => 'solo texto',
        ])->assertOk();

        $this->assertSame(
            "evaluation-bench/{$project->id}/vieja.png",
            ProjectEvaluation::where('project_id', $project->id)->first()->bench_image
        );
    }

    /**
     * Sólo quien puede editar puede ver la imagen: un usuario de consulta
     * (project list) no puede abrirla.
     */
    public function test_usuario_de_solo_lectura_puede_ver_la_imagen_del_bench(): void
    {
        $project = $this->project();
        Storage::disk('local')->put("evaluation-bench/{$project->id}/b.png", 'x');
        ProjectEvaluation::create([
            'project_id'  => $project->id,
            'bench_image' => "evaluation-bench/{$project->id}/b.png",
        ]);

        $this->givePermissions(['project list']);

        $this->get("/api/projects/{$project->id}/evaluation/bench-image")
            ->assertOk();
    }
}
