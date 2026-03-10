<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectProductType extends Model
{
    protected $fillable = ['nombre', 'categoria'];

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'product_id');
    }

    public function timeApplications(): HasMany
    {
        return $this->hasMany(TimeApplication::class, 'product_id');
    }
}
