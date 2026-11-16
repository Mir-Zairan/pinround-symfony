<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Http;

/**
 * A session as the session handler stores it, in PHP's default "php" encoding (session.serialize_handler): each
 * top-level value as its name, "|" and serialize() of the value. Symfony's attributes are the array named
 * _sf2_attributes. The values are only walked, never unserialized, so no object in a session is built here.
 *
 * Only what serialize() writes for null, booleans, numbers, strings, arrays and objects is read; a Serializable object
 * (C:), an enum (E:) or anything malformed makes the whole session unreadable, never half read.
 */
final readonly class EncodedSessionData
{
    public const string ATTRIBUTES = '_sf2_attributes';

    /** Deeper than any session this app writes; a deeper one is unreadable rather than a deep recursion. */
    private const int MAX_DEPTH = 64;

    /**
     * @param array<int|string, string>|null $attributes the raw serialized value of each attribute, by attribute name
     */
    private function __construct(
        private string $encoded,
        private ?array $attributes,
        private int $attributesStart,
        private int $attributesBodyStart,
        private int $attributesBodyEnd,
        private bool $hasReferences,
    ) {
    }

    /**
     * Null when the data is not wholly in the "php" encoding of the values above.
     */
    public static function decode(string $encoded): ?self
    {
        $attributes = null;
        $attributesStart = 0;
        $attributesBodyStart = 0;
        $attributesBodyEnd = 0;
        $hasReferences = false;
        $length = \strlen($encoded);
        $offset = 0;
        while ($offset < $length) {
            $bar = strpos($encoded, '|', $offset);
            if (false === $bar || $bar === $offset) {
                return null;
            }
            $name = substr($encoded, $offset, $bar - $offset);
            $valueEnd = self::endOfValue($encoded, $bar + 1, 0, $hasReferences);
            if (null === $valueEnd) {
                return null;
            }
            if (self::ATTRIBUTES === $name) {
                $entries = self::arrayEntries($encoded, $bar + 1, $hasReferences);
                if (null === $entries) {
                    return null;
                }
                [$attributes, $attributesBodyStart] = $entries;
                $attributesStart = $bar + 1;
                $attributesBodyEnd = $valueEnd - 1;
            }
            $offset = $valueEnd;
        }

        return new self($encoded, $attributes, $attributesStart, $attributesBodyStart, $attributesBodyEnd, $hasReferences);
    }

    /**
     * The raw serialized value of each attribute, by name; null when the session has no attributes array.
     *
     * @return array<int|string, string>|null
     */
    public function attributes(): ?array
    {
        return $this->attributes;
    }

    /**
     * Whether a value refers back to another (r: or R:), whose numbers count every value before it in the session.
     */
    public function hasReferences(): bool
    {
        return $this->hasReferences;
    }

    /**
     * The same session with these attributes added after the others; every other byte stays as it was. Adding a value
     * renumbers the values after it, so a caller first checks hasReferences().
     *
     * @param array<int|string, string> $rawValuesByName each a serialize()d value, by an attribute name the session has not
     *
     * @throws \LogicException when the session has no attributes array or already has one of the names
     */
    public function withAttributesAdded(array $rawValuesByName): string
    {
        if (null === $this->attributes) {
            throw new \LogicException('The session has no attributes to add to.');
        }
        $added = '';
        foreach ($rawValuesByName as $name => $rawValue) {
            if (\array_key_exists($name, $this->attributes)) {
                throw new \LogicException('The session already has that attribute.');
            }
            $added .= serialize((string) $name).$rawValue;
        }
        $count = \count($this->attributes) + \count($rawValuesByName);

        return substr($this->encoded, 0, $this->attributesStart)
            .'a:'.$count.':{'
            .substr($this->encoded, $this->attributesBodyStart, $this->attributesBodyEnd - $this->attributesBodyStart)
            .$added
            .substr($this->encoded, $this->attributesBodyEnd);
    }

    /**
     * The entries of the array that starts at $offset, raw values by key, and where its body starts.
     *
     * @return array{array<int|string, string>, int}|null
     */
    private static function arrayEntries(string $encoded, int $offset, bool &$hasReferences): ?array
    {
        if (1 !== preg_match('/\Ga:(\d+):\{/', $encoded, $match, 0, $offset)) {
            return null;
        }
        $bodyStart = $offset + \strlen($match[0]);
        $entries = [];
        $at = $bodyStart;
        for ($i = (int) $match[1]; $i > 0; --$i) {
            $keyEnd = self::endOfKey($encoded, $at);
            if (null === $keyEnd) {
                return null;
            }
            $valueEnd = self::endOfValue($encoded, $keyEnd, 1, $hasReferences);
            if (null === $valueEnd) {
                return null;
            }
            $key = unserialize(substr($encoded, $at, $keyEnd - $at), ['allowed_classes' => false]);
            if (!\is_string($key) && !\is_int($key)) {
                return null;
            }
            $entries[(string) $key] = substr($encoded, $keyEnd, $valueEnd - $keyEnd);
            $at = $valueEnd;
        }

        return [$entries, $bodyStart];
    }

    /**
     * Where the value that starts at $offset ends, or null when none does.
     */
    private static function endOfValue(string $encoded, int $offset, int $depth, bool &$hasReferences): ?int
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }

        switch ($encoded[$offset] ?? '') {
            case 'N':
                return 1 === preg_match('/\GN;/', $encoded, $match, 0, $offset) ? $offset + 2 : null;
            case 'b':
            case 'i':
            case 'd':
                return self::endOfScalar($encoded, $offset);
            case 'r':
            case 'R':
                $hasReferences = true;

                return self::endOfScalar($encoded, $offset);
            case 's':
                return self::endOfString($encoded, $offset);
            case 'a':
                return self::endOfMembers($encoded, $offset, '/\Ga:(\d+):\{/', $depth, $hasReferences);
            case 'O':
                if (1 !== preg_match('/\GO:(\d+):"/', $encoded, $match, 0, $offset)) {
                    return null;
                }
                $classEnd = $offset + \strlen($match[0]) + (int) $match[1];

                return self::endOfMembers($encoded, $classEnd, '/\G":(\d+):\{/', $depth, $hasReferences);
            default:
                return null;
        }
    }

    private static function endOfScalar(string $encoded, int $offset): ?int
    {
        return 1 === preg_match('/\G[bidrR]:[-+.0-9A-Za-z]+;/', $encoded, $match, 0, $offset) ? $offset + \strlen($match[0]) : null;
    }

    private static function endOfString(string $encoded, int $offset): ?int
    {
        if (1 !== preg_match('/\Gs:(\d+):"/', $encoded, $match, 0, $offset)) {
            return null;
        }
        $end = $offset + \strlen($match[0]) + (int) $match[1];

        return '";' === substr($encoded, $end, 2) ? $end + 2 : null;
    }

    /**
     * Where an array's or an object's members end, from the header $pattern matches at $offset to the closing brace.
     */
    private static function endOfMembers(string $encoded, int $offset, string $pattern, int $depth, bool &$hasReferences): ?int
    {
        if (1 !== preg_match($pattern, $encoded, $match, 0, $offset)) {
            return null;
        }
        $at = $offset + \strlen($match[0]);
        for ($i = (int) $match[1]; $i > 0; --$i) {
            $keyEnd = self::endOfKey($encoded, $at);
            $at = null === $keyEnd ? null : self::endOfValue($encoded, $keyEnd, $depth + 1, $hasReferences);
            if (null === $at) {
                return null;
            }
        }

        return '}' === ($encoded[$at] ?? '') ? $at + 1 : null;
    }

    private static function endOfKey(string $encoded, int $offset): ?int
    {
        return match ($encoded[$offset] ?? '') {
            'i' => self::endOfScalar($encoded, $offset),
            's' => self::endOfString($encoded, $offset),
            default => null,
        };
    }
}
