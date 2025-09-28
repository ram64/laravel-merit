<?php

namespace LaravelMerit\Services;

use ReflectionClass;

class AchievementService
{
    public function getAllAchievements(): array
    {
        $classes = [];
        foreach (glob(__DIR__.'/../Achievements/*.php') as $file) {
            $class = 'LaravelMerit\\Achievements\\'.pathinfo($file, PATHINFO_FILENAME);
            if (! class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }
            $classes[] = $class;
        }

        return $classes;
    }
}
