# Security Architecture — SuiteCRM MCP

This document describes the security model, threat model, and operator
runbook for SuiteCRM-MCP. It is the authoritative complement to
`NSA_SECURITY_PROMPT.md` (the spec) and `DESIGN.md` (the data model).

The implementation aligns the codebase with the NSA publication
*"Model Context Protocol (MCP): Security Design Considerations for
AI-Driven Automation"* (May 2026, v1.0). Each section below cites the
spec it implements.

---

## 1. Trust model

SuiteCRM-MCP is a local subprocess that exposes seven SuiteCRM CRUD
tools over the MCP JSON-RPC transport. The trust boundaries are:

| Boundary                | Direction        | Defence                                                                  |
|-------------------------|------------------|--------------------------------------------------------------------------|
| MCP client → server     | inbound          | Schema validation, parameter sanitisation, rate limiting, audit logging  |
| server → SuiteCRM REST  | outbound         | TLS 1.2+ enforced, certificate pinning via system trust store, timeouts  |
| SuiteCRM response → MCP | inbound          | Output filter: DLP + prompt-injection scan + truncation                  |
| Operator → environment  | deployment-time  | `.env` via vlucas/phpdotenv; required keys hard-fail at startup           |

Treat every value crossing each boundary as untrusted until validated.

---

## 2. Specification coverage

| NSA spec | Implementation                                                                                            |
|----------|-----------------------------------------------------------------------------------------------------------|
| 1.1 RBAC | `AclEnforcer` enforces a module allowlist (`MCP_ALLOWED_MODULES`) + locally-forbidden actions list. SuiteCRM 401/403 is treated as authoritative denial. |
| 1.2 Session lifecycle | `OAuthClient` + `TokenStore` — short-lived in-memory tokens, idle timeout, proactive refresh, revoke on upstream failure. No tokens on disk. |
| 1.3 Identity traceability | `CorrelationContext` issues per-process session IDs and per-request correlation IDs. Every audit event and response carries them. |
| 2.1 Centralised validation | `SchemaRegistry` + `SchemaValidator` — strict, deny-by-default JSON Schema subset, no type coercion, `additionalProperties: false`. |
| 2.2 Provenance restrictions | `ProvenanceTracker` tags every inbound string and refuses to forward tool-output back into another tool without explicit retagging. |
| 2.3 Injection isolation | `ParameterSanitizer` rejects control chars, zero-width chars, deserialisation sentinels (`__class__`, `@type`), and over-long values. URL composition uses `rawurlencode` on every path/query segment. |
| 2.4 Serialisation safety | All payloads are `json_encode`/`json_decode` only. No `unserialize` anywhere in `src/`. |
| 3.1 Data classification zones | `TrustZone` + `ModuleClassifier` map SuiteCRM modules to Public/Internal/Confidential/Restricted/Regulated. Defaults are deny-by-default (unknown module → Confidential). |
| 3.2 Least privilege | Docker image runs as UID 1000, no shell entrypoint, tini for signal handling. See section 6 below for sandbox profile. |
| 3.3 Sandboxed execution | Operator concern; see "Sandbox profile" below. |
| 3.4 No dynamic discovery | `ToolRegistry` is constructed once with a static list. Adding a tool requires a code change + version bump (capability fingerprint changes — operators pin it). |
| 4.1 Output middleware | `OutputFilter` is the only path back to the MCP client; every tool result flows through it. |
| 4.2 Prompt-injection detection | `PromptInjectionScanner` — 9 heuristic patterns covering override phrases, system-role hijack, tool pivots, jailbreak vocabulary, data URIs, zero-width smuggling. |
| 4.3 Content enforcement | `ContentPolicy` — size cap with explicit truncation marker, DLP rules for CC/SSN/AWS key/private-key headers. |
| 4.4 Chained execution | `ProvenanceTracker::assertMayForward` rejects untagged or tool-output values from being reused as inputs. |
| 5.1 Message signing | `SignatureVerifier` — HMAC-SHA256, constant-time compare. **Inert for stdio**; activate with `MCP_REQUIRE_SIGNATURES=true` when a network transport is added. |
| 5.2 Replay protection | `NonceStore` — bounded LRU, timestamp window check. Same activation conditions as signing. |
| 6.1 Audit logging | `AuditLogger` writes JSONL + SHA-256 hash per event to `MCP_AUDIT_LOG`. Hash gives a tamper-evidence anchor for offline integrity sweeps. |
| 6.2 SIEM alerting | `SiemEmitter` writes a curated security-event subset to `MCP_SIEM_ENDPOINT` (a file path picked up by a sidecar forwarder; HTTP push is operator-owned). |
| 6.3 Anomaly detection | Per-process recursion depth + `fatigue.detected` events. Cross-process anomaly correlation belongs in the SIEM. |
| 7.1 Explicit consent | Concrete interpretation for stdio: destructive operations on Confidential and higher require operator-set env flags. The env edit itself is the "explicit consent" event — auditable via deployment-config history. |
| 7.2 Capability change review | `ToolRegistry::capabilityFingerprint()` is included in `initialize`. Operators pin it; drift causes alerting. |
| 8.1 Rate limits | `RateLimiter` — global + per-tool token buckets + recursion depth ceiling. |
| 8.2 Fatigue detection | Recursion depth limit emits `fatigue.detected`. |
| 9.1 Dependency inventory | `composer.lock` + `composer audit` (Make target `audit`). |
| 9.2 Vulnerability monitoring | `composer audit` in CI; pin Alpine base by tag in `Dockerfile`. |
| 9.3 Deployment validation | See "Deployment checklist" below. |

---

## 3. Configuration reference

All security-relevant configuration is in `.env`. Required values are
listed in `.env.example`. Anything that defaults to "allow" is documented
as such — and there are none in the security-sensitive path.

| Variable | Default | Effect |
|----------|---------|--------|
| `MCP_ALLOWED_MODULES` | 12 standard modules | Deny-by-default allowlist for the SuiteCRM module name. |
| `MCP_ALLOW_DESTRUCTIVE` | `false` | Allow create/update/delete/relate on Confidential and higher zones. |
| `MCP_ALLOW_DESTRUCTIVE_CONFIDENTIAL` | `false` | Additionally permit destructive ops on Restricted and Regulated zones. |
| `MCP_RATE_LIMIT_PER_MINUTE` | `60` | Per-tool + global token-bucket capacity. |
| `MCP_MAX_PAYLOAD_BYTES` | `65536` | Maximum JSON-RPC inbound line size. |
| `MCP_HTTP_TIMEOUT` | `15` | Outbound SuiteCRM request timeout (seconds). |
| `MCP_TOKEN_IDLE_SECONDS` | `900` | OAuth idle timeout — token is discarded if unused. |
| `MCP_AUDIT_LOG` | `php://stderr` | Audit JSONL destination. |
| `MCP_SIEM_ENDPOINT` | (empty) | Secondary sink for security-event-only JSONL. |
| `MCP_REQUIRE_SIGNATURES` | `false` | Activate signature + nonce verification (use only with network transports). |
| `MCP_SIGNING_SECRET` | (empty) | Required when signatures are enabled. |
| `MCP_REPLAY_WINDOW_SECONDS` | `300` | Replay window. |

---

## 4. Audit log schema

Each line is `{json}\t{sha256}` where the hash is computed over the
serialised JSON. Sample event names:

- `server.start`, `rpc.received`, `rpc.parse_error`, `rpc.unhandled_exception`
- `auth.failed`, `auth.token_refreshed`, `auth.revoked`
- `authz.granted`, `authz.denied`
- `zone.access`, `zone.denied`
- `validation.rejected`
- `rate_limit.exceeded`, `fatigue.detected`
- `tool.invoked`, `tool.completed`, `tool.invocation_denied`
- `output.redacted`, `output.injection_detected`, `output.dropped`
- `signature.invalid`, `replay.detected`
- `capability.diff_detected`

Every event carries the session ID, correlation ID, and the configured
operator identity. Argument and result payloads are referenced by
SHA-256 hash rather than included in full — the hash is enough to
correlate with the response shown to the client without persisting the
record body in the audit log (which itself becomes a sensitive store
otherwise).

---

## 5. Sandbox profile (NSA spec 3.3)

The Docker image already runs as a non-root user and ships with no shell
entrypoint. Operators are responsible for the additional sandboxing
controls because they depend on the host orchestrator.

Recommended Kubernetes `securityContext`:

```yaml
securityContext:
  runAsNonRoot: true
  runAsUser: 1000
  runAsGroup: 1000
  readOnlyRootFilesystem: true
  allowPrivilegeEscalation: false
  capabilities:
    drop: ["ALL"]
  seccompProfile:
    type: RuntimeDefault
```

Recommended NetworkPolicy:

- Egress: HTTPS (443) to the SuiteCRM hostname only.
- Egress: deny everything else.
- Ingress: no listening ports (stdio).

The audit log destination should be a tmpfs mount or a sidecar log
collector with a forwarder (Filebeat, Promtail, Vector). Do not log to
the read-only root filesystem.

---

## 6. Deployment checklist (NSA spec 9.3)

Before promoting a build:

1. `composer audit` is clean. Any advisory must be triaged and either
   patched or risk-accepted in writing.
2. `composer install --no-dev --no-scripts` succeeds on the target PHP
   version.
3. `phpunit` runs all three test suites green.
4. The image starts with `MCP_REQUIRE_SIGNATURES=true` and
   `MCP_SIGNING_SECRET` set when a network transport is enabled.
5. `MCP_ALLOWED_MODULES` reflects only the modules this deployment
   needs. Resist the temptation to copy a permissive list across
   environments.
6. The capability fingerprint returned by `initialize` is recorded and
   matched against the previous deployment. Any drift is reviewed by
   the security owner before traffic is cut over.
7. Audit log is forwarded to the SIEM, not pooled locally.
8. `SUITECRM_URL` is HTTPS in production. Local loopback HTTP is the
   only HTTP exemption and is gated by `Config::fromEnvironment`.

---

## 7. Known limitations and accepted risks

These are deliberately documented so the next operator does not
mistake an intentional gap for an oversight.

- **Field-level ACL is delegated to SuiteCRM.** Our pre-check
  enforces a module allowlist; the authoritative field-level decision
  is made by SuiteCRM. If SuiteCRM is misconfigured, our wrapper
  cannot compensate for it.
- **Refresh tokens are not used.** The SuiteCRM password grant does
  not return a refresh token by default. We re-authenticate when the
  access token nears expiry. Operators who run with non-default grants
  can extend `OAuthClient` to consume the refresh token.
- **The signature/replay middleware is inert for stdio.** It exists
  for future network transports and is exercised by tests. Operators
  must not enable it on stdio — there is nothing for it to protect
  against on a local pipe.
- **Prompt-injection detection is pattern-based.** It raises the cost
  of casual injection and produces audit signals. It is not a
  substitute for the consuming LLM treating MCP outputs as untrusted.
- **The audit log is append-only but not append-signed.** Each line
  carries a content hash for tamper-evidence on individual edits;
  full log chain-of-custody requires an external log signer (see
  e.g. AWS CloudTrail integrity, Loki/Tempo with sigstore).
- **DLP redaction will over-match.** The credit-card pattern
  `/\b(?:\d[ \-]?){13,19}\b/` will false-positive on long order
  numbers, tax IDs, and tracking codes that legitimately appear in
  SuiteCRM Notes / Description fields. Operators who see
  `[redacted:cc]` on benign data should tighten the rule (e.g. add a
  Luhn check) rather than relax it — over-redaction is the safe
  direction; under-redaction is not.
- **Outbound OAuth + SuiteCRM HTTP paths are not unit-tested.**
  Verifying these requires a live SuiteCRM instance and is covered
  by the smoke-test step of the deployment checklist (§6), not the
  in-repo test suite.

---

## 8. Incident response quickstart

If a malicious or anomalous interaction is suspected:

1. Capture the audit log immediately. The hash column is the
   tamper-evidence anchor — preserve it intact.
2. Filter for the correlation ID associated with the suspicious
   activity to see the full request/response chain.
3. Revoke the OAuth client in SuiteCRM. Restart the MCP server so the
   in-memory token is purged. (`auth.revoked` event will fire.)
4. Pull the capability fingerprint at the time of the incident and
   compare to the deployment manifest. Any mismatch is a strong
   indicator of unauthorised code change.
5. Check `output.injection_detected` and `validation.rejected` events
   leading up to the incident for the attacker's reconnaissance trail.
