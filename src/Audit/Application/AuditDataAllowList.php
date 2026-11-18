<?php

declare(strict_types=1);

namespace App\Audit\Application;

/**
 * The check every audit consumer runs on the data of each entry it records (ADR-0026: a schema allow-list per action).
 */
final class AuditDataAllowList
{
    /**
     * @param array<string, list<string>> $dataKeysByAction action => the keys its data must hold
     * @param array<string, mixed>        $entryData
     *
     * @return array<string, mixed> $entryData unchanged
     *
     * @throws \LogicException when the action has no allow-list, or $entryData does not hold exactly its keys
     */
    public static function requireExactKeys(array $dataKeysByAction, string $action, array $entryData): array
    {
        $allowedKeys = $dataKeysByAction[$action] ?? throw new \LogicException(\sprintf('The audit action "%s" has no data allow-list.', $action));
        $keys = array_keys($entryData);
        if ([] !== array_diff($keys, $allowedKeys) || [] !== array_diff($allowedKeys, $keys)) {
            throw new \LogicException(\sprintf('The data of the audit action "%s" must hold exactly the keys %s.', $action, implode(', ', $allowedKeys)));
        }

        return $entryData;
    }
}
