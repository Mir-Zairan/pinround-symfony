<?php

declare(strict_types=1);

namespace App\Review\Domain\Scenario;

/**
 * Scenarios refused by ScenarioRules, with one plain error that names the scenario and step ("Scenario 2, step 5:
 * …"). Nothing was saved. The reason never repeats a value the caller sent, as that value is untrusted text.
 */
final class ScenarioRefused extends \DomainException
{
    public const string NOT_A_LIST = 'Send the scenarios as a list.';
    public const string TOO_MANY_SCENARIOS = 'A notebook holds at most 20 scenarios.';
    public const string SCENARIO_FIELDS = 'a scenario has exactly title, why, setup, start_url and steps.';
    public const string STEPS_NOT_A_LIST = 'steps must be a list.';
    public const string NO_STEPS = 'add at least one step.';
    public const string TOO_MANY_STEPS = 'a scenario has at most 30 steps.';
    public const string STEP_NOT_AN_OBJECT = 'write the step as an object with type and say.';
    public const string STEP_TYPE = 'type must be one of %s.';
    public const string STEP_FIELDS = 'a step of type %s takes %s.';
    public const string NOT_TEXT = '%s must be text.';
    public const string NOT_UTF8 = '%s is not valid UTF-8.';
    public const string BLANK = '%s must not be blank.';
    public const string TOO_LONG = 'keep %s to %s characters or fewer.';
    public const string START_URL = 'start_url must be a full http or https address, like http://localhost:8000/orders.';
    public const string GO_TO_URL = 'url must be a full http or https address, like http://localhost:8000/orders.';
    public const string GO_TO_OTHER_HOST = 'go_to must stay on the scheme, host and port of start_url.';
    public const string TARGET = 'find the element by role and name, by visible text or by label, never by a CSS or XPath string.';
    public const string ROLE = 'target.role must be an ARIA role in lowercase, like button or alert.';

    private function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    /**
     * @param string $place "Scenario 2" or "Scenario 2, step 5", numbered from 1
     */
    public static function at(string $place, string $reason): self
    {
        return new self($place.': '.$reason);
    }
}
