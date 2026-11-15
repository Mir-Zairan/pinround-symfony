<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Notification\Application\Digest\Digest;
use App\Notification\Application\Digest\DigestItems;
use App\Notification\Infrastructure\Mail\DigestMail;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Notification\NotificationRows;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mime\Address;

/**
 * The email digest (DigestItems and DigestMail, as the digest job will use them): every viewer has an open question
 * about each cast ticket and about SHOP-50, a ticket for everyone. The email lists only the tickets the viewer may see,
 * names SHOP-50 by its title and a restricted ticket only by its team, and never carries the team-only title. Raj asks,
 * and Noor asks Raj. Ben's rows are in Acme Foods, which his own organization's digest never reads.
 */
final class DigestEmailLeakTest extends VisibilityLeakCast
{
    private const string NOW = '2027-03-20 10:00:00+00:00';
    private const string SINCE = '2027-03-16 00:00:00+00:00';
    private const string FOR_EVERYONE_TICKET = '0197a3c0-0000-7000-8000-000000005901';
    private const string FOR_EVERYONE_KEY = 'SHOP-50';
    private const string FOR_EVERYONE_TITLE = 'Checkout button is hidden';
    private const string TEAM_ONLY_KEY = 'SHOP-7';
    private const string NO_TEAM_KEY = 'OPS-90';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        new TicketRows(self::migrator(), self::tenantContext())
            ->addWithAudienceOf(self::ACME_FOODS, self::FOR_EVERYONE_TICKET, self::SHOP_PROJECT, 50, 'all', null, self::VIEWERS['Lena'][0]);
        // TicketRows keys every ticket SHOP-<number>; the no-team ticket is in OPS.
        self::asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET key = ? WHERE id = ?',
            [self::NO_TEAM_KEY, self::NO_TEAM_TICKET],
        ));
        $rows = [];
        $index = 0;
        foreach (self::VIEWERS as $viewer => [$userId]) {
            foreach ([self::teamOnlyTicketId(), self::NO_TEAM_TICKET, self::FOR_EVERYONE_TICKET] as $ticketId) {
                $actor = self::actorOf($viewer);
                $rows[] = [
                    'organization_id' => self::ACME_FOODS,
                    'id' => \sprintf('0197a3c0-0000-7000-8000-0000000059%02x', $index),
                    'user_id' => $userId,
                    'kind' => 'question',
                    'actor_id' => self::VIEWERS[$actor][0],
                    'ticket_id' => $ticketId,
                    'data' => json_encode(['actorName' => $actor], \JSON_THROW_ON_ERROR),
                    'created_at' => new \DateTimeImmutable('2027-03-16 08:00:00+00:00')->modify(\sprintf('+%d hours', $index))->format('Y-m-d H:i:sP'),
                ];
                ++$index;
            }
        }
        new NotificationRows(self::migrator(), self::tenantContext())->addOf(self::ACME_FOODS, 'ntf_notification', $rows);
        self::ensureKernelShutdown();
    }

    protected function setUp(): void
    {
        self::getContainer()->set('clock', new MockClock(self::NOW));
    }

    #[Test]
    #[DataProvider('viewers')]
    public function theDigestEmailNeverCarriesARestrictedTicketsTitle(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $isOfAcmeFoods = self::ACME_FOODS === self::VIEWERS[$viewer][1];
        $expectedLines = array_keys(array_filter([
            \sprintf('%s asked you a question on %s: %s', self::actorOf($viewer), self::FOR_EVERYONE_KEY, self::FOR_EVERYONE_TITLE) => $isOfAcmeFoods,
            \sprintf('%s asked you a question on %s: a restricted ticket', self::actorOf($viewer), self::NO_TEAM_KEY) => $seesNoTeamTicket,
            \sprintf('%s asked you a question on %s: a restricted ticket in team Shop', self::actorOf($viewer), self::TEAM_ONLY_KEY) => $seesTeamOnly,
        ]));

        $digest = self::asViewer($viewer, fn (): Digest => $this->digestItems()->digestOf(
            new UserId(self::VIEWERS[$viewer][0]),
            new \DateTimeImmutable(self::SINCE),
            new \DateTimeImmutable(self::NOW),
        ));

        if (!$isOfAcmeFoods) {
            self::assertTrue($digest->isEmpty());

            return;
        }
        self::assertNotSame([], $digest->items);

        $email = $this->digestMail()->emailFor($digest, new Address(strtolower($viewer).'.leak@acme-foods.example'));
        $subject = $email->getSubject();
        $text = $email->getTextBody();
        $html = $email->getHtmlBody();
        self::assertIsString($subject);
        self::assertIsString($text);
        self::assertIsString($html);

        foreach (['subject' => $subject, 'text' => $text, 'html' => $html] as $part => $content) {
            self::assertStringNotContainsString(self::LEAK_MARKER, $content, $part);
        }
        self::assertSame($expectedLines, array_map(
            static fn ($item): string => \sprintf('%s %s on %s: %s', $item->actorName, DigestMail::actionOf($item->kind), $item->ticketKey, DigestMail::labelOf($item)),
            $digest->items,
        ));
        self::assertSame(DigestMail::subjectFor(\count($expectedLines)), $subject);
        foreach ($expectedLines as $line) {
            self::assertStringContainsString('- '.$line."\n", $text);
        }
        self::assertStringContainsString("\n  http://localhost/t/".self::FOR_EVERYONE_KEY."\n", $text);
        self::assertStringContainsString('Your profile: http://localhost/me/profile', $text);
        self::assertStringContainsString('<a href="http://localhost/t/'.self::FOR_EVERYONE_KEY.'">'.self::FOR_EVERYONE_KEY.'</a>: '.self::FOR_EVERYONE_TITLE, $html);
        self::assertStringContainsString('<a href="http://localhost/me/profile">', $html);
    }

    private static function actorOf(string $viewer): string
    {
        return 'Raj' === $viewer ? 'Noor' : 'Raj';
    }

    private function digestItems(): DigestItems
    {
        $digestItems = self::getContainer()->get('test.notification_digest_items');
        \assert($digestItems instanceof DigestItems);

        return $digestItems;
    }

    private function digestMail(): DigestMail
    {
        $digestMail = self::getContainer()->get('test.notification_digest_mail');
        \assert($digestMail instanceof DigestMail);

        return $digestMail;
    }
}
