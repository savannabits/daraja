<?php

namespace Savannabits\Daraja;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Savannabits\Daraja\Skeleton\SkeletonClass
 */
class DarajaFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'daraja';
    }
}
