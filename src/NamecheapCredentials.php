<?php

declare(strict_types=1);

namespace Sifrious\NamecheapConnector;

use InvalidArgumentException;

/**
 * The four values every Namecheap call carries, plus the host selector.
 *
 * `clientIp` is not decoration: Namecheap rejects any request whose ClientIp is
 * not whitelisted in the account, and the whitelisted address must be the
 * machine's actual outbound address. A wrong value here is the single most
 * common reason this connector fails.
 */
final readonly class NamecheapCredentials
{
    private const PRODUCTION_HOST = 'https://api.namecheap.com/xml.response';

    private const SANDBOX_HOST = 'https://api.sandbox.namecheap.com/xml.response';

    public string $username;

    public function __construct(
        public string $apiUser,
        public string $apiKey,
        public string $clientIp,
        ?string $username = null,
        public bool $sandbox = false,
    ) {
        foreach (['api_user' => $apiUser, 'api_key' => $apiKey, 'client_ip' => $clientIp] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Namecheap credentials require a non-empty [{$field}].");
            }
        }

        if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException("[{$clientIp}] is not a valid client IP address.");
        }

        $this->username = ($username === null || trim($username) === '') ? $apiUser : $username;
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    public static function fromArray(array $configuration): self
    {
        return new self(
            apiUser: (string) ($configuration['api_user'] ?? ''),
            apiKey: (string) ($configuration['api_key'] ?? ''),
            clientIp: (string) ($configuration['client_ip'] ?? ''),
            username: isset($configuration['username']) ? (string) $configuration['username'] : null,
            sandbox: (bool) ($configuration['sandbox'] ?? false),
        );
    }

    public function endpoint(): string
    {
        return $this->sandbox ? self::SANDBOX_HOST : self::PRODUCTION_HOST;
    }

    public function accountReference(): string
    {
        return 'namecheap:account/'.$this->apiUser;
    }

    /**
     * @return array<string, string>
     */
    public function queryParameters(): array
    {
        return [
            'ApiUser' => $this->apiUser,
            'ApiKey' => $this->apiKey,
            'UserName' => $this->username,
            'ClientIp' => $this->clientIp,
        ];
    }
}
