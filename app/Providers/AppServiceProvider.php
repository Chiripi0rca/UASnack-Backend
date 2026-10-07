<?php

namespace App\Providers;

use App\Support\Tenant;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Una sola instancia de Tenant por petición: la llena ResolverTenant y la leen
        // los controladores y los global scopes. scoped() la reinicia en cada petición.
        $this->app->scoped(Tenant::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
