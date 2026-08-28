<?php

declare(strict_types=1);

use Sifrious\NamecheapConnector\NamecheapCredentials;

it('defaults the username to the api user', function (): void {
    expect(NamecheapCredentials::fromArray(credentials())->username)->toBe('operator');
});

it('routes to the sandbox host when asked', function (): void {
    expect(NamecheapCredentials::fromArray(credentials())->endpoint())
        ->toBe('https://api.sandbox.namecheap.com/xml.response')
        ->and(NamecheapCredentials::fromArray(credentials(['sandbox' => false]))->endpoint())
        ->toBe('https://api.namecheap.com/xml.response');
});

it('refuses credentials with no client ip, because Namecheap would reject them anyway', function (): void {
    NamecheapCredentials::fromArray(credentials(['client_ip' => '']));
})->throws(InvalidArgumentException::class);

it('refuses a client ip that is not an address', function (): void {
    NamecheapCredentials::fromArray(credentials(['client_ip' => 'this-machine']));
})->throws(InvalidArgumentException::class);

it('never puts a secret in the account reference', function (): void {
    expect(NamecheapCredentials::fromArray(credentials())->accountReference())
        ->toBe('namecheap:account/operator')
        ->not->toContain('not-a-real-key');
});
