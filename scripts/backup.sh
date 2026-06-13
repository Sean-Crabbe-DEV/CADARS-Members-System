#!/usr/bin/env bash
set -euo pipefail

APP="${1:-/var/www/html/CADARS-Members-System}"
BACKUP_DIR="${2:-/root/cadars-members-backups}"
DATE="$(date +%F-%H%M%S)"

mkdir -p "$BACKUP_DIR"

tar -czf "$BACKUP_DIR/cadars-members-data-$DATE.tar.gz" \
  "$APP/database" \
  "$APP/storage"

echo "Backup created:"
echo "$BACKUP_DIR/cadars-members-data-$DATE.tar.gz"
