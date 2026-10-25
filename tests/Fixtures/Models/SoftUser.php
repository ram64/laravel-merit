<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Ram64\LaravelMerit\Concerns\CanAchieve;
use Ram64\LaravelMerit\Concerns\CanExperience;

/**
 * An achiever that soft deletes. Its table is created by the test using it.
 */
class SoftUser extends Model
{
    use CanAchieve;
    use CanExperience;
    use SoftDeletes;

    protected $table = 'soft_users';

    protected $fillable = ['name', 'points'];
}
