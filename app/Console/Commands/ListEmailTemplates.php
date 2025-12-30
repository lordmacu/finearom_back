<?php

namespace App\Console\Commands;

use App\Models\EmailTemplate;
use Illuminate\Console\Command;

class ListEmailTemplates extends Command
{
    protected $signature = 'email:list-templates';
    protected $description = 'Lista todos los email templates';

    public function handle()
    {
        $templates = EmailTemplate::orderBy('id')->get(['id', 'key', 'name', 'is_active']);

        $this->info('Total de templates: ' . $templates->count());
        $this->newLine();

        $this->table(
            ['ID', 'Key', 'Nombre', 'Activo'],
            $templates->map(fn($t) => [
                $t->id,
                $t->key,
                $t->name,
                $t->is_active ? '✓' : '✗'
            ])
        );

        return 0;
    }
}
