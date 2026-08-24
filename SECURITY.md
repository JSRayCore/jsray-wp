# Security Policy

## Reporting a vulnerability

If you find a security vulnerability, **do not** open a public issue. Please report it privately:

- Email: **support@jsray.org**
- Official site: https://jsray.org

We aim to acknowledge reports within 72 hours.

## Scope

This plugin renders code on public WordPress pages, so the security surface is **output escaping and settings input**:

- Block and shortcode output must never let post content escape into raw HTML — the bundled Core escapes `& < > "` before it ever reaches the page.
- Settings values (theme mode, fallback language) are sanitized before they are stored and before they are printed into the front-end config.
- Capability checks guard the settings screen; only users who can `manage_options` may change plugin configuration.

A payload that produces unescaped angle brackets in rendered block output, or that stores an unsanitized setting, is a **high-severity** vulnerability.

Vulnerabilities in the bundled JSRay Core snapshot belong to
[JSRay Core](https://github.com/JSRayCore/JSRay) — report them the same way, and
fixes reach this project through the next Core sync.

Out of scope:
- Issues that only reproduce with a renderer other than JSRay Core swapped in through the adapter hooks.
- Known catastrophic backtracking in grammar rules — please report it as an issue, not as a vulnerability.

## Supported versions

| Version | Security updates |
|---|---|
| 0.0.1-beta.1 | ✅ Current public beta |
| 0.0.1-internal.∗ | ❌ Superseded by the public beta |
| Stable | Not yet released |
