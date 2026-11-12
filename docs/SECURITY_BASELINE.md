# Independent security-hardening case study — Community Resource Hub

**Date:** 2026-11-12  
**Project:** An independently developed, fictional-data WordPress resource-directory demo  
**Scope:** Source review of `community-workflow-demo.php`, `compose.yaml`, test harness and related documentation. The code is public; no third-party, customer or production system was scanned.

## Purpose and approach

This is a **developer-led security review and bounded remediation exercise**, not an independent security audit, penetration test, certification, or claim of production readiness. Compare the original `main` commit `fe0095bf9cf11893a45ebfdb3ad17e798750425d` to the security branch/PR. Source inspection alone does not establish runtime security.

### Observed baseline controls (code review)

| Area | Code evidence | Boundary |
| --- | --- | --- |
| Public submission | `cwd_handle_submission()` checks request method and WordPress nonce | WordPress guest nonces are not per-visitor bot protection |
| Record publication | Public submissions use `post_status => pending`; staff edit requires WordPress `edit_post` capability | Runtime permissions require separate WordPress integration testing |
| Input handling | Title/description bounds; malformed arrays rejected; text sanitization; topic lookup | Sanitization does not guarantee submissions are free of personal data |
| External links | Accepts HTTPS only, denies embedded credentials, escapes display URL | Staff must still review external destinations |
| Output | Escapes displayed text and attributes | Not a substitute for full XSS testing |
| Local environment | Compose binds web interface to `127.0.0.1:8088`; generated DB secrets in ignored `.env` | Not a deployable production configuration |

### Findings and treatment

**F-01 — Unrestricted creation of pending resources (moderate abuse risk, source-observed).** The initial form checked a nonce and honeypot but allowed repeated valid POSTs to create unlimited pending resources. A visitor or scripted client could grow the moderation queue and consume storage. The new change places a **best-effort limit of five structurally valid submission attempts per source IP per UTC-hour-like Unix timestamp bucket**, applied before `wp_insert_post`. On limit, visitors receive a descriptive `limited` message after redirect. A transient-write failure fails closed. Only a server-observed `REMOTE_ADDR` is used, never user-supplied forwarding headers.

Implementation caveats: WordPress transients are **not atomic counters**, may expire or be evicted early, and can permit more than five requests under concurrency. Multiple users sharing one IP may be blocked together. Each hour boundary can admit a new set immediately. Client IP spoofing is not solved at an upstream proxy. A dedicated edge/WAF rate limiter, spam controls and traffic/load testing are still necessary for public deployment.

**F-02 — Submission data governance (unresolved).** Free text can contain personal information even when the form instructs users not to include it. WordPress stores and backs up pending posts. A real operator would need privacy notice, retention/deletion rules, restricted staff access, and backup handling before collecting real submissions.

**F-03 — Dependency and infrastructure assurance (unresolved).** `wordpress:php8.3-apache` and `mariadb:11.4` are mutable image tags. No image vulnerability scan, dependency inventory, production backup recovery, TLS, firewall or restoration evidence was established by this review. The current Docker setup remains **local only**. Production security depends on vendor updates, host configuration, operational controls and documented ownership.

**F-04 — Authorization and misuse tests (incomplete).** The demo's original verification record reports checks for nonces, unknown topics, guest/pending visibility and staff publishing. Those earlier checks have not been independently re-executed for this security branch. Perform an explicit local WordPress retest before claiming the new behavior works in a running CMS.

### Privacy implementation note

Rate-limit transient keys use HMAC-SHA256 derived from validated server-observed IP, hour bucket and the WordPress site salt; **the transient key does not contain the raw IP**. This is pseudonymization, not anonymity: server access logs may still retain IPs, and the transient is subject to WordPress/object-cache retention. Do not collect or publish IP samples in test evidence.

## Repeatable validation

CI performs PHP lint for the two PHP entrypoints and the PHP stubbed validation suite. The unit harness tests accepted/rejected field shapes, link validation, deterministic hashed quota keys, different source buckets, five permitted slots, sixth rejection, and failure-closed storage.

```sh
php -l community-workflow-demo.php
php -l seed-local.php
php tests/validate.php
```

**Test boundaries:** The stubbed test harness does **not** prove WordPress hook ordering, HTTP semantics, transient persistence, real moderation permissions, concurrency correctness, security posture of the host, or actual anti-bot efficacy. A passing GitHub Actions job means those specific lint/stub checks ran; it does not certify the application.

### Next authorized local end-to-end test

1. Start a clean local Docker stack using the README instructions; use only fictional content and demo-only credentials.
2. Submit a valid resource five times from a single local client; confirm five *Pending* posts only.
3. Send a sixth valid submission; confirm a rate-limit message and no sixth post.
4. Verify invalid submissions do not increment the quota, and pending posts are inaccessible to anonymous directory and REST listing.
5. Verify editor publishing permissions and safe link validation still work.
6. Check error paths, accessibility feedback, and unexpected object-cache behavior; inspect server and PHP logs without publishing credentials or addresses.
7. Capture date, environment version, exact commands, observed counts, failures, and reviewer notes. Do not claim these tests passed before running them.

## Candidate service (not offered as a certified assessment)

For a small nonprofit WordPress site, a future fixed-scope service could include backups and restore verification, account/MFA configuration review, plugin/theme patch inventory, form hardening, role review and a remediation change log. **Requires** written site-owner authorization, defined access and backup scope, safe staging, insurance review, and explicit exclusions. No vulnerability scanning or intrusive testing of clients without authorization.

**Commercial tracking:** [Business-Operations CYBER-01](https://github.com/mathesonlj16-eng/Business-Operations/issues/35).
