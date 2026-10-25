<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->generatedPath = sys_get_temp_dir().'/merit-make-'.bin2hex(random_bytes(4));

    config()->set('merit.achievements_path', $this->generatedPath);
    config()->set('merit.achievements_namespace', 'Generated\\Achievements\\');
});

afterEach(function () {
    File::deleteDirectory($this->generatedPath);
});

/**
 * Run the generator with every prompt answered.
 */
function makeAchievement($test, string $name, bool $secret = false)
{
    return $test->artisan('make:merit-achievement', ['name' => $name])
        ->expectsQuestion('What is the goal for this achievement? (default: 1)', '3')
        ->expectsConfirmation('Does this achievement have prerequisites?', 'no')
        ->expectsQuestion('Enter achievement description (default: Achieve '.str($name)->headline().')', 'Land a fish')
        ->expectsConfirmation('Should this be a secret achievement? (hidden until unlocked)', $secret ? 'yes' : 'no');
}

it('writes the class into the configured namespace, even outside the app namespace', function () {
    makeAchievement($this, 'FirstCatch')->run();

    $contents = File::get($this->generatedPath.'/FirstCatch.php');

    expect($contents)->toContain('namespace Generated\\Achievements;')
        ->and($contents)->toContain('class FirstCatch extends AchievementDefinition')
        ->and($contents)->toContain("return 'first_catch';");
});
