# Security Policy

## Supported versions

| Version | Security fixes |
| --- | --- |
| Unreleased (`develop`) | Yes, while the package is in development. |
| Tagged releases | Documented here when the first tag ships. |

## Reporting a vulnerability

Please do not open a public issue. Use GitHub's private vulnerability reporting
("Report a vulnerability" on the Security tab of `qiick-io/laravel-outbound`).
We aim to acknowledge reports within 72 hours.

This package is a network and cryptographic boundary. Reports about the SSRF
guard (curl-style IPv4, metadata, DNS pinning, redirects), Standard Webhooks
signing or verification (raw body handling, replay windows, `hash_equals`), or
secret parsing are treated as critical.
