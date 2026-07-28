# Hackly

Authorized attack-surface & application security scanning platform built with **Laravel 13** and **Filament 5**.

Hackly orchestrates rate-limited scan tasks against **targets you own or are explicitly authorized to test**, invoking system binaries (`nmap`, `dig`, `whois`, `nuclei`, OWASP ZAP) and normalizing results into findings.

> **Legal notice:** Only scan targets you are authorized to assess. Unauthorized scanning may be illegal. DNS ownership verification + an authorization note are required before a scan can start.

## Features

- **Targets** inventory (domains / IPs) with DNS TXT ownership verification
- Findings nested under each target
- Scan profiles: `quick`, `standard`, `deep`
- Jobs dispatched **immediately** on scan start (database queue — no Horizon)
- Live progress bar on Scans (auto-refresh)
- Soft rate limits, jitter, quiet hours, deep-scan cooldown
- Scanners: DNS/WHOIS, nmap, soft subdomain enum, soft path discovery, Nuclei, ZAP baseline
- Filament top navigation admin UI

## Requirements

- PHP 8.3+
- Composer
- SQLite or MySQL/PostgreSQL
- Optional binaries: `nmap`, `dig`, `whois`, `nuclei`, OWASP ZAP

## Quick start

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan hackly:check-binaries
```

Default admin (from seeder):

- URL: `/admin`
- Email: `admin@hackly.test`
- Password: `password`

Run app + queue worker:

```bash
# Terminal 1 — app
php artisan serve

# Terminal 2 — queue worker (required for scans)
php artisan queue:work --tries=1 --timeout=960

# Optional — scheduler (cleanup only)
php artisan schedule:work
```

Or use `composer run dev` (serve + queue + logs + vite).

## Usage

### Verify a domain target, then scan

1. Open `/admin` → **Targets**
2. Create a target with an authorization note
3. Click **DNS token** → publish the TXT record on the domain
4. Click **Verify DNS** (must succeed before scanning)
5. Click **Start scan** → jobs are dispatched immediately
6. Watch **Scans** for the live progress bar; open a target to see its **Findings**

### CLI

```bash
php artisan hackly:scan example.com --profile=standard
```

### Useful commands

| Command | Purpose |
|---------|---------|
| `php artisan hackly:check-binaries` | Verify nmap/dig/nuclei/zap availability |
| `php artisan hackly:scan {target} --profile=standard` | Create scan and dispatch jobs |
| `php artisan hackly:cleanup-outputs` | Delete old raw outputs |
| `php artisan queue:work` | Process scan jobs |

## Configuration

```env
QUEUE_CONNECTION=database
CACHE_STORE=database
HACKLY_ALLOWLIST_ONLY=false
HACKLY_ALLOWLIST=example.com,203.0.113.10
HACKLY_ALLOW_PRIVATE_TARGETS=true
```

In production, set `HACKLY_ALLOW_PRIVATE_TARGETS=false` and prefer `HACKLY_ALLOWLIST_ONLY=true`.

## Architecture

```
Target (DNS verified) → Scan → ScanTasks → RunScanTaskJob (queued immediately) → Findings
```

## License

MIT
