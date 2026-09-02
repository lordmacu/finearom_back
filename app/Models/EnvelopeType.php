<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EnvelopeType extends Model
{
    use HasFactory;

    protected $table = 'envelope_types';

    protected $fillable = [
        'name',
        'category',
        'photo_path',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];
}