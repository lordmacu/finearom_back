<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchOffice extends Model
{
    protected $table = 'branch_offices';

    protected $fillable = [
        'name',
        'nit',
        'client_id',
        'delivery_address',
        'delivery_city',
        'general_observations',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
