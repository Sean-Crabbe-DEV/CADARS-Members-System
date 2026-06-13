#!/usr/bin/env bash
set -euo pipefail

REPO_URL="${1:-https://github.com/Sean-Crabbe-DEV/CADARS-Members-System.git}"
BRANCH="${2:-main}"
APP="${3:-/var/www/html/CADARS-Members-System}"

SOURCE_DIR="/opt/CADARS-Members-System-source"
BACKUP_DIR="/root/cadars-members-backups"
LOCK_FILE="/tmp/cadars-members-github-update.lock"
DATE="$(date +%F-%H%M%S)"

echo "CADARS Members System updater"
echo "Repo: $REPO_URL"
echo "Branch: $BRANCH"
echo "Live path: $APP"

if [[ "$(id -u)" -ne 0 ]]; then
    echo "Run as root."
    exit 1
fi

if [[ -e "$LOCK_FILE" ]]; then
    echo "Update lock exists: $LOCK_FILE"
    exit 1
fi

touch "$LOCK_FILE"
trap 'rm -f "$LOCK_FILE"' EXIT

for cmd in git tar rsync nginx systemctl find chown chmod sha256sum; do
    command -v "$cmd" >/dev/null 2>&1 || { echo "Missing: $cmd"; exit 1; }
done

mkdir -p "$APP"

if [[ ! -d "$SOURCE_DIR/.git" ]]; then
    rm -rf "$SOURCE_DIR"
    git clone --branch "$BRANCH" "$REPO_URL" "$SOURCE_DIR"
else
    cd "$SOURCE_DIR"
    git remote set-url origin "$REPO_URL"
    git fetch --all --prune
    git checkout "$BRANCH"
    git reset --hard "origin/$BRANCH"
    git clean -fdx
fi

cd "$SOURCE_DIR"

COMMIT_HASH="$(git rev-parse --short HEAD)"
COMMIT_FULL="$(git rev-parse HEAD)"
COMMIT_MSG="$(git log -1 --pretty=%s)"
COMMIT_DATE="$(git log -1 --pretty=%ci)"

if [[ -f "$SOURCE_DIR/public/index.php" ]]; then
    SRC="$SOURCE_DIR"
elif [[ -f "$SOURCE_DIR/ham-membership-system/public/index.php" ]]; then
    SRC="$SOURCE_DIR/ham-membership-system"
else
    echo "Cannot find public/index.php"
    exit 1
fi

mkdir -p "$BACKUP_DIR"
BACKUP_FILE="$BACKUP_DIR/cadars-members-backup-$DATE-before-$COMMIT_HASH.tar.gz"

tar -czf "$BACKUP_FILE" \
    --ignore-failed-read \
    "$APP/database" \
    "$APP/storage" \
    "$APP/public/index.php" \
    "$APP/README.md" \
    2>/dev/null || true

mkdir -p "$APP/database" "$APP/storage/private"

rsync -av --delete \
    --exclude='.git/' \
    --exclude='.github/' \
    --exclude='database/' \
    --exclude='storage/' \
    "$SRC/" "$APP/"

cat > "$APP/storage/deployed_version.txt" <<EOF
Deployed at: $DATE
Repo: $REPO_URL
Branch: $BRANCH
Commit: $COMMIT_FULL
Commit short: $COMMIT_HASH
Commit date: $COMMIT_DATE
Commit message: $COMMIT_MSG
EOF

chown -R www-data:www-data "$APP"
find "$APP" -type d -exec chmod 750 {} \;
find "$APP" -type f -exec chmod 640 {} \;

mkdir -p "$APP/database" "$APP/storage/private"
chown -R www-data:www-data "$APP/database" "$APP/storage"
chmod 750 "$APP/database" "$APP/storage"

nginx -t
systemctl reload nginx

for svc in php8.4-fpm php8.3-fpm php8.2-fpm php8.1-fpm php8.0-fpm php7.4-fpm; do
    if systemctl list-units --type=service --all | grep -q "$svc"; then
        systemctl reload "$svc"
        break
    fi
done

echo "Update complete."
echo "Commit: $COMMIT_HASH - $COMMIT_MSG"
echo "Backup: $BACKUP_FILE"
