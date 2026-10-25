# Secret Achievements

Secret achievements are hidden from users until they unlock them, adding mystery and surprise to your gamification system.

## Creating Secret Achievements

Use the artisan command:

```bash
php artisan make:merit-achievement NightOwlAchievement
```

When prompted:
- **Goal**: `1`
- **Secret**: `yes`
- **Description**: `Post something at exactly midnight`

## How Secret Achievements Work

### For Users Who Haven't Unlocked It:
- **Name**: `"Hidden Achievement"`
- **Description**: `"Complete certain actions to unlock this secret achievement."`
- **Visible**: Only shows in "??? Secret Achievement" style

### For Users Who Have Unlocked It:
- **Name**: `"Night Owl"` (actual name)
- **Description**: `"Post something at exactly midnight"` (actual description)
- **Visible**: Shows full details

## Usage Examples

### Display Achievements to User

```php
use Ram64\LaravelMerit\Services\AchievementTracker;

// Get only visible achievements (excludes locked secrets)
$visibleAchievements = AchievementTracker::getVisibleAchievements($user);

// Get achievement with appropriate display info
$achievement = AchievementTracker::getAchievementDisplay($user, 'night_owl');

// Results for non-unlocked secret:
[
    'key' => 'night_owl',
    'name' => 'Hidden Achievement',
    'description' => 'Complete certain actions to unlock this secret achievement.',
    'is_secret' => true,
    'is_visible' => false,
    'is_unlocked' => false,
    // ... other fields
]
```

### Check Visibility

```php
use App\Definitions\Achievements\NightOwlAchievement;

// Check if achievement should be shown to user
if (NightOwlAchievement::isVisibleTo($user)) {
    echo NightOwlAchievement::name(); // Shows real name
} else {
    echo "??? Secret Achievement"; // Hidden
}

// Get appropriate display name
echo NightOwlAchievement::getDisplayName($user);
// Returns "Night Owl" if unlocked, "Hidden Achievement" if not

// Get appropriate description  
echo NightOwlAchievement::getDisplayDescription($user);
// Returns real description if unlocked, generic message if not
```

## Implementation Example

```php
<?php

namespace App\Definitions\Achievements;

use Ram64\LaravelMerit\Definitions\Achievements\AchievementDefinition;
use Illuminate\Database\Eloquent\Model;

class SecretMasterAchievement extends AchievementDefinition
{
    public static function isSecret(): bool
    {
        return true; // This makes it secret
    }

    public static function name(?Model $achiever = null): string
    {
        return 'Secret Master';
    }

    public static function description(?Model $achiever = null): string
    {
        return 'Unlock 10 other achievements';
    }

    public static function qualifiesFor(Model $achiever): bool
    {
        $unlockedCount = $achiever->achievements()
            ->whereNotNull('unlocked_at')
            ->count();
            
        return $unlockedCount >= 10;
    }

    // ... other required methods
}
```

## UI Implementation Tips

```blade
{{-- In your Blade template --}}
@foreach($achievements as $achievement)
    <div class="achievement-card">
        @if($achievement['is_secret'] && !$achievement['is_unlocked'])
            <div class="secret-achievement">
                <i class="icon-question"></i>
                <h3>??? {{ $achievement['name'] }}</h3>
                <p class="text-muted">{{ $achievement['description'] }}</p>
            </div>
        @else
            <div class="normal-achievement">
                <h3>{{ $achievement['name'] }}</h3>
                <p>{{ $achievement['description'] }}</p>
                <div class="progress-bar">
                    <div style="width: {{ $achievement['progress_percentage'] }}%"></div>
                </div>
            </div>
        @endif
    </div>
@endforeach
```

## Changing the placeholder text

Override `hiddenName()` and `hiddenDescription()` on the definition:

```php
public static function hiddenName(): string
{
    return '???';
}
```

## Reading the cache directly

Only the real name and description are ever cached. Anything that reads the
`merit:name:` or `merit:description:` keys itself must apply the secret gate
(`isTextHiddenFrom($achiever)`), or the real text of a locked secret leaks.
`AchievementTracker::getDisplayTextMany()` and `getAchievementDisplayMany()`
read the cache in bulk and apply the gate for you.
