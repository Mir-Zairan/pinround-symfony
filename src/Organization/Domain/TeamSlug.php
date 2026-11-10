<?php

declare(strict_types=1);

namespace App\Organization\Domain;

/**
 * The team's part of its address, unique within the organization. It is made from the name once, when the team is
 * created, and never changes afterwards, so an address stays valid when the team is renamed.
 */
final readonly class TeamSlug
{
    /**
     * Every letter of Latin-1 and Latin Extended-A, in both cases, to plain lowercase letters. Version20261110180000
     * keeps its own copy for the backfill; TeamSlugBackfillTest compares the two.
     */
    public const array TRANSLITERATIONS = [
        'À' => 'a', 'Á' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a', 'Å' => 'a', 'Æ' => 'ae', 'Ç' => 'c',
        'È' => 'e', 'É' => 'e', 'Ê' => 'e', 'Ë' => 'e', 'Ì' => 'i', 'Í' => 'i', 'Î' => 'i', 'Ï' => 'i',
        'Ð' => 'd', 'Ñ' => 'n', 'Ò' => 'o', 'Ó' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o', 'Ø' => 'o',
        'Ù' => 'u', 'Ú' => 'u', 'Û' => 'u', 'Ü' => 'u', 'Ý' => 'y', 'Þ' => 'th', 'ß' => 'ss',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ð' => 'd', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'þ' => 'th', 'ÿ' => 'y',
        'Ā' => 'a', 'ā' => 'a', 'Ă' => 'a', 'ă' => 'a', 'Ą' => 'a', 'ą' => 'a',
        'Ć' => 'c', 'ć' => 'c', 'Ĉ' => 'c', 'ĉ' => 'c', 'Ċ' => 'c', 'ċ' => 'c', 'Č' => 'c', 'č' => 'c',
        'Ď' => 'd', 'ď' => 'd', 'Đ' => 'd', 'đ' => 'd',
        'Ē' => 'e', 'ē' => 'e', 'Ĕ' => 'e', 'ĕ' => 'e', 'Ė' => 'e', 'ė' => 'e', 'Ę' => 'e', 'ę' => 'e', 'Ě' => 'e', 'ě' => 'e',
        'Ĝ' => 'g', 'ĝ' => 'g', 'Ğ' => 'g', 'ğ' => 'g', 'Ġ' => 'g', 'ġ' => 'g', 'Ģ' => 'g', 'ģ' => 'g',
        'Ĥ' => 'h', 'ĥ' => 'h', 'Ħ' => 'h', 'ħ' => 'h',
        'Ĩ' => 'i', 'ĩ' => 'i', 'Ī' => 'i', 'ī' => 'i', 'Ĭ' => 'i', 'ĭ' => 'i', 'Į' => 'i', 'į' => 'i', 'İ' => 'i', 'ı' => 'i',
        'Ĳ' => 'ij', 'ĳ' => 'ij', 'Ĵ' => 'j', 'ĵ' => 'j', 'Ķ' => 'k', 'ķ' => 'k', 'ĸ' => 'k',
        'Ĺ' => 'l', 'ĺ' => 'l', 'Ļ' => 'l', 'ļ' => 'l', 'Ľ' => 'l', 'ľ' => 'l', 'Ŀ' => 'l', 'ŀ' => 'l', 'Ł' => 'l', 'ł' => 'l',
        'Ń' => 'n', 'ń' => 'n', 'Ņ' => 'n', 'ņ' => 'n', 'Ň' => 'n', 'ň' => 'n', 'ŉ' => 'n', 'Ŋ' => 'n', 'ŋ' => 'n',
        'Ō' => 'o', 'ō' => 'o', 'Ŏ' => 'o', 'ŏ' => 'o', 'Ő' => 'o', 'ő' => 'o', 'Œ' => 'oe', 'œ' => 'oe',
        'Ŕ' => 'r', 'ŕ' => 'r', 'Ŗ' => 'r', 'ŗ' => 'r', 'Ř' => 'r', 'ř' => 'r',
        'Ś' => 's', 'ś' => 's', 'Ŝ' => 's', 'ŝ' => 's', 'Ş' => 's', 'ş' => 's', 'Š' => 's', 'š' => 's',
        'Ţ' => 't', 'ţ' => 't', 'Ť' => 't', 'ť' => 't', 'Ŧ' => 't', 'ŧ' => 't',
        'Ũ' => 'u', 'ũ' => 'u', 'Ū' => 'u', 'ū' => 'u', 'Ŭ' => 'u', 'ŭ' => 'u', 'Ů' => 'u', 'ů' => 'u', 'Ű' => 'u', 'ű' => 'u', 'Ų' => 'u', 'ų' => 'u',
        'Ŵ' => 'w', 'ŵ' => 'w', 'Ŷ' => 'y', 'ŷ' => 'y', 'Ÿ' => 'y',
        'Ź' => 'z', 'ź' => 'z', 'Ż' => 'z', 'ż' => 'z', 'Ž' => 'z', 'ž' => 'z', 'ſ' => 's',
    ];

    /** The cut leaves room for a "-2" suffix inside the 63 characters a slug may have. */
    private const int MAX_NAME_PART_LENGTH = 50;

    private const string FALLBACK = 'team';

    private string $value;

    /**
     * @throws InvalidTeamSlug when the slug is not lowercase letters and digits joined by single hyphens, of at most
     *                         63 characters
     */
    public function __construct(string $value)
    {
        if (1 !== preg_match(SlugFormat::PATTERN, $value)) {
            throw new InvalidTeamSlug('A team slug is '.SlugFormat::RULE.'.');
        }

        $this->value = $value;
    }

    /**
     * The name in lowercase ASCII, each run of other characters a single hyphen, cut to MAX_NAME_PART_LENGTH; "team"
     * when nothing is left. Two names can give the same slug: pick a free one with firstFreeAmong().
     */
    public static function fromName(string $name): self
    {
        $words = preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($name, self::TRANSLITERATIONS))) ?? '';
        $slug = rtrim(substr(trim($words, '-'), 0, self::MAX_NAME_PART_LENGTH), '-');

        return new self('' === $slug ? self::FALLBACK : $slug);
    }

    /**
     * This slug when no team has it, else the first of this slug with "-2", "-3"… that none has.
     *
     * @param list<TeamSlug> $taken the organization's slugs that are this one or start with it and a hyphen
     */
    public function firstFreeAmong(array $taken): self
    {
        $takenValues = array_flip(array_map(static fn (self $slug): string => $slug->value, $taken));
        $candidate = $this->value;
        for ($suffix = 2; isset($takenValues[$candidate]); ++$suffix) {
            $candidate = $this->value.'-'.$suffix;
        }

        return new self($candidate);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
