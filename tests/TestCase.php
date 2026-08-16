<?php

namespace Ram64\LaravelMerit\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Ram64\LaravelMerit\LaravelMeritServiceProvider;
use Ram64\LaravelMerit\Services\AchievementTracker;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Ram64\\LaravelMerit\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        // Discovery memoises into a static property, which outlives the
        // application between tests. Every test starts from an empty registry.
        AchievementTracker::resetDiscovery();
    }

    protected function tearDown(): void
    {
        AchievementTracker::resetDiscovery();

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelMeritServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
    }

    /**
     * The package ships its migrations as stubs, so they are not discoverable
     * by loadMigrationsFrom(). Include and run them by hand, alongside the
     * `users` table the fixture achiever lives in.
     */
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Test Achiever');
            $table->integer('points')->default(0);
            $table->timestamps();
        });

        $migrations = [
            'create_achiever_achievements_table',
            'create_experiencers_table',
            'create_experience_logs_table',
        ];

        foreach ($migrations as $migration) {
            (include __DIR__."/../database/migrations/{$migration}.php.stub")->up();
        }
    }
}
