<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A model with neither trait, for the guards that reject one.
 */
class PlainModel extends Model
{
    protected $table = 'users';
}
