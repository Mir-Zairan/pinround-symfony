<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Doctrine;

/**
 * PostgreSQL sums a bigint column as numeric, which PDO hands over as a string.
 */
final class ByteSum
{
    // \z, because $ would also accept a trailing newline.
    private const string DIGITS = '/^[0-9]{1,18}\z/';

    private function __construct()
    {
    }

    /**
     * @param mixed $sum COALESCE(SUM(…), 0) of a bytes column, as the driver returns it
     *
     * @throws \UnexpectedValueException when $sum is no whole number of 0 or more that fits an int, rather than
     *                                   counting it as 0 bytes
     */
    public static function of(mixed $sum): int
    {
        if (\is_int($sum) && $sum >= 0) {
            return $sum;
        }
        if (\is_string($sum) && 1 === preg_match(self::DIGITS, $sum)) {
            return (int) $sum;
        }

        throw new \UnexpectedValueException('A sum of bytes must be a whole number of 0 or more of at most 18 digits.');
    }
}
