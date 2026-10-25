<?php

use Illuminate\Support\Facades\Event;
use Ram64\LaravelMerit\Events\ExperienceGained;
use Ram64\LaravelMerit\Events\ExperienceLost;
use Ram64\LaravelMerit\Events\LevelledUp;
use Ram64\LaravelMerit\Services\ExperienceManager;

describe('static thresholds', function () {
    it('maps experience onto the configured ladder', function (int $experience, int $level) {
        expect(ExperienceManager::calculateLevel($experience))->toBe($level);
    })->with([
        'below the first step' => [0, 1],
        'just short of level 2' => [99, 1],
        'exactly level 2' => [100, 2],
        'between steps' => [249, 2],
        'exactly level 3' => [250, 3],
        'top of the ladder' => [7500, 10],
    ]);

    it('stops at the highest configured threshold', function () {
        expect(ExperienceManager::calculateLevel(999_999))->toBe(10);
    });

    it('follows a ladder the application replaced', function () {
        config()->set('merit.experience.level_thresholds', [1 => 0, 2 => 50, 3 => 200]);

        expect(ExperienceManager::calculateLevel(49))->toBe(1)
            ->and(ExperienceManager::calculateLevel(50))->toBe(2)
            ->and(ExperienceManager::calculateLevel(200))->toBe(3);
    });

    it('reports the experience each level costs', function () {
        expect(ExperienceManager::getExperienceForLevel(1))->toBe(0)
            ->and(ExperienceManager::getExperienceForLevel(5))->toBe(1000)
            ->and(ExperienceManager::getExperienceForLevel(99))->toBe(0);
    });

    it('has no next level to reach at the top of the ladder', function () {
        $user = createUser();
        $user->gainExperience(7500);

        expect($user->getLevel())->toBe(10)
            ->and($user->experiencer->getExperienceForNextLevel())->toBe(PHP_INT_MAX);
    });

    it('precalculates the configured thresholds unchanged', function () {
        expect(ExperienceManager::precalculateLevelRequirements())
            ->toBe(config('merit.experience.level_thresholds'));
    });
});

describe('dynamic formula', function () {
    beforeEach(function () {
        config()->set('merit.experience.level_calculation', 'dynamic');
        config()->set('merit.experience.level_formula', '50 * $level * $level');
    });

    it('maps experience onto the formula', function (int $experience, int $level) {
        expect(ExperienceManager::calculateLevel($experience))->toBe($level);
    })->with([
        'nothing earned yet' => [0, 1],
        'short of the first step' => [49, 1],
        'first step exactly' => [50, 1],
        'short of the second step' => [199, 1],
        'second step exactly' => [200, 2],
        'third step exactly' => [450, 3],
    ]);

    it('reports the experience each level costs', function () {
        expect(ExperienceManager::getExperienceForLevel(1))->toBe(50)
            ->and(ExperienceManager::getExperienceForLevel(2))->toBe(200)
            ->and(ExperienceManager::getExperienceForLevel(4))->toBe(800);
    });

    it('clamps at max_level however much experience is thrown at it', function () {
        config()->set('merit.experience.max_level', 3);

        expect(ExperienceManager::calculateLevel(1_000_000))->toBe(3);
    });

    it("measures a new achiever's progress from zero, not from the formula's first step", function () {
        $progress = createUser()->getExperienceProgress();

        expect($progress['current'])->toBe(0)
            ->and($progress['required'])->toBe(200)
            ->and($progress['percentage'])->toEqual(0);
    });

    it('levels an achiever up through the formula', function () {
        $user = createUser();

        $user->gainExperience(450);

        expect($user->getLevel())->toBe(3);
    });

    it('precalculates one entry per level up to max_level', function () {
        config()->set('merit.experience.max_level', 4);

        expect(ExperienceManager::precalculateLevelRequirements())
            ->toBe([1 => 50, 2 => 200, 3 => 450, 4 => 800]);
    });

    it('precalculates to an explicit ceiling when given one', function () {
        config()->set('merit.experience.max_level', 100);

        expect(ExperienceManager::precalculateLevelRequirements(2))->toBe([1 => 50, 2 => 200]);
    });

    it('caches the precalculated requirements', function () {
        expect(ExperienceManager::getCachedLevelRequirements(2))->toBe([1 => 50, 2 => 200]);

        config()->set('merit.experience.level_formula', '999 * $level');

        expect(ExperienceManager::getCachedLevelRequirements(2))->toBe([1 => 50, 2 => 200]);
    });
});

describe('setLevel()', function () {
    it('promotes an achiever and tops their experience up to the threshold', function () {
        Event::fake([LevelledUp::class, ExperienceGained::class]);

        $user = createUser();

        expect($user->setLevel(3, 'Promoted'))->toBeTrue()
            ->and($user->getLevel())->toBe(3)
            ->and($user->getExperience())->toBe(250);

        Event::assertDispatched(LevelledUp::class, function (LevelledUp $event) {
            return $event->oldLevel === 1 && $event->newLevel === 3;
        });
        Event::assertDispatched(ExperienceGained::class);
    });

    it('leaves experience alone when it already exceeds the target level', function () {
        $user = createUser();
        $user->gainExperience(1000);

        $user->setLevel(6);

        expect($user->getLevel())->toBe(6)
            ->and($user->getExperience())->toBe(1750);
    });

    it('refuses a decrease by default', function () {
        $user = createUser();
        $user->gainExperience(1000);

        expect($user->setLevel(2))->toBeTrue()
            ->and($user->getLevel())->toBe(5)
            ->and($user->getExperience())->toBe(1000);
    });

    it('allows a decrease when the config permits it', function () {
        config()->set('merit.experience.allow_level_decrease', true);

        $user = createUser();
        $user->gainExperience(1000);

        expect($user->setLevel(2))->toBeTrue()
            ->and($user->getLevel())->toBe(2);
    });

    it('brings experience down to the lowered level, so the next gain does not undo it', function () {
        config()->set('merit.experience.allow_level_decrease', true);

        $user = createUser();
        $user->gainExperience(1000);

        Event::fake([LevelledUp::class, ExperienceLost::class]);

        $user->setLevel(2);

        expect($user->getLevel())->toBe(2)
            ->and($user->getExperience())->toBe(100);
        Event::assertDispatched(ExperienceLost::class, fn (ExperienceLost $event) => $event->amount === 900);

        // One more point used to recalculate level 5 and announce it.
        $user->gainExperience(1);

        expect($user->getLevel())->toBe(2);
        Event::assertNotDispatched(LevelledUp::class);
    });

    it('leaves experience alone when lowering to a level it is already below', function () {
        config()->set('merit.experience.allow_level_decrease', true);

        $user = createUser();
        $user->gainExperience(1000);
        $user->loseExperience(950);

        $user->setLevel(2);

        expect($user->getLevel())->toBe(2)
            ->and($user->getExperience())->toBe(50);
    });

    it('records a forced decrease as an administrative change', function () {
        config()->set('merit.experience.allow_level_decrease', true);

        $user = createUser();
        $user->gainExperience(1000);

        Event::fake([LevelledUp::class]);

        $user->setLevel(2);

        // Levels going down is administrative: there is no event for it.
        Event::assertNotDispatched(LevelledUp::class);
    });

    it('rejects a level of zero or less', function () {
        $user = createUser();

        expect($user->setLevel(0))->toBeFalse()
            ->and($user->setLevel(-1))->toBeFalse()
            ->and($user->getLevel())->toBe(1);
    });

    it('is a no-op when the achiever is already at the target level', function () {
        $user = createUser();
        $user->gainExperience(150);

        Event::fake([LevelledUp::class]);

        expect($user->setLevel(2))->toBeTrue()
            ->and($user->getLevel())->toBe(2)
            ->and($user->getExperience())->toBe(150);

        Event::assertNotDispatched(LevelledUp::class);
    });
});
