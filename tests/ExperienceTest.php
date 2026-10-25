<?php

use Illuminate\Support\Facades\Event;
use Ram64\LaravelMerit\Events\ExperienceGained;
use Ram64\LaravelMerit\Events\ExperienceLost;
use Ram64\LaravelMerit\Events\LevelledUp;
use Ram64\LaravelMerit\Models\ExperienceLog;
use Ram64\LaravelMerit\Services\ExperienceManager;
use Ram64\LaravelMerit\Tests\Fixtures\Models\PlainModel;

it('starts an experiencer at zero experience and level one', function () {
    $user = createUser();

    expect($user->getExperience())->toBe(0)
        ->and($user->getLevel())->toBe(1);
});

it('records a gain against the experiencer', function () {
    $user = createUser();

    expect($user->gainExperience(60, 'Tutorial'))->toBeTrue()
        ->and($user->getExperience())->toBe(60)
        ->and($user->getLevel())->toBe(1);
});

it('accumulates repeated gains on the same instance', function () {
    $user = createUser();

    $user->gainExperience(30);
    $user->gainExperience(30);
    $user->gainExperience(30);

    expect($user->getExperience())->toBe(90);
});

it('accumulates gains that cross a level threshold', function () {
    $user = createUser();

    $user->gainExperience(150);

    expect($user->getExperience())->toBe(150)
        ->and($user->getLevel())->toBe(2);
});

it('keeps one experiencer row per achiever', function () {
    $user = createUser();

    $user->gainExperience(150);
    $user->gainExperience(150);

    expect($user->experiencer()->count())->toBe(1)
        ->and($user->getExperience())->toBe(300)
        ->and($user->getLevel())->toBe(3);
});

it('refuses a gain of zero or less', function () {
    $user = createUser();

    expect($user->gainExperience(0))->toBeFalse()
        ->and($user->gainExperience(-10))->toBeFalse()
        ->and($user->getExperience())->toBe(0);
});

it('refuses a loss of zero or less', function () {
    $user = createUser();

    expect($user->loseExperience(0))->toBeFalse()
        ->and($user->loseExperience(-10))->toBeFalse();
});

it('deducts experience without ever lowering the level', function () {
    $user = createUser();
    $user->gainExperience(1000);

    expect($user->getLevel())->toBe(5);

    $user->loseExperience(900);

    expect($user->getExperience())->toBe(100)
        ->and($user->getLevel())->toBe(5);
});

it('floors experience at zero', function () {
    $user = createUser();
    $user->gainExperience(50);

    $user->loseExperience(500);

    expect($user->getExperience())->toBe(0);
});

it('sets experience upwards through a gain', function () {
    $user = createUser();
    $user->gainExperience(100);

    $user->setExperience(500);

    expect($user->getExperience())->toBe(500)
        ->and($user->getLevel())->toBe(4);
});

it('sets experience downwards through a loss', function () {
    $user = createUser();
    $user->gainExperience(500);

    $user->setExperience(100);

    expect($user->getExperience())->toBe(100);
});

it('does nothing when experience is set to what it already is', function () {
    $user = createUser();
    $user->gainExperience(100);

    expect($user->setExperience(100))->toBeTrue()
        ->and($user->getExperience())->toBe(100);
});

describe('events', function () {
    beforeEach(function () {
        Event::fake([ExperienceGained::class, ExperienceLost::class, LevelledUp::class]);
    });

    it('fires gained without levelled up when no threshold is crossed', function () {
        $user = createUser();

        $user->gainExperience(50, 'Small win');

        Event::assertDispatchedTimes(ExperienceGained::class, 1);
        Event::assertNotDispatched(LevelledUp::class);

        Event::assertDispatched(ExperienceGained::class, function (ExperienceGained $event) use ($user) {
            return $event->amount === 50
                && $event->reason === 'Small win'
                && $event->experiencer->is($user);
        });
    });

    it('fires levelled up with the old and new level', function () {
        $user = createUser();

        $user->gainExperience(250);

        Event::assertDispatchedTimes(LevelledUp::class, 1);
        Event::assertDispatched(LevelledUp::class, function (LevelledUp $event) {
            return $event->oldLevel === 1 && $event->newLevel === 3;
        });
    });

    it('fires lost on a deduction and never levels down', function () {
        $user = createUser();
        $user->gainExperience(250);
        $user->loseExperience(200, 'Penalty');

        Event::assertDispatchedTimes(ExperienceLost::class, 1);
        Event::assertDispatched(ExperienceLost::class, function (ExperienceLost $event) {
            return $event->amount === 200 && $event->reason === 'Penalty';
        });
        Event::assertDispatchedTimes(LevelledUp::class, 1);
    });
});

describe('logging', function () {
    it('logs a gain', function () {
        $user = createUser();

        $user->gainExperience(50, 'Tutorial');

        $log = ExperienceLog::first();

        expect(ExperienceLog::count())->toBe(1)
            ->and($log->experience_before)->toBe(0)
            ->and($log->experience_after)->toBe(50)
            ->and($log->level_before)->toBe(1)
            ->and($log->level_after)->toBeNull()
            ->and($log->reason)->toBe('Tutorial')
            ->and($log->isGain())->toBeTrue()
            ->and($log->event_type)->toBe('gained');
    });

    it('records a level up on the same row as the gain that caused it', function () {
        $user = createUser();

        $user->gainExperience(150);

        $log = ExperienceLog::first();

        expect(ExperienceLog::count())->toBe(1)
            ->and($log->level_before)->toBe(1)
            ->and($log->level_after)->toBe(2)
            ->and($log->isLevelUp())->toBeTrue()
            ->and($log->event_type)->toBe('level_up');
    });

    it('counts a level up once', function () {
        $user = createUser();

        $user->gainExperience(150);

        expect(ExperienceLog::levelUps()->count())->toBe(1);
    });

    it('logs a loss', function () {
        $user = createUser();
        $user->gainExperience(50);

        $user->loseExperience(20, 'Penalty');

        $log = ExperienceLog::lost()->first();

        expect($log->experience_before)->toBe(50)
            ->and($log->experience_after)->toBe(30)
            ->and($log->isLoss())->toBeTrue()
            ->and($log->event_type)->toBe('lost');
    });

    it('writes nothing when logging is disabled', function () {
        config()->set('merit.experience.logging_enabled', false);

        $user = createUser();
        $user->gainExperience(150);

        expect(ExperienceLog::count())->toBe(0)
            ->and($user->getExperience())->toBe(150);
    });

    it('keeps each experiencer logs to themselves', function () {
        $user = createUser();
        $other = createUser();

        $user->gainExperience(10);
        $other->gainExperience(20);
        $user->gainExperience(30);

        expect($user->experienceLogs()->count())->toBe(2)
            ->and($other->experienceLogs()->count())->toBe(1);
    });
});

describe('ExperienceManager', function () {
    it('delegates awarding and deducting to the trait', function () {
        $user = createUser();

        ExperienceManager::awardExperience($user, 300, 'Quest');

        expect($user->getExperience())->toBe(300);

        ExperienceManager::deductExperience($user, 100, 'Penalty');

        expect($user->getExperience())->toBe(200);
    });

    it('delegates setting experience', function () {
        $user = createUser();

        ExperienceManager::setExperience($user, 500);

        expect($user->getExperience())->toBe(500);
    });

    it('returns the cached experience payload', function () {
        $user = createUser();
        $user->gainExperience(150);

        $data = ExperienceManager::getExperienceData($user);

        expect($data)->toHaveKeys(['experience', 'level', 'progress', 'next_level_exp'])
            ->and($data['experience'])->toBe(150)
            ->and($data['level'])->toBe(2);
    });

    it('serves the experience payload from cache until it is cleared', function () {
        $user = createUser();
        $user->gainExperience(150);

        expect(ExperienceManager::getExperienceData($user)['experience'])->toBe(150);

        // Straight to the row, so the trait cannot clear the cache on the way.
        $user->experiencer()->update(['experience' => 9999]);

        expect(ExperienceManager::getExperienceData($user)['experience'])->toBe(150);

        ExperienceManager::clearCache($user);

        expect(ExperienceManager::getExperienceData($user)['experience'])->toBe(9999);
    });

    it('limits the logs it returns', function () {
        $user = createUser();
        $user->gainExperience(10);
        $user->gainExperience(10);
        $user->gainExperience(10);

        expect(ExperienceManager::getExperienceLogs($user, 2))->toHaveCount(2);
    });

    it('reports statistics across every experiencer', function () {
        createUser()->gainExperience(150);
        createUser()->gainExperience(50);

        $stats = ExperienceManager::getStatistics();

        expect($stats['total_experiencers'])->toBe(2)
            ->and($stats['highest_level'])->toBe(2)
            ->and($stats['total_level_ups'])->toBe(1);
    });

    it('rejects a model that does not use the trait', function () {
        ExperienceManager::awardExperience(new PlainModel, 10);
    })->throws(InvalidArgumentException::class, 'Model must use the Experiencer trait');

    it('reports a failure per award in a bulk run rather than aborting', function () {
        $user = createUser();

        $results = ExperienceManager::bulkAwardExperience([
            ['experiencer' => $user, 'amount' => 100, 'reason' => 'One'],
            ['experiencer' => new PlainModel, 'amount' => 50],
        ]);

        expect($results[0]['success'])->toBeTrue()
            ->and($results[1]['success'])->toBeFalse()
            ->and($results[1])->toHaveKey('error')
            ->and($user->getExperience())->toBe(100);
    });
});

it('exposes progress towards the next level', function () {
    $user = createUser();
    $user->gainExperience(175);

    $progress = $user->getExperienceProgress();

    // Level 2 starts at 100, level 3 at 250.
    expect($progress['current'])->toBe(75)
        ->and($progress['required'])->toBe(150)
        ->and($progress['percentage'])->toBe(50.0);
});

it('never reports negative progress after a loss below the level threshold', function () {
    $user = createUser();
    $user->gainExperience(300);
    $user->loseExperience(250);

    $progress = $user->getExperienceProgress();

    // Still level 3 (from 250) on 50 experience; level 4 is at 500.
    expect($user->getLevel())->toBe(3)
        ->and($progress['percentage'])->toEqual(0)
        ->and($progress['required'] - $progress['current'])->toBe(450);
});
