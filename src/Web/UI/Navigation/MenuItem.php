<?php

declare(strict_types=1);

namespace App\Web\UI\Navigation;

/**
 * One item of the header's main menu: a page, or a group of pages that opens as a disclosure ("Teams ▾").
 */
final readonly class MenuItem
{
    /**
     * @param array<string, string> $routeParameters
     * @param list<MenuItem>        $children
     */
    private function __construct(
        private string $label,
        private ?string $routeName,
        private array $routeParameters,
        private ?string $url,
        private bool $isCurrent,
        private array $children,
        private int $badgeCount = 0,
    ) {
    }

    /**
     * @param array<string, string> $routeParameters
     * @param string|null           $url             null while no phase has added the route; the item then renders
     *                                               inert instead of as a dead link
     */
    public static function page(string $label, string $routeName, array $routeParameters, ?string $url, bool $isCurrent): self
    {
        return new self($label, $routeName, $routeParameters, $url, $isCurrent, []);
    }

    /**
     * @param list<MenuItem> $children
     */
    public static function group(string $label, array $children): self
    {
        return new self($label, null, [], null, array_any($children, static fn (MenuItem $child): bool => $child->isCurrent()), $children);
    }

    /**
     * The same item with a count tag beside its label, such as My reports' questions that wait on the person.
     *
     * @param int $badgeCount 0 for no tag
     *
     * @throws \InvalidArgumentException when the count is below 0
     */
    public function withBadgeCount(int $badgeCount): self
    {
        if ($badgeCount < 0) {
            throw new \InvalidArgumentException('A menu item\'s count tag is 0 or more.');
        }

        return new self($this->label, $this->routeName, $this->routeParameters, $this->url, $this->isCurrent, $this->children, $badgeCount);
    }

    /**
     * @return int 0 when the item shows no count tag
     */
    public function badgeCount(): int
    {
        return $this->badgeCount;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * @return string|null null for a group
     */
    public function routeName(): ?string
    {
        return $this->routeName;
    }

    /**
     * @return array<string, string>
     */
    public function routeParameters(): array
    {
        return $this->routeParameters;
    }

    /**
     * @return string|null null for a group, and for a page whose route does not exist yet
     */
    public function url(): ?string
    {
        return $this->url;
    }

    public function isPageBuilt(): bool
    {
        return null !== $this->url;
    }

    /**
     * True when the request is on this page, with the same route parameters; for a group, on one of its pages.
     */
    public function isCurrent(): bool
    {
        return $this->isCurrent;
    }

    public function isGroup(): bool
    {
        return null === $this->routeName;
    }

    /**
     * @return list<MenuItem> the pages of a group; empty for a page
     */
    public function children(): array
    {
        return $this->children;
    }
}
