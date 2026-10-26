<?php

use Illuminate\Support\Facades\File;
use Ram64\LaravelMerit\Tests\TestCase;
use Symfony\Component\Process\Process;

beforeEach(function () {
    config()->set('merit.achievements_path', sys_get_temp_dir().'/merit-make-'.bin2hex(random_bytes(4)));
    config()->set('merit.achievements_namespace', 'Generated\\Achievements\\');
});

afterEach(function () {
    File::deleteDirectory(generatedPath());
});

/**
 * The directory this test's achievements are generated into.
 */
function generatedPath(): string
{
    return config('merit.achievements_path');
}

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

    $contents = File::get(generatedPath().'/FirstCatch.php');

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
 * @return array{secret: bool, qualifies: bool, goal: int, icon: ?string, image: ?string}
 */
function inspectGenerated(string $file, string $class): array
{
    $autoload = realpath(__DIR__.'/../vendor/autoload.php');

    $code = 'require '.var_export($autoload, true).';'
        .'require '.var_export($file, true).';'
        .'$c = '.var_export($class, true).';'
        .'$m = new class extends Illuminate\Database\Eloquent\Model {};'
        .'echo json_encode(["secret" => $c::isSecret(), "qualifies" => $c::qualifiesFor($m), "goal" => $c::goal(), "icon" => $c::icon($m), "image" => $c::image($m)]);';

    $process = new Process([PHP_BINARY, '-r', $code]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());

    return json_decode($process->getOutput(), true);
}

it('generates a definition that PHP can load', function () {
    makeAchievement($this, 'FirstCatch')->run();

    expect(inspectGenerated(generatedPath().'/FirstCatch.php', 'Generated\\Achievements\\FirstCatch'))
        ->toBe(['secret' => false, 'qualifies' => false, 'goal' => 3, 'icon' => null, 'image' => null]);
});

it('marks a secret achievement secret without making it qualify', function () {
    makeAchievement($this, 'HiddenCatch', secret: true)->run();

    expect(inspectGenerated(generatedPath().'/HiddenCatch.php', 'Generated\\Achievements\\HiddenCatch'))
        ->toBe(['secret' => true, 'qualifies' => false, 'goal' => 3, 'icon' => null, 'image' => null]);
});

it('generates a loadable class from a description with quotes and a prerequisite', function () {
    $test = $this;
    /** @var TestCase $test */
    $test->artisan('make:merit-achievement', ['name' => 'SecondCatch'])
        ->expectsQuestion('What is the goal for this achievement? (default: 1)', '1')
        ->expectsConfirmation('Does this achievement have prerequisites?', 'yes')
        ->expectsConfirmation('Multiple prerequisites?', 'no')
        ->expectsQuestion('Enter prerequisite achievement key', 'first_catch')
        ->expectsQuestion('Enter achievement description (default: Achieve Second Catch)', "Land the angler's second fish \\o/")
        ->expectsConfirmation('Should this be a secret achievement? (hidden until unlocked)', 'no')
        ->assertSuccessful();

    $file = generatedPath().'/SecondCatch.php';
    $class = 'Generated\\Achievements\\SecondCatch';

    $process = new Process([PHP_BINARY, '-r',
        'require '.var_export(realpath(__DIR__.'/../vendor/autoload.php'), true).';'
        .'require '.var_export($file, true).';'
        .'echo json_encode([\\'.$class.'::description(), \\'.$class.'::prerequisite()]);',
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput())
        ->and(json_decode($process->getOutput(), true))->toBe(["Land the angler's second fish \\o/", 'first_catch']);
});

it('names the conventional image file after the key in the generated comment', function () {
    makeAchievement($this, 'FirstCatch')->run();

    expect(File::get(generatedPath().'/FirstCatch.php'))->toContain('first_catch.webp (or .png)');
});

it('exits successfully when it writes the class', function () {
    makeAchievement($this, 'FirstCatch')->assertSuccessful();
});

it('exits with a failure when the class already exists', function () {
    File::ensureDirectoryExists(generatedPath());
    File::put(generatedPath().'/FirstCatch.php', '<?php');

    // Refused before any prompt, so there is nothing to answer.
    $test = $this;
    /** @var TestCase $test */
    $test->artisan('make:merit-achievement', ['name' => 'FirstCatch'])->assertFailed();
});
