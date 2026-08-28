<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Sifrious\NamecheapConnector\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

function ncFixture(string $name): string
{
    return file_get_contents(__DIR__.'/Fixtures/'.$name) ?: '';
}

/**
 * @return array<string, string>
 */
function credentials(array $overrides = []): array
{
    return array_replace([
        'api_user' => 'operator',
        'api_key' => 'not-a-real-key',
        'client_ip' => '198.51.100.24',
        'sandbox' => true,
    ], $overrides);
}
