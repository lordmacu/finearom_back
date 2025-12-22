<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailCampaign extends Model
{
    protected $table = 'email_campaigns';

    protected $fillable = [
        'campaign_name',
        'subject',
        'email_field_type',
        'body',
        'attachments',
        'client_ids',
        'custom_emails',
        'status',
        'total_recipients',
        'sent_count',
        'failed_count',
        'user_id',
        'sent_at',
    ];

    protected $casts = [
        'client_ids' => 'array',
        'custom_emails' => 'array',
        'attachments' => 'array',
        'sent_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function logs()
    {
        return $this->hasMany(EmailCampaignLog::class);
    }
}

