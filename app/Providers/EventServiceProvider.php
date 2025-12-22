<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        
        // Spatie Permission events - invalidar caché cuando cambian permisos
        \Spatie\Permission\Events\RoleHasGivenPermission::class => [
            \App\Listeners\ClearUserPermissionsCache::class,
        ],
        \Spatie\Permission\Events\RoleHasRevokedPermission::class => [
            \App\Listeners\ClearUserPermissionsCache::class,
        ],
        \Spatie\Permission\Events\PermissionHasUsers::class => [
            \App\Listeners\ClearUserPermissionsCache::class,
        ],

        // Email logging
        \Illuminate\Mail\Events\MessageSending::class => [
            \App\Listeners\LogSentMessage::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
