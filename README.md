# Hackly

**Authorized attack-surface & application security scanning.**

Hackly helps you inventory targets you own, verify ownership, run soft rate-limited scans, and review findings — all from a Filament admin panel.

Built with **Laravel 13** and **Filament 5**.

> **Legal notice** — Only scan targets you are authorized to assess. Unauthorized scanning may be illegal. DNS ownership verification is required before a scan can start.

---

## What it does

| Area | Details |
|------|---------|
| **Targets** | Domains / IPs with DNS TXT ownership verification |
| **Scans** | Profiles `quick`, `standard`, `deep` — jobs dispatch immediately |
| **Findings** | Normalized LOW / MEDIUM / HIGH, ordered by severity |
| **Reports** | PDF export per scan |
| **Safety** | Soft rate limits, jitter, quiet hours, deep-scan cooldown, optional allowlist |
| **Admin** | Dashboard metrics, profile page, TOTP + email 2FA |

### Scanners

| Task | Tool | Profiles |
|------|------|----------|
| DNS / WHOIS | `dig`, `whois` | quick · standard · deep |
| Port scan | `nmap` | quick · standard · deep |
| Tech fingerprint | soft HTTP (PHP/Laravel headers & cookies) | quick · standard · deep |
| Subdomain enum | soft wordlist + CT logs | standard · deep |
| Path discovery | soft wordlist / Nuclei (incl. Laravel exposures) | standard · deep |
| Vulnerability templates | `nuclei` (+ laravel/php/dotenv tags) | standard · deep |
| DAST baseline | OWASP ZAP | deep |

---

## Architecture

```
Target (verified)
   └─ Scan
        └─ ScanTasks  →  RunScanTaskJob  →  Findings
```

Jobs use the **database queue** (no Horizon). A queue worker must be running for scans to execute.

---

## Requirements

- PHP **8.3+**
- Composer
- SQLite *(default)* or MySQL / PostgreSQL
- Node.js *(optional — Vite assets; Filament ships published assets)*

### Scanner binaries *(optional but recommended)*

| Binary | Used for |
|--------|----------|
| `dig`, `whois` | DNS / WHOIS |
| `nmap` | Ports |
| `nuclei` | Templates |
| OWASP ZAP (`zap.sh`) | Deep DAST |

**Ubuntu 24.04 / 26.04+** — install everything with:

```bash
sudo bash scripts/install-scanner-binaries-ubuntu.sh
```

Then verify:

```bash
php artisan hackly:check-binaries
```

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
php artisan schedule:work       # optional — cleanup only
```

---

## Usage

### From the admin UI

1. Open **Targets** → create a domain or IP
2. **DNS token** → publish the TXT record at your DNS provider
3. **Verify DNS** — must succeed before scanning
4. **Start scan** — pick a profile; jobs are dispatched immediately
5. Watch **Scans** for live progress, open a scan for findings (HIGH → LOW), export the **PDF**
6. On a target’s detail page, browse the related **Scans** table

### From the CLI

```bash
php artisan hackly:scan example.com --profile=standard
```

### Commands

| Command | Purpose |
|---------|---------|
| `hackly:check-binaries` | Verify scanner binaries |
| `hackly:scan {target} --profile=standard` | Create scan & dispatch jobs |
| `hackly:cleanup-outputs` | Delete old raw scanner outputs |
| `hackly:dispatch-due` | Re-dispatch leftover pending tasks |
| `queue:work` | Process scan jobs |

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
# Optional owner for `php artisan migrate` chown (default: autodetect www-data/nginx/…):
# HACKLY_STORAGE_OWNER=www-data
# macOS:
# HACKLY_ZAP=/Applications/ZAP.app/Contents/Java/zap.sh
HACKLY_ZAP=zap.sh
```

**Production tips**

- Set `HACKLY_ALLOW_PRIVATE_TARGETS=false`
- Prefer `HACKLY_ALLOWLIST_ONLY=true` with an explicit allowlist
- Configure real mail for email 2FA (`MAIL_MAILER=…`)
- Change the seeded admin password immediately

---

## Security model

- Scans require **ownership verification** (DNS TXT for domains)
- Optional **allowlist** and private-IP blocking
- Soft **rate limits**, **jitter**, **task spacing**, and **quiet hours** reduce ban risk and noise
- **Deep** profile has a cooldown between runs on the same target
- Admin accounts support **TOTP** and **email** multi-factor authentication

Hackly is an orchestration layer — it does not replace responsible disclosure policies or scoped pentest agreements.

---

## License

MIT
