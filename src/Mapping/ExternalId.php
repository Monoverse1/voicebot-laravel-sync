<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Mapping;

/**
 * The external_id grammar — `{producer}:{kind}:{id}` — formatted and parsed in one
 * place so the PHP producer can't drift from the contract
 * (docs/contracts/tools_contract.json#external_id_grammar). A drift test pins PATTERN
 * to that contract.
 */
final class ExternalId
{
    public const PATTERN = '/^([a-z0-9]+):([a-z_]+):(.+)$/i';

    public static function format(string $producer, string $kind, int|string $id): string
    {
        return sprintf('%s:%s:%s', $producer, $kind, (string) $id);
    }

    /** @return array{producer: string, kind: string, id: string}|null */
    public static function parse(string $externalId): ?array
    {
        if (preg_match(self::PATTERN, $externalId, $matches) !== 1) {
            return null;
        }

        return ['producer' => $matches[1], 'kind' => $matches[2], 'id' => $matches[3]];
    }

    public static function isValid(string $externalId): bool
    {
        return preg_match(self::PATTERN, $externalId) === 1;
    }
}
