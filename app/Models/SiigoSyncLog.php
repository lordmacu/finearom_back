<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiigoSyncLog extends Model
{
    protected $table = 'siigo_sync_logs';

    protected $fillable = [
        'file_name',
        'action',
        'records_count',
        'details',
    ];
}
