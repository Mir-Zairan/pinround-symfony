<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Mail;

use App\Organization\Domain\Invitation;
use App\Organization\Domain\InvitationId;
use App\Organization\Domain\InvitationLinkToken;
use App\Organization\Domain\OrgRoles;
use App\Organization\Infrastructure\Mail\SymfonyInvitationMailer;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

final class SymfonyInvitationMailerTest extends TestCase
{
    #[Test]
    public function theEmailGoesToTheInvitedAddressWithTheOrganizationInItsSubject(): void
    {
        $transport = self::recordingTransport();
        $token = InvitationLinkToken::generate();

        new SymfonyInvitationMailer($transport, 'https://pinround.example')->sendLink(self::miasInvitation(), 'Acme Foods', $token);

        self::assertCount(1, $transport->sent);
        $email = $transport->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame(['mia@acme-foods.example'], array_map(static fn ($address): string => $address->getAddress(), $email->getTo()));
        self::assertSame('You are invited to Acme Foods on pinround', $email->getSubject());
        self::assertSame(
            ['organizationName' => 'Acme Foods', 'link' => 'https://pinround.example/invite/'.$token->reveal(), 'validHours' => 72],
            $email->getContext(),
        );
    }

    #[Test]
    public function aDefaultUriEndingInASlashGivesALinkWithOneSlash(): void
    {
        $transport = self::recordingTransport();
        $token = InvitationLinkToken::generate();

        new SymfonyInvitationMailer($transport, 'https://pinround.example/')->sendLink(self::miasInvitation(), 'Acme Foods', $token);

        $email = $transport->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('https://pinround.example/invite/'.$token->reveal(), $email->getContext()['link']);
    }

    private static function miasInvitation(): Invitation
    {
        return Invitation::issue(
            new InvitationId('0197a3c0-0000-7000-8000-0000000008a1'),
            new OrganizationId('0197a3c0-0000-7000-8000-0000000008a2'),
            'mia@acme-foods.example',
            new OrgRoles(),
            null,
            null,
            new UserId('0197a3c0-0000-7000-8000-0000000008a3'),
            new \DateTimeImmutable('2027-03-15 10:00:00 UTC'),
        );
    }

    /**
     * @return TransportInterface&object{sent: list<RawMessage>}
     */
    private static function recordingTransport(): TransportInterface
    {
        return new class implements TransportInterface {
            /** @var list<RawMessage> */
            public array $sent = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->sent[] = $message;

                return null;
            }

            public function __toString(): string
            {
                return 'recording://';
            }
        };
    }
}
