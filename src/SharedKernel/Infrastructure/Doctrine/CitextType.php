<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\TextType;

/**
 * PostgreSQL's case-insensitive text (extension citext), registered as the Doctrine type "citext" in doctrine.yaml.
 * It maps the database type back too, so the schema diff can read a citext column instead of failing on it.
 */
final class CitextType extends TextType
{
    public const string NAME = 'citext';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'CITEXT';
    }

    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return [self::NAME];
    }
}
