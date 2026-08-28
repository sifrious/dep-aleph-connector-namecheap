<?php

declare(strict_types=1);

namespace Sifrious\NamecheapConnector\Contracts;

/**
 * Everything the connector needs from Namecheap, and nothing else.
 *
 * The interface exists because two implementations do: the live XML client and
 * the fixture reader a host uses to exercise the whole inventory path with no
 * credentials and no network. Attribute arrays are the currency because that is
 * what the API returns and what the normalizer consumes; `_raw` carries the
 * original fragment for the envelope payload.
 */
interface RegistrarReader
{
    /**
     * @return list<array<string, mixed>>
     */
    public function domains(): array;

    /**
     * @return list<array<string, mixed>>
     *
     * @throws \Sifrious\NamecheapConnector\NamecheapError when the domain is delegated elsewhere
     */
    public function hostRecords(string $domain): array;
}
