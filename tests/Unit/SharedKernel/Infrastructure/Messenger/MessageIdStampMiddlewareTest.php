<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Messenger;

use App\SharedKernel\Infrastructure\Messenger\MessageBeingHandled;
use App\SharedKernel\Infrastructure\Messenger\MessageIdStamp;
use App\SharedKernel\Infrastructure\Messenger\MessageIdStampMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;

final class MessageIdStampMiddlewareTest extends TestCase
{
    private const string MESSAGE_ID = '0197a3c0-0000-7000-8000-000000000301';
    private const string OUTER_MESSAGE_ID = '0197a3c0-0000-7000-8000-000000000302';

    private MessageBeingHandled $messageBeingHandled;
    private ?string $messageIdSeenByTheNextMiddleware = null;

    protected function setUp(): void
    {
        $this->messageBeingHandled = new MessageBeingHandled();
    }

    #[Test]
    public function aMessageWithoutAnIdGetsANewOneThatTheNextMiddlewareSeesAsTheMessageBeingHandled(): void
    {
        $handledEnvelope = $this->middleware()->handle(new Envelope(new \stdClass()), $this->stack());

        $stamps = $handledEnvelope->all(MessageIdStamp::class);
        self::assertCount(1, $stamps);
        self::assertSame($stamps[0]->messageId(), $this->messageIdSeenByTheNextMiddleware);
        self::assertNull($this->messageBeingHandled->messageId());
    }

    #[Test]
    public function aMessageWithAnIdKeepsIt(): void
    {
        $envelope = new Envelope(new \stdClass(), [new MessageIdStamp(self::MESSAGE_ID)]);

        $handledEnvelope = $this->middleware()->handle($envelope, $this->stack());

        self::assertSame([self::MESSAGE_ID], array_map(static fn (MessageIdStamp $stamp): string => $stamp->messageId(), $handledEnvelope->all(MessageIdStamp::class)));
        self::assertSame(self::MESSAGE_ID, $this->messageIdSeenByTheNextMiddleware);
    }

    #[Test]
    public function aMessageDispatchedWhileAnotherIsHandledGivesTheOuterIdBackAfterwards(): void
    {
        $this->messageBeingHandled->set(self::OUTER_MESSAGE_ID);

        $this->middleware()->handle(new Envelope(new \stdClass(), [new MessageIdStamp(self::MESSAGE_ID)]), $this->stack());

        self::assertSame(self::MESSAGE_ID, $this->messageIdSeenByTheNextMiddleware);
        self::assertSame(self::OUTER_MESSAGE_ID, $this->messageBeingHandled->messageId());
    }

    #[Test]
    public function aFailingMessageGivesTheOuterIdBackToo(): void
    {
        $this->messageBeingHandled->set(self::OUTER_MESSAGE_ID);
        $failure = new \RuntimeException('The handler failed.');

        try {
            $this->middleware()->handle(new Envelope(new \stdClass(), [new MessageIdStamp(self::MESSAGE_ID)]), $this->stack($failure));
            self::fail('The failure did not reach the caller.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(self::MESSAGE_ID, $this->messageIdSeenByTheNextMiddleware);
        self::assertSame(self::OUTER_MESSAGE_ID, $this->messageBeingHandled->messageId());
    }

    #[Test]
    public function aResetForgetsTheMessageBeingHandled(): void
    {
        $this->messageBeingHandled->set(self::MESSAGE_ID);

        $this->messageBeingHandled->reset();

        self::assertNull($this->messageBeingHandled->messageId());
    }

    private function middleware(): MessageIdStampMiddleware
    {
        return new MessageIdStampMiddleware($this->messageBeingHandled);
    }

    private function stack(?\Throwable $failure = null): StackMiddleware
    {
        $next = new readonly class($this, $failure) implements MiddlewareInterface {
            public function __construct(private MessageIdStampMiddlewareTest $test, private ?\Throwable $failure)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $this->test->recordTheMessageBeingHandled();
                if (null !== $this->failure) {
                    throw $this->failure;
                }

                return $envelope;
            }
        };

        return new StackMiddleware($next);
    }

    /**
     * @internal called by the next middleware of the stack above
     */
    public function recordTheMessageBeingHandled(): void
    {
        $this->messageIdSeenByTheNextMiddleware = $this->messageBeingHandled->messageId();
    }
}
