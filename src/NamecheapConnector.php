<?php

declare(strict_types=1);

namespace Sifrious\NamecheapConnector;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Sifrious\Aleph\Connector\ConfigurationField;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\Contracts\Backfills;
use Sifrious\Aleph\Connector\Contracts\ChecksHealth;
use Sifrious\Aleph\Connector\Contracts\DiscoversSources;
use Sifrious\Aleph\Connector\Contracts\SyncsIncrementally;
use Sifrious\Aleph\Connector\Values\DiscoveredSource;
use Sifrious\Aleph\Connector\Values\DiscoveredSources;
use Sifrious\Aleph\Connector\Values\HealthReport;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Connector\Values\OperationResult;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;
use Throwable;

/**
 * Read-only registrar observation for Namecheap.
 *
 * This connector enumerates domains and, where Namecheap is authoritative,
 * their host records. It exposes no operation that changes account or domain
 * state: no renewal, no privacy change, no release, no DNS write. The
 * read-only command allow-list lives in NamecheapClient and is enforced before
 * a request is built.
 *
 * Incremental sync here is checkpointed re-listing, not a delta feed. Namecheap
 * offers no changed-since filter on the domain list, so a cursor records how
 * far a run got, and a resumed run continues from that point rather than
 * fetching only what changed. Calling it a delta would be a lie that a later
 * reader would have to discover the hard way.
 */
final class NamecheapConnector implements Backfills, ChecksHealth, Connector, DiscoversSources, SyncsIncrementally
{
    public const DOMAIN_EXTENSION = 'namecheap.domain';

    public const RECORD_EXTENSION = 'namecheap.dns_record';

    public const EXTENSION_VERSION = 1;

    public const DEFAULT_BATCH = 25;

    public function __construct(
        private readonly EnvelopeSubmitter $submitter,
        private readonly Normalizer $normalizer = new Normalizer,
        private readonly ?ConnectorInstallations $installations = null,
        private readonly ?NamecheapClient $client = null,
    ) {}

    public function id(): string
    {
        return 'namecheap';
    }

    public function name(): string
    {
        return 'Namecheap';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function configuration(): ConfigurationSchema
    {
        return new ConfigurationSchema(
            ConfigurationField::text('api_user', 'The Namecheap account the API key belongs to'),
            ConfigurationField::secret('api_key', 'API key issued in the Namecheap account profile'),
            ConfigurationField::text('client_ip', 'Outbound address of this machine, whitelisted in the account'),
            ConfigurationField::text('username', 'Account acted on, when it differs from the API user', required: false),
            ConfigurationField::boolean('sandbox', 'Route calls to the Namecheap sandbox host'),
        );
    }

    /**
     * Readiness, answered without asserting anything the account has not told
     * us. A configuration this connector cannot even build credentials from is
     * unhealthy before a request is made.
     */
    public function checkHealth(): HealthReport
    {
        return HealthReport::healthy('Namecheap connector loaded; readiness is per installation.', [
            'read_only' => true,
            'commands' => NamecheapClient::READ_ONLY_COMMANDS,
            'normalizer_version' => Normalizer::VERSION,
        ]);
    }

    /**
     * The account is the source; domains are its resources. A Namecheap API key
     * is scoped to one account, so there is exactly one source per installation
     * and enumerating it costs no request.
     */
    public function discoverSources(OperationRequest $request): DiscoveredSources
    {
        $credentials = $this->credentials($request);

        return new DiscoveredSources(new DiscoveredSource(
            $credentials->accountReference(),
            'Namecheap ('.$credentials->apiUser.')',
            [
                'endpoint' => $credentials->endpoint(),
                'sandbox' => $credentials->sandbox,
                'client_ip' => $credentials->clientIp,
            ],
        ));
    }

    public function backfill(OperationRequest $request): OperationResult
    {
        return $this->ingest($request, 0);
    }

    public function syncIncrementally(OperationRequest $request): OperationResult
    {
        return $this->ingest($request, $this->offsetFrom($request->cursor));
    }

    private function ingest(OperationRequest $request, int $offset): OperationResult
    {
        try {
            $credentials = $this->credentials($request);
            $client = $this->client ?? new NamecheapClient($credentials);
            $domains = $client->domains();
        } catch (NamecheapError $error) {
            return OperationResult::failed($error->getMessage(), [
                'kind' => $error->kind,
                'provider_code' => $error->providerCode,
                'retryable' => $error->retryable,
            ]);
        } catch (Throwable $exception) {
            return OperationResult::failed($exception->getMessage(), ['kind' => 'unexpected']);
        }

        $batch = max(1, (int) $request->parameter('batch', self::DEFAULT_BATCH));
        $includeRecords = (bool) $request->parameter('include_records', true);
        $installation = (string) $request->parameter('installation', 'unconfigured');
        $capturedAt = Date::now()->toDateTimeImmutable();

        $slice = array_slice($domains, $offset, $batch);
        $accepted = 0;
        $delegatedElsewhere = [];

        foreach ($slice as $attributes) {
            $normalized = $this->normalizer->domain($attributes);

            $outcome = $this->submit($this->domainEnvelope(
                $credentials,
                $installation,
                $capturedAt,
                $normalized,
                (string) ($attributes['_raw'] ?? ''),
            ));

            if ($outcome !== null) {
                return $outcome;
            }

            $accepted++;

            if (! $includeRecords || $normalized['uses_registrar_dns'] === false) {
                continue;
            }

            try {
                $records = $client->hostRecords((string) $normalized['domain']);
            } catch (NamecheapError $error) {
                if ($error->isDelegatedElsewhere()) {
                    $delegatedElsewhere[] = $normalized['domain'];

                    continue;
                }

                return OperationResult::failed($error->getMessage(), [
                    'kind' => $error->kind,
                    'domain' => $normalized['domain'],
                    'accepted_before_failure' => $accepted,
                    'cursor' => (string) ($offset + $accepted - 1),
                ]);
            }

            foreach ($records as $record) {
                $normalizedRecord = $this->normalizer->hostRecord((string) $normalized['domain'], $record);

                $outcome = $this->submit($this->recordEnvelope(
                    $credentials,
                    $installation,
                    $capturedAt,
                    $normalizedRecord,
                    (string) ($record['_raw'] ?? ''),
                ));

                if ($outcome !== null) {
                    return $outcome;
                }

                $accepted++;
            }
        }

        $nextOffset = $offset + count($slice);
        $metadata = [
            'domains_on_account' => count($domains),
            'domains_in_batch' => count($slice),
            'delegated_elsewhere' => $delegatedElsewhere,
            'normalizer_version' => Normalizer::VERSION,
        ];

        return $nextOffset < count($domains)
            ? OperationResult::partial($accepted, (string) $nextOffset, $metadata)
            : OperationResult::completed($accepted, $metadata);
    }

    private function submit(ObservationEnvelope $envelope): ?OperationResult
    {
        $record = $this->submitter->submit($envelope);

        if ($record->isAuthoritative()) {
            return null;
        }

        return OperationResult::failed(sprintf(
            'Funes did not accept [%s]: %s',
            $envelope->resourceReference,
            $record->submission->error ?? $record->submission->status->value,
        ));
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function domainEnvelope(
        NamecheapCredentials $credentials,
        string $installation,
        DateTimeImmutable $capturedAt,
        array $normalized,
        string $raw,
    ): ObservationEnvelope {
        return new ObservationEnvelope(
            sourceReference: $credentials->accountReference(),
            sourceName: 'Namecheap ('.$credentials->apiUser.')',
            resourceReference: $this->normalizer->domainReference($normalized),
            observedAt: $capturedAt,
            payload: $raw,
            provenance: $this->provenance($installation, $capturedAt),
            contentType: 'application/xml',
            account: $credentials->apiUser,
            stream: 'domains',
            eventType: 'namecheap.domain.observed',
            providerId: $normalized['provider_id'] !== null ? (string) $normalized['provider_id'] : null,
            extensions: [new ExtensionMetadata(self::DOMAIN_EXTENSION, self::EXTENSION_VERSION, $normalized)],
        );
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function recordEnvelope(
        NamecheapCredentials $credentials,
        string $installation,
        DateTimeImmutable $capturedAt,
        array $normalized,
        string $raw,
    ): ObservationEnvelope {
        return new ObservationEnvelope(
            sourceReference: $credentials->accountReference(),
            sourceName: 'Namecheap ('.$credentials->apiUser.')',
            resourceReference: $this->normalizer->recordReference($normalized),
            observedAt: $capturedAt,
            payload: $raw,
            provenance: $this->provenance($installation, $capturedAt),
            contentType: 'application/xml',
            account: $credentials->apiUser,
            stream: 'dns/'.$normalized['domain'],
            eventType: 'namecheap.dns_record.observed',
            providerId: $normalized['provider_id'] !== null ? (string) $normalized['provider_id'] : null,
            extensions: [new ExtensionMetadata(self::RECORD_EXTENSION, self::EXTENSION_VERSION, $normalized)],
        );
    }

    private function provenance(string $installation, DateTimeImmutable $capturedAt): Provenance
    {
        return new Provenance(
            connectorId: $this->id(),
            connectorVersion: $this->version(),
            installationId: $installation,
            capturedAt: $capturedAt,
            details: ['normalizer_version' => Normalizer::VERSION],
        );
    }

    private function offsetFrom(?string $cursor): int
    {
        return $cursor !== null && ctype_digit($cursor) ? (int) $cursor : 0;
    }

    private function credentials(OperationRequest $request): NamecheapCredentials
    {
        $configuration = $request->parameter('configuration');

        if (is_array($configuration) && $configuration !== []) {
            return NamecheapCredentials::fromArray($configuration);
        }

        $installationId = $request->parameter('installation');

        if (is_string($installationId) && $this->installations !== null) {
            $installation = $this->installations->find($installationId);

            if ($installation !== null) {
                return NamecheapCredentials::fromArray($installation->configuration);
            }
        }

        throw NamecheapError::rejected(
            'No Namecheap configuration was supplied; pass a configuration array or a known installation id.'
        );
    }
}
