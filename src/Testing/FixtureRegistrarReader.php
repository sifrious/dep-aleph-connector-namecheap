<?php

declare(strict_types=1);

namespace Sifrious\NamecheapConnector\Testing;

use SimpleXMLElement;
use Sifrious\NamecheapConnector\Contracts\RegistrarReader;
use Sifrious\NamecheapConnector\NamecheapError;

/**
 * Reads the package's own sanitized fixtures instead of Namecheap.
 *
 * This lives in the package rather than in a host application on purpose: the
 * shape of a Namecheap response is provider knowledge, and a host that built
 * its own fake would be encoding provider rules in application code.
 */
final class FixtureRegistrarReader implements RegistrarReader
{
    /** @var list<array<string, mixed>> */
    private array $domains;

    /** @var array<string, list<array<string, mixed>>> */
    private array $records;

    /** @var list<string> */
    private array $delegated;

    /**
     * @param  list<array<string, mixed>>  $domains
     * @param  array<string, list<array<string, mixed>>>  $records
     * @param  list<string>  $delegated
     */
    public function __construct(array $domains = [], array $records = [], array $delegated = [])
    {
        $this->domains = array_values($domains);
        $this->records = $records;
        $this->delegated = array_values($delegated);
    }

    /**
     * The fixture account shipped with this package: three domains covering
     * auto-renew off, a domain recommended for release, and a ccTLD where WHOIS
     * privacy is not offered.
     */
    public static function fixtureAccount(): self
    {
        $directory = dirname(__DIR__, 2).'/tests/Fixtures';

        $domains = [];

        foreach (['domains-page-1.xml', 'domains-page-2.xml'] as $page) {
            $xml = new SimpleXMLElement((string) file_get_contents($directory.'/'.$page));

            foreach ($xml->CommandResponse->DomainGetListResult->Domain ?? [] as $domain) {
                $domains[] = self::attributes($domain);
            }
        }

        $hosts = new SimpleXMLElement((string) file_get_contents($directory.'/hosts-managed.xml'));
        $records = [];

        foreach ($hosts->CommandResponse->DomainDNSGetHostsResult->host ?? [] as $host) {
            $records[] = self::attributes($host);
        }

        return new self(
            domains: $domains,
            records: ['heynamatic.com' => $records, 'beam.ong' => $records],
            delegated: ['mary.is'],
        );
    }

    public function domains(): array
    {
        return $this->domains;
    }

    public function hostRecords(string $domain): array
    {
        $key = rtrim(strtolower(trim($domain)), '.');

        if (in_array($key, $this->delegated, true)) {
            throw NamecheapError::notUsingNamecheapDns($key);
        }

        return $this->records[$key] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function attributes(SimpleXMLElement $element): array
    {
        $attributes = [];

        foreach ($element->attributes() ?? [] as $name => $value) {
            $attributes[(string) $name] = (string) $value;
        }

        $attributes['_raw'] = trim($element->asXML() ?: '');

        return $attributes;
    }
}
