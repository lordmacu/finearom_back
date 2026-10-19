<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectEvaluation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
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

    public function test_guarda_si_el_bench_esta_disponible_y_el_listado(): void
    {
        $project = $this->project();

        $this->putJson("/api/projects/{$project->id}/evaluation", ['tipos' => ['Estabilidad'], 'bench_disponible' => true, 'bench_listado' => 'Opción X'])->assertOk();
        $eval = $project->fresh()->evaluation;
        $this->assertTrue($eval->bench_disponible);
        $this->assertSame('Opción X', $eval->bench_listado);

        $this->putJson("/api/projects/{$project->id}/evaluation", ['bench_disponible' => 'tal vez'])->assertStatus(422);
    }

    public function test_si_el_bench_no_esta_disponible_exige_imagen_y_descripcion(): void
    {
        Mail::fake();
        $project = $this->project();

        $this->putJson("/api/projects/{$project->id}/evaluation", ['bench_disponible' => false])
            ->assertStatus(422)->assertJsonValidationErrors(['bench_text', 'bench_image']);

        // solo descripción: falta la imagen
        $this->putJson("/api/projects/{$project->id}/evaluation", ['bench_disponible' => false, 'bench_text' => 'Perfume X'])
            ->assertStatus(422)->assertJsonValidationErrors(['bench_image'])->assertJsonMissingValidationErrors(['bench_text']);

        $this->assertNull($project->fresh()->evaluation, 'no se guardó nada');
        Mail::assertNothingSent();
    }

    public function test_con_imagen_y_descripcion_guarda_y_envia_un_solo_correo_de_compra(): void
    {
        Mail::fake();
        Storage::fake('local');
        $ejecutiva = \App\Models\User::create(['name' => 'Ejecutiva', 'email' => 'ejecutiva@finearom.co', 'password' => bcrypt('x')]);
        $project = Project::create(['nombre' => 'Proyecto Bench', 'fecha_creacion' => today(), 'ejecutivo_id' => $ejecutiva->id, 'ejecutivo' => 'Ejecutiva']);

        $this->post("/api/projects/{$project->id}/evaluation", [
            '_method' => 'PUT', 'bench_disponible' => '0', 'bench_text' => 'Perfume X 100 ml', 'bench_image' => UploadedFile::fake()->image('b.png'),
        ])->assertOk();

        Mail::assertSent(\App\Mail\ProjectBenchRequestMail::class, function ($mail) {
            return $mail->hasTo('coordinadora.comercial@finearom.com')
                && $mail->hasCc('monica.castano@finearom.com')
                && $mail->hasCc('ejecutiva@finearom.co')
                && str_contains($mail->render(), 'Buen día, requerimos tu amable ayuda con la compra del bench relacionado a continuación')
                && str_contains($mail->render(), 'Perfume X 100 ml')
                && count($mail->attachments()) === 1;
        });
        $this->assertNotNull($project->fresh()->evaluation->bench_correo_enviado_at);

        // guardar de nuevo no repite el correo
        $this->putJson("/api/projects/{$project->id}/evaluation", ['bench_disponible' => false, 'bench_text' => 'Perfume X 100 ml (ajustado)'])->assertOk();
        Mail::assertSent(\App\Mail\ProjectBenchRequestMail::class, 1);
    }

    public function test_si_el_bench_pasa_a_disponible_se_limpia_y_un_nuevo_no_disponible_vuelve_a_pedir_la_compra(): void
    {
        Mail::fake();
        Storage::fake('local');
        $project = $this->project();
        $this->post("/api/projects/{$project->id}/evaluation", ['_method' => 'PUT', 'bench_disponible' => '0', 'bench_text' => 'A', 'bench_image' => UploadedFile::fake()->image('b.png')])->assertOk();

        $this->putJson("/api/projects/{$project->id}/evaluation", ['bench_disponible' => true])->assertOk();
        $this->assertNull($project->fresh()->evaluation->bench_correo_enviado_at);

        $this->putJson("/api/projects/{$project->id}/evaluation", ['bench_disponible' => false, 'bench_text' => 'B'])->assertOk();
        Mail::assertSent(\App\Mail\ProjectBenchRequestMail::class, 2);
    }

    public function test_si_el_correo_falla_igual_se_guarda_la_evaluacion_y_se_reintenta_despues(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp caído'));
        Storage::fake('local');
        $project = $this->project();

        $this->post("/api/projects/{$project->id}/evaluation", ['_method' => 'PUT', 'bench_disponible' => '0', 'bench_text' => 'A', 'bench_image' => UploadedFile::fake()->image('b.png')])
            ->assertOk();

        $eval = $project->fresh()->evaluation;
        $this->assertSame('A', $eval->bench_text);
        $this->assertNull($eval->bench_correo_enviado_at, 'queda sin marcar para reintentar en el próximo guardado');
    }

    public function test_quien_no_manda_bench_disponible_no_queda_obligado(): void
    {
        Mail::fake();
        $project = $this->project();

        $this->putJson("/api/projects/{$project->id}/evaluation", ['tipos' => ['Tasa de evaporación']])->assertOk();

        $this->assertSame(['Tasa de evaporación'], $project->fresh()->evaluation->tipos);
        Mail::assertNothingSent();
    }
}
