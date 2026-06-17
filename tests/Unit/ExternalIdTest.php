<?php

declare(strict_types=1);

use Monoverse\VoicebotSync\Mapping\ExternalId;

/** @return array<string, mixed> */
function contractExternalIdGrammar(): array
{
    $path = __DIR__.'/../../../../docs/contracts/tools_contract.json';
    /** @var array<string, mixed> $json */
    $json = json_decode((string) file_get_contents($path), true);

    /** @var array<string, mixed> $grammar */
    $grammar = $json['external_id_grammar'];

    return $grammar;
}

it('formats producer:kind:id', function (): void {
    expect(ExternalId::format('laravel', 'product', 84))->toBe('laravel:product:84');
});

it('parses an external id into its segments and rejects a non-match', function (): void {
    expect(ExternalId::parse('laravel:variation:84-red-42'))->toBe([
        'producer' => 'laravel',
        'kind' => 'variation',
        'id' => '84-red-42',
    ]);
    expect(ExternalId::parse('nope'))->toBeNull();
});

it('validates', function (): void {
    expect(ExternalId::isValid('laravel:product:84'))->toBeTrue();
    expect(ExternalId::isValid('bad'))->toBeFalse();
});

it('PATTERN matches the contract external_id_grammar (no PHP↔contract drift)', function (): void {
    $grammar = contractExternalIdGrammar();
    $body = substr(ExternalId::PATTERN, 1, (int) strrpos(ExternalId::PATTERN, '/') - 1);

    expect($body)->toBe($grammar['pattern']);
});

it('parses every contract example into its declared segments', function (): void {
    /** @var list<array<string, string>> $examples */
    $examples = contractExternalIdGrammar()['examples'];

    foreach ($examples as $example) {
        expect(ExternalId::parse($example['external_id']))->toBe([
            'producer' => $example['producer'],
            'kind' => $example['kind'],
            'id' => $example['id'],
        ]);
    }
});
