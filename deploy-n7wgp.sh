#!/usr/bin/env bash
# Publishes the VR-N76 programmer to https://n7wgp.com
#
#   ./deploy-n7wgp.sh            dry run (default -- shows what would change)
#   ./deploy-n7wgp.sh --apply    actually deploy
#
# Hostinger SSH credentials are read from the jasonhuber.com workspace, which
# is the same hosting account (n7wgp.com is an addon domain there). Mirrors the
# safety of that workspace's site/deploy.sh: no --delete, dry run by default,
# and a post-deploy HTTP verification.
set -euo pipefail
cd "$(dirname "$0")"

ENV_FILE="$HOME/Library/CloudStorage/Dropbox/Personal/jasonhuber.com/llm-api/.env"
[[ -f "$ENV_FILE" ]] || { echo "missing $ENV_FILE" >&2; exit 1; }
[[ -f vgc-programmer.html ]] || { echo "vgc-programmer.html not found" >&2; exit 1; }

g(){ awk -v k="$1" -F= '$1==k { sub(/^[^=]*=/,""); print; exit }' "$ENV_FILE"; }
H=$(g DEPLOY_SSH_HOST); U=$(g DEPLOY_SSH_USER); P=$(g DEPLOY_SSH_PORT)
K=$(g DEPLOY_SSH_KEY);  PW=$(g DEPLOY_SSH_PASSWORD)
REMOTE="domains/n7wgp.com/public_html"

[[ -n "$H" && -n "$U" && -n "$P" ]] || { echo "incomplete SSH settings" >&2; exit 1; }

# Build step: the site root is generated from the single source file so the
# published copy can never drift from the one the tests run against.
mkdir -p public
cp vgc-programmer.html public/index.html
echo "built public/index.html ($(wc -c < public/index.html | tr -d ' ') bytes)"

RSYNC_FLAGS=(-az --human-readable --chmod=u=rwX,go=rX
    --exclude='.DS_Store' --exclude='*.map')

MODE="${1:-}"
if [[ "$MODE" != "--apply" ]]; then
    RSYNC_FLAGS+=(--dry-run --itemize-changes)
    echo "DRY RUN: pass --apply to deploy"
fi

if [[ -n "$K" && -f "${K/#\~/$HOME}" ]]; then
    SSH_CMD="ssh -o StrictHostKeyChecking=accept-new -i ${K/#\~/$HOME} -p $P"
    rsync "${RSYNC_FLAGS[@]}" -e "$SSH_CMD" public/ "$U@$H:$REMOTE/"
elif [[ -n "$PW" ]]; then
    command -v sshpass >/dev/null || { echo "sshpass required" >&2; exit 1; }
    SSHPASS="$PW" rsync "${RSYNC_FLAGS[@]}" \
        -e "sshpass -e ssh -o StrictHostKeyChecking=accept-new -p $P" \
        public/ "$U@$H:$REMOTE/"
else
    echo "no usable SSH credentials" >&2; exit 1
fi

if [[ "$MODE" == "--apply" ]]; then
    echo
    for u in "https://n7wgp.com/" "https://jasonhuber.com/" \
             "https://jasonhuber.com/llm/health" "https://jasonhuber.com/track/health"; do
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$u" || echo 000)
        printf "  %-40s %s\n" "$u" "$code"
    done
fi
