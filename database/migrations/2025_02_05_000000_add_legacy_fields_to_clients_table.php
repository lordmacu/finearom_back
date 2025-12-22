<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'phone')) {
                $table->string('phone')->nullable()->after('address');
            }
            if (! Schema::hasColumn('clients', 'dispatch_confirmation_email')) {
                $table->string('dispatch_confirmation_email')->nullable()->after('executive_email');
            }
            if (! Schema::hasColumn('clients', 'accounting_contact_email')) {
                $table->string('accounting_contact_email')->nullable()->after('dispatch_confirmation_email');
            }
            if (! Schema::hasColumn('clients', 'registration_address')) {
                $table->string('registration_address')->nullable()->after('address');
            }
            if (! Schema::hasColumn('clients', 'registration_city')) {
                $table->string('registration_city')->nullable()->after('registration_address');
            }
            if (! Schema::hasColumn('clients', 'trm')) {
                $table->string('trm')->nullable()->after('payment_day');
            }
            if (! Schema::hasColumn('clients', 'commercial_terms')) {
                $table->text('commercial_terms')->nullable()->after('commercial_conditions');
            }
            if (! Schema::hasColumn('clients', 'temporal_mark')) {
                $table->string('temporal_mark')->nullable()->after('commercial_terms');
            }
            if (! Schema::hasColumn('clients', 'business_name')) {
                $table->string('business_name')->nullable()->after('temporal_mark');
            }
            if (! Schema::hasColumn('clients', 'country')) {
                $table->string('country')->nullable()->after('business_name');
            }
            if (! Schema::hasColumn('clients', 'is_in_free_zone')) {
                $table->boolean('is_in_free_zone')->default(false)->after('country');
            }
            if (! Schema::hasColumn('clients', 'operation_type')) {
                $table->string('operation_type')->default('nacional')->after('is_in_free_zone');
            }
            if (! Schema::hasColumn('clients', 'purchasing_contact_name')) {
                $table->string('purchasing_contact_name')->nullable()->after('operation_type');
            }
            if (! Schema::hasColumn('clients', 'purchasing_contact_phone')) {
                $table->string('purchasing_contact_phone')->nullable()->after('purchasing_contact_name');
            }
            if (! Schema::hasColumn('clients', 'purchasing_contact_email')) {
                $table->string('purchasing_contact_email')->nullable()->after('purchasing_contact_phone');
            }
            if (! Schema::hasColumn('clients', 'logistics_contact_name')) {
                $table->string('logistics_contact_name')->nullable()->after('purchasing_contact_email');
            }
            if (! Schema::hasColumn('clients', 'logistics_contact_phone')) {
                $table->string('logistics_contact_phone')->nullable()->after('logistics_contact_name');
            }
            if (! Schema::hasColumn('clients', 'logistics_email')) {
                $table->string('logistics_email')->nullable()->after('logistics_contact_phone');
            }
            if (! Schema::hasColumn('clients', 'dispatch_conditions')) {
                $table->text('dispatch_conditions')->nullable()->after('logistics_email');
            }
            if (! Schema::hasColumn('clients', 'billing_contact_name')) {
                $table->string('billing_contact_name')->nullable()->after('dispatch_conditions');
            }
            if (! Schema::hasColumn('clients', 'billing_contact_phone')) {
                $table->string('billing_contact_phone')->nullable()->after('billing_contact_name');
            }
            if (! Schema::hasColumn('clients', 'taxpayer_type')) {
                $table->string('taxpayer_type')->nullable()->after('billing_contact_phone');
            }
            if (! Schema::hasColumn('clients', 'credit_term')) {
                $table->integer('credit_term')->nullable()->after('taxpayer_type');
            }
            if (! Schema::hasColumn('clients', 'portfolio_contact_name')) {
                $table->string('portfolio_contact_name')->nullable()->after('credit_term');
            }
            if (! Schema::hasColumn('clients', 'portfolio_contact_phone')) {
                $table->string('portfolio_contact_phone')->nullable()->after('portfolio_contact_name');
            }
            if (! Schema::hasColumn('clients', 'portfolio_contact_email')) {
                $table->string('portfolio_contact_email')->nullable()->after('portfolio_contact_phone');
            }
            if (! Schema::hasColumn('clients', 'r_and_d_contact_name')) {
                $table->string('r_and_d_contact_name')->nullable()->after('portfolio_contact_email');
            }
            if (! Schema::hasColumn('clients', 'r_and_d_contact_phone')) {
                $table->string('r_and_d_contact_phone')->nullable()->after('r_and_d_contact_name');
            }
            if (! Schema::hasColumn('clients', 'r_and_d_contact_email')) {
                $table->string('r_and_d_contact_email')->nullable()->after('r_and_d_contact_phone');
            }
            if (! Schema::hasColumn('clients', 'data_consent')) {
                $table->boolean('data_consent')->default(false)->after('r_and_d_contact_email');
            }
            if (! Schema::hasColumn('clients', 'marketing_consent')) {
                $table->boolean('marketing_consent')->default(false)->after('data_consent');
            }
            if (! Schema::hasColumn('clients', 'ica')) {
                $table->decimal('ica', 10, 2)->nullable()->after('marketing_consent');
            }
            if (! Schema::hasColumn('clients', 'retefuente')) {
                $table->decimal('retefuente', 10, 2)->nullable()->after('ica');
            }
            if (! Schema::hasColumn('clients', 'reteiva')) {
                $table->decimal('reteiva', 10, 2)->nullable()->after('retefuente');
            }
            if (! Schema::hasColumn('clients', 'iva')) {
                $table->decimal('iva', 10, 2)->nullable()->after('reteiva');
            }
            if (! Schema::hasColumn('clients', 'rut_file')) {
                $table->string('rut_file')->nullable()->after('iva');
            }
            if (! Schema::hasColumn('clients', 'camara_comercio_file')) {
                $table->string('camara_comercio_file')->nullable()->after('rut_file');
            }
            if (! Schema::hasColumn('clients', 'cedula_representante_file')) {
                $table->string('cedula_representante_file')->nullable()->after('camara_comercio_file');
            }
            if (! Schema::hasColumn('clients', 'declaracion_renta_file')) {
                $table->string('declaracion_renta_file')->nullable()->after('cedula_representante_file');
            }
            if (! Schema::hasColumn('clients', 'estados_financieros_file')) {
                $table->string('estados_financieros_file')->nullable()->after('declaracion_renta_file');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $columns = [
                'phone',
                'dispatch_confirmation_email',
                'accounting_contact_email',
                'registration_address',
                'registration_city',
                'trm',
                'commercial_terms',
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
                'logistics_email',
                'dispatch_conditions',
                'billing_contact_name',
                'billing_contact_phone',
                'taxpayer_type',
                'credit_term',
                'portfolio_contact_name',
                'portfolio_contact_phone',
                'portfolio_contact_email',
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
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('clients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
