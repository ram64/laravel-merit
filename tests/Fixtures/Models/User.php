<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Concerns\CanAchieve;
use Ram64\LaravelMerit\Concerns\CanExperience;

/**
 * The achiever/experiencer used across the suite.
 *
 * `points` is the single piece of state the fixture achievements read, so a
 * test moves an achiever forward with one update() rather than by faking the
 * definitions.
 */
class User extends Model
{
    use CanAchieve;
    use CanExperience;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'points',
    ];

    protected $casts = [
        'points' => 'integer',
    ];
}
