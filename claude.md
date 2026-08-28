# Namecheap connector — working notes

## The three things that break

1. **ClientIp.** Not whitelisted, or whitelisted for a different address than the machine actually
   goes out on. The API rejects everything with error 1011150 and the message says nothing useful.
2. **API access is off by default.** It is enabled per account, separately from the API key.
3. **The response is XML with an envelope.** `<ApiResponse Status="OK">` must be asserted before
   anything inside is read; an error response is still HTTP 200.

## Deliberate choices

- Throttle detection keys on HTTP 429 and on the error text, not on an error number. Namecheap's
  throttling error number has not been confirmed against a real throttled response; guessing one and
  writing it down as fact would be worse than matching on the message until a capture exists.
- Dates are anchored at UTC midnight. Namecheap emits `m/d/Y` with no timezone; they are dates, not
  instants, and giving them a local time would invent precision.
- `_raw` carries the original XML fragment for each element and is stripped out of the normalized
  `raw` map, which holds only scalar attributes. The envelope payload is the fragment.

## Testing

`vendor/bin/pest`. Everything is driven through `Http::fake()` against the fixtures; no test reaches
the network. `RecordedSleeper` captures the backoff schedule so retry tests assert on the schedule
rather than on elapsed time.
