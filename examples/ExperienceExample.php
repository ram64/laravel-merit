<?php

// Example usage of the Laravel Merit Experience System

use App\Models\User;
use Ram64\LaravelMerit\Services\ExperienceManager;
use Ram64\LaravelMerit\Events\ExperienceGained;
use Ram64\LaravelMerit\Events\ExperienceLost;
use Ram64\LaravelMerit\Events\LevelledUp;

class ExperienceExample
{
    public function basicUsage()
    {
        $user = User::find(1);

        // Award experience
        $user->gainExperience(100, 'Completed daily quest');

        // Check current stats
        $experience = $user->getExperience(); // 100
        $level = $user->getLevel(); // 2 (if threshold is 50 for level 2)
        $progress = $user->getExperienceProgress();

        // Deduct experience
        $user->loseExperience(25, 'Penalty for late submission');

        // Set specific experience amount
        $user->setExperience(200, 'Admin adjustment');
    }

    public function usingService()
    {
        $user = User::find(1);
        $experienceManager = new ExperienceManager();

        // Award experience
        $experienceManager->awardExperience($user, 50, 'Forum post');

        // Get experience data
        $data = $experienceManager->getExperienceData($user);

        // Get logs
        $logs = $experienceManager->getExperienceLogs($user, 10);
    }

    public function bulkOperations()
    {
        $experienceManager = new ExperienceManager();

        $users = User::all();
        $awards = $users->map(function ($user) {
            return [
                'experiencer' => $user,
                'amount' => 10,
                'reason' => 'Daily login bonus',
                'metadata' => ['source' => 'cron_job']
            ];
        })->toArray();

        $results = $experienceManager->bulkAwardExperience($awards);
    }

    public function eventHandling()
    {
        // Listen for experience gained
        Event::listen(ExperienceGained::class, function ($event) {
            Log::info("{$event->experiencer->name} gained {$event->amount} XP: {$event->reason}");

            // Maybe send a notification (create your own notification class)
            // $event->experiencer->notify(new ExperienceGainedNotification($event->amount));
        });

        // Listen for level ups
        Event::listen(LevelledUp::class, function ($event) {
            Log::info("{$event->experiencer->name} levelled up to {$event->newLevel}!");

            // Award achievement or send special notification
            if ($event->newLevel >= 10) {
                $event->experiencer->unlockAchievement('MasterLevel');
            }
        });

        // Listen for experience lost
        Event::listen(ExperienceLost::class, function ($event) {
            Log::info("{$event->experiencer->name} lost {$event->amount} XP: {$event->reason}");
        });
    }

    public function levelBasedFeatures()
    {
        $user = User::find(1);

        // Check if user can access premium features
        if ($user->canAccessFeature('premium_content')) {
            // Show premium content
        }

        // Get user's level badge
        $badge = $user->getLevelBadge(); // 'Expert', 'Master', etc.

        // Display in UI
        echo "Level {$user->getLevel()} {$badge}";
    }

    public function statistics()
    {
        $experienceManager = new ExperienceManager();
        $stats = $experienceManager->getStatistics();

        echo "Total users: {$stats['total_experiencers']}\n";
        echo "Total XP awarded: {$stats['total_experience_awarded']}\n";
        echo "Average level: {$stats['average_level']}\n";
        echo "Highest level: {$stats['highest_level']}\n";
    }

    public function customLevelCalculation()
    {
        // For custom level calculation, extend the Experiencer model
        // and override the calculateLevel method

        // Example: Exponential growth
        // Level = floor(log(experience + 1) / log(2))
        // 1 XP = Level 1, 3 XP = Level 2, 7 XP = Level 3, etc.
    }
}