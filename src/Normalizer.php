<?php

declare(strict_types=1);

namespace Sifrious\NamecheapConnector;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns Namecheap's attribute soup into stable, comparable values.
 *
 * Two rules hold everywhere here:
 *
 * 1. Normalization never invents. A field Namecheap did not supply comes back
 *    as null, and a value that cannot be parsed comes back as null with the
 *    original preserved under `raw`, rather than as a plausible guess.
 * 2. Normalization is deterministic. The same input always produces the same
 *    output, so two runs over an unchanged account produce identical payloads
 *    and Funes sees no change where none happened.
 */
final class Normalizer
{
    public const VERSION = 1;

    /**
     * Namecheap emits US-style dates with no timezone. They are dates, not
     * instants, so they are anchored at UTC midnight rather than given a
     * spurious local time.
     */
    private const DATE_FORMAT = 'm/d/Y';

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function domain(array $attributes): array
    {
        $name = $this->hostname((string) ($attributes['Name'] ?? ''));
        $identifier = isset($attributes['ID']) ? trim((string) $attributes['ID']) : '';

        return [
            'domain' => $name,
            'provider_id' => $identifier !== '' ? $identifier : null,
            'identity_source' => $identifier !== '' ? 'provider_id' : 'domain_name',
            'created_at' => $this->date($attributes['Created'] ?? null),
            'expires_at' => $this->date($attributes['Expires'] ?? null),
            'expired' => $this->boolean($attributes['IsExpired'] ?? null),
            'locked' => $this->boolean($attributes['IsLocked'] ?? null),
            'auto_renew' => $this->boolean($attributes['AutoRenew'] ?? null),
            'premium' => $this->boolean($attributes['IsPremium'] ?? null),
            'uses_registrar_dns' => $this->boolean($attributes['IsOurDNS'] ?? null),
            'privacy' => $this->privacy($attributes['WhoisGuard'] ?? null),
            'raw' => $this->rawScalars($attributes),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function hostRecord(string $domain, array $attributes): array
    {
        $apex = $this->hostname($domain);
        $host = trim((string) ($attributes['Name'] ?? ''));
        $type = strtoupper(trim((string) ($attributes['Type'] ?? '')));
        $ttl = isset($attributes['TTL']) ? (int) $attributes['TTL'] : 0;

        return [
            'domain' => $apex,
            'provider_id' => isset($attributes['HostId']) && trim((string) $attributes['HostId']) !== ''
                ? trim((string) $attributes['HostId'])
                : null,
            'type' => $type !== '' ? $type : null,
            'name' => $host === '@' || $host === '' ? $apex : $this->hostname($host.'.'.$apex),
            'host' => $host,
            'content' => trim((string) ($attributes['Address'] ?? '')),
            'ttl' => $ttl > 0 ? $ttl : null,
            'priority' => $type === 'MX' && isset($attributes['MXPref']) ? (int) $attributes['MXPref'] : null,
            'proxied' => null,
            'raw' => $this->rawScalars($attributes),
        ];
    }

    /**
     * A stable reference for one domain on one account. Namecheap's numeric ID
     * is preferred; where it is absent the name is used and `identity_source`
     * on the normalized record says so, because a reference that silently
     * changes shape is worse than one that is explicit about its basis.
     *
     * @param  array<string, mixed>  $normalized
     */
    public function domainReference(array $normalized): string
    {
        return 'namecheap:domain/'.($normalized['provider_id'] ?? $normalized['domain']);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function recordReference(array $normalized): string
    {
        $identifier = $normalized['provider_id']
            ?? implode('|', [
                (string) ($normalized['type'] ?? 'UNKNOWN'),
                (string) ($normalized['name'] ?? ''),
                (string) ($normalized['content'] ?? ''),
            ]);

        return 'namecheap:record/'.$normalized['domain'].'/'.$identifier;
    }

    private function hostname(string $value): string
    {
        return rtrim(strtolower(trim($value)), '.');
    }

    private function boolean(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'true', '1', 'yes', 'enabled' => true,
            'false', '0', 'no', 'disabled' => false,
            default => null,
        };
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(
            '!'.self::DATE_FORMAT,
            trim((string) $value),
            new DateTimeZone('UTC'),
        );

        return $parsed === false ? null : $parsed->format(DATE_ATOM);
    }

    /**
     * WhoisGuard is not a boolean: a domain can be enabled, disabled, or on a
     * TLD where privacy is not offered at all. Flattening the third case into
     * "off" would make an unavoidable state look like an oversight.
     */
    private function privacy(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match (strtoupper(trim((string) $value))) {
            'ENABLED' => 'enabled',
            'DISABLED' => 'disabled',
            'NOTPRESENT' => 'unavailable',
            '' => null,
            default => 'unknown',
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    private function rawScalars(array $attributes): array
    {
        $raw = [];

        foreach ($attributes as $key => $value) {
            if ($key === '_raw' || is_array($value)) {
                continue;
            }

            $raw[(string) $key] = (string) $value;
        }

        ksort($raw);

        return $raw;
    }
}
