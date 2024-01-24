<?php

namespace DTApi\App\Providers;

use Illuminate\Support\ServiceProvider;

use DTApi\Repository\notification\NotificationInterface;

use DTApi\Repository\booking\BookingInterface;

use DTApi\Repository\job\JobInterface;



class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(

            BookingInterface::class,

            JobInterface::class,
            
            NotificationInterface::class,
        );
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
