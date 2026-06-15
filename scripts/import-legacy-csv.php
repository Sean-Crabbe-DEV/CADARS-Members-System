#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * One-time legacy CSV importer for CADARS Members System.
 *
 * Imports:
 *  - Event_export.csv
 *  - Asset_export.csv
 *  - Attendance_export.csv
 *
 * Usage:
 *   php scripts/import-legacy-csv.php --events=/path/Event_export.csv --assets="/path/Asset_export (1).csv" --attendance=/path/Attendance_export.csv --dry-run
 *   php scripts/import-legacy-csv.php --events=/path/Event_export.csv --assets="/path/Asset_export (1).csv" --attendance=/path/Attendance_export.csv
 */

$options = getopt('', [
    'events:',
    'assets:',
    'attendance:',
    'timezone::',
    'dry-run',
    'no-update',
    'skip-unmatched-members',
    'help',
]);

if (isset($options['help'])) {
    echo "CADARS legacy CSV importer\n\n";
    echo "Required/optional file arguments:\n";
    echo "  --events=/path/Event_export.csv\n";
    echo "  --assets=/path/Asset_export.csv\n";
    echo "  --attendance=/path/Attendance_export.csv\n";
    echo "\nOptions:\n";
    echo "  --timezone=Europe/London       Timezone used for local event times. Default Europe/London.\n";
    echo "  --dry-run                      Parse/import inside a transaction then roll back.\n";
    echo "  --no-update                    Do not update rows that were already imported.\n";
    echo "  --skip-unmatched-members       Skip attendance rows where member email/name cannot be matched.\n";
    exit(0);
}

define('BASE_PATH', dirname(__DIR__));
define('DB_PATH', BASE_PATH . '/database/app.sqlite');
define('CONFIG_PATH', BASE_PATH . '/storage/app_config.php');

$tzName = (string)($options['timezone'] ?? 'Europe/London');
$localTz = new DateTimeZone($tzName);
$dryRun = array_key_exists('dry-run', $options);
$updateExisting = !array_key_exists('no-update', $options);
$skipUnmatchedMembers = array_key_exists('skip-unmatched-members', $options);

if (!file_exists(DB_PATH)) {
    fwrite(STDERR, "ERROR: Database not found: " . DB_PATH . PHP_EOL);
    exit(1);
}
if (!file_exists(CONFIG_PATH)) {
    fwrite(STDERR, "ERROR: App config not found: " . CONFIG_PATH . PHP_EOL);
    fwrite(STDERR, "The system must be installed before importing legacy data.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA busy_timeout = 5000');

$stats = [
    'events_added' => 0,
    'events_updated' => 0,
    'events_skipped' => 0,
    'assets_added' => 0,
    'assets_updated' => 0,
    'assets_skipped' => 0,
    'attendance_added' => 0,
    'attendance_updated' => 0,
    'attendance_skipped' => 0,
    'guests_added' => 0,
    'guests_updated' => 0,
    'warnings' => [],
];

function cfg(): array {
    $defaults = ['app_key' => null];
    $cfg = include CONFIG_PATH;
    return is_array($cfg) ? array_merge($defaults, $cfg) : $defaults;
}

function app_key(): string {
    $cfg = cfg();
    if (!empty($cfg['app_key'])) return base64_decode((string)$cfg['app_key']);
    return str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
}

function enc(?string $plain): ?string {
    if ($plain === null || trim($plain) === '') return $plain;
    if (!function_exists('sodium_crypto_secretbox')) return base64_encode($plain);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain, $nonce, app_key());
    return 'enc:' . base64_encode($nonce . $cipher);
}

function norm_header(string $s): string {
    return strtolower(trim(str_replace([' ', '-'], '_', $s)));
}

function clean($v): string {
    if ($v === null) return '';
    $s = trim((string)$v);
    if ($s === '' || strtolower($s) === 'nan' || strtolower($s) === 'null') return '';
    return $s;
}

function boolish($v): bool {
    $s = strtolower(clean($v));
    return in_array($s, ['1', 'true', 'yes', 'y', 'present', 'active'], true);
}

function money_or_null($v): ?float {
    $s = clean($v);
    if ($s === '') return null;
    $s = preg_replace('/[^0-9.\-]/', '', $s);
    return $s === '' ? null : (float)$s;
}

function date_or_null($v, DateTimeZone $localTz, bool $dateOnly=false): ?string {
    $s = clean($v);
    if ($s === '') return null;
    if (is_numeric($s) && (float)$s > 20000 && (float)$s < 80000) {
        $unix = ((float)$s - 25569) * 86400;
        $dt = (new DateTimeImmutable('@' . (int)$unix))->setTimezone($localTz);
        return $dateOnly ? $dt->format('Y-m-d') : $dt->format('Y-m-d H:i:s');
    }
    try {
        if (preg_match('/Z$/i', $s)) {
            $dt = new DateTimeImmutable($s);
            $dt = $dt->setTimezone($localTz);
        } else {
            $dt = new DateTimeImmutable($s, $localTz);
        }
        return $dateOnly ? $dt->format('Y-m-d') : $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function read_csv_assoc(string $path): array {
    if (!file_exists($path)) throw new RuntimeException("CSV not found: $path");
    $fh = fopen($path, 'rb');
    if (!$fh) throw new RuntimeException("Could not open CSV: $path");
    $header = fgetcsv($fh);
    if (!$header) throw new RuntimeException("CSV has no header: $path");
    $keys = array_map('norm_header', $header);
    $rows = [];
    while (($data = fgetcsv($fh)) !== false) {
        if (!array_filter($data, fn($x) => clean($x) !== '')) continue;
        $row = [];
        foreach ($keys as $i => $key) $row[$key] = $data[$i] ?? '';
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function q(PDO $pdo, string $sql, array $params=[]): PDOStatement {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
function one(PDO $pdo, string $sql, array $params=[]): ?array {
    $row = q($pdo, $sql, $params)->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
function exec_sql(PDO $pdo, string $sql, array $params=[]): void { q($pdo, $sql, $params); }

function ensure_import_tables(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS legacy_import_map (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source TEXT NOT NULL,
        legacy_id TEXT NOT NULL,
        local_table TEXT NOT NULL,
        local_id INTEGER NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(source, legacy_id, local_table)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_legacy_import_map_lookup ON legacy_import_map(source, legacy_id, local_table)');
}
function map_get(PDO $pdo, string $source, string $legacyId, string $table): ?int {
    if ($legacyId === '') return null;
    $row = one($pdo, 'SELECT local_id FROM legacy_import_map WHERE source=? AND legacy_id=? AND local_table=?', [$source, $legacyId, $table]);
    return $row ? (int)$row['local_id'] : null;
}
function map_set(PDO $pdo, string $source, string $legacyId, string $table, int $localId): void {
    if ($legacyId === '') return;
    exec_sql($pdo, 'INSERT INTO legacy_import_map (source, legacy_id, local_table, local_id, created_at, updated_at)
                   VALUES (?, ?, ?, ?, datetime("now"), datetime("now"))
                   ON CONFLICT(source, legacy_id, local_table) DO UPDATE SET local_id=excluded.local_id, updated_at=datetime("now")',
        [$source, $legacyId, $table, $localId]);
}
function audit(PDO $pdo, string $action, ?string $entityType, ?int $entityId, array $metadata=[]): void {
    try {
        exec_sql($pdo, 'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, result, reason, ip_address, user_agent, session_id, metadata, created_at)
                        VALUES (NULL, ?, ?, ?, "success", NULL, "cli-import", "legacy-import-script", NULL, ?, datetime("now"))',
            [$action, $entityType, $entityId, json_encode($metadata)]);
    } catch (Throwable $e) {}
}
function category_from_legacy(string $type, string $title): string {
    $t = strtolower(trim($type));
    $titleLower = strtolower($title);
    if (str_contains($titleLower, 'shack')) return 'Shack Night';
    if (str_contains($titleLower, 'rally') || str_contains($titleLower, 'rallies')) return 'Rallies';
    if (str_contains($titleLower, 'talk')) return 'Talk';
    if (str_contains($titleLower, 'social')) return 'Social';
    if (str_contains($titleLower, 'special event') || str_contains($titleLower, 'ses')) return 'Special Event Station';
    if (str_contains($titleLower, 'field') || str_contains($titleLower, 'mils on the air') || str_contains($titleLower, 'mills on the air')) return 'Field Day';
    return match ($t) {
        'field_day', 'field day' => 'Field Day',
        'special_event_station', 'special event station' => 'Special Event Station',
        'rally', 'rallies' => 'Rallies',
        'talk' => 'Talk',
        'social' => 'Social',
        'shack_night', 'shack night' => 'Shack Night',
        default => 'Club Night',
    };
}
function asset_category_from_name(string $name): ?string {
    $n = strtolower($name);
    if (str_contains($n, 'radio') || str_contains($n, 'icom') || str_contains($n, 'yaesu') || str_contains($n, 'kenwood')) return 'Radio';
    if (str_contains($n, 'antenna') || str_contains($n, 'mast') || str_contains($n, 'pole')) return 'Antenna';
    if (str_contains($n, 'psu') || str_contains($n, 'power supply')) return 'Power';
    if (str_contains($n, 'swr') || str_contains($n, 'meter')) return 'Test equipment';
    if (str_contains($n, 'firstaid') || str_contains($n, 'first aid')) return 'Safety';
    if (str_contains($n, 'generator')) return 'Generator';
    if (str_contains($n, 'coax') || str_contains($n, 'cable')) return 'Cable';
    return null;
}
function find_member(PDO $pdo, string $email, string $name): ?array {
    $email = strtolower(trim($email));
    if ($email !== '') {
        $row = one($pdo, 'SELECT * FROM members WHERE lower(email)=? LIMIT 1', [$email]);
        if ($row) return $row;
    }
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name !== '') {
        $parts = explode(' ', $name);
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? $parts[count($parts)-1] : '';
        if ($first !== '' && $last !== '') {
            $row = one($pdo, 'SELECT * FROM members WHERE lower(first_name)=lower(?) AND lower(last_name)=lower(?) LIMIT 1', [$first, $last]);
            if ($row) return $row;
        }
        $row = one($pdo, 'SELECT * FROM members WHERE lower(trim(first_name || " " || last_name))=lower(?) LIMIT 1', [$name]);
        if ($row) return $row;
    }
    return null;
}
function import_events(PDO $pdo, string $path, DateTimeZone $localTz, bool $updateExisting, array &$stats): void {
    $rows = read_csv_assoc($path);
    foreach ($rows as $row) {
        $legacyId = clean($row['id'] ?? '');
        $title = clean($row['title'] ?? '');
        if ($title === '') { $stats['events_skipped']++; $stats['warnings'][] = "Skipped event with missing title, legacy id $legacyId"; continue; }
        $start = date_or_null($row['date'] ?? '', $localTz);
        if (!$start) { $stats['events_skipped']++; $stats['warnings'][] = "Skipped event '$title' because date could not be parsed"; continue; }
        $end = date_or_null($row['end_date'] ?? '', $localTz);
        $type = category_from_legacy(clean($row['type'] ?? ''), $title);
        $descParts = [];
        if (clean($row['description'] ?? '') !== '') $descParts[] = clean($row['description']);
        if (clean($row['notes'] ?? '') !== '') $descParts[] = 'Notes: ' . clean($row['notes']);
        if (clean($row['google_calendar_event_id'] ?? '') !== '') $descParts[] = 'Google Calendar ID: ' . clean($row['google_calendar_event_id']);
        if ($legacyId !== '') $descParts[] = 'Legacy event ID: ' . $legacyId;
        $description = implode("\n\n", $descParts);
        $location = clean($row['location'] ?? '');
        $localId = map_get($pdo, 'legacy_events', $legacyId, 'events');
        if ($localId && one($pdo, 'SELECT id FROM events WHERE id=?', [$localId])) {
            if ($updateExisting) { exec_sql($pdo, 'UPDATE events SET title=?, event_type=?, description=?, location=?, start_at=?, end_at=?, updated_at=datetime("now") WHERE id=?', [$title, $type, $description, $location, $start, $end, $localId]); audit($pdo, 'import.legacy_event_updated', 'event', $localId, ['legacy_id'=>$legacyId,'title'=>$title]); $stats['events_updated']++; }
            else $stats['events_skipped']++;
            continue;
        }
        $existing = one($pdo, 'SELECT id FROM events WHERE title=? AND start_at=? LIMIT 1', [$title, $start]);
        if ($existing) {
            $localId = (int)$existing['id']; map_set($pdo, 'legacy_events', $legacyId, 'events', $localId);
            if ($updateExisting) { exec_sql($pdo, 'UPDATE events SET event_type=?, description=?, location=?, end_at=?, updated_at=datetime("now") WHERE id=?', [$type,$description,$location,$end,$localId]); $stats['events_updated']++; }
            else $stats['events_skipped']++;
            continue;
        }
        exec_sql($pdo, 'INSERT INTO events (title,event_type,description,location,start_at,end_at,visibility,max_attendees,created_by_user_id,created_at,updated_at) VALUES (?,?,?,?,?, ?,"members",0,NULL,datetime("now"),datetime("now"))', [$title,$type,$description,$location,$start,$end]);
        $newId = (int)$pdo->lastInsertId(); map_set($pdo, 'legacy_events', $legacyId, 'events', $newId); audit($pdo, 'import.legacy_event_created', 'event', $newId, ['legacy_id'=>$legacyId,'title'=>$title]); $stats['events_added']++;
    }
}
function import_assets(PDO $pdo, string $path, DateTimeZone $localTz, bool $updateExisting, array &$stats): void {
    $rows = read_csv_assoc($path);
    foreach ($rows as $row) {
        $legacyId = clean($row['id'] ?? '');
        $name = clean($row['name'] ?? '');
        if ($name === '') { $stats['assets_skipped']++; $stats['warnings'][] = "Skipped asset with missing name, legacy id $legacyId"; continue; }
        $assetNumber = 'LEGACY-' . ($legacyId !== '' ? substr(preg_replace('/[^a-zA-Z0-9]/', '', $legacyId), 0, 12) : substr(sha1($name), 0, 12));
        $condition = clean($row['condition'] ?? '');
        $cost = money_or_null($row['cost'] ?? '');
        $serial = clean($row['serial_number'] ?? '');
        $location = clean($row['location'] ?? '');
        $purchaseDate = date_or_null($row['purchase_date'] ?? '', $localTz, true);
        $category = asset_category_from_name($name);
        $notesParts = [];
        if (clean($row['description'] ?? '') !== '') $notesParts[] = clean($row['description']);
        if (clean($row['notes'] ?? '') !== '') $notesParts[] = 'Notes: ' . clean($row['notes']);
        if ($legacyId !== '') $notesParts[] = 'Legacy asset ID: ' . $legacyId;
        if (clean($row['created_by'] ?? '') !== '') $notesParts[] = 'Imported created by: ' . clean($row['created_by']);
        $notes = implode("\n\n", $notesParts);
        $localId = map_get($pdo, 'legacy_assets', $legacyId, 'equipment');
        if (!$localId) { $existing = one($pdo, 'SELECT id FROM equipment WHERE asset_number=? LIMIT 1', [$assetNumber]); if ($existing) $localId = (int)$existing['id']; }
        if ($localId && one($pdo, 'SELECT id FROM equipment WHERE id=?', [$localId])) {
            if ($updateExisting) { exec_sql($pdo, 'UPDATE equipment SET name=?,category=?,serial_number_encrypted=?,location=?,condition=?,purchase_date=?,purchase_amount=?,value=?,notes_encrypted=?,updated_at=datetime("now") WHERE id=?', [$name,$category,enc($serial),$location,$condition,$purchaseDate,$cost,$cost,enc($notes),$localId]); map_set($pdo, 'legacy_assets', $legacyId, 'equipment', $localId); audit($pdo,'import.legacy_asset_updated','equipment',$localId,['legacy_id'=>$legacyId,'name'=>$name]); $stats['assets_updated']++; }
            else $stats['assets_skipped']++;
            continue;
        }
        exec_sql($pdo, 'INSERT INTO equipment (asset_number,name,category,manufacturer,model,serial_number_encrypted,location,condition,purchase_date,purchase_amount,value,maintenance_due_at,notes_encrypted,created_at,updated_at) VALUES (?,?,?,NULL,NULL,?,?,?,?,?,?,NULL,?,datetime("now"),datetime("now"))', [$assetNumber,$name,$category,enc($serial),$location,$condition,$purchaseDate,$cost,$cost,enc($notes)]);
        $newId = (int)$pdo->lastInsertId(); map_set($pdo, 'legacy_assets', $legacyId, 'equipment', $newId); audit($pdo,'import.legacy_asset_created','equipment',$newId,['legacy_id'=>$legacyId,'name'=>$name]); $stats['assets_added']++;
    }
}
function import_attendance(PDO $pdo, string $path, bool $updateExisting, bool $skipUnmatchedMembers, array &$stats): void {
    $rows = read_csv_assoc($path);
    foreach ($rows as $row) {
        $legacyEventId = clean($row['event_id'] ?? '');
        $eventId = map_get($pdo, 'legacy_events', $legacyEventId, 'events');
        if (!$eventId || !one($pdo, 'SELECT id FROM events WHERE id=?', [$eventId])) { $stats['attendance_skipped']++; $stats['warnings'][] = "Skipped attendance row because event legacy id was not mapped: $legacyEventId"; continue; }
        $present = boolish($row['present'] ?? '');
        $isGuest = boolish($row['is_guest'] ?? '');
        $guestName = clean($row['guest_name'] ?? '');
        $userName = clean($row['user_name'] ?? '');
        $email = clean($row['user_email'] ?? '');
        $notes = clean($row['guest_notes'] ?? '');
        $legacyAttendanceId = clean($row['id'] ?? '');
        if ($isGuest || $guestName !== '') {
            $name = $guestName !== '' ? $guestName : ($userName !== '' ? $userName : 'Guest');
            $existing = one($pdo, 'SELECT id FROM event_guests WHERE event_id=? AND lower(name)=lower(?) LIMIT 1', [$eventId,$name]);
            $comment = trim(($notes ? $notes . "\n" : '') . ($legacyAttendanceId ? 'Legacy attendance ID: ' . $legacyAttendanceId : ''));
            if ($existing) { if ($updateExisting) { exec_sql($pdo, 'UPDATE event_guests SET attended=?,comment_encrypted=?,updated_at=datetime("now") WHERE id=?', [$present?1:0,enc($comment),(int)$existing['id']]); audit($pdo,'import.legacy_guest_attendance_updated','event_guest',(int)$existing['id'],['event_id'=>$eventId,'name'=>$name,'present'=>$present]); $stats['guests_updated']++; } else $stats['attendance_skipped']++; }
            else { exec_sql($pdo, 'INSERT INTO event_guests (event_id,name,comment_encrypted,attended,added_by_user_id,created_at,updated_at) VALUES (?,?,?,?,NULL,datetime("now"),datetime("now"))', [$eventId,$name,enc($comment),$present?1:0]); $newId=(int)$pdo->lastInsertId(); audit($pdo,'import.legacy_guest_attendance_created','event_guest',$newId,['event_id'=>$eventId,'name'=>$name,'present'=>$present]); $stats['guests_added']++; }
            continue;
        }
        $member = find_member($pdo,$email,$userName);
        if (!$member) {
            if ($skipUnmatchedMembers) { $stats['attendance_skipped']++; $stats['warnings'][] = "Skipped unmatched member attendance: $userName <$email>"; continue; }
            $name = $userName !== '' ? $userName : ($email !== '' ? $email : 'Imported unmatched member');
            $comment = trim("Imported as guest because no matching member was found." . ($email !== '' ? "\nEmail: $email" : '') . ($notes !== '' ? "\nNotes: $notes" : '') . ($legacyAttendanceId !== '' ? "\nLegacy attendance ID: $legacyAttendanceId" : ''));
            $existing = one($pdo, 'SELECT id FROM event_guests WHERE event_id=? AND lower(name)=lower(?) LIMIT 1', [$eventId,$name]);
            if ($existing) { if ($updateExisting) { exec_sql($pdo, 'UPDATE event_guests SET attended=?,comment_encrypted=?,updated_at=datetime("now") WHERE id=?', [$present?1:0,enc($comment),(int)$existing['id']]); $stats['guests_updated']++; } else $stats['attendance_skipped']++; }
            else { exec_sql($pdo, 'INSERT INTO event_guests (event_id,name,comment_encrypted,attended,added_by_user_id,created_at,updated_at) VALUES (?,?,?,?,NULL,datetime("now"),datetime("now"))', [$eventId,$name,enc($comment),$present?1:0]); $stats['guests_added']++; }
            $stats['warnings'][] = "Imported unmatched member attendance as guest: $name <$email>";
            continue;
        }
        $memberId = (int)$member['id'];
        $status = $present ? 'attended' : 'signed_up';
        $existing = one($pdo, 'SELECT id FROM event_attendance WHERE event_id=? AND member_id=? LIMIT 1', [$eventId,$memberId]);
        $note = trim(($notes ? $notes . "\n" : '') . ($legacyAttendanceId ? 'Legacy attendance ID: ' . $legacyAttendanceId : ''));
        if ($existing) { if ($updateExisting) { exec_sql($pdo, 'UPDATE event_attendance SET status=?,attended=?,marked_at=datetime("now"),marked_by_user_id=NULL,notes_encrypted=?,updated_at=datetime("now") WHERE id=?', [$status,$present?1:0,enc($note),(int)$existing['id']]); audit($pdo,'import.legacy_attendance_updated','event_attendance',(int)$existing['id'],['event_id'=>$eventId,'member_id'=>$memberId,'present'=>$present]); $stats['attendance_updated']++; } else $stats['attendance_skipped']++; }
        else { exec_sql($pdo, 'INSERT INTO event_attendance (event_id,member_id,status,attended,signed_up_at,marked_at,marked_by_user_id,notes_encrypted,created_at,updated_at) VALUES (?,?,?,?,NULL,datetime("now"),NULL,?,datetime("now"),datetime("now"))', [$eventId,$memberId,$status,$present?1:0,enc($note)]); $newId=(int)$pdo->lastInsertId(); audit($pdo,'import.legacy_attendance_created','event_attendance',$newId,['event_id'=>$eventId,'member_id'=>$memberId,'present'=>$present]); $stats['attendance_added']++; }
    }
}
ensure_import_tables($pdo);
echo "CADARS legacy CSV importer\n";
echo "Database: " . DB_PATH . "\n";
echo "Timezone: $tzName\n";
echo $dryRun ? "DRY RUN: changes will be rolled back.\n\n" : "LIVE IMPORT: changes will be committed.\n\n";
$pdo->beginTransaction();
try {
    if (!empty($options['events'])) { echo "Importing events from {$options['events']}...\n"; import_events($pdo, (string)$options['events'], $localTz, $updateExisting, $stats); }
    if (!empty($options['assets'])) { echo "Importing assets from {$options['assets']}...\n"; import_assets($pdo, (string)$options['assets'], $localTz, $updateExisting, $stats); }
    if (!empty($options['attendance'])) { echo "Importing attendance from {$options['attendance']}...\n"; import_attendance($pdo, (string)$options['attendance'], $updateExisting, $skipUnmatchedMembers, $stats); }
    audit($pdo, 'import.legacy_csv_complete', null, null, $stats);
    if ($dryRun) { $pdo->rollBack(); echo "\nDry run complete. Rolled back all changes.\n"; }
    else { $pdo->commit(); echo "\nImport complete. Changes committed.\n"; }
    echo "\nSummary:\n";
    foreach ($stats as $k=>$v) { if ($k==='warnings') continue; echo "  " . str_pad($k,24) . " " . $v . "\n"; }
    if ($stats['warnings']) { echo "\nWarnings (" . count($stats['warnings']) . "):\n"; foreach (array_slice($stats['warnings'],0,40) as $w) echo "  - $w\n"; if (count($stats['warnings'])>40) echo "  ... " . (count($stats['warnings'])-40) . " more warnings not shown.\n"; }
    exit(0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, "All changes rolled back.\n");
    exit(1);
}
