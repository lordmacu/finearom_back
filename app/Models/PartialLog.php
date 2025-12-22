<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Class PartialLog
 *
 * Represents audit log entries for changes made to Partial records.
 * Tracks who made changes, when, and what was modified.
 * Includes functionality to restore deleted partials and revert changes.
 *
 * @package App\Models
 */
class PartialLog extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'partial_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'partial_id',
        'user_id',
        'user_name',
        'action',
        'old_values',
        'new_values',
        'changed_fields',
        'ip_address',
        'user_agent'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'changed_fields' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'user_agent', // Ocultar por defecto para APIs, pero disponible si se necesita
    ];

    /**
     * The attributes that should be appended to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'action_description',
        'formatted_date',
        'time_ago',
        'changed_fields_count',
        'changes_summary',
        'browser_info',
        'restore_type',
        'restore_description'
    ];

    /**
     * Get the partial record that this log entry belongs to.
     *
     * @return BelongsTo
     */
    public function partial(): BelongsTo
    {
        return $this->belongsTo(Partial::class);
    }

    /**
     * Get the user who made this change.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ============================
    // SCOPES
    // ============================

    /**
     * Scope to filter logs by action type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $action
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByAction($query, $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope to filter logs by user.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get logs from a specific date range.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $startDate
     * @param string $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope to get recent logs (last N days).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query, $days = 7)
    {
        return $query->where('created_at', '>=', Carbon::now()->subDays($days));
    }

    // ============================
    // ATTRIBUTES
    // ============================

    /**
     * Get a human-readable description of the action performed.
     *
     * @return string
     */
    public function getActionDescriptionAttribute()
    {
        $descriptions = [
            'created' => 'Parcial creado',
            'updated' => 'Parcial actualizado',
            'deleted' => 'Parcial eliminado',
            'restored' => 'Parcial restaurado'
        ];

        return $descriptions[$this->action] ?? ucfirst($this->action);
    }

    /**
     * Get a formatted timestamp for display.
     *
     * @return string
     */
    public function getFormattedDateAttribute()
    {
        return $this->created_at->format('d/m/Y H:i:s');
    }

    /**
     * Get human-readable time difference.
     *
     * @return string
     */
    public function getTimeAgoAttribute()
    {
        return $this->created_at->diffForHumans();
    }

    /**
     * Get the number of fields that were changed in this log entry.
     *
     * @return int
     */
    public function getChangedFieldsCountAttribute()
    {
        return $this->changed_fields ? count($this->changed_fields) : 0;
    }

    /**
     * Get a summary of the changes made.
     *
     * @return array
     */
    public function getChangesSummaryAttribute()
    {
        if (!$this->changed_fields) {
            return [];
        }

        $summary = [];
        foreach ($this->changed_fields as $field => $change) {
            $summary[] = [
                'field' => $this->getFieldDisplayName($field),
                'old_value' => $change['old'],
                'new_value' => $change['new'],
                'formatted_old' => $this->formatFieldValue($field, $change['old']),
                'formatted_new' => $this->formatFieldValue($field, $change['new'])
            ];
        }

        return $summary;
    }

    /**
     * Get browser information from user agent.
     *
     * @return array|null
     */
    public function getBrowserInfoAttribute()
    {
        if (!$this->user_agent) {
            return null;
        }

        // Básico parseado del user agent
        $browser = 'Desconocido';
        $os = 'Desconocido';

        if (strpos($this->user_agent, 'Chrome') !== false) {
            $browser = 'Chrome';
        } elseif (strpos($this->user_agent, 'Firefox') !== false) {
            $browser = 'Firefox';
        } elseif (strpos($this->user_agent, 'Safari') !== false) {
            $browser = 'Safari';
        } elseif (strpos($this->user_agent, 'Edge') !== false) {
            $browser = 'Edge';
        }

        if (strpos($this->user_agent, 'Windows') !== false) {
            $os = 'Windows';
        } elseif (strpos($this->user_agent, 'Mac') !== false) {
            $os = 'macOS';
        } elseif (strpos($this->user_agent, 'Linux') !== false) {
            $os = 'Linux';
        } elseif (strpos($this->user_agent, 'Android') !== false) {
            $os = 'Android';
        } elseif (strpos($this->user_agent, 'iOS') !== false) {
            $os = 'iOS';
        }

        return [
            'browser' => $browser,
            'os' => $os,
            'full' => $this->user_agent
        ];
    }

    /**
     * Get the type of restoration available
     *
     * @return string|null
     */
    public function getRestoreTypeAttribute()
    {
        if (!$this->canRestore()) {
            return null;
        }

        return match ($this->action) {
            'deleted' => 'undelete', // Restaurar parcial eliminado
            'updated' => 'revert',   // Revertir cambios
            default => null
        };
    }

    /**
     * Get description of what the restoration would do
     *
     * @return string
     */
    public function getRestoreDescriptionAttribute()
    {
        switch ($this->restore_type) {
            case 'undelete':
                return 'Restaurar parcial eliminado';

            case 'revert':
                $changedCount = count($this->changed_fields ?? []);
                return "Revertir {$changedCount} campo(s) modificado(s)";

            default:
                return 'No se puede restaurar';
        }
    }

    // ============================
    // RESTORATION METHODS
    // ============================

    /**
     * Verify if this log can be restored
     *
     * @return bool
     */
    public function canRestore()
    {
        switch ($this->action) {
            case 'deleted':
                // Verificar si el parcial existe y está soft deleted
                $partial = Partial::withTrashed()->find($this->partial_id);
                return $partial && $partial->trashed();

            case 'updated':
                // Verificar si el parcial existe y no está eliminado
                return $this->old_values &&
                    $this->partial &&
                    !$this->partial->trashed();

            default:
                return false;
        }
    }

    /**
     * Restore changes or deleted partial based on log type
     *
     * @return bool
     */
    public function restore()
    {
        switch ($this->action) {
            case 'deleted':
                return $this->restoreDeleted();

            case 'updated':
                return $this->restoreUpdated();

            default:
                return false;
        }
    }

    /**
     * Restore a deleted partial (soft deleted)
     *
     * @return bool
     */
    public function restoreDeleted()
    {
        if ($this->action !== 'deleted') {
            return false;
        }

        try {
            // Buscar el parcial incluyendo los soft deleted
            $partial = Partial::withTrashed()->find($this->partial_id);

            if (!$partial) {
                return false;
            }

            if (!$partial->trashed()) {
                // Ya está restaurado
                return true;
            }

            // Restaurar el parcial (quitar soft delete)
            $partial->restore();

            // Crear log de restauración
            static::create([
                'partial_id' => $partial->id,
                'user_id' => auth()->id(),
                'user_name' => auth()->user()?->name ?? 'Sistema',
                'action' => 'restored',
                'old_values' => null,
                'new_values' => $partial->getAttributes(),
                'changed_fields' => null,
                'ip_address' => request()->ip(),
                'user_agent' => request()->header('User-Agent'),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Error restoring deleted partial: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Restore changes from an update (revert changes)
     *
     * @return bool
     */
    private function restoreUpdated()
    {
        if (!$this->old_values) {
            return false;
        }

        try {
            $partial = $this->partial;
            if (!$partial || $partial->trashed()) {
                return false;
            }

            // Restaurar valores anteriores
            if ($this->changed_fields) {
                foreach ($this->changed_fields as $field => $change) {
                    $partial->{$field} = $change['old'];
                }
            }

            $partial->save();
            return true;
        } catch (\Exception $e) {
            Log::error('Error in log restore: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get preview of what would change when restoring
     *
     * @return array
     */
    public function getRestorePreview()
    {
        if (!$this->canRestore()) {
            return [];
        }

        switch ($this->action) {
            case 'deleted':
                return [
                    'type' => 'undelete',
                    'description' => 'Se restaurará el parcial eliminado',
                    'partial_data' => $this->old_values
                ];

            case 'updated':
                $preview = [];
                $currentPartial = $this->partial;

                if ($this->changed_fields) {
                    foreach ($this->changed_fields as $field => $change) {
                        $preview[] = [
                            'field' => $this->getFieldDisplayName($field),
                            'current_value' => $currentPartial->{$field},
                            'will_restore_to' => $change['old'],
                            'formatted_current' => $this->formatFieldValue($field, $currentPartial->{$field}),
                            'formatted_restore' => $this->formatFieldValue($field, $change['old'])
                        ];
                    }
                }

                return [
                    'type' => 'revert',
                    'description' => 'Se revertirán los siguientes cambios',
                    'changes' => $preview
                ];

            default:
                return [];
        }
    }

    // ============================
    // HELPER METHODS
    // ============================

    /**
     * Get display name for a field.
     *
     * @param string $field
     * @return string
     */
    private function getFieldDisplayName($field)
    {
        $displayNames = [
            'product_id' => 'Producto',
            'order_id' => 'Orden',
            'quantity' => 'Cantidad',
            'type' => 'Tipo',
            'dispatch_date' => 'Fecha de Despacho',
            'invoice_number' => 'Número de Factura',
            'tracking_number' => 'Número de Seguimiento',
            'transporter' => 'Transportadora',
            'trm' => 'TRM',
            'product_order_id' => 'ID Orden Producto'
        ];

        return $displayNames[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * Format field value for display.
     *
     * @param string $field
     * @param mixed $value
     * @return string
     */
    private function formatFieldValue($field, $value)
    {
        if ($value === null) {
            return 'N/A';
        }

        switch ($field) {
            case 'dispatch_date':
                return $value ? Carbon::parse($value)->format('d/m/Y') : 'N/A';

            case 'trm':
                return is_numeric($value) ? number_format($value, 2) : $value;

            case 'quantity':
                return is_numeric($value) ? number_format($value) : $value;

            case 'product_id':
                // Podrías cargar el nombre del producto aquí si es necesario
                return "ID: {$value}";

            case 'order_id':
                // Podrías cargar el número de orden aquí si es necesario
                return "ID: {$value}";

            default:
                return (string) $value;
        }
    }

    // ============================
    // STATIC METHODS
    // ============================

    /**
     * Create a detailed audit trail entry.
     *
     * @param array $data
     * @return static
     */
    public static function createAuditEntry(array $data)
    {
        return static::create(array_merge([
            'ip_address' => request()->ip(),
            'user_agent' => request()->header('User-Agent'),
        ], $data));
    }

    /**
     * Get the most active users in terms of partial modifications.
     *
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getMostActiveUsers($limit = 10)
    {
        return static::selectRaw('user_id, user_name, COUNT(*) as changes_count')
            ->whereNotNull('user_id')
            ->groupBy('user_id', 'user_name')
            ->orderByDesc('changes_count')
            ->limit($limit)
            ->get();
    }

    /**
     * Get statistics about partial modifications.
     *
     * @return array
     */
    public static function getStatistics()
    {
        $totalLogs = static::count();
        $totalUpdates = static::byAction('updated')->count();
        $totalCreations = static::byAction('created')->count();
        $totalDeletions = static::byAction('deleted')->count();
        $totalRestorations = static::byAction('restored')->count();

        return [
            'total_logs' => $totalLogs,
            'updates' => $totalUpdates,
            'creations' => $totalCreations,
            'deletions' => $totalDeletions,
            'restorations' => $totalRestorations,
            'most_active_users' => static::getMostActiveUsers(5),
            'recent_activity' => static::recent(7)->count(),
            'pending_restorations' => static::getPendingRestorations()->count()
        ];
    }

    /**
     * Get logs that can be restored (deleted partials or recent updates)
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function getPendingRestorations()
    {
        return static::where(function ($query) {
            // Parciales eliminados que pueden ser restaurados
            $query->where('action', 'deleted')
                ->whereHas('partial', function ($q) {
                    $q->onlyTrashed();
                });
        })->orWhere(function ($query) {
            // Actualizaciones recientes que pueden ser revertidas
            $query->where('action', 'updated')
                ->where('created_at', '>=', Carbon::now()->subDays(30))
                ->whereHas('partial', function ($q) {
                    $q->whereNull('deleted_at');
                });
        });
    }

    /**
     * Restore from a specific log ID (static method)
     *
     * @param int $logId
     * @return bool
     */
    public static function restoreFromLog($logId)
    {
        $log = static::find($logId);

        if (!$log || !$log->canRestore()) {
            return false;
        }

        return $log->restore();
    }
}
