<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ram64\LaravelMerit\Models\AchieverAchievement;
use Ram64\LaravelMerit\Models\ExperienceLog;
use Ram64\LaravelMerit\Models\Experiencer;
use Ram64\LaravelMerit\Services\AchievementTracker;
use Ram64\LaravelMerit\Tests\Fixtures\Models\SoftUser;

beforeEach(fn () => useAchievements('Progress'));

/**
 * Give an achiever a progress row, an experiencer row and a log row.
 */
function withMeritData($achiever)
{
    AchievementTracker::award($achiever, 'counting');
    $achiever->gainExperience(50);

    return $achiever;
}

/**
 * Rows of each kind still stored for an achiever.
 *
 * @return array{achievements: int, experiencers: int, logs: int}
 */
function meritRowsFor($achiever): array
{
    $id = $achiever->getKey();
    $type = $achiever->getMorphClass();

    return [
        'achievements' => AchieverAchievement::where('achiever_id', $id)->where('achiever_type', $type)->count(),
        'experiencers' => Experiencer::where('experiencer_id', $id)->where('experiencer_type', $type)->count(),
        'logs' => ExperienceLog::where('experiencer_id', $id)->where('experiencer_type', $type)->count(),
    ];
}

it("deletes an achiever's rows when the achiever is deleted", function () {
    $user = withMeritData(createUser());
    $other = withMeritData(createUser());

    $user->delete();

    expect(meritRowsFor($user))->toBe(['achievements' => 0, 'experiencers' => 0, 'logs' => 0])
        ->and(meritRowsFor($other))->toBe(['achievements' => 1, 'experiencers' => 1, 'logs' => 1]);
});

it('keeps the rows when cleanup is turned off', function () {
    config()->set('merit.cleanup_on_delete', false);

    $user = withMeritData(createUser());

    $user->delete();

    expect(meritRowsFor($user))->toBe(['achievements' => 1, 'experiencers' => 1, 'logs' => 1]);
});

describe('a soft-deleting achiever', function () {
    beforeEach(function () {
        Schema::create('soft_users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Soft');
            $table->integer('points')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    });

    it('keeps the rows through a soft delete, so a restore brings them back', function () {
        $user = withMeritData(SoftUser::create());

        $user->delete();

        expect(meritRowsFor($user))->toBe(['achievements' => 1, 'experiencers' => 1, 'logs' => 1]);
    });

    it('deletes the rows on a force delete', function () {
        $user = withMeritData(SoftUser::create());

        $user->forceDelete();

        expect(meritRowsFor($user))->toBe(['achievements' => 0, 'experiencers' => 0, 'logs' => 0]);
    });
});
