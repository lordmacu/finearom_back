<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectGoogleTaskConfig extends Model
{
    protected $fillable = ['project_id', 'trigger', 'user_ids'];

    protected $casts = ['user_ids' => 'array'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
