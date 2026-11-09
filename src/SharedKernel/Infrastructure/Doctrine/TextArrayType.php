<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * PostgreSQL's one-dimensional `text[]` without NULL elements, as a PHP list of strings; registered as the Doctrine
 * type "text_array" in doctrine.yaml. It maps the database type back too, so the schema diff reads the column.
 */
final class TextArrayType extends Type
{
    public const string NAME = 'text_array';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'TEXT[]';
    }

    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return ['_text'];
    }

    /**
     * Every element is quoted, so no text can end an element early or read as NULL.
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!\is_array($value)) {
            throw new \InvalidArgumentException('A text array column takes a list of strings.');
        }

        $quotedElements = [];
        foreach ($value as $element) {
            if (!\is_string($element)) {
                throw new \InvalidArgumentException('A text array column takes a list of strings.');
            }
            $quotedElements[] = '"'.addcslashes($element, '"\\').'"';
        }

        return '{'.implode(',', $quotedElements).'}';
    }

    /**
     * @return list<string>|null
     *
     * @throws \UnexpectedValueException when the value is not a one-dimensional array literal without NULL elements
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value) || !str_starts_with($value, '{') || !str_ends_with($value, '}') || \strlen($value) < 2) {
            throw new \UnexpectedValueException('A text array column holds an array literal such as {"a","b"}.');
        }

        return self::elementsOf(substr($value, 1, -1));
    }

    /**
     * @return list<string>
     */
    private static function elementsOf(string $inner): array
    {
        if ('' === $inner) {
            return [];
        }

        $elements = [];
        $length = \strlen($inner);
        $position = 0;
        while (true) {
            if ($position < $length && '"' === $inner[$position]) {
                [$element, $position] = self::quotedElementAt($inner, $position + 1);
            } else {
                $end = strpos($inner, ',', $position);
                $end = false === $end ? $length : $end;
                $element = substr($inner, $position, $end - $position);
                if ('' === $element || 0 === strcasecmp($element, 'NULL') || false !== strpbrk($element, '{}"\\')) {
                    throw new \UnexpectedValueException('A text array column holds no NULL, nested or malformed element.');
                }
                $position = $end;
            }
            $elements[] = $element;

            if ($position === $length) {
                return $elements;
            }
            if (',' !== $inner[$position]) {
                throw new \UnexpectedValueException('Elements of a text array literal are separated by commas.');
            }
            ++$position;
        }
    }

    /**
     * @return array{string, int} the unescaped element and the position after its closing quote
     */
    private static function quotedElementAt(string $inner, int $position): array
    {
        $element = '';
        $length = \strlen($inner);
        while ($position < $length) {
            $character = $inner[$position];
            if ('"' === $character) {
                return [$element, $position + 1];
            }
            if ('\\' === $character) {
                ++$position;
                if ($position === $length) {
                    break;
                }
                $character = $inner[$position];
            }
            $element .= $character;
            ++$position;
        }

        throw new \UnexpectedValueException('A quoted element of a text array literal is not closed.');
    }
}
