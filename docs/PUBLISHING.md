# Publishing Laravel Merit Assets

After installing Laravel Merit, you can publish various assets to customize the package behavior.

## Publishing the Configuration File

### Option 1: Publish Everything
```bash
php artisan vendor:publish --provider="Ram64\LaravelMerit\LaravelMeritServiceProvider"
```

### Option 2: Publish Only Config
```bash
php artisan vendor:publish --provider="Ram64\LaravelMerit\LaravelMeritServiceProvider" --tag="laravel-merit-config"
```

### Option 3: Using Package Name Tag
```bash
php artisan vendor:publish --tag="merit-config"
```

This will copy the config file from `vendor/ram64/laravel-merit/config/merit.php` to your application's `config/merit.php`.

## Configuration Options

Once published, you can customize these settings in `config/merit.php`:

```php
<?php

return [
    /**
     * The default Achiever model.
     * This model should use the Achiever trait.
     */
    'achiever_model' => env('MERIT_ACHIEVER_MODEL', \App\Models\User::class),

    /**
     * The table used to store achiever achievements.
     */
    'achievements_table' => env('MERIT_ACHIEVEMENTS_TABLE', 'achiever_achievements'),

    /**
     * Cache TTL in minutes for achievements.
     */
    'cache_ttl' => 10,

    /**
     * Whether to queue events or dispatch them synchronously.
     */
    'queue_achievements' => env('MERIT_QUEUE_EVENTS', false),
    
    'queue_connection' => env('MERIT_QUEUE_CONNECTION', null),

    /**
     * Achievement class namespace and path
     */
    'achievements_namespace' => 'App\\Achievements\\',
    'achievements_path' => app_path('Achievements'),
];
```

## Publishing Migrations

The migration is automatically registered, but if you need to publish it for customization:

```bash
php artisan vendor:publish --provider="Ram64\LaravelMerit\LaravelMeritServiceProvider" --tag="laravel-merit-migrations"
```

## Publishing Views (if any)

```bash
php artisan vendor:publish --provider="Ram64\LaravelMerit\LaravelMeritServiceProvider" --tag="laravel-merit-views"
```

## Environment Variables

You can also configure Laravel Merit using environment variables in your `.env` file:

```env
# Merit Configuration
MERIT_ACHIEVER_MODEL="App\Models\User"
MERIT_ACHIEVEMENTS_TABLE="achiever_achievements"
MERIT_QUEUE_EVENTS=true
MERIT_QUEUE_CONNECTION=redis
```

## After Publishing

1. **Review the config**: Check `config/merit.php` and adjust settings as needed
2. **Set up your model**: Make sure your User (or other) model uses the `Achiever` trait
3. **Create achievements**: Use `php artisan make:merit-achievement` to create your first achievements
4. **Run migrations**: `php artisan migrate` to create the achievements table

## Complete Setup Example

```bash
# Install the package
composer require ram64/laravel-merit

# Publish config
php artisan vendor:publish --tag="merit-config"

# Run migrations
php artisan migrate

# Create your first achievement
php artisan make:merit-achievement FirstPost
```
