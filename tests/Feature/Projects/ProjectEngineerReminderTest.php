<?php

namespace Tests\Feature\Projects;

use App\Models\User;

class ProjectEngineerReminderTest extends ProjectMailTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->template('project_engineer_reminder', 'Falta asignar ingeniero de desarrollo — proyecto #|project_id| — |project_name|', '|created_date|');
    }

    private function projectSinIngeniero(array $attrs = []): \App\Models\Project
    {
        return $this->project(array_merge([
            'fecha_creacion'  => today()->subDays(2),
            'estado_externo'  => 'En espera',
            'estado_interno'  => 'En proceso',
        ], $attrs));
    }

    public function test_recuerda_solo_a_proyectos_de_mas_de_24h_sin_ingeniero(): void
    {
        $this->projectSinIngeniero();                                                       // debe recordar
        $this->projectSinIngeniero(['fecha_creacion' => today()]);                          // muy reciente
        $this->projectSinIngeniero(['desarrollador_id' => $this->user->id]);                // ya asignado
        $this->projectSinIngeniero(['estado_externo' => 'Ganado']);                         // cerrado
        $this->projectSinIngeniero(['estado_interno' => 'Entregado']);                      // entregado

        $this->artisan('projects:engineer-reminders')->assertSuccessful();

        $this->assertCount(1, $this->sentMessages());
        $email = $this->sentMessages()->first()->getOriginalMessage();
        // El recordatorio va a la ejecutiva del proyecto (el usuario Tester del fixture)
        $this->assertSame(['tester@finearom.co'], $this->addresses($email->getTo()));
        $this->assertStringContainsString('Falta asignar ingeniero de desarrollo', $email->getSubject());
    }

    public function test_dry_run_no_envia_nada(): void
    {
        $this->projectSinIngeniero();

        $this->artisan('projects:engineer-reminders', ['--dry-run' => true])->assertSuccessful();

        $this->assertCount(0, $this->sentMessages());
    }
}
