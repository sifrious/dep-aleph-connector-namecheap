<?php

declare(strict_types=1);

namespace Sifrious\NamecheapConnector;

use RuntimeException;

/**
 * A failure the connector can describe rather than merely propagate.
 *
 * The distinction that matters to a caller is whether retrying could help.
 * Rate limiting and transport failures are retryable; a rejected credential or
 * a malformed document is not, and retrying one wastes the quota that the
 * whitelisted IP shares with everything else on this machine.
 */
final class NamecheapError extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $kind,
        public readonly ?string $providerCode = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    public static function transport(string $detail): self
    {
        return new self("Namecheap could not be reached: {$detail}", 'transport', null, true);
    }

    public static function rateLimited(string $detail): self
    {
        return new self("Namecheap rate limited this account: {$detail}", 'rate_limited', null, true);
    }

    public static function rejected(string $detail, ?string $code = null): self
    {
        return new self("Namecheap rejected the request: {$detail}", 'rejected', $code, false);
    }

    public static function malformed(string $detail): self
    {
        return new self("Namecheap returned a document this connector cannot read: {$detail}", 'malformed', null, false);
    }

    public static function notUsingNamecheapDns(string $domain): self
    {
        return new self(
            "[{$domain}] does not use Namecheap DNS, so its host records are not available here.",
            'delegated_elsewhere',
            null,
            false,
        );
    }

    public function isDelegatedElsewhere(): bool
    {
        return $this->kind === 'delegated_elsewhere';
    }
}
