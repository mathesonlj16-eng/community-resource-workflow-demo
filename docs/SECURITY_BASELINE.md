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

**F-04 — Authorization and misuse tests (partially verified).** Disposable WordPress integration CI now verifies rendering of a guest nonce, invalid submission rejection, five pending submissions, sixth-attempt blocking, no pending content in public directory or normal REST collection, and denial of anonymous pending-status REST access. Publication was simulated from WordPress PHP CLI after verifying anonymous and demo-administrator capabilities, **not via a real authenticated HTTP admin session**. Earlier tests for every malformed field, cross-browser behaviour, concurrency and real staff login were not re-executed in this runtime suite.

### Privacy implementation note

Rate-limit transient keys use HMAC-SHA256 derived from validated server-observed IP, hour bucket and the WordPress site salt; **the transient key does not contain the raw IP**. This is pseudonymization, not anonymity: server access logs may still retain IPs, and the transient is subject to WordPress/object-cache retention. Do not collect or publish IP samples in test evidence.

## Repeatable validation

The **PHP security baseline checks** workflow runs PHP lint and the stubbed validation suite. The unit harness tests accepted/rejected field shapes, link validation, deterministic hashed quota keys, different source buckets, five permitted slots, sixth rejection, failure-closed storage and invalid counter rejection. The **Disposable WordPress integration** workflow starts an isolated localhost-only WordPress + MariaDB stack, creates random demo-only secrets, performs actual HTTP submissions and reads database-backed pending counts.

```sh
php -l community-workflow-demo.php
php -l seed-local.php
php tests/validate.php
```

**Test boundaries:** The stubbed test harness does **not** prove WordPress hook ordering, HTTP semantics or transient persistence; the separate disposable-stack integration checks cover selected real WordPress paths. Neither suite establishes concurrency correctness, production operations, anti-bot efficacy, authenticated HTTP admin workflow, or host security. Passing GitHub Actions does not certify the application.

### Executed disposable-stack integration evidence — November 12, 2026

- **Source revision:** [commit c5960437](https://github.com/mathesonlj16-eng/community-resource-workflow-demo/commit/c5960437fe9221c286aafd53824a595553be5cb0)
- **WordPress HTTP integration:** [GitHub Actions run 43314340274 — succeeded](https://github.com/mathesonlj16-eng/community-resource-workflow-demo/actions/runs/43314340274)
- **PHP lint and stubbed validation:** [GitHub Actions run 43314340307 — succeeded](https://github.com/mathesonlj16-eng/community-resource-workflow-demo/actions/runs/43314340307)
- **Environment:** disposable GitHub-hosted Ubuntu runner, Docker Compose localhost listener, official WordPress PHP 8.3 Apache image and MariaDB 11.4 image; random demonstration credentials, fictional records; containers and volumes deleted after execution.

**Observed checks recorded in the successful integration job:**

1. Public submission form rendered a nonce, with zero pending posts at baseline.
2. Invalid form submission was rejected without creating a pending record.
3. Five valid HTTP submissions created exactly five pending records.
4. Sixth HTTP submission received a limit response and created no additional record.
5. Anonymous directory search and the normal REST collection omitted all pending resources.
6. Anonymous `status=pending` REST query returned **HTTP 400** with error code `rest_invalid_param` and exposed no pending resource content. This is input validation, **not evidence of a particular authentication error**.
7. Anonymous WordPress PHP context lacked the publish capability; a **local CLI demo-admin context** published one pending resource. That record appeared in search while four others remained pending. This is not a browser login or authenticated HTTP admin test.

**Investigation notes:** An initial real WordPress run exposed that DB-backed transients can read back an integer counter as a numeric *string*. The initial implementation incorrectly blocked the second valid submission. It was corrected to accept bounded numeric-string counters and the stub unit tests were updated to emulate the actual storage behavior. Subsequent test assertion corrections restricted directory leak checks to rendered resource cards rather than the search form's echoed query; no pending-card leak was established.

### Remaining coverage / reproducibility

The integration workflow (`.github/workflows/wordpress-integration.yml`) includes the exact disposable setup, PHP install step, fictional seed and `tests/wordpress_e2e.py` checks. It is preferable to rerun that workflow rather than test a client site. The provided local compose instructions must remain restricted to a disposable WordPress installation.

Still **not verified:** concurrent submission bursts or distributed proxy behaviour; true HTTP admin login and permission enforcement with multiple roles; assistive-technology accessibility; spam protection at scale; production TLS, backups and disaster recovery; third-party plugin CVE and image scanning; privacy retention operations. A separate authorized test plan is required before any client-facing security claims.
