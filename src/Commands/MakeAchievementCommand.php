<?php

namespace Ram64\LaravelMerit\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Ram64\LaravelMerit\Services\AchievementTracker;

class MakeAchievementCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'make:merit-achievement {name : The name of the achievement class}';

    /**
     * The console command description.
     */
    protected $description = 'Create a new achievement class';

    /**
     * The type of class being generated.
     */
    protected $type = 'Achievement';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = $this->qualifyClass($this->getNameInput());
        $path = $this->getPath($name);

        // Check if achievement already exists
        if ($this->files->exists($path)) {
            $this->error($this->type . ' already exists!');
            return false;
        }

        // Generate achievement key from class name
        $className = class_basename($name);
        $key = $this->generateKey($className);

        // Check if key is unique among existing achievements
        if ($this->isKeyDuplicate($key)) {
            $this->error("Achievement key '{$key}' already exists! Choose a different name.");
            return false;
        }

        // Make the directory
        $this->makeDirectory($path);

        // Generate readable achievement name
        $achievementName = $this->generateReadableName($className);

        // Ask for additional information
        $goal = $this->askForGoal();
        $prerequisite = $this->askForPrerequisite();
        $description = $this->askForDescription($achievementName);
        $isSecret = $this->askIfSecret();

        // Build the class content
        $stub = $this->buildClass($name);

        // Replace the key placeholder
        $stub = str_replace('DummyKey', $key, $stub);

        // Replace name and description placeholders
        $stub = str_replace('DummyName', $achievementName, $stub);
        $stub = str_replace('DummyDescription', $description, $stub);

        // Replace goal placeholder
        $stub = str_replace('return 1;', "return {$goal};", $stub);
        
        // Replace secret placeholder
        $secretValue = $isSecret ? 'true' : 'false';
        $stub = str_replace('return false;', "return {$secretValue};", $stub);

        // Replace prerequisite placeholder
        $prerequisiteValue = $prerequisite ? "'{$prerequisite}'" : 'null';
        $stub = str_replace('return null;', "return {$prerequisiteValue};", $stub);

        $this->files->put($path, $stub);

        $this->info($this->type . ' created successfully.');
        $this->line("Achievement key: <info>{$key}</info>");
        $this->line("Achievement name: <info>{$achievementName}</info>");
        $this->line("Goal: <info>{$goal}</info>");
        $this->line("Secret: <info>" . ($isSecret ? 'yes' : 'no') . "</info>");
        $this->line("Prerequisite: <info>" . ($prerequisite ?: 'none') . "</info>");

        return true;
    }

    /**
     * Get the stub file for the generator.
     */
    protected function getStub()
    {
        return __DIR__ . '/../../stubs/achievement.stub';
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace($rootNamespace)
    {
        return config('merit.achievements_namespace', $rootNamespace . '\\Achievements');
    }

    /**
     * Get the destination class path.
     */
    protected function getPath($name)
    {
        // Just get the class basename and put it directly in achievements directory
        $className = class_basename($name);
        $achievementsPath = config('merit.achievements_path', app_path('Achievements'));

        return $achievementsPath . '/' . $className . '.php';
    }

    /**
     * Generate a unique key from the class name
     */
    protected function generateKey(string $className): string
    {
        // Remove 'Achievement' suffix if present
        $key = Str::of($className)
            ->replaceLast('Achievement', '')
            ->snake()
            ->toString();

        return $key;
    }

    /**
     * Generate a readable name from the class name
     */
    protected function generateReadableName(string $className): string
    {
        // Remove 'Achievement' suffix if present and convert to title case
        return Str::of($className)
            ->replaceLast('Achievement', '')
            ->kebab()
            ->replace('-', ' ')
            ->title()
            ->toString();
    }

    /**
     * Check if the achievement key already exists
     */
    protected function isKeyDuplicate(string $key): bool
    {
        try {
            $existingKeys = AchievementTracker::getAchievementKeys();
            return in_array($key, $existingKeys);
        } catch (\Exception $e) {
            // If we can't check existing keys, assume it's fine
            return false;
        }
    }

    /**
     * Build the class with the given name.
     */
    protected function buildClass($name)
    {
        $stub = $this->files->get($this->getStub());

        return $this->replaceNamespace($stub, $name)->replaceClass($stub, $name);
    }

    /**
     * Replace the namespace for the given stub.
     */
    protected function replaceNamespace(&$stub, $name)
    {
        $searches = [
            ['DummyNamespace', 'DummyRootNamespace', 'NamespacedDummyUserModel'],
            ['{{ namespace }}', '{{ rootNamespace }}', '{{ namespacedUserModel }}'],
            ['{{{namespace}}}', '{{{rootNamespace}}}', '{{{namespacedUserModel}}}'],
        ];

        foreach ($searches as $search) {
            $stub = str_replace(
                $search,
                [$this->getNamespace($name), $this->rootNamespace(), config('auth.providers.users.model')],
                $stub
            );
        }

        return $this;
    }

    /**
     * Replace the class name for the given stub.
     */
    protected function replaceClass($stub, $name)
    {
        $class = class_basename($name);

        return str_replace('DummyClass', $class, $stub);
    }

    /**
     * Ask user for the achievement goal
     */
    protected function askForGoal(): int
    {
        $goal = $this->ask('What is the goal for this achievement? (default: 1)', '1');

        if (!is_numeric($goal) || $goal < 1) {
            $this->error('Goal must be a positive number. Using default value of 1.');
            return 1;
        }

        return (int) $goal;
    }

    /**
     * Ask user for prerequisite achievement
     */
    protected function askForPrerequisite(): ?string
    {
        $this->line('Available achievements:');
        try {
            $existingKeys = AchievementTracker::getAchievementKeys();
            if (empty($existingKeys)) {
                $this->line('  <comment>No existing achievements found</comment>');
            } else {
                foreach ($existingKeys as $key) {
                    $this->line("  - {$key}");
                }
            }
        } catch (\Exception $e) {
            $this->line('  <comment>Could not load existing achievements</comment>');
        }

        $prerequisite = $this->ask('Enter prerequisite achievement key (optional, press enter to skip)');

        return empty($prerequisite) ? null : $prerequisite;
    }

    /**
     * Ask user for custom description
     */
    protected function askForDescription(string $defaultName): string
    {
        $defaultDescription = "Achieve {$defaultName}";
        $description = $this->ask("Enter achievement description (default: {$defaultDescription})", $defaultDescription);

        return $description ?: $defaultDescription;
    }

    /**
     * Ask user if this should be a secret achievement
     */
    protected function askIfSecret(): bool
    {
        return $this->confirm('Should this be a secret achievement? (hidden until unlocked)', false);
    }
}
