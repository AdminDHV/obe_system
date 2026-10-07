<?php

namespace App\Providers;

use App\Events\GradeUpdatedEvent;
use App\Listeners\RecalculateObeScoresListener;
use App\Listeners\RecordLastLogin;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        GradeUpdatedEvent::class => [
            RecalculateObeScoresListener::class,
        ],
        Login::class => [
            RecordLastLogin::class,
        ],
    ];
}
