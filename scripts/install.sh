#!/usr/bin/env bash
#
# Aloha Social installer for a bare-metal checkout.
#
# Runs from any directory, checks the PHP runtime, installs Composer
# dependencies, writes .env, applies migrations and optionally creates the
# first admin account. It never starts a web server, so the same script works
# on a laptop (php -S) and on a server (PHP-FPM + Nginx/Apache).
#
#   scripts/install.sh                     # interactive wizard
#   scripts/install.sh --url=https://social.example \
#       --db-dsn='pgsql:host=127.0.0.1;port=5432;dbname=alohasocial' \
#       --db-user=alohasocial --db-password='…' \
#       --handle=admin --email=admin@example --password='…'
#
# Run with no arguments on a terminal to answer a short questionnaire. Run
# with options (or --non-interactive) for unattended installs. Run with
# --help for every option.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

URL=""
NAME=""
DB_DSN=""
DB_USER=""
DB_PASSWORD=""
DATA_DIR=""
ADMIN_HANDLE=""
ADMIN_EMAIL=""
ADMIN_PASSWORD=""
ADMIN_NAME=""
SKIP_COMPOSER=0
SKIP_MIGRATE=0
NO_ADMIN=0
NON_INTERACTIVE=0
INTERACTIVE=0
FORCE_ENV=0
WITH_SERVICE_FILES=0
WITH_DEV=0
ARGUMENT_COUNT=$#

log() { printf '[aloha] %s\n' "$*"; }
fail() { printf '[aloha] error: %s\n' "$*" >&2; exit 1; }

usage() {
    cat <<'USAGE'
Usage: scripts/install.sh [options]

Options:
  --url=URL                 Public origin, e.g. https://social.example
  --name=NAME               Instance name shown in the UI
  --db-dsn=DSN             sqlite:var/server.sqlite or a pgsql: DSN
  --db-user=USER           PostgreSQL user (ignored for SQLite)
  --db-password=PASSWORD   PostgreSQL password
  --data-dir=DIR           Keep the database, key and blobs outside the
                           checkout (recommended for bare-metal updates)
  --handle=HANDLE          Create this admin account
  --email=EMAIL            Admin email address
  --password=PASSWORD      Admin password (at least 8 characters)
  --display-name=NAME      Admin display name
  --no-admin               Do not create an account
  -i, --interactive        Ask for URL, name, database and admin account
  --non-interactive        Never prompt; missing admin values are skipped
  --skip-composer          Use the existing vendor/ directory
  --skip-migrate           Do not run bin/update.php
  --with-dev               Install dev dependencies (PHPUnit)
  --write-service-files    Write var/install/aloha-worker.service and
                           var/install/aloha-nginx.conf for this checkout
  --force                  Replace an existing .env from .env.example
  -h, --help               Show this help

Without --handle/--email/--password the matching ALOHASOCIAL_ADMIN_*
environment variables are used. The installer is idempotent: running it again
keeps .env, applies only pending migrations and leaves an existing account
untouched.
USAGE
}

for argument in "$@"; do
    case "$argument" in
        --url=*) URL="${argument#--url=}" ;;
        --name=*) NAME="${argument#--name=}" ;;
        --db-dsn=*) DB_DSN="${argument#--db-dsn=}" ;;
        --db-user=*) DB_USER="${argument#--db-user=}" ;;
        --db-password=*) DB_PASSWORD="${argument#--db-password=}" ;;
        --data-dir=*) DATA_DIR="${argument#--data-dir=}" ;;
        --handle=*) ADMIN_HANDLE="${argument#--handle=}" ;;
        --email=*) ADMIN_EMAIL="${argument#--email=}" ;;
        --password=*) ADMIN_PASSWORD="${argument#--password=}" ;;
        --display-name=*) ADMIN_NAME="${argument#--display-name=}" ;;
        --skip-composer) SKIP_COMPOSER=1 ;;
        --skip-migrate) SKIP_MIGRATE=1 ;;
        --no-admin) NO_ADMIN=1 ;;
        -i|--interactive) INTERACTIVE=1 ;;
        --non-interactive) NON_INTERACTIVE=1 ;;
        --force) FORCE_ENV=1 ;;
        --write-service-files) WITH_SERVICE_FILES=1 ;;
        --with-dev) WITH_DEV=1 ;;
        -h|--help) usage; exit 0 ;;
        *) fail "unknown option: $argument (try --help)" ;;
    esac
done

ask() {
    local label="$1"
    local default_value="${2:-}"
    local answer=""
    if [ -n "$default_value" ]; then
        printf '%s [%s]: ' "$label" "$default_value" >&2
    else
        printf '%s: ' "$label" >&2
    fi
    IFS= read -r answer || answer=""
    printf '%s' "${answer:-$default_value}"
}

ask_secret() {
    local label="$1"
    local answer=""
    printf '%s: ' "$label" >&2
    IFS= read -r -s answer || answer=""
    printf '\n' >&2
    printf '%s' "$answer"
}

ask_yes_no() {
    local label="$1"
    local default_value="${2:-n}"
    local answer=""
    local suffix="y/N"
    if [ "$default_value" = "y" ]; then
        suffix="Y/n"
    fi
    printf '%s [%s]: ' "$label" "$suffix" >&2
    IFS= read -r answer || answer=""
    answer="${answer:-$default_value}"
    case "$answer" in
        y|Y|yes|YES|j|J|ja|JA) return 0 ;;
        *) return 1 ;;
    esac
}

interactive_wizard() {
    log "interactive setup (Enter keeps the value in brackets)"

    URL="$(ask 'Public URL' "${URL:-${ALOHASOCIAL_INSTANCE_URL:-http://localhost:3000}}")"
    NAME="$(ask 'Instance name' "${NAME:-${ALOHASOCIAL_INSTANCE_NAME:-Aloha Social}}")"

    printf '\nDatabase\n  1) SQLite (no database server, simplest)\n  2) PostgreSQL (recommended for a real instance)\n' >&2
    db_choice="$(ask 'Database' '1')"
    case "$db_choice" in
        2|p|pg|postgres|postgresql)
            DB_DSN="$(ask 'PostgreSQL DSN' "${DB_DSN:-pgsql:host=127.0.0.1;port=5432;dbname=alohasocial}")"
            DB_USER="$(ask 'PostgreSQL user' "${DB_USER:-${ALOHASOCIAL_DB_USER:-alohasocial}}")"
            if [ -z "$DB_PASSWORD" ]; then
                DB_PASSWORD="$(ask_secret 'PostgreSQL password (hidden)')"
            fi
            ;;
        *)
            DB_DSN="$(ask 'SQLite DSN' "${DB_DSN:-sqlite:var/server.sqlite}")"
            ;;
    esac

    printf '\n' >&2
    if [ "$NO_ADMIN" -eq 0 ] && ask_yes_no 'Create the first admin account now?' 'y'; then
        ADMIN_HANDLE="$(ask 'Admin handle' "$ADMIN_HANDLE")"
        [ -n "$ADMIN_HANDLE" ] || fail "an admin handle is required"
        ADMIN_EMAIL="$(ask 'Admin email' "${ADMIN_EMAIL:-${ADMIN_HANDLE}@example.com}")"
        [ -n "$ADMIN_EMAIL" ] || fail "an admin email address is required"
        ADMIN_NAME="$(ask 'Admin display name' "${ADMIN_NAME:-$ADMIN_HANDLE}")"
        if [ -z "$ADMIN_PASSWORD" ]; then
            ADMIN_PASSWORD="$(ask_secret 'Admin password (at least 8 characters, hidden)')"
            if [ "${#ADMIN_PASSWORD}" -lt 8 ]; then
                fail "the admin password must be at least 8 characters long"
            fi
            confirmation="$(ask_secret 'Repeat the admin password')"
            if [ "$confirmation" != "$ADMIN_PASSWORD" ]; then
                fail "the admin passwords do not match"
            fi
        fi
    elif [ "$NO_ADMIN" -eq 0 ]; then
        NO_ADMIN=1
    fi

    printf '\n' >&2
    printf '  Public URL : %s\n' "$URL" >&2
    printf '  Name       : %s\n' "$NAME" >&2
    printf '  Database   : %s\n' "$DB_DSN" >&2
    if [ "$NO_ADMIN" -eq 0 ] && [ -n "$ADMIN_HANDLE" ]; then
        printf '  Admin      : @%s <%s>\n' "$ADMIN_HANDLE" "$ADMIN_EMAIL" >&2
    else
        printf '  Admin      : none (create one later)\n' >&2
    fi
    printf '\n' >&2

    if ! ask_yes_no 'Continue with this configuration?' 'y'; then
        log "installation aborted"
        exit 0
    fi
}

ADMIN_HANDLE="${ADMIN_HANDLE:-${ALOHASOCIAL_ADMIN_HANDLE:-}}"
ADMIN_EMAIL="${ADMIN_EMAIL:-${ALOHASOCIAL_ADMIN_EMAIL:-}}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-${ALOHASOCIAL_ADMIN_PASSWORD:-}}"
ADMIN_NAME="${ADMIN_NAME:-${ALOHASOCIAL_ADMIN_DISPLAY_NAME:-}}"

if [ "$INTERACTIVE" -eq 1 ] || { [ "$ARGUMENT_COUNT" -eq 0 ] && [ "$NON_INTERACTIVE" -eq 0 ] && [ -t 0 ] && [ -t 1 ]; }; then
    interactive_wizard
fi

cd "$ROOT"

log "checking PHP"
command -v php >/dev/null 2>&1 || fail "php is not installed"
php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' \
    || fail "PHP 8.3 or newer is required (found $(php -r 'echo PHP_VERSION;'))"

missing_extensions="$(php -r '
$required = ["pdo", "gd", "mbstring", "curl", "openssl", "json"];
$missing = [];
foreach ($required as $extension) {
    if (!extension_loaded($extension)) {
        $missing[] = $extension;
    }
}
if (!extension_loaded("pdo_sqlite") && !extension_loaded("pdo_pgsql")) {
    $missing[] = "pdo_sqlite or pdo_pgsql";
}
echo implode(" ", $missing);
')"
if [ -n "$missing_extensions" ]; then
    fail "missing PHP extensions: ${missing_extensions}"
fi

if [ "$SKIP_COMPOSER" -eq 0 ] && [ ! -f vendor/autoload.php ]; then
    command -v composer >/dev/null 2>&1 || fail "composer is not installed"
    log "installing Composer dependencies"
    if [ "$WITH_DEV" -eq 1 ]; then
        composer install --no-interaction --prefer-dist
    else
        composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative
    fi
elif [ ! -f vendor/autoload.php ]; then
    fail "vendor/autoload.php is missing; run composer install first"
fi

if [ ! -f .env ] || [ "$FORCE_ENV" -eq 1 ]; then
    log "writing .env from .env.example"
    cp .env.example .env
fi

set_env() {
    local key="$1"
    local value="$2"
    php -r '
        $file = $argv[1];
        $key = $argv[2];
        $value = $argv[3];
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            fwrite(STDERR, "value for {$key} contains a line break\n");
            exit(1);
        }
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
        $found = false;
        foreach ($lines as $index => $line) {
            if (str_starts_with(trim($line), $key . "=")) {
                $lines[$index] = $key . "=" . $value;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $lines[] = $key . "=" . $value;
        }
        file_put_contents($file, implode("\n", $lines) . "\n");
    ' .env "$key" "$value"
}

if [ -n "$URL" ]; then
    URL="${URL%/}"
    case "$URL" in
        http://*|https://*) ;;
        *) fail "--url must start with http:// or https://" ;;
    esac
    set_env ALOHASOCIAL_INSTANCE_URL "$URL"
fi

if [ -n "$NAME" ]; then
    set_env ALOHASOCIAL_INSTANCE_NAME "$NAME"
fi

if [ -n "$DB_DSN" ]; then
    set_env ALOHASOCIAL_DB_DSN "$DB_DSN"
fi
if [ -n "$DB_USER" ]; then
    set_env ALOHASOCIAL_DB_USER "$DB_USER"
fi
if [ -n "$DB_PASSWORD" ]; then
    set_env ALOHASOCIAL_DB_PASSWORD "$DB_PASSWORD"
fi
if [ -n "$DATA_DIR" ]; then
    set_env ALOHASOCIAL_VAR_DIRECTORY "$DATA_DIR"
    # Keep every writable runtime path outside the checkout. Setting only
    # the var directory would leave media and mail in the release directory,
    # where a later update could replace them.
    set_env ALOHASOCIAL_BLOB_DIRECTORY "$DATA_DIR/blobs"
    set_env ALOHASOCIAL_SECRET_KEY_FILE "$DATA_DIR/secret.key"
    set_env ALOHASOCIAL_MAIL_LOG_DIR "$DATA_DIR/mail"
fi

# .env holds the database password and SMTP credentials.
chmod 0600 .env 2>/dev/null || true

mkdir -p var/blobs var/mail
if [ -n "$DATA_DIR" ]; then
    mkdir -p "$DATA_DIR/blobs" "$DATA_DIR/mail"
    chmod 0770 "$DATA_DIR" "$DATA_DIR/blobs" "$DATA_DIR/mail" 2>/dev/null || true
fi
chmod 0770 var var/blobs var/mail 2>/dev/null || true

# Resolve the configured DSN through the application's own config loader, so
# relative SQLite paths behave exactly as they do at runtime.
dsn="$(php -r '
    require "vendor/autoload.php";
    try {
        echo AlohaSocial\Server\Config\HostConfig::load(getcwd())->databaseDsn;
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        exit(1);
    }
')"

case "$dsn" in
    sqlite:*)
        sqlite_path="${dsn#sqlite:}"
        if [ "$sqlite_path" != ":memory:" ] && [ -n "$sqlite_path" ]; then
            case "$sqlite_path" in
                /*) ;;
                *) sqlite_path="$ROOT/$sqlite_path" ;;
            esac
            mkdir -p "$(dirname "$sqlite_path")"
            chmod 0770 "$(dirname "$sqlite_path")" 2>/dev/null || true
        fi
        ;;
    pgsql:*)
        php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);' \
            || fail "the configured DSN is PostgreSQL but pdo_pgsql is not installed"
        ;;
    *)
        fail "unsupported database DSN: ${dsn}"
        ;;
esac

if [ "$SKIP_MIGRATE" -eq 0 ]; then
    log "applying database migrations (with backup and account check)"
    php bin/update.php
else
    log "skipping migrations (--skip-migrate)"
fi

admin_created=0
if [ "$NO_ADMIN" -eq 0 ] && [ -n "$ADMIN_HANDLE" ]; then
    if [ -z "$ADMIN_EMAIL" ]; then
        fail "--email is required when --handle is set"
    fi

    if [ -z "$ADMIN_PASSWORD" ]; then
        if [ "$NON_INTERACTIVE" -eq 1 ] || [ ! -t 0 ]; then
            fail "--password is required in non-interactive mode"
        fi
        printf 'Password for %s: ' "$ADMIN_HANDLE" >&2
        IFS= read -r -s ADMIN_PASSWORD
        printf '\n' >&2
    fi

    log "creating admin account @${ADMIN_HANDLE} (idempotent)"
    printf '%s\n' "$ADMIN_PASSWORD" | php bin/create-account.php \
        "$ADMIN_HANDLE" \
        "$ADMIN_EMAIL" \
        --admin \
        --if-not-exists \
        --password-stdin \
        --display-name="${ADMIN_NAME:-$ADMIN_HANDLE}"
    admin_created=1
elif [ "$NO_ADMIN" -eq 0 ] && [ "$NON_INTERACTIVE" -eq 0 ]; then
    log "no admin account requested; create one later with:"
    log "  php bin/create-account.php <handle> <email> --admin"
fi

if [ "$WITH_SERVICE_FILES" -eq 1 ]; then
    service_dir="var/install"
    mkdir -p "$service_dir"
    instance_url="$(php -r '
        require "vendor/autoload.php";
        try {
            echo AlohaSocial\Server\Config\HostConfig::load(getcwd())->instanceUrl;
        } catch (Throwable $exception) {
            echo "http://localhost:3000";
        }
    ')"
    host="${instance_url#*://}"
    host="${host%%/*}"

    cat > "$service_dir/aloha-worker.service" <<EOF
[Unit]
Description=Aloha Social worker
After=network.target

[Service]
Type=simple
WorkingDirectory=${ROOT}
ExecStart=$(command -v php) ${ROOT}/bin/worker.php
Restart=always
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF

    cat > "$service_dir/aloha-nginx.conf" <<EOF
server {
    listen 80;
    server_name ${host};

    root ${ROOT}/public;
    index index.php;
    client_max_body_size 6m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \\.php\$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }

    location ~ /\\.(?!well-known).* {
        deny all;
    }
}
EOF

    log "wrote service templates to ${service_dir}/"
fi

cat <<EOF

Aloha Social is installed.

  Instance URL : $(php -r 'require "vendor/autoload.php"; try { echo AlohaSocial\Server\Config\HostConfig::load(getcwd())->instanceUrl; } catch (Throwable $e) { echo "-"; }')
  Database     : ${dsn}
  Web root     : ${ROOT}/public

Next steps:
  php -S localhost:3000 -t public public/index.php   # local development
  php bin/worker.php                                 # federation worker

Open /login to sign in and /app for the social interface. For a production
deployment see docs/installation.md, or use the Docker image described in
docs/docker.md.

Data lives in: $(php -r 'require "vendor/autoload.php"; try { echo AlohaSocial\Server\Config\HostConfig::load(getcwd())->varDirectory; } catch (Throwable $e) { echo "var"; }')
Keep this directory with the database and secret key across updates.
EOF

if [ "$admin_created" -eq 1 ]; then
    log "admin login: @${ADMIN_HANDLE} / ${ADMIN_EMAIL}"
fi
