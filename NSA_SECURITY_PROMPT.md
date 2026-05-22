# Spec-Driven Development: Implementing NSA Security Controls for SuiteCRM-MCP

## Prompt Objective

Act as a senior cybersecurity engineer and SuiteCRM integration architect.

Your task is to analyze the current repository implementation and generate the code, refactors, middleware, infrastructure controls, and automated tests required to align SuiteCRM-MCP with the National Security Agency (NSA) guidance for securing Model Context Protocol (MCP) systems.

The implementation must follow secure-by-default principles and treat the MCP environment as a continuous trust boundary where security failures, context leakage, or execution inconsistencies may propagate across multiple AI-driven workflows.

This prompt is based on the NSA publication:

> “Model Context Protocol (MCP): Security Design Considerations for AI-Driven Automation” (May 2026, Ver. 1.0) fileciteturn0file0

---

# System Context

SuiteCRM-MCP exposes SuiteCRM data and operational capabilities to AI systems using MCP.

Because MCP reverses the traditional client/server interaction model — allowing MCP servers to query, orchestrate, and execute actions on behalf of connected clients — the attack surface becomes significantly broader than traditional REST-based integrations.

The implementation must therefore:

- treat all MCP interactions as potentially hostile
- assume all upstream and downstream components may be compromised
- validate every trust transition
- isolate execution environments
- minimize implicit trust assumptions
- enforce deterministic authorization and validation behavior
- prevent prompt injection propagation
- preserve forensic traceability

The security model must explicitly protect against:

- prompt injection
- indirect prompt injection
- tool poisoning
- parameter injection
- arbitrary code execution (ACE)
- privilege escalation
- replay attacks
- unauthorized tool invocation
- data exfiltration
- semantic manipulation of MCP outputs
- toolchain hijacking
- cross-context contamination
- denial-of-service (DoS) and fatigue-based attacks

---

# Security Specifications (Implementation Tasks)

# 1. Access Control and Identity Management (RBAC)

Many MCP implementations either lack authentication entirely or fail to enforce granular authorization.

SuiteCRM-MCP must leverage SuiteCRM's RBAC and ACL system as the authoritative authorization source.

## Specification 1.1 — CRUD Authorization Enforcement

Implement a centralized authorization layer that:

- explicitly validates CRUD permissions before exposing any SuiteCRM context
- validates permissions before every tool execution
- validates permissions before every model-triggered operation
- denies access by default
- prevents privilege inheritance across MCP sessions
- logs authorization decisions

The authorization layer must:

- integrate with SuiteCRM ACL APIs
- support per-module permissions
- support field-level restrictions when available
- support ownership-aware access
- prevent privilege escalation between MCP tools

---

## Specification 1.2 — Secure Session Lifecycle Management

Implement strict OAuth 2.1 Bearer token lifecycle management.

Requirements:

- short-lived access tokens
- refresh token rotation
- token revocation support
- replay detection
- session reuse prevention
- idle timeout enforcement
- device/session fingerprinting where applicable

The implementation must:

- invalidate compromised sessions immediately
- prevent concurrent unauthorized reuse
- bind security-sensitive operations to session context

---

## Specification 1.3 — MCP Identity Traceability

All MCP requests must:

- be associated with a verified identity
- include traceable session metadata
- support forensic reconstruction
- preserve correlation IDs across chained tool invocations

---

# 2. Strict Parameter Validation and Serialization Safety

Parameter forwarding without strict validation may result in:

- unstable execution
- prompt injection
- arbitrary code execution
- hidden tool invocation
- downstream data leakage

---

## Specification 2.1 — Centralized JSON Schema Validation

Implement centralized validators for ALL MCP tool inputs.

Requirements:

- JSON Schema validation
- strict type enforcement
- enum validation
- required-field validation
- maximum length restrictions
- payload size limits
- nested object validation
- strict rejection of unknown properties

The validator must reject:

- malformed payloads
- excessive payload sizes
- ambiguous structures
- suspicious serialization patterns

---

## Specification 2.2 — Context-Aware Parameter Forwarding Restrictions

Explicitly block or restrict parameter forwarding when:

- parameter origin is ambiguous
- content may originate from unsanitized user input
- data crosses trust zones
- parameters originate from external MCP servers
- tool metadata contains executable instructions

The validation layer must:

- track parameter provenance
- preserve trust boundaries
- reject unverified context propagation

---

## Specification 2.3 — Injection and Execution Isolation

Apply strict sanitization to prevent:

- command injection (CWE-77)
- OS command injection (CWE-78)
- code injection (CWE-94)
- dynamic evaluation abuse (CWE-95)

Requirements:

- isolate executable commands from text
- prevent shell interpolation
- reject dynamic code execution
- reject unsafe deserialization
- block executable prompt fragments
- sanitize serialized context payloads

---

## Specification 2.4 — Serialization Security Controls

Protect against insecure serialization/deserialization.

Requirements:

- forbid permissive deserialization
- isolate prompts from executable objects
- validate structured content before hydration
- reject embedded executable metadata
- reject serialized instructions attempting downstream execution

---

# 3. Trust Boundary Architecture and Least Privilege

MCP systems must treat every component as operating within separate trust zones.

---

## Specification 3.1 — Data Classification Zones

Group tools into explicit trust and data classification zones.

Examples:

- Public
- Internal
- Confidential
- Restricted
- Regulated

The implementation must:

- prevent unrestricted cross-zone access
- require explicit authorization escalation
- log cross-zone interactions
- isolate sensitive MCP workflows

---

## Specification 3.2 — Least Privilege Execution

The MCP server process must:

- run with minimum OS privileges
- have no unrestricted filesystem access
- have no unrestricted network access
- have no direct access to model internals
- deny lateral movement opportunities

The MCP runtime must only access:

- SuiteCRM APIs
- strictly required databases
- explicitly approved outbound services

---

## Specification 3.3 — Sandboxed Tool Execution

All high-risk tool executions must operate in isolated execution environments.

Requirements:

- process isolation
- AppArmor/SELinux/seccomp support when applicable
- execution time limits
- memory limits
- outbound network restrictions
- filesystem restrictions

---

## Specification 3.4 — Dynamic Tool Discovery Restrictions

Dynamic tool discovery must NOT be implicitly trusted.

Requirements:

- verify tool origin
- verify tool signatures
- validate trusted registries
- prevent naming-collision attacks
- prevent parasitic toolchain hijacking

---

# 4. Output Pipeline Filtering and Chained Execution Protection

Outputs generated by tools or models must NEVER be treated as trusted input for downstream components.

---

## Specification 4.1 — MCP Output Security Middleware

Implement centralized output filtering middleware for ALL responses flowing toward:

- LLMs
- downstream MCP tools
- external orchestration systems
- chained agent workflows

---

## Specification 4.2 — Prompt Injection Detection

The output filter must detect:

- indirect prompt injection
- semantic manipulation
- hidden instructions
- encoded tool invocations
- chained prompt poisoning
- context override attempts
- tool pivot attempts

---

## Specification 4.3 — Content Enforcement Controls

The middleware must enforce:

- content length restrictions
- forbidden keyword scanning
- schema validation on outputs
- response truncation policies
- outbound DLP rules
- structured sanitization

---

## Specification 4.4 — Chained Execution Inspection

Before passing outputs into downstream MCP components:

- inspect every intermediate payload
- validate tool boundaries
- detect hidden prompts
- detect executable metadata
- reject unsafe downstream propagation

---

# 5. Message Signing and Replay Protection

TLS alone is insufficient for protecting MCP integrity.

---

## Specification 5.1 — Cryptographic Message Signatures

Add cryptographic integrity verification to ALL state-changing operations.

Requirements:

- HMAC-SHA256 or stronger
- signature validation middleware
- payload integrity verification
- tamper detection
- signature expiration support

Protect:

- Create
- Update
- Delete
- administrative operations
- privileged tool invocations

---

## Specification 5.2 — Replay Protection

Implement replay protections using:

- timestamps
- expiration windows
- nonces
- idempotency keys
- request correlation identifiers

The implementation must:

- reject delayed messages
- reject duplicate executions
- detect replay attempts
- preserve idempotent behavior

---

# 6. Audit Logging, Detection, and Forensics

Detailed audit logging is foundational for incident response and forensic analysis.

---

## Specification 6.1 — Comprehensive Audit Logging

Log ALL:

- tool invocations
- model invocations
- authorization decisions
- failed validations
- security middleware actions
- outbound MCP communications
- chained execution steps

Captured metadata must include:

- exact parameters
- authenticated identities
- HTTP headers
- session identifiers
- request origins
- trust zone transitions
- cryptographic hashes of outputs
- timestamps
- correlation IDs

---

## Specification 6.2 — SIEM and Security Alerting

Emit alerts or SIEM-compatible logs for:

- repeated malformed requests
- RBAC violations
- replay attempts
- suspicious prompt patterns
- unauthorized tool discovery
- privilege escalation attempts
- prompt storms
- abnormal chaining behavior
- excessive resource consumption

---

## Specification 6.3 — Anomaly Detection

Implement detection rules for:

- unusual execution flows
- abnormal tool invocation sequences
- excessive retries
- unexpected context propagation
- context contamination
- hidden instruction propagation

---

# 7. Approval Workflows and Human Authorization

Changes in MCP capabilities must require explicit governance.

---

## Specification 7.1 — Explicit Consent Enforcement

Sensitive actions must require explicit user or administrator approval.

Examples:

- new tool registration
- expanded data access
- privilege escalation
- external MCP integrations
- new outbound destinations

---

## Specification 7.2 — Capability Change Review

Previously trusted MCP servers must NOT silently gain additional capabilities.

Requirements:

- capability-diff detection
- approval workflow enforcement
- audit trail generation
- notification generation

---

# 8. Denial-of-Service and Resource Exhaustion Protection

MCP environments are vulnerable to prompt storms and recursive execution loops.

---

## Specification 8.1 — Rate Limiting and Resource Controls

Implement:

- request rate limiting
- concurrency limits
- recursion depth limits
- execution quotas
- payload quotas
- timeout enforcement

---

## Specification 8.2 — Fatigue-Based Attack Detection

Detect:

- recursive task chains
- intentionally expensive prompts
- prompt storms
- excessive orchestration loops
- suspicious resource consumption patterns

---

# 9. Vulnerability Management and Secure Operations

Security must remain continuously maintainable.

---

## Specification 9.1 — MCP Dependency Inventory

Maintain a continuously updated inventory of:

- MCP servers
- MCP tools
- package versions
- known vulnerabilities
- patch history
- trusted registries

---

## Specification 9.2 — Vulnerability Monitoring

Track:

- MCP CVEs
- dependency advisories
- upstream security advisories
- deprecated MCP frameworks
- archived or abandoned MCP projects

---

## Specification 9.3 — Secure Deployment Validation

The deployment pipeline must:

- scan for insecure MCP services
- detect exposed MCP endpoints
- detect unauthorized deployments
- validate network restrictions
- validate TLS configuration
- validate sandboxing policies

---

# Execution Instructions for the AI Assistant

1. Analyze `DESIGN.md` and `README.md` to understand the current SuiteCRM-MCP architecture.

2. Begin with:

```text
Specification 2.1 — Centralized JSON Schema Validation
```

Generate:

- the centralized validator architecture
- middleware integration
- reusable schema contracts
- validation error handling
- unit tests
- integration tests
- security-focused tests

3. Present the generated code and wait for approval before continuing.

4. Continue sequentially through the remaining specifications.

5. Every specification MUST include:

- implementation code
- automated tests
- security validation tests
- failure-path tests
- rollback considerations
- observability instrumentation
- threat-model notes

6. Every generated implementation must:

- follow secure-by-default principles
- minimize implicit trust
- preserve auditability
- support deterministic authorization behavior
- prevent privilege escalation
- preserve trust boundaries

7. Any detected architectural security gap not explicitly listed above must be:

- documented
- justified
- added as an additional recommendation

8. All security-sensitive logic must be heavily documented in English using:

- PHPDoc
- architecture comments
- threat-model comments
- operational notes

9. The implementation must prioritize:

- deterministic security behavior
- defense-in-depth
- operational observability
- forensic traceability
- least privilege
- secure serialization
- safe orchestration boundaries
- prevention of chained prompt exploitation