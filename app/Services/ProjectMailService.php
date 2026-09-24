<?php

namespace App\Services;

use App\Mail\ProjectThreadMail;
use App\Models\Process;
use App\Models\Project;
use App\Models\ProjectAreaDeliveryLog;
use App\Models\User;
use App\Support\HtmlText;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Correos internos de un proyecto, todos en un mismo hilo.
 *
 * Convención por acción: template `project_{accion}`. Destinatarios: solo la
 * lista "Proyectos" (processes.process_type = 'proyectos') y, una vez
 * asignado, el ingeniero de desarrollo. Nunca la ejecutiva ni el cliente. El primer correo exitoso
 * abre el hilo (se guardan su Message-ID y asunto); los siguientes van como
 * respuesta.
 *
 * Tras cada envío exitoso se guarda en `email_snapshot` el resumen por áreas
 * tal como se comunicó: el correo de modificación (sendUpdate) se arma con el
 * diff entre el estado actual y ese snapshot.
 */
class ProjectMailService
{
    /** Las entregas solo se registran con el hilo abierto por el correo de creación. */
    /**
     * Filas que salieron del correo pero siguen en snapshots viejos. 'Campo'
     * aplica a cualquier sección; 'Sección: Campo' solo a esa sección.
     */
    /** Filas renombradas: en snapshots viejos se leen con el nombre nuevo. */
    private const CAMPOS_RENOMBRADOS = ['Rango' => 'Rango de precio'];

    /** Secciones que salieron del correo (en snapshots viejos no cuentan como cambio). */
    private const SECCIONES_RETIRADAS = ['Estado comercial'];

    private const CAMPOS_RETIRADOS = [
        'Volumen (Kg/año)', 'Factor', 'Observaciones aplicación', 'Tipo de envase', 'Marketing: Observaciones',
        // Sin campo en el formulario: no se pueden ver ni editar (Precio: ahora se usa el rango)
        'Precio (USD)',
        'Internacional', 'Costo perfumación específico (USD)', 'Máx. variantes permitidas', 'Evaluaciones: Metodología', 'Evaluaciones: Referencia benchmark',
    ];

    public const SIN_HILO_ENTREGA = 'Primero la ejecutiva debe enviar la creación del proyecto: las entregas van en ese mismo hilo de correo.';

    public function __construct(
        private readonly EmailTemplateService $templates,
    ) {}

    /**
     * Envía el correo de una acción del proyecto. Nunca lanza: un fallo de
     * correo no debe romper la acción que lo disparó.
     *
     * @return bool true si el correo salió; false si no había destinatarios o falló
     */
    public function send(Project $project, string $action, array $extra = []): bool
    {
        $recipients = $this->recipients($project);

        if (empty($recipients)) {
            Log::info('Correo de proyecto omitido: sin destinatarios', [
                'project_id' => $project->id,
                'action'     => $action,
            ]);
            return false;
        }

        return $this->sendMessage($project, $action, $recipients, $extra);
    }

    /**
     * Correo de asignación del ingeniero de desarrollo: va al ingeniero (TO)
     * y a la lista "Proyectos" (CC), dentro del hilo si ya existe.
     */
    public function sendEngineerAssigned(Project $project, User $engineer): bool
    {
        $recipients = $this->normalizar(collect([$engineer->email])->merge($this->configuredEmails()));

        if (empty($recipients)) {
            Log::info('Correo de asignación omitido: ingeniero sin email válido', [
                'project_id' => $project->id,
                'engineer_id' => $engineer->id,
            ]);
            return false;
        }

        return $this->sendMessage($project, 'engineer_assigned', $recipients, [
            'engineer_name' => $engineer->name,
        ]);
    }

    /**
     * Recordatorio diario del cron: el proyecto lleva más de 24 h sin ingeniero
     * de desarrollo asignado. Va a la lista "Proyectos".
     */
    public function sendEngineerReminder(Project $project): bool
    {
        $recipients = $this->normalizar($this->configuredEmails());

        if (empty($recipients)) {
            Log::info('Recordatorio de ingeniero omitido: lista "Proyectos" vacía', [
                'project_id' => $project->id,
            ]);
            return false;
        }

        return $this->sendMessage($project, 'engineer_reminder', $recipients, [
            'created_date' => $project->fecha_creacion?->format('d/m/Y') ?? '—',
        ]);
    }

    /**
     * Correo de entrega del flujo de desarrollo (botón "Entregado" del
     * ingeniero): lista las variantes de marketing con sus referencias.
     * Destinatarios: lista "Proyectos" + ingeniero asignado.
     *
     * Si el área ya estaba entregada ($isUpdate), el correo sale con el
     * template `project_development_updated`: avisa si cambiaron las
     * referencias desde el último correo y trae la tabla completa de
     * variantes y referencias como quedaron.
     */
    public function sendDevelopmentDelivered(Project $project, User $engineer, bool $isUpdate = false): bool
    {
        $action = $isUpdate ? 'development_updated' : 'development_delivered';

        $recipients = $this->recipients($project);

        if (empty($recipients)) {
            Log::info('Correo de entrega de desarrollo omitido: sin destinatarios', [
                'project_id' => $project->id,
            ]);
            return false;
        }

        $extra = [
            'engineer_name'  => $engineer->name,
            'variants_table' => $this->variantsDeliveredTable($project),
        ];

        if ($isUpdate) {
            $extra['changes_table'] = $this->referencesChangedNotice($project);
        }

        return $this->sendMessage($project, $action, $recipients, $extra);
    }

    /**
     * Aviso de si las variantes o las referencias cambiaron desde el último
     * correo (correo de actualización de desarrollo). No repite los valores:
     * la lista completa como quedó va justo debajo, en |variants_table|.
     */
    private function referencesChangedNotice(Project $project): string
    {
        $antes   = $project->email_snapshot['Marketing']['Variantes de marketing'] ?? null;
        $despues = $this->sections($project)['Marketing']['Variantes de marketing'] ?? null;

        if ($antes === $despues) {
            return '<p style="font-size:13px;color:#6b7280;">Sin cambios en las variantes ni las referencias desde el último correo.</p>';
        }

        return '<p style="font-size:13px;color:#6b7280;">Cambiaron las variantes o las referencias; abajo está la lista completa como quedó.</p>';
    }

    /**
     * Correo de una entrega de área externa (Aplicaciones, Evaluaciones,
     * Marketing, Regulatoria o P. Especiales): las notas de ESA entrega viajan
     * en el cuerpo (HTML del editor) y sus adjuntos van adjuntos al correo.
     * Destinatarios: lista "Proyectos" + ingeniero asignado.
     *
     * El correo se atribuye al ÁREA, no a la persona que oprimió el botón
     * (`delivered_by` = "Regulatoria", "P. Especiales"…): a quien lo recibe le
     * importa qué área entregó, no quién de ese equipo lo hizo.
     *
     * Template según el tipo de entrega de la bitácora: parcial → `…_partial`,
     * final → `…_delivered`, actualización → `…_updated` (avisa si las notas
     * cambiaron respecto a las de la entrega anterior).
     */
    public function sendAreaDelivered(Project $project, string $area, ProjectAreaDeliveryLog $log, ?string $notasAntes = null): bool
    {
        $cfg = match ($area) {
            'marketing'    => ['prefix' => 'marketing',    'delivered' => 'marketing_delivered',   'label' => 'Marketing'],
            'aplicaciones' => ['prefix' => 'applications', 'delivered' => 'applications_ready',    'label' => 'Aplicaciones'],
            'regulatoria'  => ['prefix' => 'regulatoria',  'delivered' => 'regulatoria_delivered', 'label' => 'Regulatoria'],
            'especiales'   => ['prefix' => 'especiales',   'delivered' => 'especiales_delivered',  'label' => 'P. Especiales'],
            default        => ['prefix' => 'evaluation',   'delivered' => 'evaluation_delivered',  'label' => 'Evaluaciones'],
        };

        $action = match ($log->tipo) {
            ProjectAreaDeliveryLog::PARCIAL       => "{$cfg['prefix']}_partial",
            ProjectAreaDeliveryLog::ACTUALIZACION => "{$cfg['prefix']}_updated",
            default                               => $cfg['delivered'],
        };

        $recipients = $this->recipients($project);

        if (empty($recipients)) {
            Log::info('Correo de entrega de área omitido: sin destinatarios', [
                'project_id' => $project->id,
                'area'       => $area,
            ]);
            return false;
        }

        $notas = $log->notas;

        // Las notas son HTML del editor enriquecido (CkEditor) — van crudas al correo
        $extra = [
            'delivered_by'  => $cfg['label'],
            'tipo_entrega'  => match ($log->tipo) {
                ProjectAreaDeliveryLog::PARCIAL       => 'Entrega parcial',
                ProjectAreaDeliveryLog::ACTUALIZACION => 'Actualización',
                default                               => 'Entrega final',
            },
            'notas_entrega' => HtmlText::isBlank($notas) ? null : $notas,
        ];

        // Solo se avisa QUE cambiaron: las notas anteriores siguen visibles
        // en el correo previo del hilo y en la bitácora.
        if ($log->tipo === ProjectAreaDeliveryLog::ACTUALIZACION) {
            $extra['changes_table'] = $notasAntes !== $notas && !HtmlText::isBlank($notas)
                ? '<p style="font-size:13px;color:#6b7280;">Se actualizaron las notas de entrega; abajo están como quedaron.</p>'
                : '<p style="font-size:13px;color:#6b7280;">Las notas no cambiaron; esta actualización trae nuevos adjuntos.</p>';
        }

        $attachments = $log->files
            ->map(fn ($f) => ['path' => $f->path, 'name' => $f->nombre_original])
            ->all();

        return $this->sendMessage($project, $action, $recipients, $extra, $attachments);
    }

    /** Variantes de marketing con sus referencias, en HTML (correo de entrega). */
    private function variantsDeliveredTable(Project $project): string
    {
        $project->loadMissing('marketingVariants.references');

        $td    = 'style="border:1px solid #dddddd;padding:8px 12px;text-align:left;font-size:13px;vertical-align:top;"';
        $tdKey = 'style="border:1px solid #dddddd;padding:8px 12px;text-align:left;font-size:13px;background-color:#f8f8f8;font-weight:bold;color:#1F2345;white-space:nowrap;vertical-align:top;"';

        $number = fn ($value) => number_format((float) $value, 2, ',', '.');

        $html = '';
        foreach ($project->marketingVariants as $variant) {
            $refs = $variant->references
                ->map(fn ($r) => collect([
                    $r->referencia,
                    $r->codigo ? "({$r->codigo})" : null,
                    $r->aplicacion,
                    $r->dosis !== null ? $number($r->dosis) . '%' : null,
                ])->filter()->implode(' '))
                ->filter()
                ->map('e')
                ->implode('<br>');

            $label = collect([$variant->nombre, $variant->claims])->filter()->implode(' — ');

            $html .= '<tr><td ' . $tdKey . '>' . e($label) . '</td><td ' . $td . '>'
                . ($refs ?: '—')
                . '</td></tr>';
        }

        if ($html === '') {
            return '<p style="font-size:13px;color:#6b7280;">El proyecto no tiene variantes de marketing con referencias.</p>';
        }

        return '<table style="width:100%;border-collapse:collapse;margin:16px 0;"><tbody>' . $html . '</tbody></table>';
    }

    /**
     * Núcleo de envío: renderiza el template `project_{accion}`, lo manda al
     * primero de $recipients (TO) con el resto en CC, mantiene el hilo y
     * actualiza el snapshot. Nunca lanza.
     *
     * @param string[] $recipients
     * @param array<int, array{path: string, name: string}> $attachments
     */
    private function sendMessage(Project $project, string $action, array $recipients, array $extra = [], array $attachments = []): bool
    {
        $key = "project_{$action}";

        try {
            $sections = $this->sections($project);
            $rendered = $this->templates->renderTemplate($key, array_merge($this->variables($project, $sections), $extra));

            $threadId = $project->email_thread_message_id;
            $subject  = $threadId ? 'Re: ' . $project->email_thread_subject : $rendered['subject'];

            // Un solo hilo por proyecto y lo abre SOLO el correo de creación:
            // cualquier otro correo sin hilo no sale (ni suelto ni como raíz)
            $opensThread = !$threadId && $action === 'created';
            if (!$threadId && !$opensThread) {
                Log::info('Correo de proyecto omitido: el hilo aún no existe (solo la creación lo abre)', [
                    'project_id' => $project->id,
                    'action'     => $action,
                ]);
                return false;
            }

            // Al abrir el hilo fijamos nosotros el Message-ID: getMessageId()
            // del SentMessage queda sobreescrito por el id de cola del servidor
            // SMTP (SmtpTransport::parseMessageId) y las respuestas quedarían
            // apuntando a un id que no es el header del correo raíz
            $newThreadId = $opensThread ? $this->newThreadMessageId($project) : null;

            $to   = array_shift($recipients);
            $sent = Mail::to($to)->cc($recipients)->send(new ProjectThreadMail(
                rendered: $rendered,
                threadSubject: $subject,
                inReplyTo: $threadId,
                processType: $key,
                trackingMetadata: ['project_id' => $project->id],
                mailAttachments: $attachments,
                threadMessageId: $newThreadId,
            ));

            if ($sent) {
                $fill = ['email_snapshot' => $sections];
                if ($opensThread) {
                    $fill['email_thread_message_id'] = $newThreadId;
                    $fill['email_thread_subject']    = $subject;
                }
                $project->forceFill($fill)->save();
            }

            return $sent !== null;
        } catch (\Throwable $e) {
            Log::error('Error enviando correo de proyecto', [
                'project_id' => $project->id,
                'action'     => $action,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Correo de modificación: diff entre el estado actual del proyecto y el
     * snapshot del último correo enviado, dentro del mismo hilo. Nunca lanza.
     *
     * @return array{sent: bool, reason: ?string} reason: sin_hilo | sin_cambios | fallo
     */
    public function sendUpdate(Project $project): array
    {
        if (!$project->email_thread_message_id) {
            return ['sent' => false, 'reason' => 'sin_hilo'];
        }

        $current = $this->sections($project);
        // Proyectos con hilo de antes del snapshot: todo aparece como "nuevo"
        $changes = $this->diffSections($project->email_snapshot ?? [], $current);

        if (empty($changes)) {
            return ['sent' => false, 'reason' => 'sin_cambios'];
        }

        $sent = $this->send($project, 'updated', ['changes_table' => $this->renderChanges($changes)]);

        return ['sent' => $sent, 'reason' => $sent ? null : 'fallo'];
    }

    /**
     * Destinatarios de todo correo del hilo de proyectos: solo la lista
     * "Proyectos" (Configuración → Procesos) + el ingeniero de desarrollo
     * cuando está asignado. Nunca la ejecutiva ni el cliente.
     * Primero = TO, resto = CC. Sin inválidos ni duplicados.
     *
     * @return string[]
     */
    private function recipients(Project $project): array
    {
        return $this->normalizar($this->configuredEmails()->push($this->engineerEmail($project)));
    }

    /** Emails de la lista "Proyectos" (cada fila admite varios separados por coma). */
    private function configuredEmails(): \Illuminate\Support\Collection
    {
        return Process::where('process_type', Process::PROYECTOS)
            ->pluck('email')
            ->flatMap(fn ($value) => explode(',', (string) $value));
    }

    /** @return string[] */
    private function normalizar(\Illuminate\Support\Collection $emails): array
    {
        return $emails
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    private function engineerEmail(Project $project): ?string
    {
        return $project->desarrollador_id
            ? $project->desarrollador?->email
            : null;
    }

    /**
     * Variables base disponibles para todos los templates de proyecto.
     * Los valores vacíos se muestran como "—".
     */
    private function variables(Project $project, ?array $sections = null): array
    {
        $project->loadMissing(['client', 'prospect']);

        $number = fn ($value) => ($value === null || $value === '')
            ? null
            : number_format((float) $value, 2, ',', '.');

        $range = ($project->rango_min !== null || $project->rango_max !== null)
            ? ($number($project->rango_min) ?? '—') . ' – ' . ($number($project->rango_max) ?? '—')
            : null;

        $vars = [
            'project_id'      => (string) $project->id,
            'project_name'    => $project->nombre,
            'client_name'     => $project->client?->client_name
                ?? $project->prospect?->nombre
                ?? $project->nombre_prospecto
                ?? 'Sin cliente',
            'project_type'    => $project->tipo,
            'product_type'    => $project->tipo_producto,
            'executive'       => $project->ejecutivo,
            'created_by'      => auth()->user()?->name,
            'required_date'   => $project->fecha_requerida?->format('d/m/Y'),
            'calculated_date' => $project->fecha_calculada?->format('d/m/Y'),
            'volume'          => $number($project->potencial_anual_kg),
            'range'           => $range,
            'project_url'     => rtrim((string) config('app.frontend_url'), '/') . '/projects/' . $project->id,
        ];

        $table = $this->renderSections($sections ?? $this->sections($project));

        $vars = array_map(fn ($value) => ($value === null || $value === '') ? '—' : $value, $vars);
        $vars['project_table'] = $table;

        return $vars;
    }

    /**
     * Ficha completa del proyecto agrupada por las áreas del formulario
     * (Información general, Desarrollo, Evaluaciones, Regulatoria, Marketing):
     * área => [etiqueta => valor listo para mostrar]. Omite filas sin dato y
     * áreas no marcadas en `secciones_visibles` (general va siempre). El
     * estado comercial no va en los correos.
     * Es la fuente única del correo de creación, del `email_snapshot`
     * y del diff del correo de modificación.
     */
    private function sections(Project $project): array
    {
        $project->loadMissing([
            'client', 'prospect', 'product', 'productCategory', 'envelopeTypes',
            'sample', 'application', 'evaluation',
            'marketingYCalidad', 'marketingVariants.references',
            'variants.benchmarkReference', 'variants.proposals.finearomReference',
            'fragrances.fineFragrance', 'desarrollador',
        ]);

        $number = fn ($value) => ($value === null || $value === '')
            ? null
            : number_format((float) $value, 2, ',', '.');

        $visible = $project->secciones_visibles
            ?? ['desarrollo', 'evaluaciones', 'regulatoria', 'marketing', 'comercial'];

        $origen = $project->homologacion
            ? 'Homologación'
            : ($project->proactivo ? 'Proactivo' : 'Reactivo');

        $range = ($project->rango_min !== null || $project->rango_max !== null)
            ? ($number($project->rango_min) ?? '—') . ' – ' . ($number($project->rango_max) ?? '—')
            : null;

        $filled = fn ($rows) => array_filter($rows, fn ($value) => $value !== null && $value !== '');

        $sections = [];

        $sections['Información general'] = $filled([
            'Proyecto'            => $project->nombre,
            'Cliente'             => $project->client?->client_name
                ?? $project->prospect?->nombre
                ?? $project->nombre_prospecto
                ?? 'Sin cliente',
            'Tipo'                => $project->tipo,
            'Ejecutivo'           => $project->ejecutivo,
            'Ingeniero de desarrollo' => $project->desarrollador?->name,
            'Categoría'           => $project->productCategory?->name,
            'Tipo de producto'    => $project->product?->nombre ?? $project->tipo_producto,
            'Origen'              => $origen,
            'Base del cliente'    => $project->base_cliente ? 'Sí' : null,
            'Fecha de creación'   => $project->fecha_creacion?->format('d/m/Y'),
            'Fecha requerida'     => $project->fecha_requerida?->format('d/m/Y'),
            'Fecha calculada'     => $project->fecha_calculada?->format('d/m/Y'),
            'Potencial anual (Kg)'  => $number($project->potencial_anual_kg),
            'Potencial anual (USD)' => $number($project->potencial_anual_usd),
            'Rango de precio'     => $range,
            'TRM'                 => $number($project->trm),
            'Dosis (%)'           => $number($project->dosis),
            'Fecha de entrega'    => $project->fecha_entrega?->format('d/m/Y'),
            'Costo perfumación (USD/ton)' => $number($project->costo_perfumacion_tonelada),
        ]);

        if (in_array('desarrollo', $visible, true)) {
            $sample = $project->sample;
            $application = $project->application;

            $unidades = fn ($n) => $n . ((int) $n === 1 ? ' unidad' : ' unidades');

            // Muestra en gramos y copias en unidades
            $muestra = $sample?->cantidad !== null
                ? 'Cantidad: ' . $number($sample->cantidad) . ' g'
                    . ($sample->cantidad_copias ? ' · Copias: ' . $unidades($sample->cantidad_copias) : '')
                : null;

            // Dosis en porcentaje y cantidad en unidades
            $aplicacion = $application?->dosis !== null
                ? 'Dosis: ' . $number($application->dosis) . ' %'
                    . ($application->cantidad_aplicacion ? ' · Cantidad: ' . $unidades($application->cantidad_aplicacion) : '')
                : null;

            $refName = fn ($ref) => $ref ? trim(($ref->codigo ?? '') . ' ' . ($ref->nombre ?? '')) : null;

            $variantes = $project->variants
                ->map(function ($v) use ($number, $refName) {
                    $line = collect([
                        $v->nombre,
                        $v->categoria,
                        $v->descripcion,
                        $v->observaciones,
                        $v->benchmarkReference ? 'Bench: ' . $refName($v->benchmarkReference) : null,
                    ])->filter()->implode(' — ');

                    $proposals = $v->proposals
                        ->map(fn ($p) => '↳ Propuesta' . ($p->definitiva ? ' (definitiva)' : '') . ': '
                            . collect([
                                $refName($p->finearomReference),
                                $p->total_propuesta !== null ? 'USD ' . $number($p->total_propuesta) : null,
                                $p->total_propuesta_cop !== null ? 'COP ' . $number($p->total_propuesta_cop) : null,
                            ])->filter()->implode(' — '))
                        ->filter()
                        ->implode('<br>');

                    return collect([$line ?: null, $proposals ?: null])->filter()->implode('<br>');
                })
                ->filter()
                ->implode('<br>');

            $fragancias = $project->fragrances
                ->map(fn ($f) => collect([
                    $f->fineFragrance?->nombre,
                    $f->gramos !== null ? $number($f->gramos) . ' g' : null,
                    $f->margen !== null ? 'Margen: ' . $number($f->margen) . '%' : null,
                    $f->precio_calculado !== null ? 'Precio: USD ' . $number($f->precio_calculado) : null,
                    $f->notas,
                ])->filter()->implode(' — '))
                ->filter()
                ->implode('<br>');

            $sections['Desarrollo'] = $filled([
                'Envase'                 => $project->envelopeTypes->pluck('name')->implode(', ') ?: null,
                'Selección de envase para aplicación' => $project->seleccion_envase_aplicacion ? 'Sí' : null,
                'Tipo de etiquetado'     => $project->tipo_etiquetado,
                'Muestra aceite'         => $muestra,
                'Observaciones muestra'  => $sample?->observaciones,
                'Aplicación'             => $aplicacion,
                'Variantes'              => $variantes ?: null,
                'Fragancias finas'       => $fragancias ?: null,
            ]);
        }

        if (in_array('evaluaciones', $visible, true)) {
            $evaluation = $project->evaluation;

            $sections['Evaluaciones'] = $filled([
                'Tipos de evaluación' => !empty($evaluation?->tipos) ? implode(', ', $evaluation->tipos) : null,
                'Benchmark'           => $evaluation?->bench_text,
                'Observación'         => $evaluation?->observacion,
            ]);
        }

        if (in_array('regulatoria', $visible, true)) {
            $sections['Regulatoria'] = $filled([
                'Entregables'  => !empty($project->marketingYCalidad?->calidad) ? implode(', ', $project->marketingYCalidad->calidad) : null,
                'Observaciones' => $project->marketingYCalidad?->obs_calidad,
            ]);
        }

        if (in_array('marketing', $visible, true)) {
            $marketing = $project->marketingYCalidad;

            $variantesMarketing = $project->marketingVariants
                ->map(function ($v) use ($number) {
                    $line = collect([
                        $v->nombre,
                        $v->claims,
                        $v->color_etiqueta ? 'Color: ' . $v->color_etiqueta : null,
                    ])->filter()->implode(' — ');

                    $refs = $v->references
                        ->map(fn ($r) => '↳ ' . collect([
                            $r->referencia,
                            $r->codigo,
                            $r->aplicacion,
                            $r->dosis !== null ? $number($r->dosis) . '%' : null,
                        ])->filter()->implode(' — '))
                        ->filter()
                        ->implode('<br>');

                    return collect([$line ?: null, $refs ?: null])->filter()->implode('<br>');
                })
                ->filter()
                ->implode('<br>');

            $sections['Marketing'] = $filled([
                'Entregables'          => !empty($marketing?->marketing) ? implode(', ', $marketing->marketing) : null,
                'Marca'                => $marketing?->marca,
                'Descripción detallada' => $marketing?->descripcion_detallada,
                'Fecha entrega marketing' => $marketing?->fecha_entrega_marketing?->format('d/m/Y'),
                'Variantes de marketing' => $variantesMarketing ?: null,
            ]);
        }

        // El estado comercial no va en los correos del proyecto

        return $sections;
    }

    /** Resumen por áreas en HTML (variable |project_table|). */
    private function renderSections(array $sections): string
    {
        $td    = 'style="border:1px solid #dddddd;padding:8px 12px;text-align:left;font-size:13px;"';
        $tdKey = 'style="border:1px solid #dddddd;padding:8px 12px;text-align:left;font-size:13px;background-color:#f8f8f8;font-weight:bold;color:#1F2345;white-space:nowrap;vertical-align:top;"';
        $h3    = 'style="margin:20px 0 6px;font-size:14px;color:#1F2345;border-bottom:2px solid #1F2345;padding-bottom:4px;"';

        $html = '';
        foreach ($sections as $title => $rows) {
            if (empty($rows)) {
                continue;
            }
            $html .= '<h3 ' . $h3 . '>' . e($title) . '</h3>';
            $html .= '<table style="width:100%;border-collapse:collapse;margin:0 0 8px;"><tbody>';
            foreach ($rows as $label => $value) {
                $html .= '<tr><td ' . $tdKey . '>' . e($label) . '</td><td ' . $td . '>' . $this->cellValue($value) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        return $html;
    }

    /**
     * Diff entre dos snapshots de secciones:
     * área => [ ['campo' =>, 'valor' =>], ... ] — `valor` es cómo quedó el
     * campo (null = sin dato). El valor anterior solo sirve para detectar el
     * cambio: no viaja al correo, queda en el correo previo del hilo.
     */
    private function diffSections(array $before, array $current): array
    {
        $changes = [];

        $areas = array_unique(array_merge(array_keys($before), array_keys($current)));
        foreach ($areas as $area) {
            if (in_array($area, self::SECCIONES_RETIRADAS, true)) {
                continue;
            }
            $oldRows = [];
            foreach ($before[$area] ?? [] as $label => $value) {
                $oldRows[self::CAMPOS_RENOMBRADOS[$label] ?? $label] = $value;
            }
            $newRows = $current[$area] ?? [];

            $labels = array_unique(array_merge(array_keys($oldRows), array_keys($newRows)));
            foreach ($labels as $label) {
                // Campos que ya no se muestran: no son un cambio del proyecto
                if (in_array($label, self::CAMPOS_RETIRADOS, true) || in_array("{$area}: {$label}", self::CAMPOS_RETIRADOS, true)) {
                    continue;
                }
                $old = $oldRows[$label] ?? null;
                $new = $newRows[$label] ?? null;
                if ($old === $new || $this->mismoSinUnidades($label, $old, $new)) {
                    continue;
                }
                $changes[$area][] = ['campo' => $label, 'valor' => $new];
            }
        }

        return $changes;
    }

    /**
     * Muestra y Aplicación empezaron a llevar unidades (g, %, unidades): un
     * snapshot viejo sin unidades con los mismos números no es un cambio.
     */
    private function mismoSinUnidades(string $label, ?string $old, ?string $new): bool
    {
        if (!in_array($label, ['Muestra aceite', 'Aplicación'], true) || $old === null || $new === null) {
            return false;
        }
        $limpiar = fn (string $v) => preg_replace('/ (g|%|unidades|unidad)(?= ·|$)/u', '', $v);

        return $limpiar($old) === $limpiar($new);
    }

    /** Cambios en HTML por área: Campo / Cómo quedó (variable |changes_table|). */
    private function renderChanges(array $changes): string
    {
        $td    = 'style="border:1px solid #dddddd;padding:8px 12px;text-align:left;font-size:13px;vertical-align:top;"';
        $tdKey = 'style="border:1px solid #dddddd;padding:8px 12px;text-align:left;font-size:13px;background-color:#f8f8f8;font-weight:bold;color:#1F2345;white-space:nowrap;vertical-align:top;"';
        $th    = 'style="border:1px solid #dddddd;padding:8px 12px;text-align:left;font-size:12px;background-color:#1F2345;color:#ffffff;"';
        $h3    = 'style="margin:20px 0 6px;font-size:14px;color:#1F2345;border-bottom:2px solid #1F2345;padding-bottom:4px;"';

        $html = '';
        foreach ($changes as $area => $rows) {
            $html .= '<h3 ' . $h3 . '>' . e($area) . '</h3>';
            $html .= '<table style="width:100%;border-collapse:collapse;margin:0 0 8px;"><thead><tr>'
                . '<th ' . $th . '>Campo</th><th ' . $th . '>Cómo quedó</th>'
                . '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $html .= '<tr>'
                    . '<td ' . $tdKey . '>' . e($row['campo']) . '</td>'
                    . '<td ' . $td . '>' . $this->cellValue($row['valor']) . '</td>'
                    . '</tr>';
            }
            $html .= '</tbody></table>';
        }

        return $html;
    }

    /**
     * Message-ID propio para el correo que abre el hilo (único y reconocible
     * por proyecto). Se guarda en email_thread_message_id y las respuestas lo
     * referencian en In-Reply-To / References.
     */
    private function newThreadMessageId(Project $project): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'finearom.com';

        return 'project-' . $project->id . '-' . Str::lower((string) Str::ulid()) . '@' . $host;
    }

    /** Celda HTML segura: las listas con <br> se escapan por línea; null = "—". */
    private function cellValue(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return str_contains($value, '<br>')
            ? implode('<br>', array_map('e', explode('<br>', $value)))
            : e($value);
    }
}
