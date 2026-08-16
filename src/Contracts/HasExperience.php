<?php

namespace Ram64\LaravelMerit\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphOne;
use Ram64\LaravelMerit\Models\Experiencer;

/**
 * What the CanExperience trait provides.
 *
 * A model is not required to declare `implements HasExperience` — the trait
 * satisfies this shape on its own, and ExperienceManager still type-hints a
 * plain Model and guards at runtime, so nothing is enforced at the boundary.
 * The interface exists so "a Model that uses CanExperience" is nameable: static
 * analysis has no way to express it otherwise, and without it every call into
 * the trait from ExperienceManager reads as a call to an undefined method.
 *
 * @see \Ram64\LaravelMerit\Concerns\CanExperience
 */
interface HasExperience
{
    /**
     * @return MorphOne<Experiencer, covariant \Illuminate\Database\Eloquent\Model>
     */
    public function experiencer(): MorphOne;

    public function getExperience(): int;

    public function getLevel(): int;

    /**
     * @return array{current: int|float, required: int|float, percentage: int|float}
     */
    public function getExperienceProgress(): array;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function gainExperience(int $amount, ?string $reason = null, array $metadata = []): bool;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function loseExperience(int $amount, ?string $reason = null, array $metadata = []): bool;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function setExperience(int $amount, ?string $reason = null, array $metadata = []): bool;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function setLevel(int $targetLevel, ?string $reason = null, array $metadata = []): bool;

    /**
     * @return \Illuminate\Database\Eloquent\Builder<\Ram64\LaravelMerit\Models\ExperienceLog>
     */
    public function experienceLogs();

    public function clearExperienceCaches(): void;

    /**
     * @return array{experience: int, level: int, progress: array<string, int|float>, next_level_exp: int}
     */
    public function getCachedExperienceData(): array;
}
