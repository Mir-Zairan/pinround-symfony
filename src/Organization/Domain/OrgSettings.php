<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TenantOwned;
use App\SharedKernel\Domain\UserId;

/**
 * The organization-wide settings an admin changes, one row per organization (data-model.md, org_settings). New
 * settings hold the defaults of the Admin screen, with the first admin as the triager of tickets with no team.
 */
final class OrgSettings implements TenantOwned
{
    /** The values of data.step_policy (workflow-engine.md); Organization\Api\StepPolicy has one case for each. */
    public const array STEP_POLICIES = ['locked', 'extend', 'team'];

    /**
     * The settings of data.ai the Admin › AI tab changes, each with the value it stores by the choice the tab offers.
     * Organization\Api\AiSettingsQueries documents the stored shape, which reads any other value as AI off.
     */
    public const array AI_CHOICES = [
        'on' => ['on' => true, 'off' => false],
        'who' => ['everyone' => 'everyone', 'leads_only' => 'leads_only'],
        'send' => ['title_and_text' => 'title_and_text', 'title_only' => 'title_only'],
        'sync' => ['on' => true, 'off' => false],
        'where' => ['cloud' => 'cloud', 'laptop' => 'laptop', 'laptop_first' => 'laptop_first'],
        'restricted_to_cloud' => ['on' => true, 'off' => false],
        'provider' => ['huggingface' => 'huggingface', 'anthropic' => 'anthropic', 'ollama' => 'ollama'],
    ];

    private readonly string $organizationId;
    // Doctrine's optimistic lock: the column's default 1 on insert, raised by every update.
    private int $version = 1;

    /** @var array<string, mixed> */
    private array $data;

    public function __construct(OrganizationId $organizationId, UserId $firstAdmin, private readonly \DateTimeImmutable $createdAt)
    {
        $this->organizationId = $organizationId->toString();
        $this->data = [
            'ping_limit_hours' => 4,
            'auto_clear' => true,
            'quiet_hours' => false,
            'lead_check' => false,
            'triager_id' => $firstAdmin->toString(),
            'step_policy' => 'extend',
            'ai' => ['on' => true, 'who' => 'everyone', 'send' => 'title_and_text', 'sync' => true, 'where' => 'cloud', 'model' => 'qwen3:4b'],
            'retention' => '2y',
        ];
    }

    public function organizationId(): OrganizationId
    {
        return new OrganizationId($this->organizationId);
    }

    public function version(): int
    {
        return $this->version;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function triagerId(): UserId
    {
        $triagerId = $this->data['triager_id'] ?? null;

        return \is_string($triagerId) ? new UserId($triagerId) : throw new \UnexpectedValueException('The settings hold no triager.');
    }

    /**
     * Changes data.step_policy only; every other setting stays as it is.
     *
     * @throws \InvalidArgumentException when $stepPolicy is not one of STEP_POLICIES
     */
    public function changeStepPolicy(string $stepPolicy): void
    {
        if (!\in_array($stepPolicy, self::STEP_POLICIES, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a step policy.', $stepPolicy));
        }

        $this->data['step_policy'] = $stepPolicy;
    }

    /**
     * Stores the value of $choice for one AI setting; every other setting stays as it is. When data.ai is missing or
     * no object, it starts again from this one setting.
     *
     * @return bool|string|null the value stored before; null when there was none, or it was neither a bool nor a string
     *
     * @throws \InvalidArgumentException when $setting is not one of AI_CHOICES, or $choice is not one of its choices
     */
    public function changeAiSetting(string $setting, string $choice): bool|string|null
    {
        $choices = self::AI_CHOICES[$setting] ?? throw new \InvalidArgumentException(\sprintf('"%s" is not an AI setting.', $setting));
        if (!\array_key_exists($choice, $choices)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a choice of the AI setting "%s".', $choice, $setting));
        }

        $ai = $this->aiData();
        $before = $ai[$setting] ?? null;
        $ai[$setting] = $choices[$choice];
        $this->data['ai'] = $ai;

        return \is_bool($before) || \is_string($before) ? $before : null;
    }

    /**
     * Stores the organization's own AI provider key, as FieldEncryption::encrypt returned it, in data.ai.secret_enc
     * (base64) with the id of the key it was encrypted with in data.ai.key_id, taken from the value itself so the two
     * always match.
     *
     * @throws \InvalidArgumentException when $encryptedKey is not in FieldEncryption's stored format
     */
    public function changeAiProviderKey(#[\SensitiveParameter] string $encryptedKey): void
    {
        $keyIdLength = '' === $encryptedKey ? 0 : \ord($encryptedKey[0]);
        if (0 === $keyIdLength || \strlen($encryptedKey) <= 1 + $keyIdLength) {
            throw new \InvalidArgumentException('An encrypted provider key starts with the id of its key.');
        }

        $ai = $this->aiData();
        $ai['secret_enc'] = base64_encode($encryptedKey);
        $ai['key_id'] = substr($encryptedKey, 1, $keyIdLength);
        $this->data['ai'] = $ai;
    }

    /**
     * Whether data.ai holds a provider key of the organization's own, readable or not; without one the operator's key
     * applies.
     */
    public function hasAiProviderKey(): bool
    {
        $ai = $this->aiData();

        return null !== ($ai['secret_enc'] ?? null) || null !== ($ai['key_id'] ?? null);
    }

    /**
     * @return array<string, mixed> every setting by its data-model.md key
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * @return array<array-key, mixed> data.ai, or nothing when it is missing or no object
     */
    private function aiData(): array
    {
        $ai = $this->data['ai'] ?? null;

        return \is_array($ai) && !array_is_list($ai) ? $ai : [];
    }
}
