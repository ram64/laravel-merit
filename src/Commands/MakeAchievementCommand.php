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

        // Build the class content
        $stub = $this->buildClass($name);

        // Replace the key placeholder
        $stub = str_replace('DummyKey', $key, $stub);

        // Replace name and description placeholders
        $achievementName = $this->generateReadableName($className);
        $stub = str_replace('DummyName', $achievementName, $stub);
        $stub = str_replace('DummyDescription', "Achieve {$achievementName}", $stub);

        $this->files->put($path, $stub);

        $this->info($this->type . ' created successfully.');
        $this->line("Achievement key: <info>{$key}</info>");
        $this->line("Achievement name: <info>{$achievementName}</info>");

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
        // Get the class name without the root namespace
        $name = Str::replaceFirst($this->rootNamespace(), '', $name);

        // Remove the leading "Achievements" part since our path already points to Achievements directory
        $name = Str::replaceFirst('\\Achievements\\', '\\', $name);

        $achievementsPath = config('merit.achievements_path', app_path('Achievements'));

        return $achievementsPath . '/' . str_replace('\\', '/', ltrim($name, '\\')) . '.php';
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
        $class = str_replace($this->getNamespace($name) . '\\', '', $name);

        return str_replace('DummyClass', $class, $stub);
    }
}
