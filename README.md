# SuiteCRM MCP Server (NSA-aligned)

A Model Context Protocol (MCP) server for SuiteCRM, hardened against the
threats catalogued in the NSA publication
*"Model Context Protocol (MCP): Security Design Considerations for AI-Driven
Automation"* (May 2026, v1.0).

The runtime exposes seven SuiteCRM CRUD tools to MCP clients over stdio:
`list_records`, `get_record`, `create_record`, `update_record`,
`delete_record`, `search_records`, `relate_records`.

For the threat model, control inventory, and operator runbook see
**[SECURITY.md](SECURITY.md)**. For the data architecture see
**[DESIGN.md](DESIGN.md)**.

## Quick start

```bash
composer install
cp .env.example .env
# Edit .env — at minimum set SUITECRM_URL, _CLIENT_ID/SECRET, _USERNAME/PASSWORD.
make test          # unit + integration + security suites
make run           # start the stdio server
```

For Claude Desktop and other MCP clients see the original spec in
`DESIGN.md`. The on-the-wire shape is unchanged — every hardening control
is server-internal.

## Security controls at a glance

| Category | Where |
|----------|-------|
| Centralised JSON Schema validation | `src/Validation/SchemaValidator.php` |
| Parameter sanitation (CWE-77/78/94/95) | `src/Validation/ParameterSanitizer.php` |
| Module allowlist + ACL pre-check | `src/Auth/AclEnforcer.php` |
| Trust-zone gating + explicit consent | `src/Trust/ZoneGuard.php` |
| OAuth token lifecycle | `src/Auth/OAuthClient.php` |
| Hardened HTTP client (TLS 1.2+) | `src/Http/SuiteCrmClient.php` |
| Rate limit + recursion guard | `src/RateLimit/RateLimiter.php` |
| Outbound DLP + prompt-injection scan | `src/Output/OutputFilter.php` |
| Structured audit log + SIEM fan-out | `src/Audit/AuditLogger.php` |
| HMAC signing + nonce store (future) | `src/Crypto/`, `src/Replay/` |
| Static tool registry (no dynamic discovery) | `src/Tools/ToolRegistry.php` |

## Tool reference

The tool shapes match the original `DESIGN.md` documentation, but every
inbound payload is validated against a strict schema; see
`src/Validation/SchemaRegistry.php` for the authoritative contract.
`additionalProperties` is `false` by default — unknown fields produce a
`-32003` validation error.

## Development

```bash
make install     # composer install
make test        # full test suite (unit + integration + security)
make lint        # PSR-12
make audit       # composer audit (NSA spec 9.2)
make docker-build
```

## License

MIT. See `LICENSE`.
