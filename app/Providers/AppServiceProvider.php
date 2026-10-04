<?php

namespace App\Providers;

use App\Models\TutorMessage;
use App\Observers\TutorMessageObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        TutorMessage::observe(TutorMessageObserver::class);
    }
}
