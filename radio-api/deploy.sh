#!/usr/bin/env bash
# Deploy the N7WGP radio API to Hostinger (n7wgp.com/public_html/api).
#
#   ./deploy.sh            dry run (default -- shows what would change)
#   ./deploy.sh --apply    actually deploy
#
# Same hosting account and credentials as the page deploy one directory up;
# n7wgp.com is an addon domain there. Mirrors track-api's safety: no --delete,
# dry run by default, never syncs the database or config secrets by accident.
set -euo pipefail
cd "$(dirname "$0")"

ENV_FILE="$HOME/Library/CloudStorage/Dropbox/Personal/jasonhuber.com/llm-api/.env"
[[ -f "$ENV_FILE" ]] || { echo "missing $ENV_FILE" >&2; exit 1; }
[[ -f index.php ]]   || { echo "index.php not found" >&2; exit 1; }
[[ -f config.php ]]  || { echo "config.php not found -- cp config.example.php config.php and fill it in" >&2; exit 1; }

if grep -q "CHANGE_ME_TO_A" config.php; then
    echo "config.php still has a placeholder secret. Generate real ones with:" >&2
    echo "  php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'" >&2
    exit 1
fi

php -l index.php  >/dev/null || { echo "index.php has a syntax error" >&2; exit 1; }
php -l config.php >/dev/null || { echo "config.php has a syntax error" >&2; exit 1; }

g(){ awk -v k="$1" -F= '$1==k { sub(/^[^=]*=/,""); print; exit }' "$ENV_FILE"; }
H=$(g DEPLOY_SSH_HOST); U=$(g DEPLOY_SSH_USER); P=$(g DEPLOY_SSH_PORT)
K=$(g DEPLOY_SSH_KEY);  PW=$(g DEPLOY_SSH_PASSWORD)
REMOTE="domains/n7wgp.com/public_html/api"

[[ -n "$H" && -n "$U" && -n "$P" ]] || { echo "incomplete SSH settings" >&2; exit 1; }

if [[ -n "$K" && -f "${K/#\~/$HOME}" ]]; then
    SSH_CMD="ssh -o StrictHostKeyChecking=accept-new -i ${K/#\~/$HOME} -p $P"
elif [[ -n "$PW" ]]; then
    command -v sshpass >/dev/null || { echo "sshpass required" >&2; exit 1; }
    PW_FILE=$(mktemp); chmod 600 "$PW_FILE"; printf '%s' "$PW" > "$PW_FILE"
    trap 'rm -f "$PW_FILE"' EXIT
    SSH_CMD="sshpass -f $PW_FILE ssh -o StrictHostKeyChecking=accept-new -p $P"
else
    echo "no usable SSH credentials" >&2; exit 1
fi

MODE="${1:-}"

# NEVER sync the database or runtime state -- those live only on the server,
# and a stale local copy would silently overwrite real accounts.
RSYNC_FLAGS=(-az --human-readable --chmod=u=rwX,go=rX
    --include='.htaccess'
    --exclude='.env' --exclude='.git/' --exclude='deploy.sh'
    --exclude='config.example.php'
    --exclude='README.md' --exclude='.DS_Store'
    --exclude='radio-data/' --exclude='*.db' --exclude='*.db-wal' --exclude='*.db-shm'
    --exclude='*.sqlite' --exclude='*.sqlite3')

if [[ "$MODE" != "--apply" ]]; then
    RSYNC_FLAGS+=(--dry-run --itemize-changes)
    echo "DRY RUN: pass --apply to deploy"
fi

$SSH_CMD "$U@$H" "mkdir -p '$REMOTE'"
rsync "${RSYNC_FLAGS[@]}" -e "$SSH_CMD" ./ "$U@$H:$REMOTE/"

if [[ "$MODE" == "--apply" ]]; then
    # The data directory must exist, be writable by PHP, and never be listable.
    $SSH_CMD "$U@$H" "mkdir -p '$REMOTE/radio-data' && chmod 700 '$REMOTE/radio-data'"
    echo
    for u in "https://n7wgp.com/api" "https://n7wgp.com/api/health" \
             "https://n7wgp.com/api/radio-data/radio.db" "https://n7wgp.com/api/config.php" \
             "https://n7wgp.com/"; do
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$u" || echo 000)
        printf "  %-46s %s\n" "$u" "$code"
    done
    echo
    echo "  (the radio-data and config.php lines MUST be 403 or 404 -- anything else is a leak)"
fi
