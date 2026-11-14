<?php

declare(strict_types=1);

namespace App\Conversation\UI;

use App\Conversation\Api\ConversationPermission;
use App\Conversation\Api\MessageBodyRefused;
use App\Conversation\Api\MessageEditRefused;
use App\Conversation\Api\MessageNotFound;
use App\Conversation\Api\MessageRemovalRefused;
use App\Conversation\Application\EditMessage\EditMessage;
use App\Conversation\Application\PostComment\PostComment;
use App\Conversation\Application\RemoveMessage\RemoveMessage;
use App\Identity\Api\CurrentUser;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The ticket's thread (FR-CNV-01, FR-CNV-06, ticket-page.md, Questions and comments): shows it in ThreadFrame's lazy
 * frame, or as a page of its own, and posts a comment, and edits or removes one's own message. Each action answers with
 * a toast: a Turbo form in a Turbo Stream that also replaces the message list, any other form with a redirect to the
 * thread's page.
 *
 * Showing it runs in the request transaction, with the same 404 as the actions. The actions run without it, as
 * TicketMoveController: Transactions gives the action a transaction of its own, so
 * the toast is set only once the change is committed and a refusal leaves nothing. In that order: a ticket the person
 * may not see is not found, as on its page; ConversationPermission COMMENT is checked; a form token that is not valid
 * changes nothing. Who may edit or remove a message, and until when, is the domain's rule, whose refusal is a toast.
 */
final class ThreadController extends AbstractController
{
    public const string THREAD_ROUTE = 'conversation_thread';
    public const string COMMENT_ROUTE = 'conversation_comment';
    public const string EDIT_ROUTE = 'conversation_message_edit';
    public const string REMOVE_ROUTE = 'conversation_message_remove';

    public const string CSRF_FIELD = '_csrf_token';
    public const string BODY_FIELD = 'body';

    /** In characters, as Message::MAX_BODY_LENGTH, which the use cases enforce; the form only hints it. */
    public const int BODY_MAX_LENGTH = 10000;

    public const string COMMENT_ADDED = 'Comment added.';
    public const string MESSAGE_EDITED = 'Message edited.';
    public const string MESSAGE_REMOVED = 'Message removed.';
    public const string FORM_EXPIRED = 'This page was open too long. Reload it and try again.';

    /**
     * TicketShowController's route and not-found text, which Conversation's UI may not import (deptrac);
     * ThreadControllerTest keeps them the same.
     */
    public const string TICKET_ROUTE = 'ticket_show';
    public const string NOT_FOUND = 'No ticket you may see has that key.';

    /** The flash type base.html.twig shows in its toast region. */
    private const string TOAST = 'toast';
    private const string CSRF_ID_PREFIX = 'conversation_thread:';
    /** The header Turbo sends when it loads a frame, with the frame's id. */
    private const string TURBO_FRAME_HEADER = 'Turbo-Frame';

    public function __construct(
        private readonly Transactions $transactions,
        private readonly TenantContext $tenantContext,
        private readonly CurrentUser $currentUser,
        private readonly TicketVisibilities $ticketVisibilities,
        private readonly TicketQueries $ticketQueries,
        private readonly PostComment $postComment,
        private readonly EditMessage $editMessage,
        private readonly RemoveMessage $removeMessage,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The CSRF token id of every thread form of one ticket, for csrf_token() in the template.
     */
    public static function csrfIdOf(string $key): string
    {
        return self::CSRF_ID_PREFIX.$key;
    }

    /**
     * A Turbo Frame request gets the bare frame; any other request the thread as a page of its own.
     */
    #[Route('/t/{key}/thread', name: self::THREAD_ROUTE, methods: ['GET'])]
    public function thread(string $key, Request $request): Response
    {
        [, , $ticket] = $this->commentableTicketOf($key);
        $template = $request->headers->has(self::TURBO_FRAME_HEADER) ? '@Conversation/thread_frame.html.twig' : '@Conversation/thread.html.twig';

        $response = $this->render($template, ['ticket' => $ticket]);
        $response->setVary(self::TURBO_FRAME_HEADER);

        return $response;
    }

    #[Route('/t/{key}/comments', name: self::COMMENT_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function comment(string $key, Request $request): Response
    {
        $csrfToken = $request->request->getString(self::CSRF_FIELD);
        $body = $request->request->getString(self::BODY_FIELD);
        $now = $this->clock->now();

        return $this->answer($request, $key, function () use ($key, $csrfToken, $body, $now): string {
            [, $userId, $ticket] = $this->commentableTicketOf($key);
            $this->refuseAnExpiredForm($key, $csrfToken);
            $this->postComment->post($ticket->id, $ticket->visibilityFacts, $userId, $body, $now);

            return self::COMMENT_ADDED;
        });
    }

    #[Route('/t/{key}/messages/{messageId}/edit', name: self::EDIT_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function edit(string $key, string $messageId, Request $request): Response
    {
        $csrfToken = $request->request->getString(self::CSRF_FIELD);
        $body = $request->request->getString(self::BODY_FIELD);
        $now = $this->clock->now();

        return $this->answer($request, $key, function () use ($key, $messageId, $csrfToken, $body, $now): string {
            [$organizationId, $userId, $ticket] = $this->commentableTicketOf($key);
            $this->refuseAnExpiredForm($key, $csrfToken);
            $this->editMessage->edit($organizationId, $ticket->id, $messageId, $userId, $body, $now);

            return self::MESSAGE_EDITED;
        });
    }

    #[Route('/t/{key}/messages/{messageId}/remove', name: self::REMOVE_ROUTE, methods: ['POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function remove(string $key, string $messageId, Request $request): Response
    {
        $csrfToken = $request->request->getString(self::CSRF_FIELD);
        $now = $this->clock->now();

        return $this->answer($request, $key, function () use ($key, $messageId, $csrfToken, $now): string {
            [$organizationId, $userId, $ticket] = $this->commentableTicketOf($key);
            $this->refuseAnExpiredForm($key, $csrfToken);
            $this->removeMessage->remove($organizationId, $ticket->id, $messageId, $userId, $now);

            return self::MESSAGE_REMOVED;
        });
    }

    /**
     * Runs the action in a transaction of its own and answers with its toast, or the refusal's: the refusals of
     * Conversation's Api say in their message what the person sees.
     *
     * @param callable(): string $action returns the toast of a change made
     */
    private function answer(Request $request, string $key, callable $action): Response
    {
        if (null === $this->tenantContext->get()) {
            // Anonymous requests have no tenant, and the firewall sends the AccessDeniedException to the login page.
            throw $this->createAccessDeniedException();
        }
        try {
            $toast = $this->transactions->run($action(...));
        } catch (TicketNotFound|MessageNotFound) {
            throw $this->createNotFoundException(self::NOT_FOUND);
        } catch (ThreadFormExpired) {
            return $this->answerWithToast($request, $key, self::FORM_EXPIRED, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (MessageBodyRefused|MessageEditRefused|MessageRemovalRefused $refused) {
            return $this->answerWithToast($request, $key, $refused->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->answerWithToast($request, $key, $toast, Response::HTTP_OK);
    }

    /**
     * Inside a transaction, the request's or the one run opens, as the visibility predicate reads the viewer's roles under row-level security.
     * The same 404 as the ticket's page for a ticket the person may not see.
     *
     * @return array{OrganizationId, UserId, TicketPageView}
     */
    private function commentableTicketOf(string $key): array
    {
        $organizationId = $this->tenantContext->get() ?? throw $this->createAccessDeniedException();
        $userId = $this->currentUser->idOf($this->getUser()) ?? throw $this->createAccessDeniedException();
        $ticket = $this->ticketQueries->pageOf($key, $this->ticketVisibilities->of($organizationId, $userId));
        if (null === $ticket) {
            throw $this->createNotFoundException(self::NOT_FOUND);
        }
        $this->denyAccessUnlessGranted(ConversationPermission::COMMENT, $ticket->visibilityFacts);

        return [$organizationId, $userId, $ticket];
    }

    /**
     * @throws ThreadFormExpired for a form token that is not valid
     */
    private function refuseAnExpiredForm(string $key, string $csrfToken): void
    {
        if (!$this->isCsrfTokenValid(self::csrfIdOf($key), $csrfToken)) {
            throw new ThreadFormExpired();
        }
    }

    /**
     * A Turbo form gets a Turbo Stream that shows the toast and replaces the message list as it is now, read in a
     * transaction of its own after the action's commit or rollback, with the same 404 as the action. Any other form
     * gets a redirect to the thread's page with the toast flashed, whatever the outcome.
     *
     * @param int $streamStatus 200 for a change made, 422 for one refused
     */
    private function answerWithToast(Request $request, string $key, string $toast, int $streamStatus): Response
    {
        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            $this->addFlash(self::TOAST, $toast);

            return $this->redirectToRoute(self::THREAD_ROUTE, ['key' => $key], Response::HTTP_SEE_OTHER);
        }

        // The Thread component reads the thread while it renders, so the template renders inside the transaction.
        return $this->transactions->run(function () use ($key, $toast, $streamStatus): Response {
            [, , $ticket] = $this->commentableTicketOf($key);

            return $this->render(
                '@Conversation/thread.stream.html.twig',
                ['ticket' => $ticket, 'toast' => $toast],
                new Response(null, $streamStatus, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]),
            );
        });
    }
}
