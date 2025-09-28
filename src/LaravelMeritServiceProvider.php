<?php

namespace Ram64\LaravelMerit;

use Ram64\LaravelMerit\Commands\LaravelMeritCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelMeritServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-merit')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_merit_table')
            ->hasCommand(LaravelMeritCommand::class);
    }
}
