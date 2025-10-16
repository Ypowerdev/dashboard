<?php

namespace App\Providers;

use App\Models\ObjectModel;
use App\Policies\ObjectModelPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        ObjectModel::class => ObjectModelPolicy::class,
    ];

    public function boot()
    {
        $this->registerPolicies();
    }
}