<?php

declare(strict_types=1);

use Sifrious\NamecheapConnector\Normalizer;

it('normalizes a domain into stable comparable values', function (): void {
    $normalized = (new Normalizer)->domain([
        'ID' => '101',
        'Name' => 'HeyNamatic.COM.',
        'Created' => '05/14/2021',
        'Expires' => '05/14/2026',
        'IsExpired' => 'false',
        'IsLocked' => 'false',
        'AutoRenew' => 'false',
        'WhoisGuard' => 'ENABLED',
        'IsOurDNS' => 'true',
    ]);

    expect($normalized['domain'])->toBe('heynamatic.com')
        ->and($normalized['provider_id'])->toBe('101')
        ->and($normalized['identity_source'])->toBe('provider_id')
        ->and($normalized['expires_at'])->toBe('2026-05-14T00:00:00+00:00')
        ->and($normalized['auto_renew'])->toBeFalse()
        ->and($normalized['privacy'])->toBe('enabled')
        ->and($normalized['uses_registrar_dns'])->toBeTrue();
});

it('says which basis an identity rests on when the provider supplies no id', function (): void {
    $normalized = (new Normalizer)->domain(['Name' => 'example.test']);

    expect($normalized['provider_id'])->toBeNull()
        ->and($normalized['identity_source'])->toBe('domain_name')
        ->and((new Normalizer)->domainReference($normalized))->toBe('namecheap:domain/example.test');
});

it('distinguishes privacy that is off from privacy that is not offered', function (): void {
    $normalizer = new Normalizer;

    expect($normalizer->domain(['WhoisGuard' => 'DISABLED'])['privacy'])->toBe('disabled')
        ->and($normalizer->domain(['WhoisGuard' => 'NOTPRESENT'])['privacy'])->toBe('unavailable')
        ->and($normalizer->domain(['WhoisGuard' => 'SOMETHINGNEW'])['privacy'])->toBe('unknown')
        ->and($normalizer->domain([])['privacy'])->toBeNull();
});

it('returns null rather than a guess for an unparseable date', function (): void {
    expect((new Normalizer)->domain(['Expires' => 'sometime next year'])['expires_at'])->toBeNull()
        ->and((new Normalizer)->domain(['Expires' => 'sometime next year'])['raw']['Expires'])
        ->toBe('sometime next year');
});

it('produces identical output for identical input', function (): void {
    $attributes = ['ID' => '7', 'Name' => 'example.test', 'Expires' => '01/02/2027', 'AutoRenew' => 'true'];

    expect((new Normalizer)->domain($attributes))->toBe((new Normalizer)->domain($attributes));
});

it('expands the apex host and keeps MX preference only for MX records', function (): void {
    $normalizer = new Normalizer;

    $apex = $normalizer->hostRecord('example.test', ['Name' => '@', 'Type' => 'A', 'Address' => '203.0.113.10', 'TTL' => '1800', 'MXPref' => '10']);
    $mail = $normalizer->hostRecord('example.test', ['Name' => '@', 'Type' => 'mx', 'Address' => 'mail.example.test.', 'TTL' => '3600', 'MXPref' => '20']);
    $www = $normalizer->hostRecord('example.test', ['Name' => 'www', 'Type' => 'CNAME', 'Address' => 'example.test.', 'TTL' => '0']);

    expect($apex['name'])->toBe('example.test')
        ->and($apex['priority'])->toBeNull()
        ->and($mail['type'])->toBe('MX')
        ->and($mail['priority'])->toBe(20)
        ->and($www['name'])->toBe('www.example.test')
        ->and($www['ttl'])->toBeNull();
});

it('falls back to a composite record reference when the host has no id', function (): void {
    $normalizer = new Normalizer;
    $record = $normalizer->hostRecord('example.test', ['Name' => '@', 'Type' => 'TXT', 'Address' => 'v=spf1 -all']);

    expect($normalizer->recordReference($record))
        ->toBe('namecheap:record/example.test/TXT|example.test|v=spf1 -all');
});
