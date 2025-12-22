<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cartera extends Model
{
    protected $table = 'cartera';

    protected $guarded = [];

    public function client()
    {
        return $this->belongsTo(Client::class, 'nit', 'nit');
    }
}
