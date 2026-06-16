<?php

namespace App\Providers;

use App\Services\MistralTaskSplitter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MistralTaskSplitter::class, function () {
            return new MistralTaskSplitter(
                config('services.mistral.api_key'),
                config('services.mistral.model'),
                config('services.mistral.endpoint'),
                (int) config('services.mistral.timeout', 30),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
