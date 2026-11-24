#!/usr/bin/env bash
set -euo pipefail

# Upgrade a real SQLite installation from the last supported server release.
# The caller must have installed the current checkout's locked dependencies.

root="$(cd "$(dirname "$0")/.." && pwd)"
previous_tag="${1:-0.12.0}"
tmp="$(mktemp -d)"
cleanup() {
	rm -rf "$tmp"
}
trap cleanup EXIT

if ! git -C "$root" show-ref --verify --quiet "refs/tags/$previous_tag"; then
	git -C "$root" fetch --quiet --depth=1 origin "refs/tags/$previous_tag:refs/tags/$previous_tag"
fi

old="$tmp/previous"
mkdir -p "$old" "$tmp/var"
git -C "$root" archive "$previous_tag" | tar -xf - -C "$old"

composer install --working-dir="$old" --no-dev --no-interaction --no-progress --prefer-dist

export ALOHASOCIAL_DB_DSN="sqlite:$tmp/server.sqlite"
export ALOHASOCIAL_VAR_DIRECTORY="$tmp/var"
export ALOHASOCIAL_SECRET_KEY_FILE="$tmp/var/secret.key"
export ALOHASOCIAL_INSTANCE_URL='http://localhost:3000'
export ALOHASOCIAL_DEBUG=true

php "$old/bin/update.php"
php "$old/bin/create-account.php" upgrade-user upgrade@example.test --password=temporary-password
post_id="$(php "$root/tools/upgrade-smoke-probe.php" "$old" seed)"

# Run the new server and Core against the database and account made by the old
# released packages. update.php creates its normal safety backup before DDL.
php "$root/bin/update.php"
php "$root/tools/upgrade-smoke-probe.php" "$root" verify "$post_id"

printf 'Upgrade smoke passed: server %s -> current; account and post preserved.\n' "$previous_tag"
