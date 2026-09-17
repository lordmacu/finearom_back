<?php

namespace Tests\Feature\Projects;

use App\Models\Client;
use App\Models\Process;
use App\Services\ProjectMailService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Los correos del hilo de proyectos son solo internos: nunca van al correo
 * del cliente ni al del prospecto, ni lo muestran en el cuerpo.
 */
class ProjectMailInternalOnlyTest extends ProjectMailTestCase
{
    public function test_ningun_correo_del_hilo_va_al_cliente_ni_al_prospecto(): void
    {
        Storage::fake('local');
        Schema::table('clients', fn (Blueprint $t) => $t->string('email')->nullable());
        $this->givePermissions(['project list', 'project deliver']);

        $this->template('project_created', 'Nuevo proyecto #|project_id|', '|client_name| |project_table|');
        $this->template('project_applications_partial', 'Parcial #|project_id|', '|client_name| |notas_entrega|');
        Process::create(['name' => 'Interno', 'email' => 'lab@finearom.co', 'process_type' => 'proyectos']);

        $client  = Client::forceCreate(['client_name' => 'Cliente Externo SAS', 'email' => 'compras@cliente.com']);
        $conCliente   = $this->project(['client_id' => $client->id, 'nombre_prospecto' => null, 'estado_interno' => 'En proceso']);
        $conProspecto = $this->project(['email_prospecto' => 'prospecto@externo.com', 'estado_interno' => 'En proceso']);

        foreach ([$conCliente, $conProspecto] as $project) {
            app(ProjectMailService::class)->send($project, 'created');
            $this->postJson("/api/projects/{$project->id}/aplicaciones/entregar", ['tipo' => 'parcial', 'notas' => 'Avance'])->assertOk();
        }

        $this->assertCount(4, $this->sentMessages());
        foreach ($this->sentMessages() as $sent) {
            $email = $sent->getOriginalMessage();
            $todos = array_merge($this->addresses($email->getTo()), $this->addresses($email->getCc()), $this->addresses($email->getBcc()));

            $this->assertNotContains('compras@cliente.com', $todos);
            $this->assertNotContains('prospecto@externo.com', $todos);
            $this->assertStringNotContainsString('compras@cliente.com', $email->getHtmlBody());
            $this->assertStringNotContainsString('prospecto@externo.com', $email->getHtmlBody());
            $this->assertSame(['lab@finearom.co'], $this->addresses($email->getTo()));
        }
    }
}
