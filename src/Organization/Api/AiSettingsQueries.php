<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Application\UndecryptableField;
use App\SharedKernel\Domain\OrganizationId;

/**
 * An organization's AI settings (org_settings.data.ai), for Assist. The read runs under row-level security, so it needs
 * an open transaction whose tenant is the organization; under any other tenant, or none, it finds no settings row.
 *
 * The stored shape, for the writer of the Admin › AI tab (every key may be missing; a missing key or a JSON null reads
 * as the default in brackets):
 *
 *     data.ai.on                   true | false                                     (false)
 *     data.ai.who                  "everyone" | "leads_only"                       ("everyone")
 *     data.ai.send                 "title_and_text" | "title_only"                 ("title_and_text")
 *     data.ai.sync                 true | false: summarize each ticket on sync      (false)
 *     data.ai.where                "cloud" | "laptop" | "laptop_first"             ("cloud")
 *     data.ai.restricted_to_cloud  true | false: team-only tickets to cloud AI     (false)
 *     data.ai.provider             "huggingface" | "anthropic" | "ollama"          (null: Hugging Face)
 *     data.ai.provider_model       the cloud model's name                           (null: the provider's default)
 *     data.ai.secret_enc           base64 of FieldEncryption::encrypt(provider key) (no key of its own)
 *     data.ai.key_id               the id of the key secret_enc was encrypted with, to find values to re-encrypt
 *                                  after a key rotation; required with secret_enc
 *     data.ai.model                the "Model for laptops" card's model; not read here
 */
interface AiSettingsQueries
{
    /**
     * Reads the settings in one uncached query, never secret_enc or key_id, so a page can ask it on every view. AI off
     * (AiSettings::off) when the organization has no settings row (or it is not visible under the current tenant), no
     * ai key, or any AI setting this module does not know (an unknown "where", a "on" that is not a JSON boolean, a
     * provider that is not a string): an unreadable policy never reads as a permissive one. It never throws.
     */
    public function aiSettingsOf(OrganizationId $organizationId): AiSettings;

    /**
     * The organization's own provider key, decrypted, for the AI gateway only, in one uncached query. Null when it
     * stored none (no secret_enc and no key_id, or no settings row visible under the current tenant): the operator's
     * key applies then.
     *
     * @return non-empty-string|null
     *
     * @throws UndecryptableField        when a key is stored but cannot be read: secret_enc missing, not base64, altered,
     *                                   encrypted with a key no longer in the ring or to an empty key, or key_id missing
     *                                   or naming another key than the one inside secret_enc. The caller must not fall
     *                                   back to the operator's key.
     * @throws \InvalidArgumentException when the field encryption keys are not configured
     */
    public function providerKeyOf(OrganizationId $organizationId): ?string;
}
