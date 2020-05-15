<?php

namespace Savannabits\Daraja\Tests;

use Orchestra\Testbench\TestCase;
use Savannabits\Daraja\DarajaServiceProvider;

class ExampleTest extends TestCase
{

    protected function getPackageProviders($app)
    {
        return [DarajaServiceProvider::class];
    }
    
    /** @test */
    public function true_is_true()
    {
        $this->assertTrue(true);
    }
}
