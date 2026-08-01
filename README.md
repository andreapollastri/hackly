# Hackly

**Authorized attack-surface & application security scanning.**

Hackly helps you inventory targets you own, verify ownership, run soft rate-limited scans, scan linked GitHub repositories (Laravel/PHP-focused), and review findings — all from a Filament admin panel.

Built with **Laravel 13** and **Filament 5**.

> **Legal notice** — Only scan targets and repositories you are authorized to assess. Unauthorized scanning may be illegal. DNS ownership verification is required before a **target** scan can start.

---

## What it does

| Area | Details |
|------|---------|
| **Targets** | Domains with DNS TXT ownership verification |
| **Repositories** | GitHub repos cloned via PAT; optional link to one or more Targets |
| **Scans** | Target DAST profiles `quick` / `standard` / `deep` — jobs dispatch immediately |
| **Repo scans** | SAST / SCA / secrets / IaC + Laravel-specific checks; independent from Targets |
| **Findings** | Normalized LOW / MEDIUM / HIGH; repo findings include reachability & noise flags |
| **Nightly** | All nightly repos (+ deep linked targets) and deep scan of unlinked targets |
| **Reports** | PDF export per target scan |
| **Safety** | Soft rate limits, jitter, quiet hours, deep-scan cooldown, optional allowlist |
| **Admin** | Dashboard metrics, profile page, TOTP + email 2FA |

### Target scanners (DAST / ASM)

| Task | Tool | Profiles |
|------|------|----------|
| DNS / WHOIS | `dig`, `whois` (+ DNSSEC, CAA, AXFR, wildcard) | quick · standard · deep |
| Mail security | SPF / DKIM / DMARC / MTA-STS / TLS-RPT | quick · standard · deep |
| TLS check | cert expiry, hostname, legacy protocols | quick · standard · deep |
| Port scan | `nmap` | quick · standard · deep |
| Tech fingerprint | headers, cookies, CORS, APP_DEBUG | quick · standard · deep |
| Subdomain enum | wordlist + CT logs + takeover fingerprints | standard · deep |
| Origin exposure | direct-IP Host-header probes | standard · deep |
| Path discovery | soft wordlist / Nuclei (Laravel exposures) | standard · deep |
| Vulnerability templates | `nuclei` (+ laravel/php/dotenv tags) | standard · deep |
| DAST baseline | OWASP ZAP (calibrated severities) | deep |

### Repository scanners (Laravel / PHP)

Orchestrated similarly to Aikido-style pipelines: open-source tools + Hackly post-processing (dedupe, PHP reachability, noise filters).

| Task | Tool | Profiles |
|------|------|----------|
| SAST | `semgrep` (`p/php`) | standard · deep |
| SCA | `trivy` | standard · deep |
| Secrets | `gitleaks` | quick · standard · deep |
| IaC | `checkov` (when Docker/TF/Actions present) | deep |
| Composer / CVE | `composer audit` + [OSV](https://osv.dev) | quick · standard · deep |
| Laravel PHP audit | Hackly static checks on the clone | quick · standard · deep |
| Laravel live pentest | Live probes on **linked** Targets (only when included) | standard · deep* |

\* Live pentest runs only when the scan is started with **Include linked targets** (or nightly). Repo-only scans skip it.

**Post-processing (repo findings)**

- Cross-tool **deduplication** (e.g. same `package + CVE` from Trivy and OSV → one finding)
- **PHP reachability**: namespace usage in `app/`, `routes/`, `config/`, …; `require-dev` → unreachable; Laravel package auto-discovery
- **Noise filters**: placeholder secrets, path out of app scope, configurable rule suppressions
- Findings store `reachability`, `confidence`, `noise_filtered`, and `tools[]`

---

## Architecture

```
Target (verified)                    Repository (GitHub PAT)
   │                                      │
   ├─ optional link ──────────────────────┤
   │                                      │
   ├─ Scan (DAST)                         ├─ RepoScan
   │    └─ ScanTasks → Findings           │    └─ RepoScanTasks → Findings
   │                                      │         (asset_id nullable)
   └─ optional: include linked repos      └─ optional: include linked targets
```

**Independent scan modes**

| Mode | Behavior |
|------|----------|
| Target only | DAST profiles quick / standard / deep — no repo work |
| Target + repos | Target DAST **and** repo scans for every linked repository |
| Repo only | Clone + SAST/SCA/secrets/… — no Target DAST / no live pentest |
| Repo + targets | Repo scan **and** deep DAST on every linked verified Target |

Jobs use the **database queue** (no Horizon). A queue worker must be running for scans to execute.

---

## Requirements

- PHP **8.3+**
- Composer
- SQLite *(default)* or MySQL / PostgreSQL
- Node.js *(optional — Vite assets; Filament ships published assets)*
- `git` (for repository clones)

### Target scanner binaries *(optional but recommended)*

| Binary | Used for |
|--------|----------|
| `dig`, `whois` | DNS / WHOIS |
| `nmap` | Ports |
| `nuclei` | Templates |
| OWASP ZAP (`zap.sh`) | Deep DAST |

```bash
sudo bash scripts/install-scanner-binaries-ubuntu.sh
```

### Repository scanner binaries *(for GitHub repo scanning)*

| Binary | Used for |
|--------|----------|
| `semgrep` | PHP SAST |
| `trivy` | SCA |
| `gitleaks` | Secrets |
| `checkov` | IaC |
| `composer` | `composer audit` |

**Ubuntu 24.04 / 26.x** — non-interactive install:

```bash
sudo bash scripts/install-repo-scanners.sh
```

Then verify:

```bash
php artisan hackly:check-binaries
```

Missing repo binaries cause those tasks to be **skipped** (other tasks still run). Composer/OSV and Hackly Laravel auditors do not require Semgrep/Trivy.

---

## Quick start

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
```

Default admin:

| | |
|---|---|
| URL | `/` (login) |
| Email | `admin@hackly.test` |
| Password | `password` |

Run the app + queue worker:

```bash
# All-in-one (serve + queue + logs + vite)
composer run dev
```

Or separately:

```bash
php artisan serve
php artisan queue:work          # required for scans
php artisan schedule:work       # nightly + cleanup
```

---

## Usage

### Targets (admin UI)

1. Open **Targets** → create a domain
2. **DNS token** → publish the TXT record at your DNS provider
3. **Verify DNS** — must succeed before target scanning
4. **Start scan** — pick `quick` / `standard` / `deep`
   - Optionally enable **Include linked repositories**
5. Watch **Scans** for progress, open findings (HIGH → LOW), export the **PDF**
6. On a target’s detail page: **Linked repositories** + **Scans**

Targets do **not** need a linked repository.

### GitHub repositories (admin UI)

1. Open **GitHub tokens** → add a PAT → **Validate**
2. Open **Repositories** → add `owner/repo` (token is checked against the GitHub API)
3. Optionally link one or more **Targets** (not required)
4. **Scan now** — pick a repo profile
   - Optionally enable **Include linked targets** (queues deep DAST on each verified linked Target)
5. Review **Repo scans** and **Findings** on the repository page  
   (repo findings are stored on the repository; `asset_id` may be null)

#### GitHub token permissions

**Classic PAT**

| Scope | When |
|-------|------|
| `repo` | Private repositories |
| `public_repo` | Public repositories only |

**Fine-grained PAT** *(recommended)*

| Permission | Access |
|------------|--------|
| **Contents** | Read |
| **Metadata** | Read |

Select only the repositories you intend to scan.

### Independent scan combinations

| Goal | UI | CLI |
|------|----|-----|
| Target only | Start scan (toggle off) | `hackly:scan example.com --profile=standard` |
| Target + linked repos | Start scan → Include linked repositories | `hackly:scan example.com --include-repos` |
| Repo only | Scan now (toggle off) | `hackly:repo-scan owner/repo` |
| Repo + linked targets | Scan now → Include linked targets | `hackly:repo-scan owner/repo --include-targets` |

### Nightly schedule

`hackly:nightly` runs daily (default **02:30**, timezone from config):

1. Every **active** repository with **Nightly** enabled → repo scan + **deep** scan of linked verified Targets  
2. Every **active verified Target with no linked repository** → **deep** scan  

```bash
php artisan hackly:nightly
# optional overrides:
php artisan hackly:nightly --repo-profile=deep --target-profile=deep
```

Ensure the scheduler is running in production:

```bash
* * * * * cd /path/to/hackly && php artisan schedule:run >> /dev/null 2>&1
```

### Commands

| Command | Purpose |
|---------|---------|
| `hackly:check-binaries` | Verify scanner binaries (target + repo tools) |
| `hackly:scan {target} --profile=standard` | Target scan; optional `--include-repos` |
| `hackly:repo-scan {owner/repo} --profile=standard` | Repo scan; optional `--include-targets` |
| `hackly:nightly` | Nightly orchestration (repos + standalone targets) |
| `hackly:cleanup-outputs` | Delete old raw scanner outputs |
| `hackly:dispatch-due` | Re-dispatch leftover pending tasks |
| `queue:work` | Process scan jobs |
| `schedule:work` / cron `schedule:run` | Nightly + hourly cleanup |

---

## Configuration

Primary config: [`config/hackly.php`](config/hackly.php) — override via `.env`.

```env
QUEUE_CONNECTION=database
CACHE_STORE=database

# Safety
HACKLY_ALLOWLIST_ONLY=false
HACKLY_ALLOWLIST=example.com,203.0.113.10
HACKLY_ALLOW_PRIVATE_TARGETS=true

# Soft rate limits
HACKLY_PER_TARGET_PER_MINUTE=2
HACKLY_GLOBAL_CONCURRENT=5
HACKLY_JITTER_SECONDS=5
HACKLY_TASK_SPACING_SECONDS=10
HACKLY_DEEP_COOLDOWN_HOURS=24
HACKLY_QUIET_HOURS_ENABLED=false

# Job timeout must exceed longest scanner (ZAP ~900s)
HACKLY_JOB_TIMEOUT=960
DB_QUEUE_RETRY_AFTER=1020

# Binary paths (examples)
HACKLY_NMAP=nmap
HACKLY_NUCLEI=nuclei
# Writable HOME for nuclei when the queue user cannot write the app CWD:
# HACKLY_NUCLEI_HOME=/path/to/storage/app/nuclei-home
# macOS:
# HACKLY_ZAP=/Applications/ZAP.app/Contents/Java/zap.sh
HACKLY_ZAP=zap.sh

# GitHub repository scanning
HACKLY_GIT=git
HACKLY_COMPOSER=composer
HACKLY_SEMGREP=semgrep
HACKLY_TRIVY=trivy
HACKLY_GITLEAKS=gitleaks
HACKLY_CHECKOV=checkov
HACKLY_REPO_NIGHTLY_AT=02:30
HACKLY_REPO_NIGHTLY_TZ=UTC
HACKLY_NIGHTLY_REPO_PROFILE=standard
HACKLY_NIGHTLY_TARGET_PROFILE=deep
# HACKLY_SEMGREP_CONFIG=p/php
# HACKLY_OSV_ENABLED=true
# HACKLY_REPO_HIDE_UNREACHABLE=false
# HACKLY_REPO_DROP_NOISE=false
```

**Production tips**

- Set `HACKLY_ALLOW_PRIVATE_TARGETS=false`
- Prefer `HACKLY_ALLOWLIST_ONLY=true` with an explicit allowlist
- Configure real mail for email 2FA (`MAIL_MAILER=…`)
- Change the seeded admin password immediately
- Store GitHub PATs only via the **GitHub tokens** UI (encrypted at rest with `APP_KEY`)
- Run both install scripts on the scan worker host, then `hackly:check-binaries`

---

## Security model

- **Target** scans require **ownership verification** (DNS TXT for domains)
- **Repository** scans require a GitHub token with read access to that repo
- Linking Targets ↔ Repositories is optional; scans remain independently startable
- Optional **allowlist** and private-IP blocking for Targets
- Soft **rate limits**, **jitter**, **task spacing**, and **quiet hours** reduce ban risk and noise
- **Deep** target profile has a cooldown between runs (nightly ignores cooldown)
- Admin accounts support **TOTP** and **email** multi-factor authentication
- Repo workspace clones are removed after finalize when `HACKLY_REPO_CLEANUP=true` (default)

Hackly is an orchestration layer — it does not replace responsible disclosure policies or scoped pentest agreements. Reachability / noise reduction for PHP is heuristic (strong signal for Laravel apps), not a full interprocedural guarantee.

---

## License

MIT
