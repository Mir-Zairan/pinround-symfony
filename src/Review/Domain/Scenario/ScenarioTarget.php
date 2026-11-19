<?php

declare(strict_types=1);

namespace App\Review\Domain\Scenario;

/**
 * How a step finds its element: by accessible role (and name), by visible text or by label. Exactly one of role, text
 * and label is set; name goes only with role. There is no CSS or XPath kind on purpose (ADR-0046).
 */
final readonly class ScenarioTarget
{
    private function __construct(
        public ?string $role,
        public ?string $name,
        public ?string $text,
        public ?string $label,
    ) {
    }

    public static function byRole(string $role, ?string $name): self
    {
        return new self($role, $name, null, null);
    }

    public static function byVisibleText(string $text): self
    {
        return new self(null, null, $text, null);
    }

    public static function byLabel(string $label): self
    {
        return new self(null, null, null, $label);
    }

    /**
     * @return array<string, string> the target as scenario.v1.json writes it, without the keys it does not use
     */
    public function toArray(): array
    {
        return array_filter(
            ['role' => $this->role, 'name' => $this->name, 'text' => $this->text, 'label' => $this->label],
            static fn (?string $value): bool => null !== $value,
        );
    }
}
