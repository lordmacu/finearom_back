<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectMarketing;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MarketingUploadTest extends ProjectFieldsTestCase
{
    private function project(): Project
    {
        return Project::create(['nombre' => 'Proyecto X', 'fecha_creacion' => today()]);
    }

    public function test_sube_archivo_de_marketing_y_lo_guarda_en_el_array(): void
    {
        $project = $this->project();

        $res = $this->post("/api/projects/{$project->id}/marketing-upload", [
            'file'  => UploadedFile::fake()->image('etiqueta.png'),
            'field' => 'catalog_etiquetas',
        ]);

        $res->assertStatus(201);

        $marketing = ProjectMarketing::where('project_id', $project->id)->first();
        $this->assertCount(1, $marketing->catalog_etiquetas);
    }

    /**
     * La URL que devuelve el upload debe poder abrirse. Las rutas viven en
     * routes/api.php, que RouteServiceProvider monta bajo el prefijo /api.
     */
    public function test_la_url_devuelta_por_el_upload_apunta_a_una_ruta_existente(): void
    {
        $project = $this->project();

        $res = $this->post("/api/projects/{$project->id}/marketing-upload", [
            'file'  => UploadedFile::fake()->image('etiqueta.png'),
            'field' => 'catalog_etiquetas',
        ]);

        $url  = $res->json('data.url');
        $path = parse_url($url, PHP_URL_PATH);

        $this->assertStringStartsWith(
            '/api/projects/',
            $path,
            "El upload devolvió [$path]: sin el prefijo /api la URL cae en el SPA, no en el backend."
        );
    }

    public function test_no_permite_borrar_un_archivo_de_otro_proyecto(): void
    {
        $victima = $this->project();
        $atacante = $this->project();

        $this->post("/api/projects/{$victima->id}/marketing-upload", [
            'file'  => UploadedFile::fake()->image('secreto.png'),
            'field' => 'catalog_etiquetas',
        ]);

        $pathVictima = ProjectMarketing::where('project_id', $victima->id)->first()->catalog_etiquetas[0];

        $res = $this->delete("/api/projects/{$atacante->id}/marketing-upload", [
            'field' => 'catalog_etiquetas',
            'path'  => $pathVictima,
        ]);

        $res->assertStatus(422);
        Storage::disk('local')->assertExists($pathVictima);
    }

    public function test_la_descarga_valida_el_campo_contra_la_lista_blanca(): void
    {
        $project = $this->project();
        Storage::disk('local')->put("marketing-otra_cosa/{$project->id}/x.txt", 'contenido');

        $res = $this->get("/api/projects/{$project->id}/marketing-upload/otra_cosa/x.txt");

        $this->assertNotEquals(
            200,
            $res->getStatusCode(),
            'La ruta de descarga sirvió un `field` fuera de la lista blanca.'
        );
    }
}
