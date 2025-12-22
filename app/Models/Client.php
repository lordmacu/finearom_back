<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        'user_id',
        'executive_phone',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
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
