<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfigSystem extends Model
{
    protected $table = 'config_systems';

    protected $fillable = [
        'key',
        'value',
        'type',
    ];
}
