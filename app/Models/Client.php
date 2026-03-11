<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $table = 'clients';

    protected $fillable = [
        'client_name',
        'nit',
        'client_type',
        'payment_type',
        'email',
        'executive_email',
        'executive',
        'phone',
        'address',
        'city',
        'registration_address',
        'registration_city',
        'billing_closure',
        'billing_closure_date',
        'commercial_conditions',
        'commercial_terms',
        'proforma_invoice',
        'payment_method',
        'payment_day',
        'status',
        'dispatch_confirmation_email',
        'accounting_contact_email',
        'portfolio_contact_email',
        'compras_email',
        'logistics_email',
        'trm',
        'temporal_mark',
        'business_name',
        'country',
        'is_in_free_zone',
        'operation_type',
        'purchasing_contact_name',
        'purchasing_contact_phone',
        'purchasing_contact_email',
        'logistics_contact_name',
        'logistics_contact_phone',
        'dispatch_conditions',
        'billing_contact_name',
        'billing_contact_phone',
        'taxpayer_type',
        'credit_term',
        'portfolio_contact_name',
        'portfolio_contact_phone',
        'r_and_d_contact_name',
        'r_and_d_contact_phone',
        'r_and_d_contact_email',
        'data_consent',
        'marketing_consent',
        'ica',
        'retefuente',
        'reteiva',
        'iva',
        'rut_file',
        'camara_comercio_file',
        'cedula_representante_file',
        'declaracion_renta_file',
        'estados_financieros_file',
        'drive_doc_links',
        'drive_folder_link',
        'user_id',
        'executive_phone',
        'lead_time',
        'first_dispatch_date',
        'estimated_launch_date',
        'first_dispatch_quantity',
        'purchase_frequency',
        'estimated_monthly_quantity',
        'product_category',
        'default_factor',
    ];

    protected $casts = [
        'drive_doc_links' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'client_id');
    }

    protected static function booted()
    {
        static::creating(function ($client) {
            if (empty($client->client_type)) {
                $client->client_type = 'pareto';
            }
        });
    }
}
