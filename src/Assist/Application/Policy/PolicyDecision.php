<?php

declare(strict_types=1);

namespace App\Assist\Application\Policy;

/**
 * What PolicyEvaluator decided for one AI request: a route to send it on with the "what goes to the AI" filter, the
 * setup sheet, or a refusal with its reason.
 */
final readonly class PolicyDecision
{
    private function __construct(public ?AiRoute $route, public ?PolicyRefusal $refusal, public bool $isTitleOnly)
    {
    }

    /**
     * @param bool $isTitleOnly the ticket text is left out of the prompt
     */
    public static function send(AiRoute $route, bool $isTitleOnly): self
    {
        if (AiRoute::SetupSheet === $route) {
            throw new \InvalidArgumentException('The setup sheet sends nothing.');
        }

        return new self($route, null, $isTitleOnly);
    }

    /**
     * Laptop AI only, and the laptop is not ready: the person sees the setup sheet with the LaptopNotReady line.
     */
    public static function setupSheet(): self
    {
        return new self(AiRoute::SetupSheet, PolicyRefusal::LaptopNotReady, true);
    }

    public static function refuse(PolicyRefusal $refusal): self
    {
        return new self(null, $refusal, true);
    }

    /**
     * True when the request may be sent, to the cloud or to the laptop.
     */
    public function isSendAllowed(): bool
    {
        return null === $this->refusal;
    }

    /**
     * The plain sentence for the refusal or the setup sheet; null when the request may be sent.
     */
    public function reason(): ?string
    {
        return $this->refusal?->sentence();
    }
}
