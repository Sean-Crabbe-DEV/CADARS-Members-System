#!/usr/bin/env bash
set -euo pipefail

# CADARS Members System - GitHub Live Updater
# ------------------------------------------------------------
# Pulls latest code from GitHub and updates the live web system.
#
# It preserves live data:
#   - database/
#   - storage/
#
# It updates app/code files:
#   - public/
#   - docs/
#   - README.md
#   - any other repo files except database/storage
#
# Default repo:
#   https://github.com/Sean-Crabbe-DEV/CADARS-Members-System.git
#
# Default live path:
#   /var/www/html/CADARS-Members-System
#
# Usage:
#   chmod +x update-cadars-from-github.sh
#   ./update-cadars-from-github.sh
#
# Optional custom usage:
#   ./update-cadars-from-github.sh <repo_url> <branch> <live_path>
#
# Example:
#   ./update-cadars-from-github.sh \
#     https://github.com/Sean-Crabbe-DEV/CADARS-Members-System.git \
#     main \
#     /var/www/html/CADARS-Members-System

REPO_URL="${1:-https://github.com/Sean-Crabbe-DEV/CADARS-Members-System.git}"
BRANCH="${2:-main}"
APP="${3:-/var/www/html/CADARS-Members-System}"

SOURCE_DIR="/opt/CADARS-Members-System-source"
BACKUP_DIR="/root/cadars-members-backups"
LOCK_FILE="/tmp/cadars-members-github-update.lock"
DATE="$(date +%F-%H%M%S)"

echo "================================================="
echo " CADARS Members System - GitHub Live Updater"
echo "================================================="
echo
echo "Repo:       $REPO_URL"
echo "Branch:     $BRANCH"
echo "Source dir: $SOURCE_DIR"
echo "Live path:  $APP"
echo

if [[ "$(id -u)" -ne 0 ]]; then
    echo "ERROR: Run this as root."
    exit 1
fi

if [[ -e "$LOCK_FILE" ]]; then
    echo "ERROR: Lock file exists:"
    echo "  $LOCK_FILE"
    echo
    echo "If no update is running, remove it with:"
    echo "  rm -f $LOCK_FILE"
    exit 1
fi

touch "$LOCK_FILE"
trap 'rm -f "$LOCK_FILE"' EXIT

echo "[1/12] Checking required commands..."

for cmd in git tar rsync nginx systemctl find chown chmod grep sha256sum; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo "ERROR: Required command missing: $cmd"
        echo
        echo "Install basics with:"
        echo "  apt update && apt install git rsync tar coreutils -y"
        exit 1
    fi
done

echo "[2/12] Checking live app folder..."

if [[ ! -d "$APP" ]]; then
    echo "Live folder does not exist, creating:"
    echo "  $APP"
    mkdir -p "$APP"
fi

if [[ -f "$APP/public/index.php" ]]; then
    BEFORE_HASH="$(sha256sum "$APP/public/index.php" | awk '{print $1}')"
else
    BEFORE_HASH="missing"
fi

echo "Live public/index.php before update:"
echo "  $BEFORE_HASH"
echo

echo "[3/12] Cloning/pulling latest code..."

if [[ ! -d "$SOURCE_DIR/.git" ]]; then
    echo "No source checkout found. Cloning fresh..."
    rm -rf "$SOURCE_DIR"
    git clone --branch "$BRANCH" "$REPO_URL" "$SOURCE_DIR"
else
    echo "Existing source checkout found. Resetting to GitHub..."
    cd "$SOURCE_DIR"

    CURRENT_REMOTE="$(git config --get remote.origin.url || true)"

    if [[ "$CURRENT_REMOTE" != "$REPO_URL" ]]; then
        echo "Remote URL was:"
        echo "  $CURRENT_REMOTE"
        echo "Changing remote to:"
        echo "  $REPO_URL"
        git remote set-url origin "$REPO_URL"
    fi

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

echo
echo "GitHub code now at:"
echo "  Commit: $COMMIT_HASH"
echo "  Date:   $COMMIT_DATE"
echo "  Msg:    $COMMIT_MSG"
echo

echo "[4/12] Finding app source folder..."

# Supports both layouts:
# 1) repo/public/index.php
# 2) repo/ham-membership-system/public/index.php

if [[ -f "$SOURCE_DIR/public/index.php" ]]; then
    SRC="$SOURCE_DIR"
elif [[ -f "$SOURCE_DIR/ham-membership-system/public/index.php" ]]; then
    SRC="$SOURCE_DIR/ham-membership-system"
else
    echo "ERROR: Cannot find app public/index.php in GitHub checkout."
    echo
    echo "Checked:"
    echo "  $SOURCE_DIR/public/index.php"
    echo "  $SOURCE_DIR/ham-membership-system/public/index.php"
    echo
    echo "Current files:"
    ls -la "$SOURCE_DIR"
    exit 1
fi

echo "Using deploy source:"
echo "  $SRC"

if [[ -f "$SRC/public/index.php" ]]; then
    SOURCE_HASH="$(sha256sum "$SRC/public/index.php" | awk '{print $1}')"
else
    echo "ERROR: Source public/index.php is missing."
    exit 1
fi

echo "Source public/index.php hash:"
echo "  $SOURCE_HASH"
echo

echo "[5/12] Creating backup..."

mkdir -p "$BACKUP_DIR"
BACKUP_FILE="$BACKUP_DIR/cadars-members-backup-$DATE-before-$COMMIT_HASH.tar.gz"

tar -czf "$BACKUP_FILE" \
    --ignore-failed-read \
    "$APP/database" \
    "$APP/storage" \
    "$APP/public/index.php" \
    "$APP/README.md" \
    2>/dev/null || true

echo "Backup saved:"
echo "  $BACKUP_FILE"
echo

echo "[6/12] Ensuring live data folders exist..."

mkdir -p "$APP/database"
mkdir -p "$APP/storage"
mkdir -p "$APP/storage/private"
mkdir -p "$APP/storage/private/brickworks-evidence"
mkdir -p "$APP/storage/private/email-attachments"
mkdir -p "$APP/storage/private/equipment-documents"
mkdir -p "$APP/storage/private/member-documents"
mkdir -p "$APP/storage/private/event-attachments"

echo "[7/12] Deploying files to live site..."
echo
echo "IMPORTANT: preserving live:"
echo "  $APP/database/"
echo "  $APP/storage/"
echo

rsync -av --delete \
    --exclude='.git/' \
    --exclude='.github/' \
    --exclude='database/' \
    --exclude='storage/' \
    "$SRC/" "$APP/"

echo
echo "[8/12] Writing deployed version marker..."

cat > "$APP/storage/deployed_version.txt" <<EOF
Deployed at: $DATE
Repo: $REPO_URL
Branch: $BRANCH
Commit: $COMMIT_FULL
Commit short: $COMMIT_HASH
Commit date: $COMMIT_DATE
Commit message: $COMMIT_MSG
Source dir: $SRC
Live path: $APP
Before public/index.php hash: $BEFORE_HASH
Source public/index.php hash: $SOURCE_HASH
EOF

echo "Version marker written:"
echo "  $APP/storage/deployed_version.txt"
echo

echo "[9/12] Fixing ownership and permissions..."

chown -R www-data:www-data "$APP"

find "$APP" -type d -exec chmod 750 {} \;
find "$APP" -type f -exec chmod 640 {} \;

# These need to remain writable by PHP/www-data.
chown -R www-data:www-data "$APP/database" "$APP/storage"
chmod 750 "$APP/database" "$APP/storage"
find "$APP/database" -type d -exec chmod 750 {} \;
find "$APP/storage" -type d -exec chmod 750 {} \;
find "$APP/database" -type f -exec chmod 640 {} \;
find "$APP/storage" -type f -exec chmod 640 {} \;

echo "[10/12] Checking live public/index.php after update..."

if [[ -f "$APP/public/index.php" ]]; then
    AFTER_HASH="$(sha256sum "$APP/public/index.php" | awk '{print $1}')"
else
    AFTER_HASH="missing"
fi

echo "Live public/index.php after update:"
echo "  $AFTER_HASH"
echo

if [[ "$AFTER_HASH" == "$SOURCE_HASH" ]]; then
    echo "OK: Live public/index.php matches the GitHub source file."
else
    echo "WARNING: Live public/index.php does NOT match the GitHub source file."
    echo "This usually means the live path is wrong or rsync failed."
fi

if [[ "$BEFORE_HASH" == "$AFTER_HASH" ]]; then
    echo "NOTE: public/index.php hash did not change."
    echo "That means either GitHub had no code changes, or the changed files were elsewhere."
else
    echo "OK: public/index.php changed."
fi

echo

echo "[11/12] Testing Nginx config..."

nginx -t

echo

echo "[12/12] Reloading web services..."

systemctl reload nginx

PHP_RELOADED=0

for svc in php8.4-fpm php8.3-fpm php8.2-fpm php8.1-fpm php8.0-fpm php7.4-fpm; do
    if systemctl list-units --type=service --all | grep -q "$svc"; then
        systemctl reload "$svc"
        PHP_RELOADED=1
        echo "Reloaded $svc"
        break
    fi
done

if [[ "$PHP_RELOADED" -eq 0 ]]; then
    echo "WARNING: Could not detect PHP-FPM service automatically."
    echo "Reload manually if needed, for example:"
    echo "  systemctl reload php8.1-fpm"
fi

echo
echo "================================================="
echo " Update complete"
echo "================================================="
echo
echo "Deployed:"
echo "  $COMMIT_HASH - $COMMIT_MSG"
echo
echo "Backup:"
echo "  $BACKUP_FILE"
echo
echo "Version marker:"
echo "  $APP/storage/deployed_version.txt"
echo
echo "Useful checks:"
echo "  cat $APP/storage/deployed_version.txt"
echo "  ls -la $APP/public"
echo "  sha256sum $APP/public/index.php"
echo
echo "If nothing appears to have changed on the website:"
echo "  1. Check the GitHub repo actually contains the latest changed files."
echo "  2. Check this script is using the correct live path:"
echo "       $APP"
echo "  3. Hard refresh your browser."
echo "  4. Restart PHP-FPM instead of reload:"
echo "       systemctl restart php8.1-fpm"
echo
echo "Rollback command:"
echo "  tar -xzf \"$BACKUP_FILE\" -C /"
echo "  chown -R www-data:www-data \"$APP\""
echo "  systemctl reload nginx"
echo
