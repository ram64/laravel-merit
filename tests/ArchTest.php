<?php

use Illuminate\Contracts\Queue\ShouldQueue;

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

// Queueing is decided by a listener implementing ShouldQueue. On an event the
// interface does nothing, and only suggests the event itself is queued.
arch('events do not claim to be queued')
    ->expect('Ram64\LaravelMerit\Events')
    ->not->toImplement(ShouldQueue::class);
