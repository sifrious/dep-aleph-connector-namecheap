# Owned diff

## Read-only command allow-list — 2026-08-28, MME-1561

SEAM: authored — the boundary between what this connector may ask Namecheap for and what the
Namecheap API offers.

PAYS WHEN: any change to this package. The ticket's scope fence says no DNS writes, no renewal
changes, no privacy changes, no release operations; an allow-list makes that a property of the code
rather than of the reviewer's memory.

CHARGES WHEN: a legitimate read command is added and the list has to be updated too. That is one
line, and the friction is the point.

TRIGGER: fired now — the connector reaches an API whose write commands sit alongside its read
commands on the same endpoint, distinguished only by the `Command` parameter.

## Retry classification by message text — 2026-08-28, MME-1561

SEAM: borrowed — Namecheap's error taxonomy, serviced by Namecheap.

PAYS WHEN: a throttled run backs off instead of burning the shared quota on the whitelisted IP.

CHARGES WHEN: Namecheap changes the wording. The classifier then silently treats throttling as a
rejection and stops retrying — a degradation, not a break, but a quiet one.

TRIGGER: fired now, partially. HTTP 429 handling is certain. The message-text branch is a stand-in
for an error-number branch that cannot be written until a real throttled response is captured, and
the code says so rather than inventing a number.
