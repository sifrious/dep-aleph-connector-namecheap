# Fixtures

These are **synthetic** responses whose shape is derived from the Namecheap XML API and from the
parser that has been reading it in Landing since 2026-06-06. They are not captured responses.

The domains are the ones C-19 flagged on 2026-04-19, so the golden path exercises the cases that
actually matter: auto-renew off on a namesake domain, a domain recommended for release, and a ccTLD
where WHOIS privacy is not offered at all.

MME-1561 requires a captured, sanitized response before this connector is trusted against a live
account. Replacing these files is a prerequisite, not a nicety: a field Landing never read is a field
these fixtures may have wrong.
