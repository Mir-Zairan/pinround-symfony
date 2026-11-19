<?php

declare(strict_types=1);

namespace App\Review\Domain\Scenario;

/**
 * The scenario format of ADR-0046 (FR-EXP-04), the same rules as docs/api/scenario.v1.json; a unit test keeps the two
 * in step. A scenario is data the extension plays, never code. Lengths count characters (code points), as JSON
 * Schema's maxLength does, and text is kept as sent, not trimmed, so the schema and these rules accept the same
 * values. Two rules the schema cannot carry: a port is 1 to 65535, and every go_to url stays on the scheme, host and
 * port of start_url.
 */
final class ScenarioRules
{
    public const int MAX_SCENARIOS = 20;
    public const int MAX_STEPS = 30;
    public const int TITLE_MAX_LENGTH = 120;
    public const int WHY_MAX_LENGTH = 600;
    public const int SETUP_MAX_LENGTH = 600;
    public const int SAY_MAX_LENGTH = 200;
    public const int TEXT_VALUE_MAX_LENGTH = 1000;

    /**
     * The step types, in ADR-0046's order, each with its fields besides type and say: true when the field is required.
     */
    public const array STEP_FIELDS = [
        'go_to' => ['url' => true],
        'click' => ['target' => true],
        'type' => ['target' => true, 'text' => true],
        'select' => ['target' => true, 'option' => true],
        'wait_for' => ['target' => true],
        'expect_text' => ['text' => true, 'target' => false],
        'expect_visible' => ['target' => true],
        'pause' => [],
        'capture' => [],
    ];

    /** The keys of a target, one of which it uses; name goes only with role. */
    public const array LOCATOR_KINDS = ['role', 'text', 'label'];

    // Written to mean the same in PCRE and ECMAScript, so the schema carries this very string. The host allows no
    // "@" or "\", so no browser reads a host other than the one compared here. The byte order mark is written as
    // itself: ECMAScript's \s holds it and PCRE's does not, and neither reads the other's escape for it.
    public const string URL_PATTERN = '^(https?)://([A-Za-z0-9.-]+|\[[0-9A-Fa-f:.]+\])(?::([0-9]{1,5}))?(?:[/?#][^\s\\\\\x00-\x1f\x7f'."\u{FEFF}".']*)?$';
    public const string ROLE_PATTERN = '^[a-z]+(?:-[a-z]+)*$';

    public const array SCENARIO_FIELDS = ['title', 'why', 'setup', 'start_url', 'steps'];

    // The schema's "\S" in ECMAScript: Unicode white space and the byte order mark are blank.
    private const string NOT_BLANK = '/(*UCP)[^\s\x{FEFF}]/u';

    private const int MAX_PORT = 65535;

    /**
     * Decode the JSON with json_decode($json) and its objects as stdClass, never with $associative true: that would
     * turn {} and {"0": …} into PHP lists, and they would pass where the schema wants a list. An array with string
     * keys is read as an object too, so the scenarios ScenarioRules gave (Scenarios::toArray) parse again.
     *
     * @param mixed $decoded a list of at most MAX_SCENARIOS scenario objects
     *
     * @throws ScenarioRefused with the first rule broken, checking each scenario's fields in order, then its steps
     */
    public static function parse(mixed $decoded): Scenarios
    {
        if (!\is_array($decoded) || !array_is_list($decoded)) {
            throw ScenarioRefused::because(ScenarioRefused::NOT_A_LIST);
        }
        if (\count($decoded) > self::MAX_SCENARIOS) {
            throw ScenarioRefused::because(ScenarioRefused::TOO_MANY_SCENARIOS);
        }
        $scenarios = [];
        foreach ($decoded as $index => $scenario) {
            $scenarios[] = self::scenario($scenario, \sprintf('Scenario %d', $index + 1));
        }

        return new Scenarios($scenarios);
    }

    private static function scenario(mixed $scenario, string $place): Scenario
    {
        $fields = self::fieldsOf($scenario) ?? throw ScenarioRefused::at($place, ScenarioRefused::SCENARIO_FIELDS);
        self::refuseUnlessFieldsAre($fields, self::SCENARIO_FIELDS, [], $place, ScenarioRefused::SCENARIO_FIELDS);
        $title = self::text($fields['title'], 'title', self::TITLE_MAX_LENGTH, false, $place);
        $why = self::text($fields['why'], 'why', self::WHY_MAX_LENGTH, false, $place);
        $setup = self::text($fields['setup'], 'setup', self::SETUP_MAX_LENGTH, true, $place);
        $startUrl = self::text($fields['start_url'], 'start_url', self::TEXT_VALUE_MAX_LENGTH, false, $place);
        $startOrigin = self::originOf($startUrl) ?? throw ScenarioRefused::at($place, ScenarioRefused::START_URL);

        $steps = $fields['steps'];
        if (!\is_array($steps) || !array_is_list($steps)) {
            throw ScenarioRefused::at($place, ScenarioRefused::STEPS_NOT_A_LIST);
        }
        if ([] === $steps) {
            throw ScenarioRefused::at($place, ScenarioRefused::NO_STEPS);
        }
        if (\count($steps) > self::MAX_STEPS) {
            throw ScenarioRefused::at($place, ScenarioRefused::TOO_MANY_STEPS);
        }
        $checkedSteps = [];
        foreach ($steps as $index => $step) {
            $checkedSteps[] = self::step($step, $startOrigin, \sprintf('%s, step %d', $place, $index + 1));
        }

        return new Scenario($title, $why, $setup, $startUrl, $checkedSteps);
    }

    private static function step(mixed $step, string $startOrigin, string $place): ScenarioStep
    {
        $fields = self::fieldsOf($step) ?? throw ScenarioRefused::at($place, ScenarioRefused::STEP_NOT_AN_OBJECT);
        $type = $fields['type'] ?? null;
        if (!\is_string($type) || !\array_key_exists($type, self::STEP_FIELDS)) {
            throw ScenarioRefused::at($place, \sprintf(ScenarioRefused::STEP_TYPE, implode(', ', array_keys(self::STEP_FIELDS))));
        }
        $fieldsOfType = self::STEP_FIELDS[$type];
        $required = ['type', 'say', ...array_keys(array_filter($fieldsOfType))];
        $optional = array_keys(array_filter($fieldsOfType, static fn (bool $isRequired): bool => !$isRequired));
        self::refuseUnlessFieldsAre($fields, $required, $optional, $place, \sprintf(ScenarioRefused::STEP_FIELDS, $type, self::described($required, $optional)));

        $say = self::text($fields['say'], 'say', self::SAY_MAX_LENGTH, false, $place);
        $target = \array_key_exists('target', $fields) ? self::target($fields['target'], $place) : null;
        // Typing nothing clears a field, which can be the very case a scenario shows.
        $text = \array_key_exists('text', $fields) ? self::text($fields['text'], 'text', self::TEXT_VALUE_MAX_LENGTH, 'type' === $type, $place) : null;
        $option = \array_key_exists('option', $fields) ? self::text($fields['option'], 'option', self::TEXT_VALUE_MAX_LENGTH, false, $place) : null;
        $url = null;
        if (\array_key_exists('url', $fields)) {
            $url = self::text($fields['url'], 'url', self::TEXT_VALUE_MAX_LENGTH, false, $place);
            $origin = self::originOf($url) ?? throw ScenarioRefused::at($place, ScenarioRefused::GO_TO_URL);
            if ($origin !== $startOrigin) {
                throw ScenarioRefused::at($place, ScenarioRefused::GO_TO_OTHER_HOST);
            }
        }

        return new ScenarioStep($type, $say, $target, $text, $option, $url);
    }

    private static function target(mixed $target, string $place): ScenarioTarget
    {
        $fields = self::fieldsOf($target) ?? throw ScenarioRefused::at($place, ScenarioRefused::TARGET);
        foreach (array_keys($fields) as $key) {
            if ('name' !== $key && !\in_array($key, self::LOCATOR_KINDS, true)) {
                throw ScenarioRefused::at($place, ScenarioRefused::TARGET);
            }
        }
        $kinds = array_values(array_filter(self::LOCATOR_KINDS, static fn (string $kind): bool => \array_key_exists($kind, $fields)));
        if (1 !== \count($kinds) || (\array_key_exists('name', $fields) && 'role' !== $kinds[0])) {
            throw ScenarioRefused::at($place, ScenarioRefused::TARGET);
        }

        return match ($kinds[0]) {
            'role' => self::roleTarget($fields, $place),
            'text' => ScenarioTarget::byVisibleText(self::text($fields['text'], 'target.text', self::TEXT_VALUE_MAX_LENGTH, false, $place)),
            default => ScenarioTarget::byLabel(self::text($fields['label'], 'target.label', self::TEXT_VALUE_MAX_LENGTH, false, $place)),
        };
    }

    /**
     * @param array<mixed> $fields
     */
    private static function roleTarget(array $fields, string $place): ScenarioTarget
    {
        $role = self::text($fields['role'], 'target.role', self::TEXT_VALUE_MAX_LENGTH, false, $place);
        if (1 !== preg_match('~'.self::ROLE_PATTERN.'~D', $role)) {
            throw ScenarioRefused::at($place, ScenarioRefused::ROLE);
        }
        $name = \array_key_exists('name', $fields) ? self::text($fields['name'], 'target.name', self::TEXT_VALUE_MAX_LENGTH, false, $place) : null;

        return ScenarioTarget::byRole($role, $name);
    }

    /**
     * @throws ScenarioRefused when the value is not text, not UTF-8, holds a NUL character (PostgreSQL's jsonb refuses
     *                         one), is over the cap, or is blank where it may not be
     */
    private static function text(mixed $value, string $field, int $maxLength, bool $mayBeBlank, string $place): string
    {
        if (!\is_string($value)) {
            throw ScenarioRefused::at($place, \sprintf(ScenarioRefused::NOT_TEXT, $field));
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw ScenarioRefused::at($place, \sprintf(ScenarioRefused::NOT_UTF8, $field));
        }
        if (str_contains($value, "\0")) {
            throw ScenarioRefused::at($place, \sprintf(ScenarioRefused::NUL, $field));
        }
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw ScenarioRefused::at($place, \sprintf(ScenarioRefused::TOO_LONG, $field, number_format($maxLength)));
        }
        if (!$mayBeBlank && 1 !== preg_match(self::NOT_BLANK, $value)) {
            throw ScenarioRefused::at($place, \sprintf(ScenarioRefused::BLANK, $field));
        }

        return $value;
    }

    /**
     * @return string|null "scheme://host:port" in lowercase with the default port filled in, or null when the url is
     *                     no full http or https address or its port is outside 1 to MAX_PORT
     */
    private static function originOf(string $url): ?string
    {
        // (*UCP) gives \s ECMAScript's Unicode white space; D makes $ the true end, so a trailing newline is refused.
        if (1 !== preg_match('~(*UCP)'.self::URL_PATTERN.'~uD', $url, $parts)) {
            return null;
        }
        $scheme = $parts[1];
        $defaultPort = 'https' === $scheme ? 443 : 80;
        $port = isset($parts[3]) && '' !== $parts[3] ? (int) $parts[3] : $defaultPort;
        if ($port < 1 || $port > self::MAX_PORT) {
            return null;
        }

        return \sprintf('%s://%s:%d', $scheme, strtolower($parts[2]), $port);
    }

    /**
     * @return array<mixed>|null the fields of a JSON object, decoded as stdClass or as an array; null for any other value
     */
    private static function fieldsOf(mixed $value): ?array
    {
        if ($value instanceof \stdClass) {
            return get_object_vars($value);
        }

        return \is_array($value) ? $value : null;
    }

    /**
     * @param array<mixed> $fields
     * @param list<string> $required
     * @param list<string> $optional
     */
    private static function refuseUnlessFieldsAre(array $fields, array $required, array $optional, string $place, string $reason): void
    {
        foreach ($required as $field) {
            if (!\array_key_exists($field, $fields)) {
                throw ScenarioRefused::at($place, $reason);
            }
        }
        foreach (array_keys($fields) as $field) {
            if (!\in_array($field, $required, true) && !\in_array($field, $optional, true)) {
                throw ScenarioRefused::at($place, $reason);
            }
        }
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     */
    private static function described(array $required, array $optional): string
    {
        $last = array_pop($required);
        $described = [] === $required ? (string) $last : implode(', ', $required).' and '.$last;

        return [] === $optional ? $described : $described.', and may take '.implode(' and ', $optional);
    }
}
