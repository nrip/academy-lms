# Verify / Reset Link Access-Log Redaction — Production Prerequisite

**Owner:** Platform Operations (primary) with Security Engineering review sign-off before production cutover.

**Status:** Mandatory deployment prerequisite for WP-01B-2a verify/reset link surfaces.

## Scope

Application-layer protections (`SensitiveDataProcessor`, audit redaction, probe hygiene) do **not** close leakage of raw verification tokens from infrastructure access logs.

Before production deployment, the following layers **must** omit or redact query strings for:

- `/verify-email`
- `/reset-password`

(and any equivalent ingress paths that accept `?token=`).

## Required layers

| Layer | Requirement | Responsible |
|---|---|---|
| Web server (e.g. nginx/Apache) | Access logs must not retain full query string for the paths above (omit query, or redact `token=` / equivalent) | Platform Operations |
| Reverse proxy | Same as web server when proxy access logging is enabled | Platform Operations |
| Load balancer | Access/request logs must omit or redact query strings for these paths | Platform Operations / Cloud Networking |
| CDN | Edge request logs / WAF logs must omit or redact query strings for these paths | Platform Operations / CDN owner |
| APM / RUM | Transaction URLs and HTTP attributes must not store raw `token` query values | Observability owner (Platform Operations) |

## Request-size / URL-length limits

Infrastructure **must** enforce request-size and URL-length limits at the edge (web server / proxy / load balancer / CDN) so oversized `?token=` probes are rejected before application crypto. PHP length checks are defence-in-depth only.

## Explicit non-claim

The PHP `SensitiveDataProcessor` alone does **not** close access-log leakage. Production readiness requires the infrastructure controls above with named operational ownership.

## Verification

Ops must confirm in a staging-like environment that sample GET requests to `/verify-email?token=…` and `/reset-password?token=…` do not persist the raw token in any retained access, edge, or APM log stream before production go-live.
