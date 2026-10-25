<?php

use Illuminate\Support\Facades\Schema;
use Ram64\LaravelMerit\Models\ExperienceLog;
use Ram64\LaravelMerit\Models\Experiencer;

/**
 * The migrations and the models have to agree on where the tables live. The
 * models read merit.experience.table and merit.experience.logs_table, so the
 * migrations must create the tables under those same names.
 */
it('creates the experience tables under the names the models read', function () {
    // The suite has already migrated the default names; clear them so the
    // index names are free and only the stubs under test create anything.
    Schema::drop('experiencers');
    Schema::drop('experience_logs');

    config()->set('merit.experience.table', 'custom_experiencers');
    config()->set('merit.experience.logs_table', 'custom_experience_logs');

    (include __DIR__.'/../database/migrations/create_experiencers_table.php.stub')->up();
    (include __DIR__.'/../database/migrations/create_experience_logs_table.php.stub')->up();

    expect(Schema::hasTable((new Experiencer)->getTable()))->toBeTrue()
        ->and(Schema::hasTable((new ExperienceLog)->getTable()))->toBeTrue()
        ->and((new Experiencer)->getTable())->toBe('custom_experiencers')
        ->and((new ExperienceLog)->getTable())->toBe('custom_experience_logs');
});
