<?php

namespace Savannabits\Daraja;

use Illuminate\Support\Facades\Facade;

class DarajaFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return Daraja::class;
    }
}
