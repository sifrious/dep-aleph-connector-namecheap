<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Sifrious\NamecheapConnector\NamecheapClient;
use Sifrious\NamecheapConnector\NamecheapCredentials;
use Sifrious\NamecheapConnector\NamecheapError;
use Sifrious\NamecheapConnector\RecordedSleeper;

function client(?RecordedSleeper $sleeper = null, array $overrides = []): NamecheapClient
{
    return new NamecheapClient(
        NamecheapCredentials::fromArray(credentials($overrides)),
        $sleeper ?? new RecordedSleeper,
        backoffMilliseconds: 100,
    );
}

it('follows pagination to the end of the account', function (): void {
    Http::fakeSequence()
        ->push(ncFixture('domains-page-1.xml'))
        ->push(ncFixture('domains-page-2.xml'));

    $domains = client()->domains();

    expect($domains)->toHaveCount(3)
        ->and(array_column($domains, 'Name'))->toBe(['heynamatic.com', 'beam.ong', 'MARY.IS']);
});

it('carries the credentials and the command on every request', function (): void {
    Http::fakeSequence()->push(ncFixture('domains-page-1.xml'))->push(ncFixture('domains-page-2.xml'));

    client()->domains();

    Http::assertSent(function ($request): bool {
        return str_starts_with($request->url(), 'https://api.sandbox.namecheap.com/xml.response')
            && $request['ApiUser'] === 'operator'
            && $request['ClientIp'] === '198.51.100.24'
            && $request['Command'] === 'namecheap.domains.getList';
    });
});

it('reads host records for a domain the registrar still answers for', function (): void {
    Http::fake([ncFixture('hosts-managed.xml')]);
    Http::fake(fn () => Http::response(ncFixture('hosts-managed.xml')));

    $records = client()->hostRecords('heynamatic.com');

    expect($records)->toHaveCount(4)
        ->and($records[0]['Type'])->toBe('A')
        ->and($records[3]['Address'])->toBe('v=spf1 -all');
});

it('splits a multi-label suffix without losing the tld', function (): void {
    expect(client()->splitDomain('example.co.uk'))->toBe(['example', 'co.uk'])
        ->and(client()->splitDomain('Example.Test.'))->toBe(['example', 'test']);
});

it('reports a delegated domain as a fact about the domain, not a failure', function (): void {
    Http::fake(fn () => Http::response(ncFixture('hosts-delegated.xml')));

    try {
        client()->hostRecords('mary.is');
        $this->fail('Expected a delegation error.');
    } catch (NamecheapError $error) {
        expect($error->isDelegatedElsewhere())->toBeTrue()
            ->and($error->retryable)->toBeFalse();
    }
});

it('does not retry a rejected credential', function (): void {
    Http::fake(fn () => Http::response(ncFixture('error-invalid-ip.xml')));
    $sleeper = new RecordedSleeper;

    try {
        client($sleeper)->domains();
        $this->fail('Expected a rejection.');
    } catch (NamecheapError $error) {
        expect($error->kind)->toBe('rejected')
            ->and($error->providerCode)->toBe('1011150')
            ->and($sleeper->slept)->toBeEmpty();
    }

    Http::assertSentCount(1);
});

it('retries a throttled response with exponential backoff and then gives up', function (): void {
    Http::fake(fn () => Http::response(ncFixture('error-throttled.xml')));
    $sleeper = new RecordedSleeper;

    try {
        client($sleeper)->domains();
        $this->fail('Expected a rate-limit error.');
    } catch (NamecheapError $error) {
        expect($error->kind)->toBe('rate_limited')
            ->and($error->retryable)->toBeTrue()
            ->and($sleeper->slept)->toBe([100, 200]);
    }

    Http::assertSentCount(3);
});

it('recovers when a retry succeeds', function (): void {
    Http::fakeSequence()
        ->push(ncFixture('error-throttled.xml'))
        ->push(ncFixture('domains-page-1.xml'))
        ->push(ncFixture('domains-page-2.xml'));

    expect(client()->domains())->toHaveCount(3);
});

it('treats HTTP 429 as throttling', function (): void {
    Http::fake(fn () => Http::response('', 429));
    $sleeper = new RecordedSleeper;

    try {
        client($sleeper)->domains();
        $this->fail('Expected a rate-limit error.');
    } catch (NamecheapError $error) {
        expect($error->kind)->toBe('rate_limited')
            ->and($sleeper->slept)->toHaveCount(2);
    }
});

it('reports a malformed document rather than parsing past it', function (): void {
    Http::fake(fn () => Http::response('<ApiResponse Status="OK"><CommandResponse>'));

    try {
        client()->domains();
        $this->fail('Expected a malformed-document error.');
    } catch (NamecheapError $error) {
        expect($error->kind)->toBe('malformed')
            ->and($error->retryable)->toBeFalse();
    }
});

it('refuses any command that is not on the read-only list', function (): void {
    expect(NamecheapClient::READ_ONLY_COMMANDS)
        ->not->toContain('namecheap.domains.dns.setHosts')
        ->not->toContain('namecheap.domains.renew')
        ->and(NamecheapClient::READ_ONLY_COMMANDS)->toHaveCount(4);
});
