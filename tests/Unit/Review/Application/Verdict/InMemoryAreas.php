<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application\Verdict;

use App\Review\Domain\Area;
use App\Review\Domain\AreaId;
use App\Review\Domain\Areas;
use App\Review\Domain\PinId;
use App\Review\Domain\PinToCheck;
use App\Review\Domain\ReviewedRound;
use App\Review\Domain\RoundId;
use App\SharedKernel\Application\TenantContext;

/**
 * Like DbalAreas: every read and write needs the tenant and sees its rows only; an area is found in its own round
 * only, and a pin only in the rounds with an area whose "before" picture holds it. saveVerdict of an area that is not
 * stored is a LogicException, and savePinVerdict writes only while the stored verdict is still the one read (false
 * otherwise). Each call is logged by name, so a test can show the round was locked first.
 */
final class InMemoryAreas implements Areas
{
    /** @var list<string> the methods called, in order */
    public array $calls = [];

    /**
     * Runs at the start of savePinVerdict, standing for another request that saved between this one's read and save.
     *
     * @var (\Closure(self): void)|null
     */
    public ?\Closure $beforePinSave = null;

    /** @var array<string, array<string, ReviewedRound>> by organization id, then round id */
    private array $rounds = [];

    /** @var array<string, array<string, Area>> by organization id, then area id */
    private array $areas = [];

    /** @var array<string, array<string, PinToCheck>> by organization id, then pin id */
    private array $pins = [];

    /** @var array<string, array<string, list<string>>> by organization id, then pin id: the rounds it is checked in */
    private array $pinRounds = [];

    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function addRound(string $organizationId, ReviewedRound $round): void
    {
        $this->rounds[$organizationId][$round->id->toString()] = $round;
    }

    public function addArea(string $organizationId, Area $area): void
    {
        $this->areas[$organizationId][$area->id()->toString()] = $area;
    }

    /**
     * @param list<string> $roundIds the rounds with an area whose "before" picture holds the pin
     */
    public function addPin(string $organizationId, PinToCheck $pin, array $roundIds): void
    {
        $this->pins[$organizationId][$pin->id()->toString()] = $pin;
        $this->pinRounds[$organizationId][$pin->id()->toString()] = $roundIds;
    }

    public function storedArea(string $organizationId, string $areaId): ?Area
    {
        return $this->areas[$organizationId][$areaId] ?? null;
    }

    public function storedPin(string $organizationId, string $pinId): ?PinToCheck
    {
        return $this->pins[$organizationId][$pinId] ?? null;
    }

    /**
     * Writes a verdict over the stored pin as another request would.
     */
    public function replaceStoredPin(string $organizationId, PinToCheck $pin): void
    {
        $this->pins[$organizationId][$pin->id()->toString()] = $pin;
    }

    /**
     * @return array{array<string, array<string, Area>>, array<string, array<string, PinToCheck>>} every stored area and pin
     */
    public function snapshot(): array
    {
        return [$this->areas, $this->pins];
    }

    public function lockRound(RoundId $roundId): ?ReviewedRound
    {
        $this->calls[] = 'lockRound';

        return $this->rounds[$this->tenant()][$roundId->toString()] ?? null;
    }

    public function areaOf(RoundId $roundId, AreaId $areaId): ?Area
    {
        $this->calls[] = 'areaOf';
        $area = $this->areas[$this->tenant()][$areaId->toString()] ?? null;

        return null !== $area && $area->roundId()->equals($roundId) ? $area : null;
    }

    public function saveVerdict(Area $area): void
    {
        $this->calls[] = 'saveVerdict';
        $organizationId = $this->tenant();
        $stored = $this->areas[$organizationId][$area->id()->toString()] ?? null;
        if (null === $stored || !$stored->roundId()->equals($area->roundId())) {
            throw new \LogicException(\sprintf('Area %s is not stored; a verdict is saved on an area read before.', $area->id()->toString()));
        }
        $this->areas[$organizationId][$area->id()->toString()] = $area;
    }

    public function pinToCheckOf(RoundId $roundId, PinId $pinId): ?PinToCheck
    {
        $this->calls[] = 'pinToCheckOf';
        $organizationId = $this->tenant();
        $isChecked = \in_array($roundId->toString(), $this->pinRounds[$organizationId][$pinId->toString()] ?? [], true);

        return $isChecked ? $this->pins[$organizationId][$pinId->toString()] : null;
    }

    public function savePinVerdict(PinToCheck $read, PinToCheck $changed): bool
    {
        $this->calls[] = 'savePinVerdict';
        $organizationId = $this->tenant();
        if (!$read->id()->equals($changed->id())) {
            throw new \LogicException('A pin verdict is saved over the same pin it was read from.');
        }
        if (null !== $this->beforePinSave) {
            ($this->beforePinSave)($this);
        }
        $stored = $this->pins[$organizationId][$read->id()->toString()] ?? null;
        if (null === $stored || $stored->verdict() !== $read->verdict()) {
            return false;
        }
        $this->pins[$organizationId][$read->id()->toString()] = $changed;

        return true;
    }

    private function tenant(): string
    {
        return $this->tenantContext->require()->toString();
    }
}
