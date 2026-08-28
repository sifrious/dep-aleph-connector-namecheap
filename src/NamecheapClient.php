<?php

declare(strict_types=1);

namespace Sifrious\NamecheapConnector;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Sifrious\NamecheapConnector\Contracts\RegistrarReader;
use Sifrious\NamecheapConnector\Contracts\Sleeper;
use Throwable;

/**
 * Read-only Namecheap XML API reader.
 *
 * Every call carries ApiUser, ApiKey, UserName and a whitelisted ClientIp;
 * responses are XML and must assert the <ApiResponse Status="OK"> envelope
 * before anything inside them is trusted. This class issues no command that
 * changes account or domain state, and the command allow-list below is the
 * enforcement, not a convention.
 */
final class NamecheapClient implements RegistrarReader
{
    /**
     * Commands this connector may issue. A command not on this list is refused
     * before a request is built, so a read-only connector stays read-only even
     * if a caller asks it not to be.
     */
    public const READ_ONLY_COMMANDS = [
        'namecheap.domains.getList',
        'namecheap.domains.getInfo',
        'namecheap.domains.dns.getHosts',
        'namecheap.domains.dns.getList',
    ];

    public const PAGE_SIZE = 100;

    public function __construct(
        private readonly NamecheapCredentials $credentials,
        private readonly Sleeper $sleeper = new RealSleeper,
        private readonly int $maxAttempts = 3,
        private readonly int $backoffMilliseconds = 500,
        private readonly int $timeoutSeconds = 30,
    ) {}

    /**
     * Every domain on the account, in the order Namecheap returns them.
     *
     * @return list<array<string, mixed>>
     */
    public function domains(): array
    {
        $domains = [];
        $page = 1;

        do {
            $response = $this->call('namecheap.domains.getList', [
                'Page' => $page,
                'PageSize' => self::PAGE_SIZE,
            ]);

            $result = $response->CommandResponse->DomainGetListResult ?? null;

            foreach ($result->Domain ?? [] as $domain) {
                $domains[] = $this->attributes($domain);
            }

            $totalItems = (int) ($response->CommandResponse->Paging->TotalItems ?? 0);
            $pageSize = (int) ($response->CommandResponse->Paging->PageSize ?? self::PAGE_SIZE);
            $totalPages = $pageSize > 0 ? (int) ceil($totalItems / $pageSize) : 1;
            $page++;
        } while ($page <= max(1, $totalPages));

        return $domains;
    }

    /**
     * Host records for one domain.
     *
     * A domain delegated away from Namecheap DNS has no host records here; that
     * is a fact about the domain, not a failure of the call, so it is raised as
     * a distinguishable error rather than returned as an empty list.
     *
     * @return list<array<string, mixed>>
     */
    public function hostRecords(string $domain): array
    {
        [$sld, $tld] = $this->splitDomain($domain);

        $response = $this->call('namecheap.domains.dns.getHosts', ['SLD' => $sld, 'TLD' => $tld]);
        $result = $response->CommandResponse->DomainDNSGetHostsResult ?? null;

        if ($result === null) {
            throw NamecheapError::malformed("No host result for [{$domain}].");
        }

        if (((string) $result['IsUsingOurDNS']) === 'false') {
            throw NamecheapError::notUsingNamecheapDns($domain);
        }

        $records = [];

        foreach ($result->host ?? [] as $host) {
            $records[] = $this->attributes($host);
        }

        return $records;
    }

    /**
     * Split into [SLD, TLD], keeping multi-label suffixes intact
     * (example.co.uk becomes ['example', 'co.uk']).
     *
     * @return array{0: string, 1: string}
     */
    public function splitDomain(string $name): array
    {
        $parts = explode('.', rtrim(strtolower(trim($name)), '.'), 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * @param  array<string, scalar>  $parameters
     */
    private function call(string $command, array $parameters = []): SimpleXMLElement
    {
        if (! in_array($command, self::READ_ONLY_COMMANDS, true)) {
            throw NamecheapError::rejected("[{$command}] is not a read-only command.");
        }

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->attempt($command, $parameters);
            } catch (NamecheapError $error) {
                if (! $error->retryable || $attempt >= $this->maxAttempts) {
                    throw $error;
                }

                $this->sleeper->sleep($this->backoffMilliseconds * (2 ** ($attempt - 1)));
            }
        }
    }

    /**
     * @param  array<string, scalar>  $parameters
     */
    private function attempt(string $command, array $parameters): SimpleXMLElement
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)->get(
                $this->credentials->endpoint(),
                array_merge($this->credentials->queryParameters(), ['Command' => $command], $parameters),
            );
        } catch (ConnectionException $exception) {
            throw NamecheapError::transport($exception->getMessage());
        }

        if ($response->status() === 429) {
            throw NamecheapError::rateLimited('HTTP 429.');
        }

        if ($response->serverError()) {
            throw NamecheapError::transport('HTTP '.$response->status().'.');
        }

        if ($response->clientError()) {
            throw NamecheapError::rejected('HTTP '.$response->status().'.');
        }

        return $this->parse($response->body());
    }

    private function parse(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = new SimpleXMLElement($body);
        } catch (Throwable $exception) {
            throw NamecheapError::malformed($exception->getMessage());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (((string) $xml['Status']) !== 'OK') {
            $error = $xml->Errors->Error ?? null;
            $message = $error !== null ? trim((string) $error) : 'no error detail supplied';
            $code = $error !== null ? (string) $error['Number'] : null;

            /*
             * Namecheap signals throttling in the error text rather than in a
             * status code we have confirmed. Until a real throttled response is
             * captured, the classifier keys on the message; anything else is a
             * rejection, which is not retried.
             */
            if (stripos($message, 'too many requests') !== false || stripos($message, 'rate limit') !== false) {
                throw NamecheapError::rateLimited($message);
            }

            throw NamecheapError::rejected($message, $code);
        }

        return $xml;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(SimpleXMLElement $element): array
    {
        $attributes = [];

        foreach ($element->attributes() ?? [] as $name => $value) {
            $attributes[(string) $name] = (string) $value;
        }

        $attributes['_raw'] = trim($element->asXML() ?: '');

        return $attributes;
    }
}
