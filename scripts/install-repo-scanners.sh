#!/usr/bin/env bash
# Non-interactive installer for Hackly GitHub repo scanners on Ubuntu 24.04/26.x
# Usage: sudo bash scripts/install-repo-scanners.sh
#
# pipx tools are installed system-wide under /opt/pipx with shims in /usr/local/bin
# so PHP/queue users (www-data, cipi, …) can execute them — NOT only root's ~/.local/bin.
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
export PIPX_HOME=/opt/pipx
export PIPX_BIN_DIR=/usr/local/bin

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: run as root (sudo bash scripts/install-repo-scanners.sh)" >&2
  exit 1
fi

ARCH="$(uname -m)"
case "${ARCH}" in
  x86_64|amd64) ARCH_TRIVY="64bit"; ARCH_GITLEAKS="x64" ;;
  aarch64|arm64) ARCH_TRIVY="ARM64"; ARCH_GITLEAKS="arm64" ;;
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

mkdir -p "${PIPX_HOME}" "${PIPX_BIN_DIR}"
export PATH="${PIPX_BIN_DIR}:/usr/bin:/bin:${PATH}"

install_pipx_tool() {
  local pkg="$1"
  local bin="${2:-$1}"

  echo "==> Installing ${pkg} (system-wide → ${PIPX_BIN_DIR}/${bin})"

  # Drop broken root-only symlink that points into /root/.local/bin
  if [[ -L "${PIPX_BIN_DIR}/${bin}" ]]; then
    local target
    target="$(readlink -f "${PIPX_BIN_DIR}/${bin}" 2>/dev/null || true)"
    if [[ "${target}" == /root/* ]]; then
      echo "    removing root-only symlink ${PIPX_BIN_DIR}/${bin} → ${target}"
      rm -f "${PIPX_BIN_DIR}/${bin}"
    fi
  fi

  PIPX_HOME="${PIPX_HOME}" PIPX_BIN_DIR="${PIPX_BIN_DIR}" pipx install --force "${pkg}"

  chmod -R a+rX "${PIPX_HOME}"
  if [[ -e "${PIPX_BIN_DIR}/${bin}" ]]; then
    chmod a+rx "${PIPX_BIN_DIR}/${bin}"
  fi

  if [[ -x "${PIPX_BIN_DIR}/${bin}" ]]; then
    echo "    OK: ${PIPX_BIN_DIR}/${bin}"
  else
    echo "    WARNING: ${PIPX_BIN_DIR}/${bin} missing after install" >&2
  fi
}

install_trivy() {
  echo "==> Installing Trivy"
  if [[ -x /usr/bin/trivy || -x /usr/local/bin/trivy ]]; then
    echo "    trivy already present"
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
  if [[ -x /usr/local/bin/gitleaks || -x /usr/bin/gitleaks ]]; then
    echo "    gitleaks already present"
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
  chmod a+rx /usr/local/bin/composer
}

install_pipx_tool semgrep semgrep
install_pipx_tool checkov checkov
install_trivy
install_gitleaks
ensure_composer

echo
echo "==> World-readable check (non-root simulation)"
for bin in semgrep checkov trivy gitleaks; do
  path="$(command -v "${bin}" 2>/dev/null || true)"
  if [[ -z "${path}" ]]; then
    printf '  %-10s MISSING from PATH\n' "${bin}"
    continue
  fi
  if [[ "${path}" == /root/* ]] || { [[ -L "${path}" ]] && readlink -f "${path}" 2>/dev/null | grep -q '^/root/'; }; then
    printf '  %-10s BAD: %s still under /root (PHP users cannot run this)\n' "${bin}" "${path}"
  else
    printf '  %-10s OK: %s\n' "${bin}" "${path}"
  fi
done

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
echo "Done. Binaries are in ${PIPX_BIN_DIR} (readable by www-data/cipi/queue workers)."
echo "Verify as the app user, e.g.:"
echo "  sudo -u cipi php /home/cipi/path/to/artisan hackly:check-binaries"
echo "  # or: sudo -u www-data …"
