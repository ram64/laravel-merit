# Multiple Prerequisites for Achievements

Laravel Merit now supports both single and multiple prerequisites for achievements. You can define prerequisites as either a single string or an array of achievement keys.

## Usage Examples

### Single Prerequisite
```php
<?php

namespace App\Definitions\Achievements\General;

use Ram64\LaravelMerit\Definitions\Achievements\AchievementDefinition;
use Illuminate\Database\Eloquent\Model;

class SecondPostAchievement extends AchievementDefinition
{
    public static function key(): string
    {
        return 'second_post';
    }

    public static function name(): string
    {
        return 'Second Post';
    }

    public static function description(): string
    {
        return 'Create your second blog post';
    }

    public static function goal(): int
    {
        return 1;
    }

    // Single prerequisite
    public static function prerequisite(): string|array|null
    {
        return 'first_post';
    }

    public static function qualifiesFor(Model $achiever): bool
    {
        return $achiever->posts()->count() >= 2;
    }

    public static function calculateCurrentProgress(Model $achiever): int
    {
        return min($achiever->posts()->count(), 2);
    }
}
```

### Multiple Prerequisites
```php
<?php

namespace App\Definitions\Achievements\Advanced;

use Ram64\LaravelMerit\Definitions\Achievements\AchievementDefinition;
use Illuminate\Database\Eloquent\Model;

class ExpertWriterAchievement extends AchievementDefinition
{
    public static function key(): string
    {
        return 'expert_writer';
    }

    public static function name(): string
    {
        return 'Expert Writer';
    }

    public static function description(): string
    {
        return 'Master the art of writing';
    }

    public static function goal(): int
    {
        return 1;
    }

    // Multiple prerequisites - ALL must be completed
    public static function prerequisite(): string|array|null
    {
        return [
            'first_post',
            'ten_posts',
            'first_comment',
            'daily_streak_7'
        ];
    }

    public static function qualifiesFor(Model $achiever): bool
    {
        return $achiever->posts()->count() >= 50 && 
               $achiever->comments()->count() >= 100;
    }

    public static function calculateCurrentProgress(Model $achiever): int
    {
        $hasEnoughPosts = $achiever->posts()->count() >= 50;
        $hasEnoughComments = $achiever->comments()->count() >= 100;
        
        return $hasEnoughPosts && $hasEnoughComments ? 1 : 0;
    }
}
```

### No Prerequisites
```php
<?php

namespace App\Definitions\Achievements\General;

use Ram64\LaravelMerit\Definitions\Achievements\AchievementDefinition;
use Illuminate\Database\Eloquent\Model;

class WelcomeAchievement extends AchievementDefinition
{
    public static function key(): string
    {
        return 'welcome';
    }

    public static function name(): string
    {
        return 'Welcome!';
    }

    public static function description(): string
    {
        return 'Welcome to our platform';
    }

    public static function goal(): int
    {
        return 1;
    }

    // No prerequisites
    public static function prerequisite(): string|array|null
    {
        return null;
    }

    public static function qualifiesFor(Model $achiever): bool
    {
        return true; // Always qualifies
    }

    public static function calculateCurrentProgress(Model $achiever): int
    {
        return 1; // Always completed
    }
}
```

## How Prerequisites Work

### Logic
- **Single prerequisite (string)**: The specified achievement must be unlocked
- **Multiple prerequisites (array)**: **ALL** achievements in the array must be unlocked
- **No prerequisites (null)**: Achievement can be started immediately

### Checking Prerequisites
The `canStart()` method automatically handles all three cases:

```php
public static function canStart(Model $achiever): bool
{
    $prerequisites = static::prerequisite();

    if (!$prerequisites) {
        return true; // No prerequisites
    }

    // Convert single prerequisite to array for uniform handling
    if (is_string($prerequisites)) {
        $prerequisites = [$prerequisites];
    }

    // Check if ALL prerequisites are unlocked
    foreach ($prerequisites as $prerequisite) {
        $isUnlocked = AchieverAchievement::where('achiever_id', $achiever->id)
            ->where('achiever_type', get_class($achiever))
            ->where('achievement_key', $prerequisite)
            ->whereNotNull('unlocked_at')
            ->exists();

        if (!$isUnlocked) {
            return false; // At least one prerequisite not met
        }
    }

    return true; // All prerequisites met
}
```

## Using the Make Command

When creating achievements with the artisan command:

```bash
php artisan make:merit-achievement ExpertWriter
```

You'll be prompted:
1. **Does this achievement have prerequisites?** (yes/no)
2. **Multiple prerequisites?** (yes/no)
3. If multiple, you can enter keys one by one
4. If single, enter one key

Example interaction:
```
What category should this achievement be in? [General]: Advanced
What is the goal for this achievement? [1]: 1
Enter achievement description [Achieve Expert Writer]: Master the art of writing
Should this be a secret achievement? (yes/no) [no]: no
Does this achievement have prerequisites? (yes/no) [no]: yes
Multiple prerequisites? (yes/no) [no]: yes
Enter prerequisite keys one by one (press enter with empty value to finish):
Enter prerequisite achievement key (or press enter to finish): first_post
  ✓ Added: first_post
Enter prerequisite achievement key (or press enter to finish): ten_posts
  ✓ Added: ten_posts
Enter prerequisite achievement key (or press enter to finish): daily_streak_7
  ✓ Added: daily_streak_7
Enter prerequisite achievement key (or press enter to finish): 

Achievement created successfully!
File: app/Definitions/Achievements/Advanced/ExpertWriterAchievement.php
Key: expert_writer
Name: Expert Writer
Category: Advanced
Goal: 1
Secret: no
Prerequisites: first_post, ten_posts, daily_streak_7
```

## Benefits

✅ **Flexible**: Support both single and multiple prerequisites  
✅ **Type Safe**: Uses union types for better IDE support  
✅ **Backward Compatible**: Existing achievements with single prerequisites still work  
✅ **User Friendly**: Command prompts guide you through setup  
✅ **Logical**: ALL prerequisites must be met (AND logic)

This system allows you to create complex achievement chains and unlock sequences! 🎯🔗
