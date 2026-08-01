#!/usr/bin/env bash
# Non-interactive installer for Hackly GitHub repo scanners on Ubuntu 24.04/26.x
# Usage: sudo bash scripts/install-repo-scanners.sh
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: run as root (sudo bash scripts/install-repo-scanners.sh)" >&2
  exit 1
fi

ARCH="$(uname -m)"
case "${ARCH}" in
  x86_64|amd64) ARCH_DEB="amd64"; ARCH_TRIVY="64bit"; ARCH_GITLEAKS="x64" ;;
  aarch64|arm64) ARCH_DEB="arm64"; ARCH_TRIVY="ARM64"; ARCH_GITLEAKS="arm64" ;;
  *)
    echo "ERROR: unsupported architecture: ${ARCH}" >&2
    exit 1
    ;;
esac

echo "==> Updating apt indexes"
apt-get update -y

echo "==> Installing base packages"
apt-get install -y --no-install-recommends \
  ca-certificates \
  curl \
  wget \
  gnupg \
  git \
  unzip \
  jq \
  python3 \
  python3-pip \
  python3-venv \
  pipx \
  apt-transport-https

# Ensure pipx path for root and typical deploy users
pipx ensurepath >/dev/null 2>&1 || true
export PATH="${PATH}:/root/.local/bin:/usr/local/bin"

install_semgrep() {
  echo "==> Installing Semgrep"
  if command -v semgrep >/dev/null 2>&1; then
    echo "    semgrep already present: $(command -v semgrep)"
    return
  fi
  pipx install --force semgrep
  ln -sfn /root/.local/bin/semgrep /usr/local/bin/semgrep
}

install_checkov() {
  echo "==> Installing Checkov"
  if command -v checkov >/dev/null 2>&1; then
    echo "    checkov already present: $(command -v checkov)"
    return
  fi
  pipx install --force checkov
  ln -sfn /root/.local/bin/checkov /usr/local/bin/checkov
}

install_trivy() {
  echo "==> Installing Trivy"
  if command -v trivy >/dev/null 2>&1; then
    echo "    trivy already present: $(command -v trivy)"
    return
  fi

  install -m 0755 -d /usr/share/keyrings
  curl -fsSL https://aquasecurity.github.io/trivy-repo/deb/public.key \
    | gpg --dearmor -o /usr/share/keyrings/trivy.gpg
  echo "deb [signed-by=/usr/share/keyrings/trivy.gpg] https://aquasecurity.github.io/trivy-repo/deb generic main" \
    > /etc/apt/sources.list.d/trivy.list
  apt-get update -y
  apt-get install -y trivy || {
    echo "    apt install failed — falling back to GitHub release"
    TMP="$(mktemp -d)"
    TRIVY_VER="$(curl -fsSL https://api.github.com/repos/aquasecurity/trivy/releases/latest | jq -r .tag_name)"
    TRIVY_VER_NUM="${TRIVY_VER#v}"
    curl -fsSL -o "${TMP}/trivy.deb" \
      "https://github.com/aquasecurity/trivy/releases/download/${TRIVY_VER}/trivy_${TRIVY_VER_NUM}_Linux-${ARCH_TRIVY}.deb"
    apt-get install -y "${TMP}/trivy.deb"
    rm -rf "${TMP}"
  }
}

install_gitleaks() {
  echo "==> Installing Gitleaks"
  if command -v gitleaks >/dev/null 2>&1; then
    echo "    gitleaks already present: $(command -v gitleaks)"
    return
  fi

  TMP="$(mktemp -d)"
  GL_VER="$(curl -fsSL https://api.github.com/repos/gitleaks/gitleaks/releases/latest | jq -r .tag_name)"
  curl -fsSL -o "${TMP}/gitleaks.tar.gz" \
    "https://github.com/gitleaks/gitleaks/releases/download/${GL_VER}/gitleaks_${GL_VER#v}_linux_${ARCH_GITLEAKS}.tar.gz"
  tar -xzf "${TMP}/gitleaks.tar.gz" -C "${TMP}"
  install -m 0755 "${TMP}/gitleaks" /usr/local/bin/gitleaks
  rm -rf "${TMP}"
}

ensure_composer() {
  echo "==> Checking Composer"
  if command -v composer >/dev/null 2>&1; then
    echo "    composer already present: $(command -v composer)"
    return
  fi
  echo "    composer not found — installing to /usr/local/bin/composer"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
  rm -f /tmp/composer-setup.php
}

install_semgrep
install_checkov
install_trivy
install_gitleaks
ensure_composer

echo
echo "==> Versions"
for bin in git composer semgrep trivy gitleaks checkov; do
  if command -v "${bin}" >/dev/null 2>&1; then
    printf '  %-10s %s\n' "${bin}" "$(${bin} --version 2>/dev/null | head -n1)"
  else
    printf '  %-10s MISSING\n' "${bin}"
  fi
done

echo
echo "Done. Point Hackly env binaries if needed:"
echo "  HACKLY_SEMGREP=semgrep"
echo "  HACKLY_TRIVY=trivy"
echo "  HACKLY_GITLEAKS=gitleaks"
echo "  HACKLY_CHECKOV=checkov"
echo "  HACKLY_COMPOSER=composer"
echo
echo "Then: php artisan hackly:check-binaries"
echo "Schedule nightly scans via cron: * * * * * php /path/to/artisan schedule:run"
