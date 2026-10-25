<?php

namespace Ram64\LaravelMerit\Support;

use InvalidArgumentException;

/**
 * Evaluates merit.experience.level_formula for a level.
 *
 * The formula used to be run through eval(). It comes from config, so it was
 * not open to users, but anything that ever let it be set from outside the
 * codebase would have been code execution. This evaluates the same
 * arithmetic without eval(): numbers, $level, + - * / % ** and parentheses,
 * and the math functions in FUNCTIONS. Each operation is done with PHP's own
 * operator or function, so a formula gives exactly what eval() gave.
 *
 * The formula may instead be the name of an invokable class, which receives
 * the level and returns the experience it needs. A class name survives
 * config:cache, where a closure would not.
 */
final class LevelFormula
{
    /**
     * The functions a formula may call.
     */
    private const FUNCTIONS = ['abs', 'ceil', 'exp', 'floor', 'intdiv', 'log', 'max', 'min', 'pow', 'round', 'sqrt'];

    /**
     * Parsed formulas, so a level ladder is not re-parsed for every rung.
     *
     * @var array<string, array<mixed>>
     */
    private static array $parsed = [];

    /**
     * @var array<int, array{0: string, 1: string}>
     */
    private array $tokens = [];

    private int $position = 0;

    /**
     * The experience a level needs under the given formula.
     */
    public static function evaluate(string $formula, int $level): int
    {
        if (preg_match('/^\\\\?[A-Za-z_][\\w\\\\]*$/', $formula) === 1 && class_exists($formula)) {
            return (int) app($formula)($level);
        }

        $tree = self::$parsed[$formula] ??= (new self)->parse($formula);

        return (int) self::run($tree, $level);
    }

    /**
     * @return array<mixed>
     */
    private function parse(string $formula): array
    {
        $this->tokens = self::tokenize($formula);
        $this->position = 0;

        $tree = $this->expression();

        if ($this->peek() !== null) {
            throw self::invalid($formula, "unexpected '{$this->peek()[1]}'");
        }

        return $tree;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private static function tokenize(string $formula): array
    {
        preg_match_all(
            '/\s*(?:(?<number>\d+(?:\.\d+)?)|(?<variable>\$level\b)|(?<name>[A-Za-z_]\w*)|(?<operator>\*\*|[-+*\/%(),]))/A',
            $formula,
            $matches,
            PREG_SET_ORDER
        );

        $tokens = [];
        $consumed = 0;

        foreach ($matches as $match) {
            $consumed += strlen($match[0]);

            foreach (['number', 'variable', 'name', 'operator'] as $type) {
                if (($match[$type] ?? '') !== '') {
                    $tokens[] = [$type, $match[$type]];

                    break;
                }
            }
        }

        if (trim(substr($formula, $consumed)) !== '') {
            throw self::invalid($formula, "unexpected '".trim(substr($formula, $consumed))[0]."'");
        }

        return $tokens;
    }

    /**
     * expression := term (('+' | '-') term)*
     *
     * @return array<mixed>
     */
    private function expression(): array
    {
        $node = $this->term();

        while (in_array($this->peekOperator(), ['+', '-'], true)) {
            $node = ['binary', $this->next()[1], $node, $this->term()];
        }

        return $node;
    }

    /**
     * term := unary (('*' | '/' | '%') unary)*
     *
     * @return array<mixed>
     */
    private function term(): array
    {
        $node = $this->unary();

        while (in_array($this->peekOperator(), ['*', '/', '%'], true)) {
            $node = ['binary', $this->next()[1], $node, $this->unary()];
        }

        return $node;
    }

    /**
     * unary := ('-' | '+') unary | power
     *
     * As in PHP, ** binds tighter than a sign on its left: -2 ** 2 is -4.
     *
     * @return array<mixed>
     */
    private function unary(): array
    {
        if (in_array($this->peekOperator(), ['+', '-'], true)) {
            return ['unary', $this->next()[1], $this->unary()];
        }

        return $this->power();
    }

    /**
     * power := primary ('**' unary)?
     *
     * Right-associative, as in PHP.
     *
     * @return array<mixed>
     */
    private function power(): array
    {
        $node = $this->primary();

        if ($this->peekOperator() === '**') {
            $this->next();

            return ['binary', '**', $node, $this->unary()];
        }

        return $node;
    }

    /**
     * primary := number | $level | name '(' arguments ')' | '(' expression ')'
     *
     * @return array<mixed>
     */
    private function primary(): array
    {
        $token = $this->next();

        if ($token === null) {
            throw new InvalidArgumentException('Invalid level formula: it ends early.');
        }

        [$type, $value] = $token;

        if ($type === 'number') {
            return ['number', str_contains($value, '.') ? (float) $value : (int) $value];
        }

        if ($type === 'variable') {
            return ['level'];
        }

        if ($type === 'name') {
            $function = strtolower($value);

            if (! in_array($function, self::FUNCTIONS, true)) {
                throw new InvalidArgumentException(
                    "Invalid level formula: {$value}() is not allowed. Use one of ".implode(', ', self::FUNCTIONS).'.'
                );
            }

            $this->expect('(');
            $arguments = [];

            if ($this->peekOperator() !== ')') {
                do {
                    $arguments[] = $this->expression();
                } while ($this->peekOperator() === ',' && $this->next());
            }

            $this->expect(')');

            return ['call', $function, $arguments];
        }

        if ($value === '(') {
            $node = $this->expression();
            $this->expect(')');

            return $node;
        }

        throw new InvalidArgumentException("Invalid level formula: unexpected '{$value}'.");
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function run(array $node, int $level): int|float
    {
        switch ($node[0]) {
            case 'number':
                return $node[1];
            case 'level':
                return $level;
            case 'unary':
                $value = self::run($node[2], $level);

                return $node[1] === '-' ? -$value : +$value;
            case 'call':
                $arguments = array_map(fn (array $argument) => self::run($argument, $level), $node[2]);

                return ($node[1])(...$arguments);
        }

        $left = self::run($node[2], $level);
        $right = self::run($node[3], $level);

        return match ($node[1]) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '/' => $left / $right,
            '%' => $left % $right,
            '**' => $left ** $right,
            default => throw new InvalidArgumentException("Invalid level formula: unknown operator '{$node[1]}'."),
        };
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function peek(): ?array
    {
        return $this->tokens[$this->position] ?? null;
    }

    private function peekOperator(): ?string
    {
        $token = $this->peek();

        return $token !== null && $token[0] === 'operator' ? $token[1] : null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function next(): ?array
    {
        return $this->tokens[$this->position++] ?? null;
    }

    private function expect(string $operator): void
    {
        $token = $this->next();

        if ($token === null || $token[1] !== $operator) {
            throw new InvalidArgumentException("Invalid level formula: expected '{$operator}'.");
        }
    }

    private static function invalid(string $formula, string $reason): InvalidArgumentException
    {
        return new InvalidArgumentException("Invalid level formula '{$formula}': {$reason}.");
    }
}
