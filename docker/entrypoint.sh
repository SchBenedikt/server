#!/bin/sh
#
# Container entrypoint.
#
# Responsibilities, in order:
#   1. create the writable runtime directories,
#   2. wait for the database and apply pending migrations through the
#      guarded updater (backup plus account-count verification),
#   3. optionally bootstrap the first admin account on an empty instance,
#   4. hand over to the command from docker-compose (Apache or the worker).
#
# Every step is idempotent, so restarting a container is always safe.

set -eu

cd /var/www/html

mkdir -p var/blobs var/mail

# Apache runs its workers as www-data. A fresh named volume is owned by root,
# so hand it to the web user before PHP writes the secret key, SQLite file or
# uploaded blobs. The `|| true` covers read-only development bind mounts.
fix_permissions() {
    chown -R www-data:www-data var 2>/dev/null || true
    chmod 0770 var var/blobs var/mail 2>/dev/null || true
}

fix_permissions

log() {
    printf '[aloha] %s\n' "$*" >&2
}

update_args="--quiet"
case "${ALOHASOCIAL_DB_DSN:-}" in
    pgsql:*) update_args="$update_args --allow-unbacked" ;;
esac

wait_for_database() {
    attempt=1
    max_attempts="${ALOHASOCIAL_DB_WAIT_ATTEMPTS:-60}"

    while :; do
        # shellcheck disable=SC2086
        if php bin/update.php $update_args; then
            return 0
        fi

        if [ "$attempt" -ge "$max_attempts" ]; then
            log "database did not become ready after ${max_attempts} attempts"
            return 1
        fi

        log "database not ready (attempt ${attempt}/${max_attempts}); retrying in 2s"
        attempt=$((attempt + 1))
        sleep 2
    done
}

# Only the HTTP process owns migrations. The worker waits for the app's
# healthcheck in docker-compose, so two containers never migrate concurrently.
# The updater backs up the database before applying a pending migration and
# refuses to continue when the account count drops.
if [ "${1:-}" = "apache2-foreground" ]; then
    wait_for_database

    if [ -n "${ALOHASOCIAL_ADMIN_HANDLE:-}" ] && [ -n "${ALOHASOCIAL_ADMIN_PASSWORD:-}" ]; then
        admin_email="${ALOHASOCIAL_ADMIN_EMAIL:-${ALOHASOCIAL_ADMIN_HANDLE}@example.invalid}"
        admin_name="${ALOHASOCIAL_ADMIN_DISPLAY_NAME:-${ALOHASOCIAL_ADMIN_HANDLE}}"

        log "ensuring admin account @${ALOHASOCIAL_ADMIN_HANDLE} exists"
        printf '%s\n' "${ALOHASOCIAL_ADMIN_PASSWORD}" \
            | php bin/create-account.php \
                "${ALOHASOCIAL_ADMIN_HANDLE}" \
                "${admin_email}" \
                --admin \
                --if-not-exists \
                --password-stdin \
                --display-name="${admin_name}"
    fi

    # The migration bootstrap runs as root in the container. Files it created
    # (SQLite database, WAL files, var/secret.key) must belong to the Apache
    # user before the server starts, even though the first chown above already
    # covered an existing volume.
    fix_permissions

    # Apache's master process starts as root and drops its workers to
    # www-data itself.
    exec "$@"
fi

# The worker and any `docker compose run` command must not write database or
# blob files as root, or a later web request cannot open the SQLite WAL.
if command -v setpriv >/dev/null 2>&1; then
    exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"
fi

exec runuser -u www-data -- "$@"
