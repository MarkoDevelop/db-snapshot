<?php

namespace Overthink\DbSnapshot\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use Overthink\DbSnapshot\Contracts\Driver;
use Overthink\DbSnapshot\DriverManager;

/**
 * @method static Driver driver(?string $driver = null)
 * @method static DriverManager extend(string $driver, Closure $callback)
 * @method static list<string> available()
 *
 * @see DriverManager
 */
class DbSnapshot extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DriverManager::class;
    }
}
