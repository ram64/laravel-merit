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

/**
 * Load a generated definition in a fresh PHP process and report on it.
 *
 * A separate process, because a class whose methods do not match the parent
 * is a fatal error at declaration time and would take the suite down with it.
 *
 * @return array{secret: bool, qualifies: bool, goal: int}
 */
function inspectGenerated(string $file, string $class): array
{
    $autoload = realpath(__DIR__.'/../vendor/autoload.php');

    $code = 'require '.var_export($autoload, true).';'
        .'require '.var_export($file, true).';'
        .'$c = '.var_export($class, true).';'
        .'$m = new class extends Illuminate\Database\Eloquent\Model {};'
        .'echo json_encode(["secret" => $c::isSecret(), "qualifies" => $c::qualifiesFor($m), "goal" => $c::goal()]);';

    $process = new Symfony\Component\Process\Process([PHP_BINARY, '-r', $code]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true);
}

it('generates a definition that PHP can load', function () {
    makeAchievement($this, 'FirstCatch')->run();

    expect(inspectGenerated($this->generatedPath.'/FirstCatch.php', 'Generated\\Achievements\\FirstCatch'))
        ->toBe(['secret' => false, 'qualifies' => false, 'goal' => 3]);
});

it('marks a secret achievement secret without making it qualify', function () {
    makeAchievement($this, 'HiddenCatch', secret: true)->run();

    expect(inspectGenerated($this->generatedPath.'/HiddenCatch.php', 'Generated\\Achievements\\HiddenCatch'))
        ->toBe(['secret' => true, 'qualifies' => false, 'goal' => 3]);
});
