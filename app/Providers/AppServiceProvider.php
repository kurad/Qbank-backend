<?php

namespace App\Providers;

use App\Models\TutorMessage;
use App\Observers\TutorMessageObserver;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);
        TutorMessage::observe(TutorMessageObserver::class);
    }
}
