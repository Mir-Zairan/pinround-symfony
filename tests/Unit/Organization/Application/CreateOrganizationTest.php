<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Api\Event\MemberJoined;
use App\Organization\Application\CreateOrganization;
use App\Organization\Domain\Organization;
use App\Organization\Domain\Organizations;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgSettings;
use App\Organization\Domain\OrgSettingsStore;
use App\SharedKernel\Application\OrganizationSetupStep;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreateOrganizationTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000005a1';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000556';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000552';

    #[Test]
    public function theFirstAdminIsAnAdminMemberAndTheDefaultTriager(): void
    {
        $organizations = self::organizations();
        $memberships = new InMemoryMemberships();
        $settings = self::settingsStore();
        $events = new RecordingOrganizationEvents();
        $now = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');

        new CreateOrganization($organizations, $memberships, $settings, $events, [])(new OrganizationId(self::ACME_FOODS), 'Acme Foods', 'acme-foods', new UserId(self::SARA), new UserId(self::LENA), $now);

        self::assertSame(['Acme Foods', 'acme-foods'], [$organizations->added[0]->name(), $organizations->added[0]->slug()]);
        self::assertCount(1, $memberships->all());
        $membership = $memberships->all()[0];
        self::assertSame([self::ACME_FOODS, self::SARA, [OrgRole::Admin]], [$membership->organizationId()->toString(), $membership->userId()->toString(), $membership->orgRoles()->toList()]);
        self::assertSame(self::SARA, $settings->added[0]->triagerId()->toString());
        self::assertEquals([new MemberJoined(self::ACME_FOODS, self::SARA, ['admin'], self::LENA, $now)], $events->published);
    }

    #[Test]
    public function theSystemAsCreatorIsRecordedAsNoUser(): void
    {
        $events = new RecordingOrganizationEvents();

        new CreateOrganization(self::organizations(), new InMemoryMemberships(), self::settingsStore(), $events, [])(new OrganizationId(self::ACME_FOODS), 'Acme Foods', 'acme-foods', new UserId(self::SARA), null, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));

        self::assertCount(1, $events->published);
        self::assertNull($events->published[0]->actorId);
    }

    /**
     * Each step records the organization id it was given and how many organization rows, settings and events there
     * were when it ran, so a step run before the rows or after MemberJoined shows other counts.
     */
    #[Test]
    public function theSetupStepsRunInOrderForTheNewOrganizationAfterItsRowsAndBeforeMemberJoined(): void
    {
        $organizations = self::organizations();
        $settings = self::settingsStore();
        $events = new RecordingOrganizationEvents();
        /** @var \ArrayObject<int, array{string, string, int, int, int}> $sightings */
        $sightings = new \ArrayObject();
        $sightingStep = static fn (string $stepName): OrganizationSetupStep => new CallbackOrganizationSetupStep(
            static fn (OrganizationId $organizationId) => $sightings->append([$stepName, $organizationId->toString(), \count($organizations->added), \count($settings->added), \count($events->published)]),
        );

        new CreateOrganization($organizations, new InMemoryMemberships(), $settings, $events, [$sightingStep('workflow'), $sightingStep('second')])(new OrganizationId(self::ACME_FOODS), 'Acme Foods', 'acme-foods', new UserId(self::SARA), null, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));

        self::assertSame([['workflow', self::ACME_FOODS, 1, 1, 0], ['second', self::ACME_FOODS, 1, 1, 0]], $sightings->getArrayCopy());
        self::assertCount(1, $events->published);
    }

    #[Test]
    public function aFailingSetupStepStopsTheLaterStepsAndMemberJoined(): void
    {
        $events = new RecordingOrganizationEvents();
        /** @var \ArrayObject<int, string> $laterStepRuns */
        $laterStepRuns = new \ArrayObject();
        $failingStep = new CallbackOrganizationSetupStep(static fn () => throw new \RuntimeException('Setup failed.'));
        $laterStep = new CallbackOrganizationSetupStep(static fn (OrganizationId $organizationId) => $laterStepRuns->append($organizationId->toString()));

        try {
            new CreateOrganization(self::organizations(), new InMemoryMemberships(), self::settingsStore(), $events, [$failingStep, $laterStep])(new OrganizationId(self::ACME_FOODS), 'Acme Foods', 'acme-foods', new UserId(self::SARA), null, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
            self::fail('The failing setup step was not reported.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Setup failed.', $failure->getMessage());
        }

        self::assertSame([], $laterStepRuns->getArrayCopy());
        self::assertSame([], $events->published);
    }

    /**
     * @return Organizations&object{added: list<Organization>}
     */
    private static function organizations(): Organizations
    {
        return new class implements Organizations {
            /** @var list<Organization> */
            public array $added = [];

            public function nextIdentity(): OrganizationId
            {
                throw new \LogicException('Not used.');
            }

            public function byId(OrganizationId $id): ?Organization
            {
                throw new \LogicException('Not used.');
            }

            public function add(Organization $organization): void
            {
                $this->added[] = $organization;
            }
        };
    }

    /**
     * @return OrgSettingsStore&object{added: list<OrgSettings>}
     */
    private static function settingsStore(): OrgSettingsStore
    {
        return new class implements OrgSettingsStore {
            /** @var list<OrgSettings> */
            public array $added = [];

            public function of(OrganizationId $organizationId): ?OrgSettings
            {
                throw new \LogicException('Not used.');
            }

            public function add(OrgSettings $settings): void
            {
                $this->added[] = $settings;
            }
        };
    }
}
