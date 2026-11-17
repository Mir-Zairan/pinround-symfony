<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\UI\Voter;

use App\Media\Api\MediaImageStatus;
use App\Media\Api\MediaPermission;
use App\Media\Api\UploadedMedia;
use App\Media\Api\UploadedMediaId;
use App\Media\UI\Voter\UploadStateVoter;
use App\Organization\Api\ActorContext;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Mia uploaded the picture MIA_PICTURE at Acme Foods; Omar, also at Acme Foods, did not.
 */
final class UploadStateVoterTest extends TestCase
{
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f6201';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f6202';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f620a';
    private const string MIA_PICTURE = '0197a3c0-0000-7000-8000-0000000f62a1';

    /** @var \ArrayObject<int, array{organizationId: string, uploader: string, mediaIds: list<string>}> */
    private \ArrayObject $reads;

    protected function setUp(): void
    {
        $this->reads = new \ArrayObject();
    }

    #[Test]
    public function itGrantsThePersonWhoUploadedThePicture(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter()->vote(self::tokenOf(self::MIA), new UploadedMediaId(self::MIA_PICTURE), [MediaPermission::VIEW_UPLOAD_STATE]));
        self::assertSame([['organizationId' => self::ACME_FOODS, 'uploader' => self::MIA, 'mediaIds' => [self::MIA_PICTURE]]], $this->reads->getArrayCopy());
    }

    #[Test]
    public function itGrantsAnUppercaseSpellingOfTheIdOfThePicture(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter()->vote(self::tokenOf(self::MIA), new UploadedMediaId(strtoupper(self::MIA_PICTURE)), [MediaPermission::VIEW_UPLOAD_STATE]));
    }

    #[Test]
    public function itDeniesAnotherPersonOfTheSameOrganization(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote(self::tokenOf(self::OMAR), new UploadedMediaId(self::MIA_PICTURE), [MediaPermission::VIEW_UPLOAD_STATE]));
        self::assertSame([['organizationId' => self::ACME_FOODS, 'uploader' => self::OMAR, 'mediaIds' => [self::MIA_PICTURE]]], $this->reads->getArrayCopy());
    }

    #[Test]
    public function itDeniesAnIdNobodyUploadedAndAMalformedId(): void
    {
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::MIA), new UploadedMediaId('0197a3c0-0000-7000-8000-0000000f62ff'), [MediaPermission::VIEW_UPLOAD_STATE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf(self::MIA), new UploadedMediaId('not-an-id'), [MediaPermission::VIEW_UPLOAD_STATE]));
    }

    #[Test]
    public function withoutAnActorItDeniesAndReadsNothing(): void
    {
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new UploadedMediaId(self::MIA_PICTURE), [MediaPermission::VIEW_UPLOAD_STATE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(self::tokenOf('0197a3c0-0000-7000-8000-0000000f62fe'), new UploadedMediaId(self::MIA_PICTURE), [MediaPermission::VIEW_UPLOAD_STATE]));
        self::assertSame([], $this->reads->getArrayCopy());
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnOtherSubjects(): void
    {
        $voter = $this->voter();
        $token = self::tokenOf(self::MIA);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new UploadedMediaId(self::MIA_PICTURE), [OrganizationPermission::VIEW]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new UploadedMediaId(self::MIA_PICTURE), [strtolower(MediaPermission::VIEW_UPLOAD_STATE)]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, self::MIA_PICTURE, [MediaPermission::VIEW_UPLOAD_STATE]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, [MediaPermission::VIEW_UPLOAD_STATE]));
        self::assertSame([], $this->reads->getArrayCopy());
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    private function voter(): UploadStateVoter
    {
        $uploadedMedia = new readonly class(self::MIA, self::MIA_PICTURE, $this->reads) implements UploadedMedia {
            /**
             * @param \ArrayObject<int, array{organizationId: string, uploader: string, mediaIds: list<string>}> $reads
             */
            public function __construct(private string $uploader, private string $pictureId, private \ArrayObject $reads)
            {
            }

            public function statesOf(OrganizationId $organizationId, UserId $uploader, array $mediaIds): array
            {
                $this->reads->append(['organizationId' => $organizationId->toString(), 'uploader' => $uploader->toString(), 'mediaIds' => $mediaIds]);
                $states = [];
                foreach ($mediaIds as $mediaId) {
                    if ($uploader->toString() === $this->uploader && strtolower($mediaId) === $this->pictureId) {
                        $states[$this->pictureId] = MediaImageStatus::Pending;
                    }
                }

                return $states;
            }
        };

        return new UploadStateVoter(
            new FixedCurrentActor([
                self::MIA => new ActorContext(new UserId(self::MIA), new OrganizationId(self::ACME_FOODS), Roles::none()),
                self::OMAR => new ActorContext(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS), Roles::none()),
            ]),
            $uploadedMedia,
        );
    }
}
