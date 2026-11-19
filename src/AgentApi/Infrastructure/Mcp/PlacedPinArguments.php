<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

use App\Review\Api\PlacedPin;
use App\Review\Api\RoundCommands;

/**
 * The pins of one picture as a tool's input takes them, {x_pct, y_pct, note}, for every tool that adds a picture with
 * its pins: one schema and one reading, so the tools never disagree on what a pin is.
 */
final class PlacedPinArguments
{
    /**
     * The input schema of a picture's "pins": at most RoundCommands::MAX_PINS_PER_SCREENSHOT, numbered 1, 2, … in order.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'array',
            'maxItems' => RoundCommands::MAX_PINS_PER_SCREENSHOT,
            'description' => 'Pins on the picture, numbered 1, 2, … in this order.',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'x_pct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'description' => 'From the left edge, in percent of the width.'],
                    'y_pct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'description' => 'From the top edge, in percent of the height.'],
                    'note' => ['type' => 'string', 'maxLength' => RoundCommands::MAX_PIN_NOTE_LENGTH, 'description' => \sprintf('What is at the pin, at most %s characters.', number_format(RoundCommands::MAX_PIN_NOTE_LENGTH))],
                ],
                'required' => ['x_pct', 'y_pct', 'note'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @param mixed  $pins     the "pins" argument, valid against schema(); null when it was left out
     * @param string $toolName for the message of a call the schema should have refused
     *
     * @return list<PlacedPin>
     *
     * @throws \LogicException when $pins is not what schema() lets through
     */
    public static function of(mixed $pins, string $toolName): array
    {
        $pins ??= [];
        if (!\is_array($pins)) {
            throw new \LogicException(\sprintf('The input schema lets %s run only with "pins" as a list.', $toolName));
        }

        return array_values(array_map(static function (mixed $pin) use ($toolName): PlacedPin {
            $xPercent = \is_array($pin) ? ($pin['x_pct'] ?? null) : null;
            $yPercent = \is_array($pin) ? ($pin['y_pct'] ?? null) : null;
            $note = \is_array($pin) ? ($pin['note'] ?? null) : null;
            if (!(\is_int($xPercent) || \is_float($xPercent)) || !(\is_int($yPercent) || \is_float($yPercent)) || !\is_string($note)) {
                throw new \LogicException(\sprintf('The input schema lets %s run only with pins of {x_pct, y_pct, note}.', $toolName));
            }

            return new PlacedPin((float) $xPercent, (float) $yPercent, $note);
        }, $pins));
    }
}
