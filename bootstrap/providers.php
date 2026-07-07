<?php

use App\Providers\AppServiceProvider;
use App\Providers\DatabaseGuardServiceProvider;
use App\Providers\ModulesServiceProvider;

$providers = [
    AppServiceProvider::class,
    ModulesServiceProvider::class,
    DatabaseGuardServiceProvider::class,
];

return $providers;
