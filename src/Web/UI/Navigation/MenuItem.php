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
