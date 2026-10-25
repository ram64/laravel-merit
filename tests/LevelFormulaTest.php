<?php

use Ram64\LaravelMerit\Services\ExperienceManager;
use Ram64\LaravelMerit\Support\LevelFormula;
use Ram64\LaravelMerit\Tests\Fixtures\Formulas\TripleLevel;

it('gives what PHP gives for the formulas in use', function (string $formula, Closure $php) {
    foreach (range(1, 100) as $level) {
        expect(LevelFormula::evaluate($formula, $level))->toBe((int) $php($level));
    }
})->with([
    'the default' => ['50 * $level * $level', fn (int $level) => 50 * $level * $level],
    'the hub' => [
        'round((100 * (pow($level, 2) - $level) / 2) / 10) * 10',
        fn (int $level) => round((100 * (pow($level, 2) - $level) / 2) / 10) * 10,
    ],
    'fractions and floor' => ['floor(12.5 * $level ** 1.5)', fn (int $level) => floor(12.5 * $level ** 1.5)],
    'modulo and min' => ['min(1000, $level % 7 * 100 + 1)', fn (int $level) => min(1000, $level % 7 * 100 + 1)],
]);

it('follows PHP precedence', function (string $formula, int|float $expected) {
    expect(LevelFormula::evaluate($formula, 3))->toBe((int) $expected);
})->with([
    'multiplication before addition' => ['1 + 2 * $level', 7],
    'parentheses first' => ['(1 + 2) * $level', 9],
    'power before a leading sign' => ['-2 ** 2 + 10', 6],
    'power is right-associative' => ['2 ** 3 ** 2 / 64', 8],
    'left to right within a level' => ['100 / 10 / 2', 5],
]);

it('calls an invokable class named as the formula', function () {
    config()->set('merit.experience.level_calculation', 'dynamic');
    config()->set('merit.experience.level_formula', TripleLevel::class);

    expect(ExperienceManager::getExperienceForLevel(4))->toBe(12)
        ->and(ExperienceManager::calculateLevel(9))->toBe(3);
});

it('refuses anything that is not arithmetic', function (string $formula) {
    LevelFormula::evaluate($formula, 1);
})->with([
    'a function outside the list' => ['system("id")'],
    'a statement separator' => ['$level; exit'],
    'backticks' => ['`id`'],
    'another variable' => ['$levels * 2'],
    'an unclosed parenthesis' => ['(1 + $level'],
    'a dangling operator' => ['$level *'],
])->throws(InvalidArgumentException::class);
