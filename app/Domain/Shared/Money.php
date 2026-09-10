<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;

/**
 * Decimal-safe money, as required by ARCHITECTURE-V1.md section 2.5.
 *
 * Every value is held as a STRING and every operation goes through bcmath.
 * Nothing here ever becomes a PHP float, because `0.1 + 0.2 !== 0.3` and a
 * single float round-trip eventually produces a one-paisa mismatch that fails
 * GST reconciliation.
 *
 * Immutable: operations return a new instance.
 */
final class Money
{
    public const SCALE = 2;

    /** Working scale, wider than SCALE so intermediate division keeps precision. */
    private const WORKING_SCALE = 8;

    private function __construct(private readonly string $amount) {}

    public static function of(string|int|float|null $value): self
    {
        if ($value === null || $value === '') {
            return self::zero();
        }

        // A float argument is accepted for ergonomics but normalised through a
        // string immediately, so it can never propagate binary error.
        $normalised = is_float($value)
            ? number_format($value, self::WORKING_SCALE, '.', '')
            : (string) $value;

        if (! preg_match('/^-?\d+(\.\d+)?$/', $normalised)) {
            throw new InvalidArgumentException("Not a decimal amount: [{$normalised}]");
        }

        return new self(bcadd($normalised, '0', self::SCALE));
    }

    /** Keeps the wider working scale, for intermediate results. */
    public static function raw(string $value): self
    {
        return new self(bcadd($value, '0', self::WORKING_SCALE));
    }

    public static function zero(): self
    {
        return new self(bcadd('0', '0', self::SCALE));
    }

    public function plus(self $other): self
    {
        return new self(bcadd($this->amount, $other->amount, self::WORKING_SCALE));
    }

    public function minus(self $other): self
    {
        return new self(bcsub($this->amount, $other->amount, self::WORKING_SCALE));
    }

    /** Multiply by a plain decimal (a quantity or a rate), not by money. */
    public function times(string $multiplier): self
    {
        return new self(bcmul($this->amount, $multiplier, self::WORKING_SCALE));
    }

    public function dividedBy(string $divisor): self
    {
        if (bccomp($divisor, '0', self::WORKING_SCALE) === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return new self(bcdiv($this->amount, $divisor, self::WORKING_SCALE));
    }

    /** `percent` is a whole percentage: 18 means 18%. */
    public function percentage(string $percent): self
    {
        return $this->times($percent)->dividedBy('100');
    }

    /** Half-up to 2 decimal places, the convention for Indian invoicing. */
    public function round(): self
    {
        $negative = bccomp($this->amount, '0', self::WORKING_SCALE) < 0;
        $absolute = $negative ? bcmul($this->amount, '-1', self::WORKING_SCALE) : $this->amount;

        // bcadd truncates, so adding half of the last kept place turns
        // truncation into half-up rounding.
        $rounded = bcadd($absolute, '0.005', self::SCALE);

        return new self($negative ? bcmul($rounded, '-1', self::SCALE) : $rounded);
    }

    /** Difference to the nearest whole rupee, for the round-off line. */
    public function roundOffDelta(): self
    {
        $rounded = $this->roundToWholeUnit();

        return $rounded->minus($this);
    }

    public function roundToWholeUnit(): self
    {
        $negative = bccomp($this->amount, '0', self::WORKING_SCALE) < 0;
        $absolute = $negative ? bcmul($this->amount, '-1', self::WORKING_SCALE) : $this->amount;
        $rounded = bcadd($absolute, '0.5', 0);

        return new self($negative ? bcmul($rounded, '-1', self::SCALE) : bcadd($rounded, '0', self::SCALE));
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) < 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return bccomp($this->amount, $other->amount, self::SCALE) > 0;
    }

    public function isGreaterThanOrEqual(self $other): bool
    {
        return bccomp($this->amount, $other->amount, self::SCALE) >= 0;
    }

    public function equals(self $other): bool
    {
        return bccomp($this->amount, $other->amount, self::SCALE) === 0;
    }

    /** Persistable / serialisable form, always exactly 2 decimals. */
    public function toString(): string
    {
        return bcadd($this->amount, '0', self::SCALE);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /** Full working-scale value, for intermediate comparisons only. */
    public function toRawString(): string
    {
        return $this->amount;
    }
}
