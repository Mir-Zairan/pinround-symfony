<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The tickets a person walks one after another (FR-TKT-15, P03.T10): their keys in walk order and the label the queue
 * bar and the Finish toast show. A person has one queue, kept in their session; starting another replaces it. It holds
 * keys only, never titles, and the ticket page still reads each ticket under the viewer's visibility, so a key the
 * viewer may no longer see answers the same 404 as any hidden ticket. Readonly; the state lives in the session passed
 * in, so worker mode keeps nothing between requests.
 */
final readonly class TicketQueue
{
    /** A starter takes the first MAX_KEYS of its tickets in their order; the queue refuses more. */
    public const int MAX_KEYS = 100;

    private const string SESSION_KEY = 'ticketing.queue';

    /**
     * @param list<string> $keys in walk order, each once
     *
     * @throws \InvalidArgumentException for a blank label, no key, an empty or repeated key, or more than MAX_KEYS
     */
    public function __construct(public string $label, public array $keys)
    {
        if ('' === trim($label)) {
            throw new \InvalidArgumentException('A queue needs a label.');
        }
        if ([] === $keys) {
            throw new \InvalidArgumentException('A queue needs at least one ticket key.');
        }
        if (\count($keys) > self::MAX_KEYS) {
            throw new \InvalidArgumentException(\sprintf('A queue holds at most %d ticket keys.', self::MAX_KEYS));
        }
        if (\in_array('', $keys, true)) {
            throw new \InvalidArgumentException('A queue\'s ticket keys are never empty.');
        }
        if (\count(array_unique($keys)) !== \count($keys)) {
            throw new \InvalidArgumentException('A queue holds each ticket key once.');
        }
    }

    /**
     * @return self|null null when the session holds no queue, or a value no queue would store
     */
    public static function of(SessionInterface $session): ?self
    {
        $stored = $session->get(self::SESSION_KEY);
        if (!\is_array($stored) || !\is_string($stored['label'] ?? null) || !\is_array($stored['keys'] ?? null) || !array_is_list($stored['keys'])) {
            return null;
        }
        $keys = array_values(array_filter($stored['keys'], is_string(...)));
        if (\count($keys) !== \count($stored['keys'])) {
            return null;
        }
        try {
            return new self($stored['label'], $keys);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Replaces any queue the session held.
     */
    public function storeIn(SessionInterface $session): void
    {
        $session->set(self::SESSION_KEY, ['label' => $this->label, 'keys' => $this->keys]);
    }

    public static function endIn(SessionInterface $session): void
    {
        $session->remove(self::SESSION_KEY);
    }

    public function count(): int
    {
        return \count($this->keys);
    }

    /**
     * @return int|null the key's place, from 1; null when the queue does not hold it
     */
    public function placeOf(string $key): ?int
    {
        $index = array_search($key, $this->keys, true);

        return false === $index ? null : $index + 1;
    }

    /**
     * @return string|null null on the first key, or for a key the queue does not hold
     */
    public function previousKeyOf(string $key): ?string
    {
        $place = $this->placeOf($key);

        return null === $place ? null : ($this->keys[$place - 2] ?? null);
    }

    /**
     * @return string|null null on the last key, or for a key the queue does not hold
     */
    public function nextKeyOf(string $key): ?string
    {
        $place = $this->placeOf($key);

        return null === $place ? null : ($this->keys[$place] ?? null);
    }
}
