# Verification record — Community Resource Hub

**Prepared from private PORTFOLIO-01/Codex evidence on November 11, 2026.** This is an independent WordPress plugin demonstration using fictional organizations/resources, not a completed client contract, accessibility certification, or production security assessment.

## Reported completed runtime checks

The project was run on Windows with Docker Desktop, WordPress **7.1.4**, PHP **8.3.36**, and Edge Chromium. The original evaluator reported:
- PowerShell `start-local.ps1` bootstrap and Docker Compose config inspection passed on a fresh local stack
- PHP lint for `community-workflow-demo.php` and `seed-local.php` passed
- `php tests/validate.php` passed **12** assertions on input validation and rejection paths
- Repeated fixture seed preserved the page URLs and fixture record count
- Anonymous user submitted a fictional resource. It remained **Pending**, excluded from the public directory and anonymous WordPress REST results
- After a staff editor published the pending item, the same keyword+topic search found the published item
- Edge viewport at **390px**, keyboard Tab/focus, invalid/missing request nonce, unknown topic, unsafe/credential-bearing URLs, malformed text fields, and HTML sanitation received browser/runtime coverage

**Scope:** These are tests reported for the locally executed demo, not a continuous integration status or an independent third-party audit. Screen-reader evaluation, multiple browser engines, load testing, spam defense at scale and production hosting were **not** covered.

## Screenshot provenance

- `directory.png`: live local WordPress frontend, fictional seed content
- `submission-received.png`: local visitor submission confirmation
- `staff-pending.png`: signed-in demo-only WordPress editor pending queue
- `published-filter.png`: public directory after staff review/publishing with keyword + topic search
- `mobile-form.png`: mobile viewport in Edge connected to local WordPress
- `preview.html`: **static illustrative layout**, not proof of the WordPress runtime

These images are original local demonstration captures. The WordPress admin screenshot contains only a fictional, demo-only account. No real client data are included.

## Reproduce

```powershell
./start-local.ps1
```

Open `http://127.0.0.1:8088`, install local WordPress with demo-only credentials, activate the Community Workflow Demo plugin and seed the example records:

```powershell
Get-Content -Raw ./seed-local.php | docker compose exec -T wordpress php
```

Follow the three-step guest → Pending → staff approve → visible search walkthrough in the root README. On machines with PHP installed:

```powershell
php -l community-workflow-demo.php
php -l seed-local.php
php tests/validate.php
```

Do not expose this stack publicly. `.env` is generated locally and excluded from Git. Do not reuse these demo database or admin accounts in production. A public write endpoint needs substantial additional spam, privacy and operational controls.
