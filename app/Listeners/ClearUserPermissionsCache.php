<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use App\Models\User;

class ClearUserPermissionsCache
{
    /**
     * Handle the event.
     */
    public function handle($event): void
    {
        // Spatie Permission dispara eventos automáticamente
        // cuando se asignan/remueven roles o permisos
        
        if (method_exists($event, 'getModel')) {
            $model = $event->getModel();
            
            // Si es un User, limpiar su caché
            if ($model instanceof User) {
                Cache::forget("user.{$model->id}.permissions");
                Cache::forget("user.{$model->id}.roles");
            }
            
            // Si es un Role, limpiar caché de todos los usuarios con ese rol
            if ($model instanceof Role) {
                $users = $model->users;
                foreach ($users as $user) {
                    Cache::forget("user.{$user->id}.permissions");
                    Cache::forget("user.{$user->id}.roles");
                }
            }
        }
    }
}
