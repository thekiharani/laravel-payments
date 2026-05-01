<?php

namespace NoriaLabs\Payments\Facades;

use Illuminate\Support\Facades\Facade;
use NoriaLabs\Payments\PaymentsManager;

class Payments extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PaymentsManager::class;
    }
}
