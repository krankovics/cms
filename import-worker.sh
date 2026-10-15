#!/bin/sh
# comesy CLI importer: use a supported CLI PHP, never the host's legacy default.
# Usage: /bin/sh /path/to/import-worker.sh --max=5
# Diagnostics only: /bin/sh /path/to/import-worker.sh --check
set -eu

script_dir=$(CDPATH= cd "$(dirname "$0")" && pwd)
[ -f "$script_dir/import-worker.php" ] || {
  echo "comesy: import-worker.php was not found next to the launcher." >&2
  exit 1
}

# Prefer explicitly configured DirectAdmin CLI versions. Hosting installations
# may expose the same interpreter as php83, php8.3 or a full binary path.
for candidate in ${COMESY_PHP_BIN:-} \
  /usr/local/php83/bin/php /usr/local/php84/bin/php \
  /usr/local/php82/bin/php /usr/local/php81/bin/php \
  /opt/alt/php83/usr/bin/php /opt/alt/php84/usr/bin/php \
  /opt/alt/php82/usr/bin/php /opt/alt/php81/usr/bin/php \
  php8.3 php83 php8.4 php84 php8.2 php82 php8.1 php81 \
  /usr/local/bin/php /usr/bin/php php
do
  [ -n "$candidate" ] || continue
  if ! command -v "$candidate" >/dev/null 2>&1; then
    continue
  fi
  version=$("$candidate" -r 'echo PHP_VERSION_ID;' 2>/dev/null) || continue
  case "$version" in
    ''|*[!0-9]*) continue ;;
  esac
  [ "$version" -ge 80100 ] || continue
  if ! "$candidate" -r '
    foreach (array("pdo_mysql", "curl", "dom", "mbstring", "iconv") as $extension) {
      if (!extension_loaded($extension)) exit(1);
    }' >/dev/null 2>&1; then
    continue
  fi

  if [ "${1:-}" = "--check" ]; then
    "$candidate" -r 'echo "comesy PHP CLI: ", PHP_VERSION, " [", PHP_BINARY, "]", PHP_EOL;'
    exit 0
  fi
  exec "$candidate" "$script_dir/import-worker.php" "$@"
done

echo "comesy: no compatible PHP CLI found (PHP >= 8.1 with pdo_mysql, curl, dom, mbstring, iconv). In DirectAdmin, select a PHP 8.2/8.3 CLI binary or set COMESY_PHP_BIN." >&2
exit 1
