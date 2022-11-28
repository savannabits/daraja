<?php

namespace Savannabits\Daraja;

use Illuminate\Support\ServiceProvider;

class DarajaServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(Daraja::class, function ($app) {
            return new Daraja();
        });
    }

    public function boot()
    {
        //
    }
}
