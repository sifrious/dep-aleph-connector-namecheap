# Aleph connector — Namecheap

Read-only registrar observation. This connector enumerates the domains on a Namecheap account and,
where Namecheap is still authoritative for a zone, its host records. It emits provenance-bearing
observation envelopes and changes nothing.

Implements MME-1561 (ALEPH-DNS-001).

## Read-only is enforced, not promised

`NamecheapClient::READ_ONLY_COMMANDS` is an allow-list checked before a request is built. A command
that is not on it is refused in-process, so this connector cannot renew a domain, change privacy,
release a registration, or write a DNS record even if a caller asks it to.

## Configuration

| Field | | |
|---|---|---|
| `api_user` | required | The account the API key belongs to |
| `api_key` | required, secret | Issued in the Namecheap account profile |
| `client_ip` | required | This machine's outbound address, whitelisted in the account |
| `username` | optional | The account acted on, when it differs from the API user |
| `sandbox` | optional | Route to `api.sandbox.namecheap.com` |

`client_ip` is the field that breaks first. Namecheap rejects any request whose `ClientIp` is not
whitelisted, and the whitelisted value must be the machine's real outbound address — which is why it
is validated as an IP address here rather than accepted as a string and discovered to be wrong three
API calls later.

## Capabilities

| Capability | What it does |
|---|---|
| `health.check` | Reports readiness without contacting the provider |
| `sources.discover` | Declares the account as the single source; costs no request |
| `history.backfill` | Reads the account from the start |
| `sync.incremental` | Resumes from a cursor |

**Incremental sync here is checkpointed re-listing, not a delta feed.** Namecheap offers no
changed-since filter on the domain list, so the cursor records how far a run got and a resumed run
continues from that offset. Calling it a delta would be a lie a later reader would have to discover
the hard way.

## What comes out

One envelope per domain and one per host record, each carrying stable identity, the exact provider
fields, the observed time, the raw XML fragment as its payload, the connector version, and the
normalizer version. Funes computes the payload hash and owns acceptance.

Extensions: `namecheap.domain` and `namecheap.dns_record`, version 1.

## Normalization

`Normalizer` never invents. A field Namecheap did not supply comes back null; a value that cannot be
parsed comes back null with the original preserved under `raw`. Two consequences are deliberate:

- **Privacy is not a boolean.** `enabled`, `disabled`, and `unavailable` are three different facts.
  Several ccTLDs do not offer WHOIS privacy at all, and flattening that into "off" would make an
  unavoidable state look like an oversight — exactly the misreading C-19 warns about.
- **Identity says what it rests on.** Namecheap's numeric domain ID is preferred; where it is absent
  the domain name is used and `identity_source` records which basis was taken.

## Failure

`NamecheapError` distinguishes what retrying could fix from what it cannot. Rate limiting and
transport failures are retried with exponential backoff; a rejected credential and a malformed
document are not, because retrying one wastes quota that the whitelisted IP shares with everything
else on this machine. A domain delegated away from Namecheap DNS raises a distinguishable error
rather than returning an empty record list — it is a fact about the domain, not a failure of the call.

## Fixtures

The fixtures in `tests/Fixtures` are **synthetic**, shape-derived from the API and from the parser
that has been reading it in Landing since 2026-06-06. Replacing them with a captured, sanitized live
response is a prerequisite before this connector is trusted against a real account.
