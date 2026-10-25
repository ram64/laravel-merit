<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Formulas;

/**
 * A level formula given as a class rather than an expression.
 */
class TripleLevel
{
    public function __invoke(int $level): int
    {
        return 3 * $level;
    }
}
