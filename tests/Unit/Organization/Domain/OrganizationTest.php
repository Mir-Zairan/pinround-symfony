<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Domain;

use App\Organization\Domain\BlankOrganizationName;
use App\Organization\Domain\InvalidOrganizationSlug;
use App\Organization\Domain\Organization;
use App\SharedKernel\Domain\OrganizationId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OrganizationTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000000a';

    #[Test]
    public function aNewOrganizationKeepsItsTrimmedNameSlugAndCreationTime(): void
    {
        $createdAt = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
        $organization = new Organization(new OrganizationId(self::ACME_FOODS), ' Acme Foods ', 'acme-foods', $createdAt);

        self::assertSame(self::ACME_FOODS, $organization->id()->toString());
        self::assertSame('Acme Foods', $organization->name());
        self::assertSame('acme-foods', $organization->slug());
        self::assertSame($createdAt, $organization->createdAt());
    }

    #[Test]
    public function aBlankNameIsRefused(): void
    {
        $this->expectException(BlankOrganizationName::class);

        new Organization(new OrganizationId(self::ACME_FOODS), " \n", 'acme-foods', new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notASlug(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Acme-Foods'];
        yield 'space' => ['acme foods'];
        yield 'leading hyphen' => ['-acme'];
        yield 'trailing hyphen' => ['acme-'];
        yield 'double hyphen' => ['acme--foods'];
        yield 'trailing newline' => ["acme\n"];
        yield 'longer than 63 characters' => [str_repeat('a', 64)];
    }

    #[Test]
    #[DataProvider('notASlug')]
    public function aSlugIsLowercaseLettersAndDigitsJoinedBySingleHyphens(string $slug): void
    {
        $this->expectException(InvalidOrganizationSlug::class);

        new Organization(new OrganizationId(self::ACME_FOODS), 'Acme Foods', $slug, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
    }

    #[Test]
    public function aSlugOf63CharactersIsAccepted(): void
    {
        $slug = str_repeat('a', 63);

        self::assertSame($slug, new Organization(new OrganizationId(self::ACME_FOODS), 'Acme Foods', $slug, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'))->slug());
    }
}
