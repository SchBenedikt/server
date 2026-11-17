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
MAIL_TRANSPORT=""
MAIL_FROM=""
SMTP_HOST=""
SMTP_PORT=""
SMTP_USER=""
SMTP_PASSWORD=""
SMTP_ENCRYPTION=""
SKIP_COMPOSER=0
SKIP_MIGRATE=0
NO_ADMIN=0
NON_INTERACTIVE=0
INTERACTIVE=0
FORCE_ENV=0
WITH_SERVICE_FILES=0
WITH_DEV=0
VERIFY=1
ARGUMENT_COUNT=$#

log() { printf '[aloha] %s\n' "$*"; }
warn() { printf '[aloha] warning: %s\n' "$*" >&2; }
fail() { printf '[aloha] error: %s\n' "$*" >&2; exit 1; }

usage() {
    cat <<'USAGE'
Usage: scripts/install.sh [options]

Options:
  --url=URL                 Public origin, e.g. https://social.example
  --name=NAME               Instance name shown in the UI
  --db-dsn=DSN              sqlite:var/server.sqlite or a pgsql: DSN
  --db-user=USER            PostgreSQL user (ignored for SQLite)
  --db-password=PASSWORD    PostgreSQL password
  --data-dir=DIR            Keep the database, key, blobs and mail outside
                            the checkout (recommended for bare-metal updates)
  --handle=HANDLE           Create this admin account
  --email=EMAIL             Admin email address
  --password=PASSWORD       Admin password (at least 8 characters)
  --display-name=NAME       Admin display name
  --no-admin                Do not create an account
  --mail-transport=log|smtp Mail delivery for password resets (default: log)
  --mail-from=EMAIL         Envelope and From address
  --smtp-host=HOST          SMTP server for --mail-transport=smtp
  --smtp-port=PORT          SMTP port (default: 587)
  --smtp-user=USER          SMTP AUTH LOGIN user
  --smtp-password=PASSWORD  SMTP AUTH LOGIN password
  --smtp-encryption=MODE    starttls, tls or none
  -i, --interactive         Ask for URL, name, storage, database, mail and
                            the first admin account
  --non-interactive         Never prompt; missing admin values are skipped
  --skip-composer           Use the existing vendor/ directory
  --skip-migrate            Do not run bin/update.php
  --with-dev                Install dev dependencies (PHPUnit)
  --write-service-files     Write var/install/aloha-worker.service and
                            var/install/aloha-nginx.conf for this checkout
  --force                   Replace an existing .env from .env.example, keep
                            the database/runtime paths and save the previous
                            file as a timestamped backup
  --no-verify               Skip the final bin/doctor.php check
  -h, --help                Show this help

Without --handle/--email/--password the matching ALOHASOCIAL_ADMIN_*
environment variables are used. The installer is idempotent: running it again
keeps an existing .env, applies only pending migrations and leaves an existing
account untouched. Changing --data-dir on an existing SQLite installation
copies the database, key, blobs and mail safely before switching the paths.
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
        --mail-transport=*) MAIL_TRANSPORT="${argument#--mail-transport=}" ;;
        --mail-from=*) MAIL_FROM="${argument#--mail-from=}" ;;
        --smtp-host=*) SMTP_HOST="${argument#--smtp-host=}" ;;
        --smtp-port=*) SMTP_PORT="${argument#--smtp-port=}" ;;
        --smtp-user=*) SMTP_USER="${argument#--smtp-user=}" ;;
        --smtp-password=*) SMTP_PASSWORD="${argument#--smtp-password=}" ;;
        --smtp-encryption=*) SMTP_ENCRYPTION="${argument#--smtp-encryption=}" ;;
        --skip-composer) SKIP_COMPOSER=1 ;;
        --skip-migrate) SKIP_MIGRATE=1 ;;
        --no-admin) NO_ADMIN=1 ;;
        -i|--interactive) INTERACTIVE=1 ;;
        --non-interactive) NON_INTERACTIVE=1 ;;
        --force) FORCE_ENV=1 ;;
        --write-service-files) WITH_SERVICE_FILES=1 ;;
        --with-dev) WITH_DEV=1 ;;
        --no-verify) VERIFY=0 ;;
        -h|--help) usage; exit 0 ;;
        *) fail "unknown option: $argument (try --help)" ;;
    esac
done

cd "$ROOT"

trim() {
    local value="$1"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    printf '%s' "$value"
}

dotenv_get() {
    local key="$1" line value first last
    [ -f .env ] || return 1
    while IFS= read -r line || [ -n "$line" ]; do
        line="${line%$'\r'}"
        line="$(trim "$line")"
        case "$line" in
            ''|'#'*) continue ;;
        esac
        if [[ "$line" == "$key="* ]]; then
            value="${line#*=}"
            value="$(trim "$value")"
            if [ "${#value}" -ge 2 ]; then
                first="${value:0:1}"
                last="${value: -1}"
                if [ "$first" = "$last" ] && { [ "$first" = '"' ] || [ "$first" = "'" ]; }; then
                    value="${value:1:${#value}-2}"
                fi
            fi
            printf '%s' "$value"
            return 0
        fi
    done < .env
    return 1
}

# Real environment variables win over .env, matching HostConfig.
setting() {
    local key="$1"
    local fallback="${2:-}"
    local environment_value="${!key:-}"
    local file_value
    if [ -n "$environment_value" ]; then
        printf '%s' "$environment_value"
        return 0
    fi
    if file_value="$(dotenv_get "$key")"; then
        printf '%s' "$file_value"
        return 0
    fi
    printf '%s' "$fallback"
}

resolve_path() {
    local path="$1"
    case "$path" in
        /*) printf '%s' "$path" ;;
        *) printf '%s/%s' "$ROOT" "$path" ;;
    esac
}

sqlite_path_from_dsn() {
    local dsn="$1" path
    case "$dsn" in
        sqlite:*) path="${dsn#sqlite:}" ;;
        *) return 1 ;;
    esac
    [ "$path" = ':memory:' ] && return 1
    [ -n "$path" ] || return 1
    resolve_path "$path"
}

normalize_url() {
    local url="$1" scheme rest
    url="$(trim "$url")"
    if [[ "$url" =~ ^([A-Za-z][A-Za-z0-9+.-]*)://(.*)$ ]]; then
        scheme="${BASH_REMATCH[1],,}"
        rest="${BASH_REMATCH[2]}"
        url="${scheme}://${rest}"
    fi
    printf '%s' "${url%/}"
}

validate_url() {
    if ! php -r '
        $url = $argv[1];
        $parts = parse_url($url);
        if ($parts === false || !isset($parts["scheme"], $parts["host"])) {
            exit(1);
        }
        if (!in_array(strtolower($parts["scheme"]), ["http", "https"], true)) {
            exit(1);
        }
        if (isset($parts["user"]) || isset($parts["pass"]) || isset($parts["query"]) || isset($parts["fragment"])) {
            exit(1);
        }
        if (isset($parts["path"]) && $parts["path"] !== "" && $parts["path"] !== "/") {
            exit(1);
        }
        if (isset($parts["port"]) && ($parts["port"] < 1 || $parts["port"] > 65535)) {
            exit(1);
        }
        if (preg_match("/\\s/", $url) === 1) {
            exit(1);
        }
    ' "$1"; then
        fail "the instance URL must be an origin such as https://social.example (no path, query or credentials): $1"
    fi
}

validate_email() {
    if ! php -r '
        $email = $argv[1];
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match("/[\\r\\n]/", $email) === 1) {
            exit(1);
        }
    ' "$1"; then
        fail "not a valid email address: $1"
    fi
}

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

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------

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

if [ ! -f .env.example ]; then
    fail ".env.example is missing; run the installer from a complete checkout"
fi
if [ ! -f bin/update.php ] || [ ! -f bin/create-account.php ] || [ ! -f bin/doctor.php ]; then
    fail "the bin/ directory is incomplete; run the installer from a complete checkout"
fi

if [ "$SKIP_COMPOSER" -eq 0 ] && { [ ! -f vendor/autoload.php ] || [ "$WITH_DEV" -eq 1 ]; }; then
    command -v composer >/dev/null 2>&1 || fail "composer is not installed"
fi
if [ "$SKIP_COMPOSER" -eq 1 ] && [ ! -f vendor/autoload.php ]; then
    fail "vendor/autoload.php is missing; run composer install first or drop --skip-composer"
fi

EXISTING_ENV=0
[ -f .env ] && EXISTING_ENV=1

# Defaults shown by the wizard. CLI options already live in the main
# variables, the environment and .env supply the current instance state.
CURRENT_URL="$(setting ALOHASOCIAL_INSTANCE_URL 'http://localhost:3000')"
CURRENT_NAME="$(setting ALOHASOCIAL_INSTANCE_NAME 'Aloha Social')"
CURRENT_VAR="$(setting ALOHASOCIAL_VAR_DIRECTORY 'var')"
CURRENT_BLOB="$(setting ALOHASOCIAL_BLOB_DIRECTORY "${CURRENT_VAR}/blobs")"
CURRENT_KEY="$(setting ALOHASOCIAL_SECRET_KEY_FILE "${CURRENT_VAR}/secret.key")"
CURRENT_MAIL_DIR="$(setting ALOHASOCIAL_MAIL_LOG_DIR "${CURRENT_VAR}/mail")"
CURRENT_DSN="$(setting ALOHASOCIAL_DB_DSN "sqlite:${CURRENT_VAR}/server.sqlite")"
CURRENT_DB_USER="$(setting ALOHASOCIAL_DB_USER '')"
CURRENT_MAIL_TRANSPORT="$(setting ALOHASOCIAL_MAIL_TRANSPORT 'log')"
CURRENT_MAIL_FROM="$(setting ALOHASOCIAL_MAIL_FROM '')"
CURRENT_SMTP_HOST="$(setting ALOHASOCIAL_SMTP_HOST '')"
CURRENT_SMTP_PORT="$(setting ALOHASOCIAL_SMTP_PORT '587')"
CURRENT_SMTP_USER="$(setting ALOHASOCIAL_SMTP_USER '')"
CURRENT_SMTP_ENCRYPTION="$(setting ALOHASOCIAL_SMTP_ENCRYPTION 'starttls')"
CURRENT_SMTP_PASSWORD_SET=0
CURRENT_SMTP_PASSWORD="$(setting ALOHASOCIAL_SMTP_PASSWORD '')"
if [ -n "$CURRENT_SMTP_PASSWORD" ]; then
    CURRENT_SMTP_PASSWORD_SET=1
fi
CURRENT_DB_PASSWORD_SET=0
CURRENT_DB_PASSWORD="$(setting ALOHASOCIAL_DB_PASSWORD '')"
if [ -n "$CURRENT_DB_PASSWORD" ]; then
    CURRENT_DB_PASSWORD_SET=1
fi

interactive_wizard() {
    log "interactive setup (press Enter to keep the value in brackets)"
    if [ "$EXISTING_ENV" -eq 1 ]; then
        log "existing .env detected; its values are the defaults"
    fi

    URL="$(ask 'Public URL' "${URL:-$CURRENT_URL}")"
    URL="$(normalize_url "$URL")"
    validate_url "$URL"

    NAME="$(ask 'Instance name' "${NAME:-$CURRENT_NAME}")"
    [ -n "$NAME" ] || fail "the instance name must not be empty"

    printf '\nStorage\n  An absolute directory outside the checkout survives a replaced\n  release, git clean or new checkout and keeps accounts and media safe.\n' >&2
    DATA_DIR="$(ask 'Data directory' "${DATA_DIR:-$CURRENT_VAR}")"
    DATA_DIR="${DATA_DIR%/}"
    [ -n "$DATA_DIR" ] || fail "the data directory must not be empty"

    printf '\nDatabase\n  1) SQLite (no database server, simplest)\n  2) PostgreSQL (recommended for a real instance)\n' >&2
    effective_dsn="${DB_DSN:-$CURRENT_DSN}"
    default_db_choice=1
    old_engine="sqlite"
    case "$effective_dsn" in
        pgsql:*)
            default_db_choice=2
            old_engine="pgsql"
            ;;
    esac
    db_choice="$(ask 'Database' "$default_db_choice")"

    sqlite_default="sqlite:${DATA_DIR}/server.sqlite"
    case "$effective_dsn" in
        sqlite:*) sqlite_default="$effective_dsn" ;;
    esac
    if [ "$DATA_DIR" != "$CURRENT_VAR" ]; then
        case "$sqlite_default" in
            "sqlite:var/server.sqlite"|"sqlite:${ROOT}/var/server.sqlite"|"sqlite:${CURRENT_VAR}/server.sqlite"|"sqlite:${ROOT}/${CURRENT_VAR}/server.sqlite")
                sqlite_default="sqlite:${DATA_DIR}/server.sqlite"
                ;;
        esac
    fi
    postgres_default="pgsql:host=127.0.0.1;port=5432;dbname=alohasocial"
    case "$effective_dsn" in
        pgsql:*) postgres_default="$effective_dsn" ;;
    esac

    case "$db_choice" in
        2|p|pg|postgres|postgresql)
            DB_DSN="$(ask 'PostgreSQL DSN' "${DB_DSN:-$postgres_default}")"
            DB_USER="$(ask 'PostgreSQL user' "${DB_USER:-${CURRENT_DB_USER:-alohasocial}}")"
            if [ -z "$DB_PASSWORD" ]; then
                if [ "$CURRENT_DB_PASSWORD_SET" -eq 1 ]; then
                    DB_PASSWORD="$(ask_secret 'PostgreSQL password (leave empty to keep the current one)')"
                else
                    DB_PASSWORD="$(ask_secret 'PostgreSQL password (hidden)')"
                fi
            fi
            if [ "$old_engine" = "sqlite" ]; then
                warn "switching from SQLite to PostgreSQL does not copy existing accounts automatically"
            fi
            ;;
        *)
            DB_DSN="$(ask 'SQLite DSN' "$sqlite_default")"
            if [ "$old_engine" = "pgsql" ]; then
                ask_yes_no 'Switch from PostgreSQL to SQLite? Existing accounts are not copied.' 'n' \
                    || fail "installation aborted; keep the PostgreSQL DSN or migrate the data first"
            fi
            ;;
    esac

    printf '\nMail for password resets\n  1) log files in the data directory (development)\n  2) SMTP (recommended for a real instance)\n' >&2
    default_mail_choice=1
    if [ "$MAIL_TRANSPORT" = "smtp" ] || { [ -z "$MAIL_TRANSPORT" ] && [ "$CURRENT_MAIL_TRANSPORT" = "smtp" ]; }; then
        default_mail_choice=2
    fi
    mail_choice="$(ask 'Mail transport' "$default_mail_choice")"
    case "$mail_choice" in
        2|s|smtp)
            MAIL_TRANSPORT="smtp"
            MAIL_FROM="$(ask 'Mail From address' "${MAIL_FROM:-${CURRENT_MAIL_FROM:-no-reply@$(php -r 'echo parse_url($argv[1], PHP_URL_HOST) ?: "localhost";' "$URL")}}")"
            SMTP_HOST="$(ask 'SMTP host' "${SMTP_HOST:-$CURRENT_SMTP_HOST}")"
            SMTP_PORT="$(ask 'SMTP port' "${SMTP_PORT:-$CURRENT_SMTP_PORT}")"
            SMTP_USER="$(ask 'SMTP user (empty for no AUTH)' "${SMTP_USER:-$CURRENT_SMTP_USER}")"
            if [ -z "$SMTP_PASSWORD" ]; then
                if [ "$CURRENT_SMTP_PASSWORD_SET" -eq 1 ]; then
                    SMTP_PASSWORD="$(ask_secret 'SMTP password (leave empty to keep the current one)')"
                else
                    SMTP_PASSWORD="$(ask_secret 'SMTP password (hidden, empty for no AUTH)')"
                fi
            fi
            SMTP_ENCRYPTION="$(ask 'SMTP encryption (starttls, tls or none)' "${SMTP_ENCRYPTION:-$CURRENT_SMTP_ENCRYPTION}")"
            ;;
        *)
            MAIL_TRANSPORT="log"
            ;;
    esac

    printf '\n' >&2
    admin_default="y"
    [ "$EXISTING_ENV" -eq 1 ] && admin_default="n"
    if [ "$NO_ADMIN" -eq 0 ] && [ -z "$ADMIN_HANDLE" ] && ask_yes_no 'Create an admin account now?' "$admin_default"; then
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
    elif [ "$NO_ADMIN" -eq 0 ] && [ -n "$ADMIN_HANDLE" ]; then
        # The handle came from an option; keep it and ask for the remaining
        # details only when they are still missing.
        [ -n "$ADMIN_EMAIL" ] || ADMIN_EMAIL="$(ask 'Admin email' "${ADMIN_HANDLE}@example.com")"
        [ -n "$ADMIN_PASSWORD" ] || ADMIN_PASSWORD="$(ask_secret 'Admin password (at least 8 characters, hidden)')"
    fi

    if [ "$WITH_SERVICE_FILES" -eq 0 ] && ask_yes_no 'Write systemd and Nginx templates for this checkout?' 'n'; then
        WITH_SERVICE_FILES=1
    fi

    printf '\n' >&2
    printf '  Public URL : %s\n' "$URL" >&2
    printf '  Name       : %s\n' "$NAME" >&2
    printf '  Data       : %s\n' "$DATA_DIR" >&2
    printf '  Database   : %s\n' "$DB_DSN" >&2
    printf '  Mail       : %s\n' "$MAIL_TRANSPORT" >&2
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

# --force rewrites .env from the example. Keep the runtime state that makes
# existing accounts findable unless the operator supplied new values.
PRESERVE_BLOB=""
PRESERVE_KEY=""
PRESERVE_MAIL=""
if [ "$FORCE_ENV" -eq 1 ] && [ "$EXISTING_ENV" -eq 1 ]; then
    if [ -z "$DATA_DIR" ]; then
        DATA_DIR="$CURRENT_VAR"
        PRESERVE_BLOB="$CURRENT_BLOB"
        PRESERVE_KEY="$CURRENT_KEY"
        PRESERVE_MAIL="$CURRENT_MAIL_DIR"
    fi
    if [ -z "$DB_DSN" ]; then
        case "$CURRENT_DSN" in
            pgsql:*) DB_DSN="$CURRENT_DSN" ;;
            sqlite:*)
                if [ -z "$DATA_DIR" ] || [ "$DATA_DIR" = "$CURRENT_VAR" ]; then
                    DB_DSN="$CURRENT_DSN"
                fi
                ;;
        esac
    fi
    [ -n "$DB_USER" ] || DB_USER="$CURRENT_DB_USER"
    [ -n "$DB_PASSWORD" ] || DB_PASSWORD="$CURRENT_DB_PASSWORD"
    log "--force keeps the existing database and runtime paths"
fi

# Keeping the same data directory must not silently replace deliberately
# separated blob, key or mail paths with the defaults under that directory.
if [ -n "$DATA_DIR" ] && [ "$(resolve_path "$DATA_DIR")" = "$(resolve_path "$CURRENT_VAR")" ]; then
    if [ "$(resolve_path "$CURRENT_BLOB")" != "$(resolve_path "$DATA_DIR/blobs")" ]; then
        PRESERVE_BLOB="$CURRENT_BLOB"
    fi
    if [ "$(resolve_path "$CURRENT_KEY")" != "$(resolve_path "$DATA_DIR/secret.key")" ]; then
        PRESERVE_KEY="$CURRENT_KEY"
    fi
    if [ "$(resolve_path "$CURRENT_MAIL_DIR")" != "$(resolve_path "$DATA_DIR/mail")" ]; then
        PRESERVE_MAIL="$CURRENT_MAIL_DIR"
    fi
fi

# ---------------------------------------------------------------------------
# Validation before anything is written
# ---------------------------------------------------------------------------

if [ -n "$URL" ]; then
    URL="$(normalize_url "$URL")"
    validate_url "$URL"
fi

if [ -n "$NAME" ] && [[ "$NAME" == *$'\n'* || "$NAME" == *$'\r'* ]]; then
    fail "the instance name must be a single line"
fi

if [ -n "$DATA_DIR" ]; then
    DATA_DIR="${DATA_DIR%/}"
    [ -n "$DATA_DIR" ] || fail "the data directory must not be empty"
    if [[ "$DATA_DIR" == *$'\n'* || "$DATA_DIR" == *$'\r'* ]]; then
        fail "the data directory must be a single line"
    fi
fi

if [ -n "$DB_DSN" ]; then
    case "$DB_DSN" in
        sqlite:*)
            sqlite_dsn_path="${DB_DSN#sqlite:}"
            if [ -z "$sqlite_dsn_path" ] || [ "$sqlite_dsn_path" = ":memory:" ]; then
                fail "the installer needs a file-backed SQLite DSN, not '${DB_DSN}'"
            fi
            php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' \
                || fail "the configured DSN is SQLite but pdo_sqlite is not installed"
            ;;
        pgsql:*)
            php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);' \
                || fail "the configured DSN is PostgreSQL but pdo_pgsql is not installed"
            ;;
        *)
            fail "unsupported database DSN: ${DB_DSN} (use sqlite:… or pgsql:…)"
            ;;
    esac
fi

effective_database_dsn="${DB_DSN:-$CURRENT_DSN}"
case "$effective_database_dsn" in
    sqlite:*)
        effective_sqlite_path="${effective_database_dsn#sqlite:}"
        if [ -z "$effective_sqlite_path" ] || [ "$effective_sqlite_path" = ':memory:' ]; then
            fail "the installer needs a file-backed SQLite DSN, not '${effective_database_dsn}'"
        fi
        php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' \
            || fail "the configured DSN is SQLite but pdo_sqlite is not installed"
        ;;
    pgsql:*)
        php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);' \
            || fail "the configured DSN is PostgreSQL but pdo_pgsql is not installed"
        ;;
    *)
        fail "unsupported database DSN: ${effective_database_dsn} (use sqlite:… or pgsql:…)"
        ;;
esac

if [ -n "$DB_USER" ] && [[ "$DB_USER" == *$'\n'* || "$DB_USER" == *$'\r'* ]]; then
    fail "the database user must be a single line"
fi
if [ -n "$DB_PASSWORD" ] && [[ "$DB_PASSWORD" == *$'\n'* || "$DB_PASSWORD" == *$'\r'* ]]; then
    fail "the database password must be a single line"
fi

if [ -n "$MAIL_TRANSPORT" ]; then
    case "${MAIL_TRANSPORT,,}" in
        log|smtp) MAIL_TRANSPORT="${MAIL_TRANSPORT,,}" ;;
        *) fail "--mail-transport must be log or smtp, got: ${MAIL_TRANSPORT}" ;;
    esac
fi
if [ -n "$SMTP_ENCRYPTION" ]; then
    case "${SMTP_ENCRYPTION,,}" in
        starttls|tls|none) SMTP_ENCRYPTION="${SMTP_ENCRYPTION,,}" ;;
        *) fail "--smtp-encryption must be starttls, tls or none, got: ${SMTP_ENCRYPTION}" ;;
    esac
fi
if [ -n "$SMTP_PORT" ]; then
    if ! php -r 'exit(ctype_digit($argv[1]) && (int) $argv[1] >= 1 && (int) $argv[1] <= 65535 ? 0 : 1);' "$SMTP_PORT"; then
        fail "the SMTP port must be between 1 and 65535: ${SMTP_PORT}"
    fi
fi
if [ -n "$MAIL_FROM" ]; then
    if ! php -r 'exit(preg_match("/^[^@\\s]+@[^@\\s]+$/", $argv[1]) === 1 ? 0 : 1);' "$MAIL_FROM"; then
        fail "not a valid mail From address: ${MAIL_FROM}"
    fi
fi
effective_smtp_host="${SMTP_HOST:-$CURRENT_SMTP_HOST}"
if [ "$MAIL_TRANSPORT" = "smtp" ] && [ -z "$effective_smtp_host" ]; then
    fail "--smtp-host is required when --mail-transport=smtp is used"
fi
if [ -n "$SMTP_HOST" ] && [[ "$SMTP_HOST" == *$'\n'* || "$SMTP_HOST" == *$'\r'* ]]; then
    fail "the SMTP host must be a single line"
fi
if [ -n "$SMTP_USER" ] && [[ "$SMTP_USER" == *$'\n'* || "$SMTP_USER" == *$'\r'* ]]; then
    fail "the SMTP user must be a single line"
fi
if [ -n "$SMTP_PASSWORD" ] && [[ "$SMTP_PASSWORD" == *$'\n'* || "$SMTP_PASSWORD" == *$'\r'* ]]; then
    fail "the SMTP password must be a single line"
fi

if [ -n "$ADMIN_HANDLE" ]; then
    ADMIN_HANDLE="$(printf '%s' "$ADMIN_HANDLE" | tr '[:upper:]' '[:lower:]')"
    ADMIN_HANDLE="$(trim "$ADMIN_HANDLE")"
    ADMIN_EMAIL="$(trim "$ADMIN_EMAIL")"
    ADMIN_NAME="$(trim "$ADMIN_NAME")"
    if ! php -r 'exit(preg_match("/^[a-z0-9][a-z0-9_-]{1,29}$/", $argv[1]) === 1 ? 0 : 1);' "$ADMIN_HANDLE"; then
        fail "the admin handle must be 2-30 characters: lower-case letters, digits, _ or -"
    fi
    [ -n "$ADMIN_EMAIL" ] || fail "--email is required when --handle is set"
    validate_email "$ADMIN_EMAIL"
    if [ -n "$ADMIN_PASSWORD" ] && [ "${#ADMIN_PASSWORD}" -lt 8 ]; then
        fail "the admin password must be at least 8 characters long"
    fi
    if [ -z "$ADMIN_PASSWORD" ] && { [ "$NON_INTERACTIVE" -eq 1 ] || [ ! -t 0 ]; }; then
        fail "--password is required when --handle is used non-interactively"
    fi
fi
if [ "$NO_ADMIN" -eq 0 ] && { [ -n "$ADMIN_EMAIL" ] || [ -n "$ADMIN_PASSWORD" ]; }; then
    [ -n "$ADMIN_HANDLE" ] || fail "--handle is required when --email or --password is set"
fi

# ---------------------------------------------------------------------------
# Composer
# ---------------------------------------------------------------------------

if [ "$SKIP_COMPOSER" -eq 0 ]; then
    if [ ! -f vendor/autoload.php ] || [ "$WITH_DEV" -eq 1 ]; then
        log "installing Composer dependencies"
        if [ "$WITH_DEV" -eq 1 ]; then
            composer install --no-interaction --no-progress --prefer-dist
        else
            composer install --no-dev --no-interaction --no-progress --prefer-dist --classmap-authoritative
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Move runtime state when the data directory changes
# ---------------------------------------------------------------------------

OLD_VAR_PATH="$(resolve_path "$CURRENT_VAR")"
OLD_BLOB_PATH="$(resolve_path "$CURRENT_BLOB")"
OLD_KEY_PATH="$(resolve_path "$CURRENT_KEY")"
OLD_MAIL_PATH="$(resolve_path "$CURRENT_MAIL_DIR")"
OLD_DB_PATH=""
if OLD_DB_PATH="$(sqlite_path_from_dsn "$CURRENT_DSN")"; then :; else OLD_DB_PATH=""; fi

copy_directory_contents() {
    local source="$1" target="$2" label="$3"
    [ -d "$source" ] || return 0
    [ "$source" = "$target" ] && return 0

    if [ -d "$target" ] && [ -n "$(find "$target" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
        if [ -n "$(find "$source" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
            warn "${label} already exist in ${target}; left the old files in ${source} untouched"
        fi
        return 0
    fi

    mkdir -p "$target"
    if [ -n "$(find "$source" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
        cp -a "${source}/." "${target}/"
        log "copied ${label} to ${target}"
    fi
}

if [ -n "$DATA_DIR" ]; then
    NEW_VAR_PATH="$(resolve_path "$DATA_DIR")"
    mkdir -p "$NEW_VAR_PATH" || fail "cannot create the data directory: ${NEW_VAR_PATH}"
    if [ ! -w "$NEW_VAR_PATH" ]; then
        fail "the data directory is not writable: ${NEW_VAR_PATH}"
    fi

    # A wizard or an explicit --db-dsn may already contain the new path.
    # Copy whenever the target is the data directory's server.sqlite, even if
    # DB_DSN is non-empty, so choosing a new data directory can never make an
    # existing database look empty.
    new_dsn="${DB_DSN:-sqlite:${DATA_DIR}/server.sqlite}"
    case "$new_dsn" in
        sqlite:*)
            if NEW_DB_PATH="$(sqlite_path_from_dsn "$new_dsn")"; then
                if [ "$NEW_DB_PATH" = "${NEW_VAR_PATH}/server.sqlite" ] && [ -n "$OLD_DB_PATH" ] && [ "$OLD_DB_PATH" != "$NEW_DB_PATH" ]; then
                    if [ -f "$OLD_DB_PATH" ] && [ -f "$NEW_DB_PATH" ]; then
                        fail "both ${OLD_DB_PATH} and ${NEW_DB_PATH} exist; move one away before changing --data-dir"
                    fi
                    if [ -f "$OLD_DB_PATH" ]; then
                        log "copying the SQLite database to ${NEW_DB_PATH}"
                        php -r '
                            $source = $argv[1];
                            $target = $argv[2];
                            $pdo = new PDO("sqlite:" . $source, null, null, [
                                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            ]);
                            $pdo->exec("VACUUM INTO " . $pdo->quote($target));
                        ' "$OLD_DB_PATH" "$NEW_DB_PATH" \
                            || fail "could not copy the SQLite database; the old data was left untouched"
                    fi
                fi
            fi
            [ -n "$DB_DSN" ] || DB_DSN="$new_dsn"
            ;;
        pgsql:*)
            log "keeping the PostgreSQL DSN; --data-dir only moves files"
            ;;
    esac

    if [ -z "${ALOHASOCIAL_SECRET_KEY:-}" ] && [ -f "$OLD_KEY_PATH" ]; then
        NEW_KEY_PATH="${NEW_VAR_PATH}/secret.key"
        if [ "$OLD_KEY_PATH" != "$NEW_KEY_PATH" ]; then
            if [ -e "$NEW_KEY_PATH" ]; then
                if ! cmp -s "$OLD_KEY_PATH" "$NEW_KEY_PATH"; then
                    fail "both ${OLD_KEY_PATH} and ${NEW_KEY_PATH} exist and differ; keep only the matching instance key"
                fi
            else
                mkdir -p "$(dirname "$NEW_KEY_PATH")"
                cp -p "$OLD_KEY_PATH" "$NEW_KEY_PATH"
                chmod 0600 "$NEW_KEY_PATH" 2>/dev/null || true
                log "copied the instance secret key to ${NEW_KEY_PATH}"
            fi
        fi
    fi

    copy_directory_contents "$OLD_BLOB_PATH" "${NEW_VAR_PATH}/blobs" "uploaded media"
    copy_directory_contents "$OLD_MAIL_PATH" "${NEW_VAR_PATH}/mail" "mail files"
fi

# ---------------------------------------------------------------------------
# Write .env
# ---------------------------------------------------------------------------

if [ ! -f .env ]; then
    log "writing .env from .env.example"
    cp .env.example .env
elif [ "$FORCE_ENV" -eq 1 ]; then
    backup=".env.backup-$(date '+%Y%m%d-%H%M%S')-$$"
    cp .env "$backup"
    chmod 0600 "$backup" 2>/dev/null || true
    log "kept the old configuration as ${backup}"
    cp .env.example .env
fi

set_env() {
    local key="$1"
    local value="$2"
    # Send the value over stdin so passwords never appear in the process list.
    printf '%s' "$value" | php -r '
        $file = $argv[1];
        $key = $argv[2];
        $value = stream_get_contents(STDIN);
        if ($value === false) {
            fwrite(STDERR, "cannot read the value for {$key}\n");
            exit(1);
        }
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
        if (file_put_contents($file, implode("\n", $lines) . "\n") === false) {
            fwrite(STDERR, "cannot write {$file}\n");
            exit(1);
        }
    ' .env "$key"
}

if [ -n "$URL" ]; then
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
    # Setting only the var directory would leave media and mail in the
    # release directory, where a later update could replace them.
    set_env ALOHASOCIAL_BLOB_DIRECTORY "$DATA_DIR/blobs"
    set_env ALOHASOCIAL_SECRET_KEY_FILE "$DATA_DIR/secret.key"
    set_env ALOHASOCIAL_MAIL_LOG_DIR "$DATA_DIR/mail"
fi
if [ -n "$PRESERVE_BLOB" ]; then
    set_env ALOHASOCIAL_BLOB_DIRECTORY "$PRESERVE_BLOB"
fi
if [ -n "$PRESERVE_KEY" ]; then
    set_env ALOHASOCIAL_SECRET_KEY_FILE "$PRESERVE_KEY"
fi
if [ -n "$PRESERVE_MAIL" ]; then
    set_env ALOHASOCIAL_MAIL_LOG_DIR "$PRESERVE_MAIL"
fi
if [ -n "$MAIL_TRANSPORT" ]; then
    set_env ALOHASOCIAL_MAIL_TRANSPORT "$MAIL_TRANSPORT"
fi
if [ -n "$MAIL_FROM" ]; then
    set_env ALOHASOCIAL_MAIL_FROM "$MAIL_FROM"
fi
if [ "$MAIL_TRANSPORT" = "smtp" ]; then
    [ -n "$effective_smtp_host" ] || fail "--smtp-host is required when --mail-transport=smtp is used"
    if [ -n "$SMTP_HOST" ]; then
        set_env ALOHASOCIAL_SMTP_HOST "$SMTP_HOST"
    fi
    if [ -n "$SMTP_PORT" ]; then
        set_env ALOHASOCIAL_SMTP_PORT "$SMTP_PORT"
    fi
    if [ -n "$SMTP_USER" ]; then
        set_env ALOHASOCIAL_SMTP_USER "$SMTP_USER"
    fi
    if [ -n "$SMTP_PASSWORD" ]; then
        set_env ALOHASOCIAL_SMTP_PASSWORD "$SMTP_PASSWORD"
    fi
    if [ -n "$SMTP_ENCRYPTION" ]; then
        set_env ALOHASOCIAL_SMTP_ENCRYPTION "$SMTP_ENCRYPTION"
    fi
fi

# .env holds the database password and SMTP credentials.
chmod 0600 .env 2>/dev/null || true

# ---------------------------------------------------------------------------
# Resolve and prepare the real runtime paths
# ---------------------------------------------------------------------------

log "checking configuration"
runtime_values="$(php -r '
    require "vendor/autoload.php";
    try {
        $config = AlohaSocial\Server\Config\HostConfig::load(getcwd());
        echo implode("\n", [
            $config->databaseDsn,
            $config->varDirectory,
            $config->blobDirectory,
            $config->mailLogDirectory,
            dirname($config->secretKeyFile),
        ]);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        exit(1);
    }
')" || fail "the configuration is invalid; check .env"

mapfile -t runtime_paths <<< "$runtime_values"
[ "${#runtime_paths[@]}" -eq 5 ] || fail "could not resolve the runtime paths from .env"

dsn="${runtime_paths[0]}"
var_dir="${runtime_paths[1]}"
blob_dir="${runtime_paths[2]}"
mail_dir="${runtime_paths[3]}"
key_dir="${runtime_paths[4]}"

for directory in "$var_dir" "$blob_dir" "$mail_dir" "$key_dir"; do
    mkdir -p "$directory" || fail "cannot create ${directory}"
    chmod 0770 "$directory" 2>/dev/null || true
    [ -w "$directory" ] || fail "the application cannot write to ${directory}"
done

case "$dsn" in
    sqlite:*)
        sqlite_path="${dsn#sqlite:}"
        if [ "$sqlite_path" != ':memory:' ] && [ -n "$sqlite_path" ]; then
            case "$sqlite_path" in
                /*) ;;
                *) sqlite_path="$ROOT/$sqlite_path" ;;
            esac
            mkdir -p "$(dirname "$sqlite_path")" || fail "cannot create the database directory for ${sqlite_path}"
            chmod 0770 "$(dirname "$sqlite_path")" 2>/dev/null || true
            [ -w "$(dirname "$sqlite_path")" ] || fail "the database directory is not writable: $(dirname "$sqlite_path")"
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

# ---------------------------------------------------------------------------
# Migrate and create the account
# ---------------------------------------------------------------------------

if [ "$SKIP_MIGRATE" -eq 0 ]; then
    log "applying database migrations (with backup and account check)"
    php bin/update.php || fail "database update failed; inspect the output above"
else
    log "skipping migrations (--skip-migrate)"
fi

admin_ready=0
if [ "$NO_ADMIN" -eq 0 ] && [ -n "$ADMIN_HANDLE" ]; then
    if [ -z "$ADMIN_PASSWORD" ]; then
        if [ "$NON_INTERACTIVE" -eq 1 ] || [ ! -t 0 ]; then
            fail "--password is required in non-interactive mode"
        fi
        printf 'Password for %s: ' "$ADMIN_HANDLE" >&2
        IFS= read -r -s ADMIN_PASSWORD
        printf '\n' >&2
        if [ "${#ADMIN_PASSWORD}" -lt 8 ]; then
            fail "the admin password must be at least 8 characters long"
        fi
    fi

    log "creating admin account @${ADMIN_HANDLE} (idempotent)"
    printf '%s\n' "$ADMIN_PASSWORD" | php bin/create-account.php \
        "$ADMIN_HANDLE" \
        "$ADMIN_EMAIL" \
        --admin \
        --if-not-exists \
        --password-stdin \
        --display-name="${ADMIN_NAME:-$ADMIN_HANDLE}" \
        || fail "could not create the admin account"
    admin_ready=1
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
    service_user="$(id -un)"
    service_group="$(id -gn)"

    cat > "$service_dir/aloha-worker.service" <<EOF
[Unit]
Description=Aloha Social worker
After=network.target

[Service]
Type=simple
User=${service_user}
Group=${service_group}
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
    client_max_body_size 64m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

    log "wrote service templates to ${service_dir}/ (review User, Group, TLS and the PHP socket)"
fi

if [ "$VERIFY" -eq 1 ] && [ "$SKIP_MIGRATE" -eq 0 ]; then
    log "verifying the installation"
    php bin/doctor.php || fail "installation checks failed; fix the problems above"
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

local_url="$(php -r '
    require "vendor/autoload.php";
    try {
        echo AlohaSocial\Server\Config\HostConfig::load(getcwd())->instanceUrl;
    } catch (Throwable $exception) {
        echo "-";
    }
')"
dev_port=3000
if [[ "$local_url" == http://* ]]; then
    dev_port="$(php -r 'echo parse_url($argv[1], PHP_URL_PORT) ?: 3000;' "$local_url")"
fi

cat <<EOF

Aloha Social is installed.

  Instance URL : ${local_url}
  Database     : ${dsn}
  Data         : ${var_dir}
  Blobs        : ${blob_dir}
  Mail         : ${mail_dir}
  Web root     : ${ROOT}/public

Next steps:
  php -S 127.0.0.1:${dev_port} -t public public/index.php   # local development
  php bin/worker.php                                       # federation worker
  php bin/doctor.php                                       # repeat diagnostics

Open /login to sign in and /app for the social interface. The data directory
also contains the secret key; keep it with the database in every backup, and
never run "docker compose down -v" on an instance you want to keep.

For a production deployment see docs/installation.md, or use the Docker image
described in docs/docker.md.
EOF

if [ "$admin_ready" -eq 1 ]; then
    log "admin login: @${ADMIN_HANDLE} / ${ADMIN_EMAIL}"
fi
