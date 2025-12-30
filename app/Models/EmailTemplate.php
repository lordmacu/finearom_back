<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'subject',
        'title',
        'header_content',
        'footer_content',
        'signature',
        'available_variables',
        'is_active',
    ];

    protected $casts = [
        'available_variables' => 'array',
        'is_active' => 'boolean',
    ];
}
