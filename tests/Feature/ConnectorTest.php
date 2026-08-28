<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Connector\ConnectorDispatcher;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\NamecheapConnector\NamecheapClient;
use Sifrious\NamecheapConnector\NamecheapConnector;
use Sifrious\NamecheapConnector\Normalizer;

function inventoryRequest(array $parameters = []): OperationRequest
{
    return new OperationRequest(
        'namecheap:account/operator',
        array_replace(['configuration' => credentials(), 'installation' => 'install-1'], $parameters),
    );
}

/**
 * One full read of the account: two list pages, then host records for the two
 * domains the registrar still answers for. Passing a count fakes that many
 * consecutive reads, because Http::fakeSequence cannot be re-armed mid-test.
 */
function fakeAccount(int $reads = 1): void
{
    $sequence = Http::fakeSequence();

    for ($read = 0; $read < $reads; $read++) {
        $sequence
            ->push(ncFixture('domains-page-1.xml'))
            ->push(ncFixture('domains-page-2.xml'))
            ->push(ncFixture('hosts-managed.xml'))
            ->push(ncFixture('hosts-managed.xml'));
    }
}

it('registers itself with the capabilities the ticket requires', function (): void {
    $manifest = app(ConnectorRegistry::class)->manifest('namecheap');
    $dispatcher = app(ConnectorDispatcher::class);

    expect($dispatcher->supports('namecheap', Capability::DiscoversSources))->toBeTrue()
        ->and($dispatcher->supports('namecheap', Capability::Backfills))->toBeTrue()
        ->and($dispatcher->supports('namecheap', Capability::SyncsIncrementally))->toBeTrue()
        ->and($dispatcher->supports('namecheap', Capability::ChecksHealth))->toBeTrue()
        ->and($manifest->capabilityIds())->not->toContain(Capability::DownloadsArtifacts->value);
});

it('declares the account as its only source and costs no request to say so', function (): void {
    Http::fake();

    $sources = app(NamecheapConnector::class)->discoverSources(inventoryRequest());

    expect($sources)->toHaveCount(1)
        ->and($sources->references())->toBe(['namecheap:account/operator'])
        ->and($sources->sources[0]->metadata['sandbox'])->toBeTrue();

    Http::assertNothingSent();
});

it('declares a configuration schema that keeps the api key secret', function (): void {
    $schema = app(NamecheapConnector::class)->configuration();

    expect($schema->secrets())->toBe(['api_key'])
        ->and($schema->required())->toContain('client_ip')
        ->and($schema->required())->not->toContain('username');
});

it('accepts every domain and record on the account', function (): void {
    fakeAccount();

    $result = app(NamecheapConnector::class)->backfill(inventoryRequest());

    expect($result->successful)->toBeTrue()
        ->and($result->complete)->toBeTrue()
        ->and($result->records)->toBe(11)
        ->and($result->metadata['domains_on_account'])->toBe(3)
        ->and($result->metadata['normalizer_version'])->toBe(Normalizer::VERSION);
});

it('issues only read-only commands', function (): void {
    fakeAccount();

    app(NamecheapConnector::class)->backfill(inventoryRequest());

    Http::assertSent(fn ($request): bool => in_array(
        $request['Command'],
        NamecheapClient::READ_ONLY_COMMANDS,
        true,
    ));
});

it('skips host records for a domain delegated away from the registrar', function (): void {
    fakeAccount();

    app(NamecheapConnector::class)->backfill(inventoryRequest());

    Http::assertSentCount(4);
});

it('records a domain that reports a delegation only at fetch time', function (): void {
    Http::fakeSequence()
        ->push(ncFixture('domains-page-1.xml'))
        ->push(ncFixture('domains-page-2.xml'))
        ->push(ncFixture('hosts-delegated.xml'))
        ->push(ncFixture('hosts-managed.xml'));

    $result = app(NamecheapConnector::class)->backfill(inventoryRequest());

    expect($result->successful)->toBeTrue()
        ->and($result->metadata['delegated_elsewhere'])->toBe(['heynamatic.com']);
});

it('checkpoints a partial run and resumes from the cursor', function (): void {
    Http::fakeSequence()
        ->push(ncFixture('domains-page-1.xml'))
        ->push(ncFixture('domains-page-2.xml'))
        ->push(ncFixture('hosts-managed.xml'))
        ->push(ncFixture('domains-page-1.xml'))
        ->push(ncFixture('domains-page-2.xml'))
        ->push(ncFixture('hosts-managed.xml'))
        ->push(ncFixture('domains-page-1.xml'))
        ->push(ncFixture('domains-page-2.xml'));

    $first = app(NamecheapConnector::class)->backfill(inventoryRequest(['batch' => 1]));

    expect($first->successful)->toBeTrue()
        ->and($first->complete)->toBeFalse()
        ->and($first->cursor)->toBe('1');

    $second = app(NamecheapConnector::class)
        ->syncIncrementally(inventoryRequest(['batch' => 1])->withCursor($first->cursor));

    expect($second->successful)->toBeTrue()
        ->and($second->complete)->toBeFalse()
        ->and($second->cursor)->toBe('2')
        ->and($second->metadata['domains_in_batch'])->toBe(1);

    $third = app(NamecheapConnector::class)
        ->syncIncrementally(inventoryRequest(['batch' => 1])->withCursor($second->cursor));

    expect($third->successful)->toBeTrue()
        ->and($third->complete)->toBeTrue()
        ->and($third->cursor)->toBeNull();
});

it('reports a rejected credential as a structured failure rather than an exception', function (): void {
    Http::fake(fn () => Http::response(ncFixture('error-invalid-ip.xml')));

    $result = app(NamecheapConnector::class)->backfill(inventoryRequest());

    expect($result->successful)->toBeFalse()
        ->and($result->metadata['kind'])->toBe('rejected')
        ->and($result->metadata['retryable'])->toBeFalse()
        ->and($result->error)->toContain('Invalid request IP');
});

it('fails clearly when no configuration was supplied at all', function (): void {
    Http::fake();

    $result = app(NamecheapConnector::class)->backfill(new OperationRequest('namecheap:account/operator'));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toContain('No Namecheap configuration');
});

it('reports readiness without contacting the provider', function (): void {
    Http::fake();

    $report = app(NamecheapConnector::class)->checkHealth();

    expect($report->healthy)->toBeTrue()
        ->and($report->details['read_only'])->toBeTrue();

    Http::assertNothingSent();
});

it('produces the same accepted count when the same account is read twice', function (): void {
    fakeAccount(2);

    $first = app(NamecheapConnector::class)->backfill(inventoryRequest());
    $second = app(NamecheapConnector::class)->backfill(inventoryRequest());

    expect($first->successful)->toBeTrue()
        ->and($second->successful)->toBeTrue()
        ->and($second->records)->toBe($first->records);
});

it('runs the whole inventory from fixtures with no network and no credentials', function (): void {
    Http::fake();

    $connector = new NamecheapConnector(
        app(Sifrious\Aleph\Envelope\EnvelopeSubmitter::class),
        new Normalizer,
        null,
        Sifrious\NamecheapConnector\Testing\FixtureRegistrarReader::fixtureAccount(),
    );

    $result = $connector->backfill(inventoryRequest());

    expect($result->successful)->toBeTrue()
        ->and($result->complete)->toBeTrue()
        ->and($result->records)->toBe(11)
        /*
         * mary.is reports IsOurDNS="false" in the domain list, so its host
         * records are never requested and it never reaches the delegation
         * error. Skipping on the flag is the cheaper path and the reason
         * delegated_elsewhere is empty here rather than naming it.
         */
        ->and($result->metadata['delegated_elsewhere'])->toBe([]);

    Http::assertNothingSent();
});
