<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailCampaignLog extends Model
{
    protected $table = 'email_campaign_logs';

    protected $fillable = [
        'email_campaign_id',
        'client_id',
        'email_field_used',
        'email_sent_to',
        'status',
        'error_message',
        'sent_at',
        'opened_at',
        'open_count',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(EmailCampaign::class, 'email_campaign_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}

