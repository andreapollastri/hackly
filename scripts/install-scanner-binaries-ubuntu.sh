#!/usr/bin/env bash
# Non-interactive installer for Hackly scanner binaries on Ubuntu 24.04 / 26.04+.
#
# Installs: dig (dnsutils), whois, nmap, nuclei, OWASP ZAP (+ OpenJDK)
#
# Usage (must use bash — not sh/dash):
#   sudo bash scripts/install-scanner-binaries-ubuntu.sh
#
# Do NOT run with: sudo sh …  (Ubuntu's sh is dash and will fail)
# Optional env:
#   HACKLY_BIN_DIR=/usr/local/bin   # nuclei symlink destination
#   HACKLY_ZAP_DIR=/opt/zaproxy     # ZAP install directory
#   SKIP_NUCLEI=1 / SKIP_ZAP=1      # skip individual installs

set -euo pipefail

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

BIN_DIR="${HACKLY_BIN_DIR:-/usr/local/bin}"
ZAP_DIR="${HACKLY_ZAP_DIR:-/opt/zaproxy}"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

log()  { printf '==> %s\n' "$*"; }
warn() { printf '!!  %s\n' "$*" >&2; }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

require_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    die "Run as root: sudo bash $0"
  fi
}

detect_arch() {
  case "$(uname -m)" in
    x86_64|amd64) echo "amd64" ;;
    aarch64|arm64) echo "arm64" ;;
    *) die "Unsupported architecture: $(uname -m)" ;;
  esac
}

check_ubuntu() {
  if [[ ! -f /etc/os-release ]]; then
    warn "Cannot detect OS; continuing anyway."
    return
  fi
  # shellcheck disable=SC1091
  . /etc/os-release
  if [[ "${ID:-}" != "ubuntu" ]]; then
    warn "This script targets Ubuntu (detected: ${ID:-unknown}). Continuing anyway."
  else
    log "Detected Ubuntu ${VERSION_ID:-unknown} (${VERSION_CODENAME:-})"
  fi
}

apt_install() {
  log "Updating apt indexes"
  apt-get update -y

  log "Installing apt packages: dnsutils whois nmap curl ca-certificates unzip openjdk-17-jre-headless jq"
  apt-get install -y --no-install-recommends \
    dnsutils \
    whois \
    nmap \
    curl \
    ca-certificates \
    unzip \
    openjdk-17-jre-headless \
    jq
}

install_nuclei() {
  if [[ "${SKIP_NUCLEI:-0}" == "1" ]]; then
    warn "Skipping nuclei (SKIP_NUCLEI=1)"
    return
  fi

  local arch="$1"
  local api="https://api.github.com/repos/projectdiscovery/nuclei/releases/latest"
  local asset_pattern="linux_${arch}.zip"

  log "Fetching latest nuclei release"
  local json
  json="$(curl -fsSL "$api")"

  local tag url
  tag="$(printf '%s' "$json" | jq -r '.tag_name // empty')"
  url="$(printf '%s' "$json" | jq -r --arg p "$asset_pattern" '.assets[] | select(.name | test($p)) | .browser_download_url' | head -n1)"

  [[ -n "$tag" && -n "$url" ]] || die "Could not resolve nuclei download URL for arch=${arch}"

  log "Installing nuclei ${tag} (${arch})"
  local zip="$TMP_DIR/nuclei.zip"
  curl -fsSL -o "$zip" "$url"
  unzip -qo "$zip" -d "$TMP_DIR/nuclei"
  install -m 0755 "$TMP_DIR/nuclei/nuclei" "$BIN_DIR/nuclei"

  log "Updating nuclei templates"
  # Non-fatal: templates update needs network and may take a bit.
  if ! "$BIN_DIR/nuclei" -update-templates >/dev/null 2>&1; then
    warn "nuclei -update-templates failed (will retry on first scan)"
  fi
}

install_zap() {
  if [[ "${SKIP_ZAP:-0}" == "1" ]]; then
    warn "Skipping ZAP (SKIP_ZAP=1)"
    return
  fi

  local arch="$1"
  # Official Linux package is amd64-only in most releases; arm64 falls back to snap if available.
  local api="https://api.github.com/repos/zaproxy/zaproxy/releases/latest"

  log "Fetching latest OWASP ZAP release"
  local json
  json="$(curl -fsSL "$api")"

  local tag url
  tag="$(printf '%s' "$json" | jq -r '.tag_name // empty')"
  url="$(printf '%s' "$json" | jq -r '.assets[] | select(.name | test("Linux\\.tar\\.gz$")) | .browser_download_url' | head -n1)"

  if [[ -z "$url" || "$arch" == "arm64" ]]; then
    if command -v snap >/dev/null 2>&1; then
      log "Installing ZAP via snap (classic) — preferred for arch=${arch} or missing Linux tarball"
      snap install zaproxy --classic
      # snap ships zap.sh on PATH via /snap/bin
      if [[ -x /snap/bin/zap.sh ]]; then
        ln -sfn /snap/bin/zap.sh "$BIN_DIR/zap.sh"
      elif [[ -x /snap/zaproxy/current/zap.sh ]]; then
        ln -sfn /snap/zaproxy/current/zap.sh "$BIN_DIR/zap.sh"
      fi
      return
    fi
    if [[ -z "$url" ]]; then
      die "Could not resolve ZAP Linux tarball URL and snap is unavailable"
    fi
    warn "arm64: snap unavailable; attempting official Linux tarball (may be amd64-only)"
  fi

  [[ -n "$tag" && -n "$url" ]] || die "Could not resolve ZAP download URL"

  log "Installing OWASP ZAP ${tag} into ${ZAP_DIR}"
  local tarball="$TMP_DIR/zap.tar.gz"
  curl -fsSL -o "$tarball" "$url"

  rm -rf "$ZAP_DIR"
  mkdir -p "$ZAP_DIR"
  tar -xzf "$tarball" -C "$ZAP_DIR" --strip-components=1

  if [[ ! -x "$ZAP_DIR/zap.sh" ]]; then
    die "zap.sh not found after extract in ${ZAP_DIR}"
  fi

  ln -sfn "$ZAP_DIR/zap.sh" "$BIN_DIR/zap.sh"
  # Convenience alias used by some docs
  ln -sfn "$ZAP_DIR/zap.sh" "$BIN_DIR/zaproxy"
}

print_versions() {
  log "Installed versions"
  printf '  dig:    %s\n' "$(dig -v 2>&1 | head -n1 || true)"
  printf '  whois:  %s\n' "$(whois -V 2>&1 | head -n1 || true)"
  printf '  nmap:   %s\n' "$(nmap --version 2>&1 | head -n1 || true)"
  printf '  nuclei: %s\n' "$(nuclei -version 2>&1 | head -n1 || true)"
  printf '  java:   %s\n' "$(java -version 2>&1 | head -n1 || true)"
  if command -v zap.sh >/dev/null 2>&1; then
    printf '  zap:    %s\n' "$(command -v zap.sh)"
  else
    printf '  zap:    NOT FOUND\n'
  fi
}

print_env_hint() {
  cat <<EOF

Add to your Hackly .env if binaries are not on PATH:

  HACKLY_NMAP=nmap
  HACKLY_DIG=dig
  HACKLY_WHOIS=whois
  HACKLY_NUCLEI=${BIN_DIR}/nuclei
  HACKLY_ZAP=${BIN_DIR}/zap.sh

Then verify:

  php artisan hackly:check-binaries

EOF
}

main() {
  require_root
  check_ubuntu

  local arch
  arch="$(detect_arch)"
  log "Architecture: ${arch}"

  mkdir -p "$BIN_DIR"

  apt_install
  install_nuclei "$arch"
  install_zap "$arch"
  print_versions
  print_env_hint

  log "Done."
}

main "$@"
