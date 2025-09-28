<?php

namespace Ram64\LaravelMerit\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Ram64\LaravelMerit\LaravelMerit
 */
class LaravelMerit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ram64\LaravelMerit\LaravelMerit::class;
    }
}
