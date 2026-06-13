#!/usr/bin/env bash
set -euo pipefail
APP="${1:-/var/www/html/CADARS-Members-System}"
BACKUP_DIR="${2:-/root/cadars-members-backups}"
mkdir -p "$BACKUP_DIR"
tar -czf "$BACKUP_DIR/cadars-members-data-$(date +%F-%H%M%S).tar.gz" "$APP/database" "$APP/storage"
