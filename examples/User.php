<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Concerns\Experiencer;

class User extends Model
{
    use Experiencer;

    protected $fillable = [
        'name',
        'email',
    ];

    // Example methods showing experience integration
    public function completeTutorial()
    {
        // Award experience for completing tutorial
        $this->gainExperience(50, 'Completed tutorial');
    }

    public function postArticle()
    {
        // Award experience for posting an article
        $this->gainExperience(25, 'Posted an article');
    }

    public function receivePenalty()
    {
        // Deduct experience for rule violation
        $this->loseExperience(10, 'Rule violation penalty');
    }

    public function getLevelBadge(): string
    {
        $level = $this->getLevel();

        return match (true) {
            $level >= 10 => 'Master',
            $level >= 5 => 'Expert',
            $level >= 3 => 'Advanced',
            $level >= 2 => 'Intermediate',
            default => 'Beginner',
        };
    }

    public function canAccessFeature(string $feature): bool
    {
        $level = $this->getLevel();

        return match ($feature) {
            'premium_content' => $level >= 3,
            'admin_panel' => $level >= 10,
            'advanced_tools' => $level >= 5,
            default => true,
        };
    }
}