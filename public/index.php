<?php
session_start();

define('BASE_PATH', dirname(__DIR__));
define('DB_PATH', BASE_PATH . '/database/app.sqlite');
define('LOCK_PATH', BASE_PATH . '/storage/installed.lock');
define('CONFIG_PATH', BASE_PATH . '/storage/app_config.php');
define('PRIVATE_PATH', BASE_PATH . '/storage/private');

if (!is_dir(BASE_PATH . '/database')) mkdir(BASE_PATH . '/database', 0750, true);
if (!is_dir(PRIVATE_PATH)) mkdir(PRIVATE_PATH, 0750, true);

function app_config(): array {
    $defaults = [
        'app_key' => null,
        'society_name' => 'Ham Radio Society',
        'base_url' => '',
        'email_from_name' => 'Membership System',
        'email_from_address' => '',
        'email_reply_to' => '',
        'email_method' => 'php_mail',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_security' => 'tls',
        'smtp_username' => '',
        'smtp_password' => '',
        'resend_api_key' => '',
    ];
    if (file_exists(CONFIG_PATH)) {
        $cfg = include CONFIG_PATH;
        if (is_array($cfg)) return array_merge($defaults, $cfg);
    }
    return $defaults;
}
function save_app_config(array $updates): void {
    $cfg = array_merge(app_config(), $updates);
    file_put_contents(CONFIG_PATH, '<?php return ' . var_export($cfg, true) . ';');
}

function send_configured_email(array $cfg, array $to, array $bcc, string $subject, string $html, string $fromName, string $fromAddress, string $replyTo): array {
    $method = $cfg['email_method'] ?? 'php_mail';
    $to = array_values(array_filter(array_map('trim', $to)));
    $bcc = array_values(array_filter(array_map('trim', $bcc)));
    $subject = str_replace(["\r","\n"], '', $subject);
    $fromName = str_replace(["\r","\n"], '', $fromName);
    $fromAddress = str_replace(["\r","\n"], '', $fromAddress);
    $replyTo = str_replace(["\r","\n"], '', $replyTo);

    if (!$to) return ['ok' => false, 'error' => 'No To recipient supplied.'];

    if ($method === 'resend') {
        $apiKey = trim((string)($cfg['resend_api_key'] ?? ''));
        if ($apiKey === '') return ['ok' => false, 'error' => 'Resend API key is not configured.'];

        $payload = [
            'from' => $fromName ? ($fromName . ' <' . $fromAddress . '>') : $fromAddress,
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
        ];
        if ($bcc) $payload['bcc'] = $bcc;
        if ($replyTo) $payload['reply_to'] = $replyTo;

        $json = json_encode($payload);
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init('https://api.resend.com/emails');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) return ['ok' => false, 'error' => $error ?: 'Resend cURL request failed.'];
            if ($status >= 200 && $status < 300) return ['ok' => true, 'error' => null, 'provider_response' => $response];
            return ['ok' => false, 'error' => 'Resend API error HTTP ' . $status . ': ' . substr((string)$response, 0, 500)];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $json,
                'timeout' => 30,
                'ignore_errors' => true,
            ]
        ]);
        $response = @file_get_contents('https://api.resend.com/emails', false, $context);
        $status = 0;
        if (!empty($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', $h, $m)) { $status = (int)$m[1]; break; }
            }
        }
        if ($response !== false && $status >= 200 && $status < 300) return ['ok' => true, 'error' => null, 'provider_response' => $response];
        return ['ok' => false, 'error' => 'Resend API request failed' . ($status ? ' HTTP ' . $status : '') . ': ' . substr((string)$response, 0, 500)];
    }

    $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\n";
    $headers .= 'From: ' . $fromName . ' <' . $fromAddress . ">\r\n";
    $headers .= 'Reply-To: ' . $replyTo . "\r\n";
    if ($bcc) $headers .= 'Bcc: ' . implode(', ', $bcc) . "\r\n";

    $ok = @mail(implode(', ', $to), $subject, $html, $headers);
    return ['ok' => (bool)$ok, 'error' => $ok ? null : 'mail() failed or is not configured.'];
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function redirect(string $route): void {
    // Allow internal routes with query parameters, e.g. event_view&id=3.
    if (str_contains($route, '&') || str_contains($route, '=')) header('Location: ?route=' . $route);
    else header('Location: ?route=' . urlencode($route));
    exit;
}
function route(): string { return $_GET['route'] ?? 'dashboard'; }
function installed(): bool { return file_exists(LOCK_PATH); }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function require_csrf(): void { if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) { http_response_code(403); exit('Invalid CSRF token'); } }

function app_key(): string {
    $cfg = app_config();
    if (!empty($cfg['app_key'])) return base64_decode($cfg['app_key']);
    return str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
}
function encrypt_value(?string $plain): ?string {
    if ($plain === null || $plain === '') return $plain;
    if (!function_exists('sodium_crypto_secretbox')) return base64_encode($plain);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain, $nonce, app_key());
    return 'enc:' . base64_encode($nonce . $cipher);
}
function decrypt_value(?string $cipher): ?string {
    if ($cipher === null || $cipher === '') return $cipher;
    if (!str_starts_with($cipher, 'enc:')) return base64_decode($cipher, true) ?: $cipher;
    if (!function_exists('sodium_crypto_secretbox_open')) return '';
    $raw = base64_decode(substr($cipher, 4));
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ct = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($ct, $nonce, app_key());
    return $plain === false ? '[decrypt failed]' : $plain;
}

function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND status = "active"');
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
}
function require_login(): array { $u = current_user(); if (!$u) redirect('login'); return $u; }
function user_roles(int $user_id): array {
    $stmt = db()->prepare('SELECT r.name FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ? AND (ur.expires_at IS NULL OR ur.expires_at > datetime("now"))');
    $stmt->execute([$user_id]);
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
}
function user_permissions(int $user_id): array {
    $stmt = db()->prepare('SELECT DISTINCT p.name FROM permissions p JOIN role_permissions rp ON rp.permission_id = p.id JOIN user_roles ur ON ur.role_id = rp.role_id WHERE ur.user_id = ? AND (ur.expires_at IS NULL OR ur.expires_at > datetime("now"))');
    $stmt->execute([$user_id]);
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
}
function has_permission(string $permission): bool {
    $u = current_user(); if (!$u) return false;
    return in_array($permission, user_permissions((int)$u['id']), true);
}
function require_permission(string $permission): void {
    $u = require_login();
    if (!has_permission($permission)) {
        audit('permission.denied', null, null, 'denied', 'missing_permission:' . $permission);
        http_response_code(403);
        page_header('Access denied');
        echo '<div class="card"><h2>Access denied</h2><p>You do not have permission to access this section.</p></div>';
        page_footer();
        exit;
    }
}
function client_ip(): ?string {
    // Cloudflare Tunnel / Cloudflare proxy aware client IP handling.
    // Prefer CF-Connecting-IP, then True-Client-IP, then first valid X-Forwarded-For IP, then REMOTE_ADDR.
    $candidates = [];
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_TRUE_CLIENT_IP'])) $candidates[] = $_SERVER['HTTP_TRUE_CLIENT_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $ip) $candidates[] = trim($ip);
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) $candidates[] = $_SERVER['REMOTE_ADDR'];
    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return $_SERVER['REMOTE_ADDR'] ?? null;
}
function request_ip_metadata(): array {
    return [
        'client_ip_source' => !empty($_SERVER['HTTP_CF_CONNECTING_IP']) ? 'cf_connecting_ip' : (!empty($_SERVER['HTTP_TRUE_CLIENT_IP']) ? 'true_client_ip' : (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? 'x_forwarded_for' : 'remote_addr')),
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
        'cf_connecting_ip' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        'true_client_ip' => $_SERVER['HTTP_TRUE_CLIENT_IP'] ?? null,
        'x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        'cf_ray' => $_SERVER['HTTP_CF_RAY'] ?? null,
        'cf_ipcountry' => $_SERVER['HTTP_CF_IPCOUNTRY'] ?? null,
    ];
}

function audit(string $action, ?string $entity_type=null, ?int $entity_id=null, string $result='success', ?string $reason=null, array $metadata=[]): void {
    if (!installed() || !file_exists(DB_PATH)) return;
    $u = current_user();
    try {
        $stmt = db()->prepare('INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, result, reason, ip_address, user_agent, session_id, metadata, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime("now"))');
        $stmt->execute([
            $u['id'] ?? null,
            $action,
            $entity_type,
            $entity_id,
            $result,
            $reason,
            client_ip(),
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            session_id(),
            json_encode(array_filter(array_merge($metadata, ['ip_details' => request_ip_metadata()]), fn($v) => $v !== null && $v !== []))
        ]);
    } catch (Throwable $e) { /* never break app because logging failed */ }
}
function first(string $sql, array $args=[]): ?array { $s=db()->prepare($sql); $s->execute($args); $r=$s->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
function all(string $sql, array $args=[]): array { $s=db()->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC); }
function exec_sql(string $sql, array $args=[]): void { $s=db()->prepare($sql); $s->execute($args); }
function csv_download(string $filename, array $headers, array $rows): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "ï»¿";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}
function emergency_contact_summary(array $m): string {
    $name = decrypt_value($m['emergency_contact_name_encrypted'] ?? '') ?: '';
    $rel = decrypt_value($m['emergency_contact_relationship_encrypted'] ?? '') ?: '';
    $phone = decrypt_value($m['emergency_contact_phone_encrypted'] ?? '') ?: '';
    if ($name || $rel || $phone) return trim($name . ($rel ? ' (' . $rel . ')' : '') . ($phone ? ' - ' . $phone : ''));
    return decrypt_value($m['emergency_contact_encrypted'] ?? '') ?: '';
}
function record_action_history(int $action_id, string $type, string $note='', array $changes=[]): void {
    $u = current_user();
    exec_sql('INSERT INTO committee_action_updates (action_id, update_type, update_encrypted, changes_json, created_by_user_id, created_at) VALUES (?,?,?,?,?,datetime("now"))', [
        $action_id,
        $type,
        encrypt_value($note),
        $changes ? json_encode($changes) : null,
        $u['id'] ?? null
    ]);
}
function has_role(string $role): bool { $u=current_user(); return $u ? in_array($role, user_roles((int)$u['id']), true) : false; }
function is_committee_or_admin(): bool { return has_role('committee') || has_role('admin'); }
function can_manage_events(): bool { return is_committee_or_admin(); }
function event_categories(): array {
    return ['Club Night','Shack Night','Field Day','Special Event Station','Rallies','Talk','Social','Other'];
}
function event_category_options(?string $selected = null): string {
    $html = '';
    foreach (event_categories() as $cat) {
        $html .= '<option value="' . e($cat) . '" ' . ($selected === $cat ? 'selected' : '') . '>' . e($cat) . '</option>';
    }
    if ($selected && !in_array($selected, event_categories(), true)) {
        $html = '<option value="' . e($selected) . '" selected>' . e($selected) . '</option>' . $html;
    }
    return $html;
}

function event_attendance_counts(int $event_id): array {
    $member = first('SELECT COUNT(*) total, SUM(CASE WHEN attended=1 THEN 1 ELSE 0 END) attended FROM event_attendance WHERE event_id=?', [$event_id]) ?: ['total'=>0,'attended'=>0];
    $guest = first('SELECT COUNT(*) total, SUM(CASE WHEN attended=1 THEN 1 ELSE 0 END) attended FROM event_guests WHERE event_id=?', [$event_id]) ?: ['total'=>0,'attended'=>0];
    return [
        'member_total' => (int)($member['total'] ?? 0),
        'member_attended' => (int)($member['attended'] ?? 0),
        'guest_total' => (int)($guest['total'] ?? 0),
        'guest_attended' => (int)($guest['attended'] ?? 0),
        'total_listed' => (int)($member['total'] ?? 0) + (int)($guest['total'] ?? 0),
        'total_attended' => (int)($member['attended'] ?? 0) + (int)($guest['attended'] ?? 0),
    ];
}
function percent_display($num, $den): string {
    $den = (int)$den;
    if ($den <= 0) return 'N/A';
    return round(((float)$num / $den) * 100, 1) . '%';
}

function table_has_column(string $table, string $column): bool {
    try {
        $rows = db()->query('PRAGMA table_info(' . preg_replace('/[^A-Za-z0-9_]/', '', $table) . ')')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) if (($row['name'] ?? '') === $column) return true;
    } catch (Throwable $e) { return false; }
    return false;
}
function ensure_schema_updates(): void {
    if (!table_has_column('members', 'joined_before_system')) {
        db()->exec('ALTER TABLE members ADD COLUMN joined_before_system INTEGER NOT NULL DEFAULT 0');
    }
    if (!table_has_column('members', 'emergency_contact_name_encrypted')) {
        db()->exec('ALTER TABLE members ADD COLUMN emergency_contact_name_encrypted TEXT NULL');
    }
    if (!table_has_column('members', 'emergency_contact_relationship_encrypted')) {
        db()->exec('ALTER TABLE members ADD COLUMN emergency_contact_relationship_encrypted TEXT NULL');
    }
    if (!table_has_column('members', 'emergency_contact_phone_encrypted')) {
        db()->exec('ALTER TABLE members ADD COLUMN emergency_contact_phone_encrypted TEXT NULL');
    }
    if (!table_has_column('equipment', 'purchase_date')) {
        db()->exec('ALTER TABLE equipment ADD COLUMN purchase_date TEXT NULL');
    }
    if (!table_has_column('equipment', 'purchase_amount')) {
        db()->exec('ALTER TABLE equipment ADD COLUMN purchase_amount REAL NULL');
    }
    db()->exec('CREATE TABLE IF NOT EXISTS event_guests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        comment_encrypted TEXT NULL,
        attended INTEGER NOT NULL DEFAULT 0,
        added_by_user_id INTEGER NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    db()->exec('CREATE TABLE IF NOT EXISTS equipment_maintenance_tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        equipment_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "open",
        priority TEXT NOT NULL DEFAULT "normal",
        due_date TEXT NULL,
        description_encrypted TEXT NULL,
        action_taken_encrypted TEXT NULL,
        cost REAL NULL,
        assigned_user_id INTEGER NULL,
        created_by_user_id INTEGER NULL,
        closed_at TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    db()->exec('CREATE TABLE IF NOT EXISTS committee_actions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "open",
        priority TEXT NOT NULL DEFAULT "normal",
        action_required TEXT NOT NULL,
        description_encrypted TEXT NULL,
        due_date TEXT NULL,
        assigned_user_id INTEGER NULL,
        assigned_member_id INTEGER NULL,
        created_by_user_id INTEGER NULL,
        completed_at TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    db()->exec('CREATE TABLE IF NOT EXISTS committee_action_updates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        action_id INTEGER NOT NULL,
        update_type TEXT NOT NULL DEFAULT "note",
        update_encrypted TEXT NULL,
        changes_json TEXT NULL,
        created_by_user_id INTEGER NULL,
        created_at TEXT NOT NULL
    )');
}
function is_admin_user(): bool { return has_role('admin'); }
function can_edit_membership_number(): bool { return is_admin_user(); }
function member_joined_display(array $m): string {
    if (!empty($m['joined_before_system']) && empty($m['date_joined'])) return 'Not on record - joined before system';
    if (!empty($m['joined_before_system'])) return ($m['date_joined'] ?: 'Not on record') . ' - joined before system';
    return $m['date_joined'] ?: 'Not on record';
}
function consent_labels(): array {
    return [
        'email_comms' => 'Email communications',
        'text_comms' => 'Text messages',
        'whatsapp_community' => 'Opt into WhatsApp community'
    ];
}
function get_member_consent(int $member_id, string $type): bool {
    $row = first('SELECT granted FROM member_consents WHERE member_id=? AND consent_type=? ORDER BY updated_at DESC, id DESC LIMIT 1', [$member_id, $type]);
    return $row ? (bool)$row['granted'] : false;
}
function set_member_consent(int $member_id, string $type, bool $granted, ?int $by_user_id=null): void {
    $existing = first('SELECT id FROM member_consents WHERE member_id=? AND consent_type=? ORDER BY id DESC LIMIT 1', [$member_id, $type]);
    if ($existing) {
        exec_sql('UPDATE member_consents SET granted=?, granted_at=CASE WHEN ?=1 THEN COALESCE(granted_at, datetime("now")) ELSE granted_at END, withdrawn_at=CASE WHEN ?=0 THEN datetime("now") ELSE NULL END, recorded_by_user_id=?, updated_at=datetime("now") WHERE id=?', [$granted?1:0, $granted?1:0, $granted?1:0, $by_user_id, $existing['id']]);
    } else {
        exec_sql('INSERT INTO member_consents (member_id, consent_type, granted, granted_at, withdrawn_at, recorded_by_user_id, created_at, updated_at) VALUES (?, ?, ?, CASE WHEN ?=1 THEN datetime("now") ELSE NULL END, CASE WHEN ?=0 THEN datetime("now") ELSE NULL END, ?, datetime("now"), datetime("now"))', [$member_id, $type, $granted?1:0, $granted?1:0, $granted?1:0, $by_user_id]);
    }
}
function render_consent_checkboxes(int $member_id): string {
    $html = '';
    foreach (consent_labels() as $type=>$label) {
        $html .= '<label><input type="checkbox" name="consent_' . e($type) . '" ' . (get_member_consent($member_id, $type) ? 'checked' : '') . '> ' . e($label) . '</label>';
    }
    return $html;
}
function save_consent_post(int $member_id, ?int $by_user_id): void {
    foreach (consent_labels() as $type=>$label) set_member_consent($member_id, $type, isset($_POST['consent_' . $type]), $by_user_id);
}

function page_header(string $title): void {
    $u = current_user(); $cfg = app_config();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title><link rel="stylesheet" href="?route=assets.css"></head><body>';
    echo '<header><div><strong>' . e($cfg['society_name'] ?? 'Ham Radio Society') . '</strong><span>Membership System</span></div>';
    if ($u) {
        $displayName = 'My account';
        if (installed()) {
            try {
                $member = !empty($u['member_id']) ? first('SELECT first_name,last_name,callsign FROM members WHERE id=?', [(int)$u['member_id']]) : null;
                if ($member) {
                    $displayName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
                    if (!$displayName && !empty($member['callsign'])) $displayName = $member['callsign'];
                }
            } catch (Throwable $e) { /* profile name is cosmetic only */ }
        }
        echo '<nav class="main-nav">';
        echo '<a href="?route=dashboard">Dashboard</a><a href="?route=events">Programme</a><a href="?route=directory">Directory</a><a href="?route=brickworks">Brickworks</a>';
        if (is_committee_or_admin()) {
            echo '<div class="dropdown"><button type="button" class="nav-drop" aria-haspopup="true">Committee ▾</button><div class="dropdown-menu">';
            if (has_permission('view_membership_db')) echo '<a href="?route=members">Members</a>';
            if (has_permission('track_attendance')) echo '<a href="?route=attendance">Attendance</a><a href="?route=attendance_stats">Attendance stats</a>';
            if (has_permission('send_member_emails')) echo '<a href="?route=emails">Emails</a>';
            if (has_permission('view_equipment')) echo '<a href="?route=equipment">Equipment / assets</a>';
            if (has_permission('view_committee_actions')) echo '<a href="?route=committee_actions">Actions</a>';
            if (has_permission('manage_users')) echo '<a href="?route=users">Users</a>';
            if (has_permission('view_audit_logs')) echo '<a href="?route=audit">Audit logs</a>';
            echo '</div></div>';
        }
        echo '<div class="dropdown user-menu"><button type="button" class="nav-drop">' . e($displayName) . ' ▾</button><div class="dropdown-menu dropdown-right"><a href="?route=profile">My Profile</a><a href="?route=logout">Logout</a></div></div>';
        echo '</nav>';
    }
    echo '</header><main>';
    if (!empty($_SESSION['flash'])) { echo '<div class="flash">' . e($_SESSION['flash']) . '</div>'; unset($_SESSION['flash']); }
}
function page_footer(): void {
    echo <<<'HTML'
</main><footer>Audit logging enabled • Private uploads are stored outside web root • Open tracking is opt-in only</footer>
<script>
document.querySelectorAll('.nav-drop').forEach(function(btn){
  btn.addEventListener('click', function(e){
    var d = btn.closest('.dropdown');
    if (!d) return;
    e.preventDefault();
    document.querySelectorAll('.dropdown.open').forEach(function(x){ if (x !== d) x.classList.remove('open'); });
    d.classList.toggle('open');
  });
});
document.addEventListener('click', function(e){
  if (!e.target.closest('.dropdown')) document.querySelectorAll('.dropdown.open').forEach(function(x){ x.classList.remove('open'); });
});
</script></body></html>
HTML;
}
function flash(string $msg): void { $_SESSION['flash'] = $msg; }

function create_schema(): void {
    $pdo = db();
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 member_id INTEGER NULL,
 email TEXT NOT NULL UNIQUE,
 password_hash TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'active',
 force_password_change INTEGER NOT NULL DEFAULT 0,
 two_factor_enabled INTEGER NOT NULL DEFAULT 0,
 last_login_at TEXT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS roles (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 name TEXT NOT NULL UNIQUE,
 display_name TEXT NOT NULL,
 description TEXT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS permissions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 name TEXT NOT NULL UNIQUE,
 description TEXT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS role_permissions (role_id INTEGER NOT NULL, permission_id INTEGER NOT NULL, PRIMARY KEY(role_id, permission_id));
CREATE TABLE IF NOT EXISTS user_roles (
 user_id INTEGER NOT NULL,
 role_id INTEGER NOT NULL,
 assigned_by_user_id INTEGER NULL,
 assigned_at TEXT NOT NULL,
 expires_at TEXT NULL,
 PRIMARY KEY(user_id, role_id)
);
CREATE TABLE IF NOT EXISTS user_role_history (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL,
 role_id INTEGER NOT NULL,
 action TEXT NOT NULL,
 changed_by_user_id INTEGER NULL,
 changed_at TEXT NOT NULL,
 reason TEXT NULL
);
CREATE TABLE IF NOT EXISTS members (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 membership_number TEXT NULL UNIQUE,
 first_name TEXT NOT NULL,
 last_name TEXT NOT NULL,
 callsign TEXT NULL,
 licence_level TEXT NULL,
 email TEXT NOT NULL,
 phone_encrypted TEXT NULL,
 address_encrypted TEXT NULL,
 emergency_contact_encrypted TEXT NULL,
 emergency_contact_name_encrypted TEXT NULL,
 emergency_contact_relationship_encrypted TEXT NULL,
 emergency_contact_phone_encrypted TEXT NULL,
 date_joined TEXT NULL,
 joined_before_system INTEGER NOT NULL DEFAULT 0,
 date_left TEXT NULL,
 renewal_date TEXT NULL,
 membership_status TEXT NOT NULL DEFAULT 'active',
 membership_type TEXT NULL,
 notes_encrypted TEXT NULL,
 data_last_confirmed_at TEXT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS member_consents (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 member_id INTEGER NOT NULL,
 consent_type TEXT NOT NULL,
 granted INTEGER NOT NULL DEFAULT 0,
 privacy_notice_version TEXT NULL,
 granted_at TEXT NULL,
 withdrawn_at TEXT NULL,
 recorded_by_user_id INTEGER NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS member_directory_preferences (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 member_id INTEGER NOT NULL UNIQUE,
 show_callsign INTEGER NOT NULL DEFAULT 0,
 show_first_name INTEGER NOT NULL DEFAULT 0,
 show_surname INTEGER NOT NULL DEFAULT 0,
 show_licence_level INTEGER NOT NULL DEFAULT 0,
 show_email INTEGER NOT NULL DEFAULT 0,
 show_phone INTEGER NOT NULL DEFAULT 0,
 consent_given_at TEXT NULL,
 consent_updated_at TEXT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS member_email_preferences (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 member_id INTEGER NOT NULL UNIQUE,
 receive_admin_emails INTEGER NOT NULL DEFAULT 1,
 receive_subs_emails INTEGER NOT NULL DEFAULT 1,
 receive_event_emails INTEGER NOT NULL DEFAULT 1,
 receive_newsletter_emails INTEGER NOT NULL DEFAULT 1,
 receive_brickworks_emails INTEGER NOT NULL DEFAULT 1,
 allow_open_tracking INTEGER NOT NULL DEFAULT 0,
 open_tracking_consented_at TEXT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS subscription_payments (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 member_id INTEGER NOT NULL,
 subscription_year INTEGER NOT NULL,
 amount_due REAL NOT NULL,
 amount_paid REAL NOT NULL DEFAULT 0,
 payment_date TEXT NULL,
 payment_method TEXT NULL,
 payment_reference TEXT NULL,
 receipt_number TEXT NULL,
 status TEXT NOT NULL DEFAULT 'unpaid',
 recorded_by_user_id INTEGER NULL,
 notes_encrypted TEXT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS member_status_history (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 member_id INTEGER NOT NULL,
 old_status TEXT NULL,
 new_status TEXT NOT NULL,
 changed_by_user_id INTEGER NULL,
 changed_at TEXT NOT NULL,
 reason TEXT NULL
);
CREATE TABLE IF NOT EXISTS events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 title TEXT NOT NULL,
 event_type TEXT NULL,
 description TEXT NULL,
 location TEXT NULL,
 start_at TEXT NOT NULL,
 end_at TEXT NULL,
 visibility TEXT NOT NULL DEFAULT 'members',
 max_attendees INTEGER NULL,
 created_by_user_id INTEGER NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS event_attachments (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 event_id INTEGER NOT NULL,
 original_filename TEXT NOT NULL,
 stored_filename TEXT NOT NULL,
 mime_type TEXT NOT NULL,
 file_size INTEGER NOT NULL,
 uploaded_by_user_id INTEGER NOT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS event_attendance (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 event_id INTEGER NOT NULL,
 member_id INTEGER NOT NULL,
 status TEXT NOT NULL DEFAULT 'signed_up',
 attended INTEGER NULL,
 signed_up_at TEXT NULL,
 marked_at TEXT NULL,
 marked_by_user_id INTEGER NULL,
 notes_encrypted TEXT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL,
 UNIQUE(event_id, member_id)
);
CREATE TABLE IF NOT EXISTS event_guests (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 event_id INTEGER NOT NULL,
 name TEXT NOT NULL,
 comment_encrypted TEXT NULL,
 attended INTEGER NOT NULL DEFAULT 0,
 added_by_user_id INTEGER NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS equipment (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 asset_number TEXT NOT NULL UNIQUE,
 name TEXT NOT NULL,
 category TEXT NULL,
 manufacturer TEXT NULL,
 model TEXT NULL,
 serial_number_encrypted TEXT NULL,
 location TEXT NULL,
 condition TEXT NULL,
 purchase_date TEXT NULL,
 purchase_amount REAL NULL,
 value REAL NULL,
 maintenance_due_at TEXT NULL,
 notes_encrypted TEXT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS equipment_loans (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 equipment_id INTEGER NOT NULL,
 member_id INTEGER NOT NULL,
 borrowed_at TEXT NOT NULL,
 due_back_at TEXT NULL,
 returned_at TEXT NULL,
 condition_out TEXT NULL,
 condition_returned TEXT NULL,
 approved_by_user_id INTEGER NULL,
 notes_encrypted TEXT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS maintenance_logs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 equipment_id INTEGER NOT NULL,
 work_date TEXT NOT NULL,
 fault TEXT NULL,
 work_done TEXT NOT NULL,
 cost REAL NULL,
 completed_by_user_id INTEGER NULL,
 next_due_at TEXT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS equipment_maintenance_tickets (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 equipment_id INTEGER NOT NULL,
 title TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'open',
 priority TEXT NOT NULL DEFAULT 'normal',
 due_date TEXT NULL,
 description_encrypted TEXT NULL,
 action_taken_encrypted TEXT NULL,
 cost REAL NULL,
 assigned_user_id INTEGER NULL,
 created_by_user_id INTEGER NULL,
 closed_at TEXT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS committee_actions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 title TEXT NOT NULL,
 status TEXT NOT NULL DEFAULT 'open',
 priority TEXT NOT NULL DEFAULT 'normal',
 action_required TEXT NOT NULL,
 description_encrypted TEXT NULL,
 due_date TEXT NULL,
 assigned_user_id INTEGER NULL,
 assigned_member_id INTEGER NULL,
 created_by_user_id INTEGER NULL,
 completed_at TEXT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS committee_action_updates (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 action_id INTEGER NOT NULL,
 update_type TEXT NOT NULL DEFAULT 'note',
 update_encrypted TEXT NULL,
 changes_json TEXT NULL,
 created_by_user_id INTEGER NULL,
 created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS brickworks_themes (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 name TEXT NOT NULL,
 description TEXT NULL,
 sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS brickworks_criteria (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 theme_id INTEGER NOT NULL,
 title TEXT NOT NULL,
 description TEXT NOT NULL,
 evidence_guidance TEXT NULL,
 active INTEGER NOT NULL DEFAULT 1,
 sort_order INTEGER NOT NULL DEFAULT 0,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS brickworks_participants (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 member_id INTEGER NOT NULL UNIQUE,
 status TEXT NOT NULL DEFAULT 'active',
 joined_at TEXT NOT NULL,
 completed_at TEXT NULL,
 current_award TEXT NULL,
 notes_encrypted TEXT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS brickworks_progress (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 participant_id INTEGER NOT NULL,
 criterion_id INTEGER NOT NULL,
 status TEXT NOT NULL DEFAULT 'not_completed',
 member_comment_encrypted TEXT NULL,
 reviewer_comment_encrypted TEXT NULL,
 started_at TEXT NULL,
 submitted_at TEXT NULL,
 completed_at TEXT NULL,
 reviewed_by_user_id INTEGER NULL,
 reviewed_at TEXT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL,
 UNIQUE(participant_id, criterion_id)
);
CREATE TABLE IF NOT EXISTS brickworks_evidence (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 progress_id INTEGER NOT NULL,
 uploaded_by_user_id INTEGER NOT NULL,
 original_filename TEXT NOT NULL,
 stored_filename TEXT NOT NULL,
 mime_type TEXT NOT NULL,
 file_size INTEGER NOT NULL,
 encrypted_file_path TEXT NOT NULL,
 evidence_comment_encrypted TEXT NULL,
 status TEXT NOT NULL DEFAULT 'submitted',
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS brickworks_awards (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 participant_id INTEGER NOT NULL,
 award_level TEXT NOT NULL,
 awarded_at TEXT NOT NULL,
 awarded_by_user_id INTEGER NULL,
 certificate_reference TEXT NULL,
 created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS emails (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 subject TEXT NOT NULL,
 body_html TEXT NOT NULL,
 body_text TEXT NULL,
 status TEXT NOT NULL DEFAULT 'draft',
 category TEXT NULL,
 created_by_user_id INTEGER NOT NULL,
 sent_at TEXT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS email_recipients (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 email_id INTEGER NOT NULL,
 member_id INTEGER NULL,
 user_id INTEGER NULL,
 email_address TEXT NOT NULL,
 recipient_name TEXT NULL,
 tracking_enabled INTEGER NOT NULL DEFAULT 0,
 tracking_id TEXT NULL,
 status TEXT NOT NULL DEFAULT 'queued',
 sent_at TEXT NULL,
 failed_at TEXT NULL,
 failure_reason TEXT NULL,
 opened_at TEXT NULL,
 open_count INTEGER NOT NULL DEFAULT 0,
 last_opened_at TEXT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS email_opens (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 email_recipient_id INTEGER NOT NULL,
 opened_at TEXT NOT NULL,
 ip_address TEXT NULL,
 user_agent TEXT NULL,
 created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS email_attachments (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 email_id INTEGER NOT NULL,
 original_filename TEXT NOT NULL,
 stored_filename TEXT NOT NULL,
 mime_type TEXT NOT NULL,
 file_size INTEGER NOT NULL,
 uploaded_by_user_id INTEGER NOT NULL,
 created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS notifications (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NULL,
 member_id INTEGER NULL,
 type TEXT NOT NULL,
 title TEXT NOT NULL,
 message TEXT NOT NULL,
 action_url TEXT NULL,
 read_at TEXT NULL,
 expires_at TEXT NULL,
 created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS audit_logs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 actor_user_id INTEGER NULL,
 action TEXT NOT NULL,
 entity_type TEXT NULL,
 entity_id INTEGER NULL,
 target_user_id INTEGER NULL,
 target_member_id INTEGER NULL,
 result TEXT NOT NULL DEFAULT 'success',
 reason TEXT NULL,
 ip_address TEXT NULL,
 user_agent TEXT NULL,
 session_id TEXT NULL,
 metadata TEXT NULL,
 created_at TEXT NOT NULL
);
SQL);
    ensure_schema_updates();
}

function seed_roles_permissions(): void {
    $roles = [
        'member' => 'Member',
        'committee' => 'Committee Member',
        'member_db' => 'Member DB User',
        'equipment_manager' => 'Equipment Manager',
        'event_manager' => 'Event Manager',
        'brickworks_participant' => 'Brickworks Participant',
        'brickworks_reviewer' => 'Brickworks Reviewer',
        'treasurer' => 'Treasurer',
        'admin' => 'Admin',
    ];
    $permissions = [
        'view_own_profile','edit_own_profile','view_events','signup_events','view_internal_directory','search_internal_directory','manage_own_directory_preferences','manage_own_email_preferences','view_own_brickworks','join_brickworks','submit_brickworks_evidence',
        'manage_events','track_attendance','view_committee_actions','manage_committee_actions','view_equipment','edit_equipment','manage_equipment_loans','view_membership_db','edit_membership_db','manage_subscriptions','export_member_data','manage_users','manage_roles','reset_passwords','view_audit_logs','view_security_logs','view_member_audit_logs','view_equipment_audit_logs','export_audit_logs','view_brickworks_participants','review_brickworks_evidence','approve_brickworks_criteria','manage_brickworks_criteria','export_brickworks_reports','send_member_emails','send_role_emails','send_event_emails','send_subs_reminders','send_brickworks_emails','manage_email_templates','view_email_reports','view_email_open_tracking','manage_email_attachments','system_admin'
    ];
    foreach ($roles as $name=>$display) exec_sql('INSERT OR IGNORE INTO roles (name, display_name, description, created_at, updated_at) VALUES (?, ?, ?, datetime("now"), datetime("now"))', [$name,$display,$display]);
    foreach ($permissions as $p) exec_sql('INSERT OR IGNORE INTO permissions (name, description, created_at, updated_at) VALUES (?, ?, datetime("now"), datetime("now"))', [$p,$p]);
    $rolePerms = [
        'member' => ['view_own_profile','edit_own_profile','view_events','signup_events','view_internal_directory','search_internal_directory','manage_own_directory_preferences','manage_own_email_preferences','view_own_brickworks','join_brickworks','submit_brickworks_evidence'],
        'committee' => ['view_events','manage_events','track_attendance','view_committee_actions','manage_committee_actions','view_equipment','edit_equipment','manage_equipment_loans'],
        'equipment_manager' => ['view_equipment','edit_equipment','manage_equipment_loans','view_equipment_audit_logs'],
        'event_manager' => ['manage_events','track_attendance','view_committee_actions','manage_committee_actions','send_event_emails'],
        'member_db' => ['view_membership_db','edit_membership_db','manage_subscriptions','export_member_data','view_member_audit_logs','send_member_emails','send_subs_reminders','view_email_reports','view_email_open_tracking'],
        'brickworks_reviewer' => ['view_brickworks_participants','review_brickworks_evidence','approve_brickworks_criteria','export_brickworks_reports','send_brickworks_emails'],
        'treasurer' => ['view_membership_db','manage_subscriptions','send_subs_reminders'],
        'admin' => $permissions
    ];
    foreach ($rolePerms as $role=>$perms) {
        $rid = first('SELECT id FROM roles WHERE name=?',[$role])['id'];
        foreach ($perms as $perm) {
            $pid = first('SELECT id FROM permissions WHERE name=?',[$perm])['id'];
            exec_sql('INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (?,?)',[$rid,$pid]);
        }
    }
}
function seed_brickworks(): void {
    $themes = ['Having a go','Getting involved','Taking part','Making','Promoting amateur radio'];
    foreach ($themes as $i=>$t) exec_sql('INSERT OR IGNORE INTO brickworks_themes (id, name, description, sort_order) VALUES (?, ?, ?, ?)', [$i+1, $t, $t, $i+1]);
    $criteria = [
        [1,'Make your first HF contact','Submit evidence of an HF contact.','Log extract, screenshot or written confirmation.'],
        [1,'Make your first VHF/UHF contact','Submit evidence of a VHF/UHF contact.','Log extract, screenshot or written confirmation.'],
        [1,'Use a club station','Operate under supervision at a club station.','Club attendance or supervisor comment.'],
        [2,'Attend a club meeting','Attend and participate in a society meeting.','Attendance record can be used as evidence.'],
        [2,'Help at a club event','Support setup, operation, logging or pack-down.','Event organiser comment or photo.'],
        [2,'Join a net','Take part in a local or national amateur radio net.','Log, screenshot or written details.'],
        [3,'Take part in a contest','Submit proof of contest participation.','Contest log or screenshot.'],
        [3,'Try portable operating','Operate away from the home station.','Photo, log or written summary.'],
        [3,'Log contacts electronically','Use electronic logging for QSOs.','Screenshot or exported log.'],
        [4,'Build or repair equipment','Build, repair or modify radio-related equipment.','Photos and notes.'],
        [4,'Make or test an antenna','Build/test an antenna and explain the result.','Photos, analyser screenshot or notes.'],
        [4,'Learn basic station safety','Show understanding of safe station setup.','Reviewer discussion/comment.'],
        [5,'Promote amateur radio','Help explain amateur radio to others.','Photo, write-up or event evidence.'],
        [5,'Help a new operator','Support another member or newcomer.','Reviewer comment.'],
        [5,'Give a short talk/demo','Give a short club talk or practical demo.','Event record or slides.']
    ];
    $count = first('SELECT COUNT(*) AS c FROM brickworks_criteria')['c'] ?? 0;
    if ((int)$count === 0) {
        foreach ($criteria as $i=>$c) exec_sql('INSERT INTO brickworks_criteria (theme_id,title,description,evidence_guidance,active,sort_order,created_at,updated_at) VALUES (?,?,?,?,1,?,datetime("now"),datetime("now"))', [$c[0],$c[1],$c[2],$c[3],$i+1]);
    }
}
function assign_role(int $user_id, string $role_name, ?int $by=null, ?string $reason=null): void {
    $role = first('SELECT * FROM roles WHERE name=?',[$role_name]); if (!$role) return;
    $already = first('SELECT 1 AS x FROM user_roles WHERE user_id=? AND role_id=?', [$user_id, $role['id']]);
    exec_sql('INSERT OR IGNORE INTO user_roles (user_id, role_id, assigned_by_user_id, assigned_at) VALUES (?,?,?,datetime("now"))', [$user_id,$role['id'],$by]);
    if (!$already) {
        exec_sql('INSERT INTO user_role_history (user_id, role_id, action, changed_by_user_id, changed_at, reason) VALUES (?,?,"assigned",?,datetime("now"),?)', [$user_id,$role['id'],$by,$reason]);
    }
}
function remove_role(int $user_id, string $role_name, ?int $by=null, ?string $reason=null): void {
    $role = first('SELECT * FROM roles WHERE name=?',[$role_name]); if (!$role) return;
    $had = first('SELECT 1 AS x FROM user_roles WHERE user_id=? AND role_id=?', [$user_id, $role['id']]);
    exec_sql('DELETE FROM user_roles WHERE user_id=? AND role_id=?', [$user_id,$role['id']]);
    if ($had) {
        exec_sql('INSERT INTO user_role_history (user_id, role_id, action, changed_by_user_id, changed_at, reason) VALUES (?,?,"removed",?,datetime("now"),?)', [$user_id,$role['id'],$by,$reason]);
    }
}
function set_user_roles(int $user_id, array $role_names, int $by, string $reason='Admin updated roles'): array {
    $validRoles = array_column(all('SELECT name FROM roles ORDER BY id'), 'name');
    $role_names = array_values(array_unique(array_intersect($role_names, $validRoles)));
    if (!in_array('member', $role_names, true)) $role_names[] = 'member';

    $targetCurrent = user_roles($user_id);
    $actorCurrent = user_roles($by);

    // Safety: do not let an admin remove their own final admin role and lock themselves out.
    if ($user_id === $by && in_array('admin', $targetCurrent, true) && !in_array('admin', $role_names, true)) {
        $adminCount = (int)(first('SELECT COUNT(DISTINCT ur.user_id) AS c FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE r.name="admin" AND u.status="active"')['c'] ?? 0);
        if ($adminCount <= 1) $role_names[] = 'admin';
    }

    $added=[]; $removed=[];
    foreach ($validRoles as $rn) {
        $has = in_array($rn, $targetCurrent, true);
        $want = in_array($rn, $role_names, true);
        if ($want && !$has) { assign_role($user_id, $rn, $by, $reason); $added[]=$rn; }
        if (!$want && $has) { remove_role($user_id, $rn, $by, $reason); $removed[]=$rn; }
    }
    return ['added'=>$added,'removed'=>$removed,'final'=>user_roles($user_id)];
}
function attendance_stats(int $member_id): array {
    $signed = first('SELECT COUNT(*) c FROM event_attendance WHERE member_id=? AND status IN ("signed_up","attended","did_not_attend")',[$member_id])['c'] ?? 0;
    $att = first('SELECT COUNT(*) c FROM event_attendance WHERE member_id=? AND attended=1',[$member_id])['c'] ?? 0;
    $events = first('SELECT COUNT(*) c FROM events WHERE visibility="members"',[])['c'] ?? 0;
    return [
        'signed_up' => (int)$signed,
        'attended' => (int)$att,
        'signup_percent' => $signed ? round($att/$signed*100,1) : null,
        'eligible_events' => (int)$events,
        'overall_percent' => $events ? round($att/$events*100,1) : null,
    ];
}
function brickworks_award(int $complete): ?string {
    if ($complete >= 23) return 'Diamond';
    if ($complete >= 15) return 'Platinum';
    if ($complete >= 10) return 'Gold';
    if ($complete >= 5) return 'Silver';
    if ($complete >= 3) return 'Bronze';
    return null;
}

if (route() === 'assets.css') {
    header('Content-Type: text/css');
    echo 'body{margin:0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f6f7fb;color:#18202a}header{background:#101827;color:white;padding:18px 24px}header div{display:flex;gap:12px;align-items:end}header span{opacity:.7}.main-nav{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;align-items:center}.main-nav a,.nav-drop{color:white;background:#24324a;padding:8px 10px;border-radius:8px;text-decoration:none;border:0;font:inherit;cursor:pointer}.dropdown{position:relative;padding-bottom:14px;margin-bottom:-14px}.dropdown::after{content:"";position:absolute;left:0;right:0;top:100%;height:14px}.dropdown-menu{display:none;position:absolute;z-index:999;top:calc(100% - 6px);left:0;min-width:220px;background:white;border-radius:10px;box-shadow:0 10px 25px #0003;padding:8px;margin-top:0}.dropdown:hover .dropdown-menu,.dropdown:focus-within .dropdown-menu,.dropdown.open .dropdown-menu{display:block}.dropdown-menu a{display:block;color:#18202a;background:white;padding:10px;border-radius:8px}.dropdown-menu a:hover{background:#f1f5f9}main{max-width:1180px;margin:24px auto;padding:0 18px}.card{background:white;border-radius:14px;padding:18px;margin:16px 0;box-shadow:0 1px 4px #0001}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}label{display:block;margin:10px 0 4px;font-weight:600}input,select,textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #ccd3df;border-radius:8px}textarea{min-height:110px}button,.btn{background:#1d4ed8;color:white;border:0;border-radius:8px;padding:10px 14px;text-decoration:none;display:inline-block;cursor:pointer}button.secondary,.btn.secondary{background:#475569}.btn.danger,button.danger{background:#b91c1c}table{width:100%;border-collapse:collapse;background:white}th,td{border-bottom:1px solid #e5e7eb;padding:10px;text-align:left;vertical-align:top}th{background:#f1f5f9}.flash{background:#dcfce7;border:1px solid #86efac;padding:12px;border-radius:10px}.danger-box,.card.danger{background:#fee2e2;border:1px solid #fecaca}.pill{display:inline-block;padding:4px 8px;border-radius:999px;background:#e0e7ff}.muted{color:#64748b}.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}.event-list{display:grid;gap:12px}.event-row{display:flex;gap:18px;justify-content:space-between;align-items:flex-start;border:1px solid #e5e7eb;border-radius:12px;padding:14px;background:#fff}.event-actions{white-space:nowrap}.toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.calendar{display:grid;grid-template-columns:repeat(7,1fr);gap:8px}.calendar-head{font-weight:700;text-align:center;background:#e2e8f0;border-radius:8px;padding:8px}.calendar-day{min-height:110px;background:white;border:1px solid #e5e7eb;border-radius:10px;padding:8px}.calendar-day.muted-day{background:#f8fafc;color:#94a3b8}.calendar-date{font-weight:700;margin-bottom:6px}.calendar-event{display:block;background:#dbeafe;color:#1e3a8a;text-decoration:none;border-radius:8px;padding:5px;margin:4px 0;font-size:.88rem}.leaderboard{counter-reset:rank}.leaderboard-row{display:grid;grid-template-columns:42px 1fr auto;gap:10px;align-items:center;border-bottom:1px solid #e5e7eb;padding:10px 0}.leaderboard-row:before{counter-increment:rank;content:counter(rank);background:#e0e7ff;border-radius:999px;width:30px;height:30px;display:grid;place-items:center;font-weight:700}.progressbar{height:10px;background:#e5e7eb;border-radius:999px;overflow:hidden}.progressbar span{display:block;height:100%;background:#1d4ed8}.small{font-size:.9rem}.status-complete{background:#dcfce7}.status-pending{background:#fef3c7}.status-none{background:#f1f5f9}.user-menu{margin-left:auto}.dropdown-right{right:0;left:auto}.modal{border:0;border-radius:16px;padding:0;max-width:820px;width:calc(100% - 32px);box-shadow:0 24px 80px #0005}.modal::backdrop{background:#0f172acc}.modal .card{margin:0;box-shadow:none}.modal-head{display:flex;align-items:center;gap:12px}.modal-head h2{margin-right:auto}.icon-btn{background:#e2e8f0;color:#0f172a;border-radius:999px;padding:8px 12px}.category-pill{background:#eef2ff;color:#312e81}.attendance-tools{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:end}.attendance-list{display:grid;gap:8px}.attendance-item{display:grid;grid-template-columns:32px 1fr 160px;gap:10px;align-items:center;border:1px solid #e5e7eb;border-radius:10px;padding:10px}.attendance-item input[type=checkbox]{width:auto}.attendance-modern{padding:0;overflow:hidden;border-radius:18px}.attendance-modern-head{display:grid;grid-template-columns:1fr auto;gap:18px;padding:28px 32px 18px;align-items:start}.attendance-modern-head h2{font-size:1.9rem;margin:.1rem 0 .35rem}.attendance-date{font-size:1.25rem;color:#64748b;font-weight:650}.attendance-counts{display:flex;gap:28px;text-align:center;align-items:start}.attendance-counts span{display:block;color:#64748b;font-weight:650}.attendance-counts strong{font-size:1.45rem}.attendance-counts .present{color:#16a34a}.attendance-counts .guest{color:#ea580c}.attendance-counts .absent{color:#dc2626}.attendance-modern-controls{display:grid;grid-template-columns:1fr 220px auto auto;gap:14px;padding:18px 32px 28px;align-items:center}.attendance-search-wrap{position:relative}.attendance-search-wrap:before{content:"⌕";position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:1.35rem;color:#94a3b8}.attendance-search{font-size:1.05rem;padding-left:46px}.attendance-filter,.attendance-search{height:44px;box-shadow:0 2px 7px #00000012}.attendance-modern-list{border-top:1px solid #e5e7eb}.attendance-modern-row{display:grid;grid-template-columns:44px 1fr auto;gap:14px;align-items:center;padding:18px 32px;border-bottom:1px solid #e5e7eb;background:#fff}.attendance-modern-row:hover{background:#f8fafc}.attendance-modern-row input[type=checkbox]{width:24px;height:24px;accent-color:#1d4ed8}.attendance-person strong{display:block;font-size:1.05rem}.attendance-person span{display:block;color:#64748b;margin-top:2px}.attendance-row-status{font-weight:700;color:#94a3b8}.attendance-row-status.present{color:#16a34a}.attendance-row-status.guest{color:#ea580c}.attendance-modern-footer{display:grid;grid-template-columns:1fr 1fr auto;gap:12px;padding:18px 32px;align-items:end;background:#f8fafc}.attendance-modern-footer input{background:white}.attendance-savebar{padding:18px 32px;display:flex;gap:10px;justify-content:space-between;align-items:center;background:#fff}.attendance-empty{padding:22px 32px;color:#64748b}.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.stat-tile{background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:12px}.full{grid-column:1/-1}.bw-hero{display:grid;grid-template-columns:1fr auto;gap:18px;align-items:center}.bw-score{font-size:2.2rem;font-weight:800}.bw-steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:12px}.bw-step{background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:10px}.bw-grid{display:grid;gap:14px}.bw-card{border:1px solid #e5e7eb;border-radius:14px;padding:14px;background:#fff}.bw-card-head{display:flex;gap:10px;align-items:flex-start;justify-content:space-between}.bw-card h3{margin:.1rem 0 .35rem}.bw-theme{font-size:.85rem;color:#475569;font-weight:700;text-transform:uppercase;letter-spacing:.03em}.bw-status{display:inline-block;border-radius:999px;padding:5px 9px;font-weight:700;font-size:.85rem;white-space:nowrap}.bw-status.complete{background:#dcfce7;color:#166534}.bw-status.pending{background:#fef3c7;color:#92400e}.bw-status.none{background:#f1f5f9;color:#334155}.bw-form{display:grid;gap:8px;margin-top:12px;background:#f8fafc;border-radius:12px;padding:12px}.bw-comments{margin-top:10px;border-left:4px solid #e2e8f0;padding-left:10px}.bw-muted-line{color:#64748b;font-size:.92rem}.ticket{border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;margin:10px 0}.ticket-head{display:flex;gap:10px;align-items:center;justify-content:space-between}.ticket.open{border-left:5px solid #2563eb}.ticket.in_progress{border-left:5px solid #f59e0b}.ticket.closed{border-left:5px solid #16a34a}.ticket.cancelled{border-left:5px solid #64748b}.matrix-wrap{overflow:auto;max-width:100%;border:1px solid #e5e7eb;border-radius:12px}.matrix{min-width:980px}.matrix th{position:sticky;top:0;z-index:3}.matrix th:first-child,.matrix td:first-child{position:sticky;left:0;background:#fff;z-index:2;box-shadow:2px 0 0 #e5e7eb}.matrix th:first-child{z-index:4;background:#f1f5f9}.matrix-cell{min-width:220px}.inline-form{display:grid;gap:6px}.inline-form select,.inline-form textarea,.inline-form input{font-size:.9rem;padding:7px}.status-pill{display:inline-block;border-radius:999px;padding:4px 8px;font-size:.82rem;font-weight:700}.status-open{background:#dbeafe;color:#1e40af}.status-in_progress,.status-pending_approval{background:#fef3c7;color:#92400e}.status-complete,.status-closed{background:#dcfce7;color:#166534}.status-not_completed,.status-cancelled{background:#f1f5f9;color:#334155}.asset-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.asset-field{background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:10px}.asset-field span{display:block;color:#64748b;font-size:.85rem}.asset-field strong{display:block;margin-top:3px}.actions-board{display:grid;gap:12px}.recipient-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:8px;max-height:360px;overflow:auto;border:1px solid #e5e7eb;border-radius:12px;padding:10px;background:#f8fafc}.recipient-item{display:flex;gap:10px;align-items:flex-start;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:9px;margin:0;font-weight:400}.recipient-item input{width:auto;margin-top:4px}.email-app{background:#fff;border:1px solid #dde4ef;border-radius:14px;overflow:hidden;box-shadow:0 1px 4px #0001;margin:16px 0}.email-top{background:#4638cf;color:white;padding:16px 18px;display:flex;align-items:center;gap:12px}.email-title{display:flex;align-items:center;gap:10px;font-size:1.25rem}.email-icon{font-size:1.3rem}.email-top-actions{margin-left:auto}.email-config-btn{background:#ffffff22;color:white;border:1px solid #ffffff55;border-radius:9px;padding:8px 10px;text-decoration:none;font-weight:700}.email-layout{display:grid;grid-template-columns:330px 1fr;min-height:560px}.email-sidebar{border-right:1px solid #dde4ef;background:#f8fafc}.email-recipient-head{display:flex;align-items:center;gap:10px;padding:15px 14px;border-bottom:1px solid #dde4ef;font-size:1.05rem}.email-people{color:#4f46e5}.email-count{background:#e0e7ff;color:#4338ca;border-radius:9px;padding:3px 12px;font-weight:800;box-shadow:0 2px 6px #0001}.email-filter-bar{display:flex;gap:7px;flex-wrap:wrap;padding:12px 14px;border-bottom:1px solid #e7edf6}.email-chip{width:auto;border-radius:7px;padding:8px 10px;background:#eef2ff;color:#4338ca;font-weight:800}.email-chip.active{background:#4f46e5;color:#fff}.email-chip.paid{background:#dcfce7;color:#166534}.email-chip.unpaid{background:#fee2e2;color:#b91c1c}.email-chip.pending{background:#fef3c7;color:#92400e}.email-chip.committee{background:#f3e8ff;color:#7e22ce}.email-chip.none{background:#e2e8f0;color:#64748b}.email-search-wrap{padding:12px 14px}.email-search{background:#fff;padding-left:14px}.email-member-list{max-height:430px;overflow:auto}.email-member-card{display:grid;grid-template-columns:24px 1fr auto;gap:10px;align-items:center;padding:12px 14px;border-top:1px solid #edf2f7;background:#eef2ff;cursor:pointer;margin:0;font-weight:400}.email-member-card:hover{background:#e0e7ff}.email-member-card input{display:none}.email-check-ui{width:18px;height:18px;border-radius:6px;background:#4f46e5;color:#fff;display:grid;place-items:center;font-size:.78rem;font-weight:900}.email-member-card input:not(:checked)+.email-check-ui{background:#fff;color:transparent;border:2px solid #cbd5e1}.email-member-main strong{display:block;color:#1e293b}.email-member-main small,.email-callsign{display:block;color:#94a3b8;font-weight:700;margin-top:2px}.email-badge{border-radius:7px;padding:6px 9px;font-weight:800;font-size:.82rem}.email-badge.paid{background:#dcfce7;color:#166534}.email-badge.unpaid{background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5}.email-badge.pending{background:#fef3c7;color:#92400e}.email-empty{padding:14px}.email-compose{display:flex;flex-direction:column;background:#fff}.email-fields{padding:16px 22px;border-bottom:1px solid #dde4ef}.email-row{display:grid;grid-template-columns:70px 1fr;gap:12px;align-items:center;margin:8px 0}.email-row label{margin:0;text-align:right;color:#64748b}.email-row input{background:#f8fafc}.email-toolbar{display:flex;gap:14px;align-items:center;padding:12px 22px;border-bottom:1px solid #eef2f7}.email-attach-btn{display:inline-flex;align-items:center;gap:8px;width:auto;margin:0;padding:9px 12px;border:1px solid #dbe3ee;border-radius:9px;background:#fff;box-shadow:0 2px 5px #0001;cursor:pointer}.email-attach-btn input{display:none}.email-help{color:#94a3b8;font-weight:700}.email-help code{background:#eef2ff;color:#64748b;border-radius:5px;padding:2px 5px}.email-message{border:0;border-radius:0;min-height:330px;padding:22px;font-size:1rem;resize:vertical}.email-message:focus{outline:2px solid #c7d2fe;outline-offset:-2px}.email-sendbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:auto;padding:14px 22px;border-top:1px solid #eef2f7;background:#fbfdff}@media(max-width:900px){.email-layout{grid-template-columns:1fr}.email-sidebar{border-right:0;border-bottom:1px solid #dde4ef}.email-member-list{max-height:260px}.email-row{grid-template-columns:1fr}.email-row label{text-align:left}.email-sendbar{display:block}.email-sendbar div{margin-top:10px}}footer{text-align:center;color:#64748b;padding:24px}@media(max-width:800px){.two{grid-template-columns:1fr}.event-row{display:block}.calendar{grid-template-columns:1fr}.calendar-head{display:none}table{font-size:.9rem}}';
    exit;
}
if (route() === 'email_open') {
    $tid = $_GET['id'] ?? '';
    if ($tid) {
        $r = first('SELECT * FROM email_recipients WHERE tracking_id=? AND tracking_enabled=1',[$tid]);
        if ($r) {
            exec_sql('UPDATE email_recipients SET open_count=open_count+1, opened_at=COALESCE(opened_at, datetime("now")), last_opened_at=datetime("now"), updated_at=datetime("now") WHERE id=?',[$r['id']]);
            exec_sql('INSERT INTO email_opens (email_recipient_id, opened_at, ip_address, user_agent, created_at) VALUES (?, datetime("now"), ?, ?, datetime("now"))',[$r['id'], client_ip(), $_SERVER['HTTP_USER_AGENT'] ?? null]);
        }
    }
    header('Content-Type: image/gif');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='); exit;
}

if (!installed() && route() !== 'install') redirect('install');

if (route() === 'install') {
    if (installed()) redirect('login');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $society = trim($_POST['society_name'] ?? 'Ham Radio Society');
        $first = trim($_POST['first_name'] ?? ''); $last = trim($_POST['last_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? '')); $pass = $_POST['password'] ?? ''; $confirm = $_POST['password_confirm'] ?? '';
        if (!$first || !$last || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 10 || $pass !== $confirm) {
            page_header('Install'); echo '<div class="card danger">Check the form. Passwords must match and be at least 10 characters.</div>'; page_footer(); exit;
        }
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        file_put_contents(CONFIG_PATH, '<?php return ' . var_export(['society_name'=>$society,'app_key'=>base64_encode($key),'base_url'=>($_POST['base_url'] ?? '')], true) . ';');
        create_schema(); seed_roles_permissions(); seed_brickworks();
        exec_sql('INSERT INTO members (membership_number,first_name,last_name,callsign,licence_level,email,date_joined,membership_status,membership_type,created_at,updated_at) VALUES (?,?,?,?,?,?,date("now"),"active","Admin",datetime("now"),datetime("now"))', ['0001',$first,$last,trim($_POST['callsign'] ?? ''),trim($_POST['licence_level'] ?? ''),$email]);
        $member_id = (int)db()->lastInsertId();
        exec_sql('INSERT INTO users (member_id,email,password_hash,status,created_at,updated_at) VALUES (?,?,?,"active",datetime("now"),datetime("now"))', [$member_id,$email,password_hash($pass,PASSWORD_DEFAULT)]);
        $user_id = (int)db()->lastInsertId();
        assign_role($user_id,'member',$user_id,'Initial setup'); assign_role($user_id,'admin',$user_id,'Initial setup');
        exec_sql('INSERT INTO member_directory_preferences (member_id, created_at, updated_at) VALUES (?, datetime("now"), datetime("now"))',[$member_id]);
        exec_sql('INSERT INTO member_email_preferences (member_id, created_at, updated_at) VALUES (?, datetime("now"), datetime("now"))',[$member_id]);
        file_put_contents(LOCK_PATH, date('c'));
        $_SESSION['user_id'] = $user_id;
        audit('install.completed','system',null,'success');
        flash('System installed and admin user created.'); redirect('dashboard');
    }
    page_header('Install');
    echo '<div class="card"><h1>First-time setup</h1><p>No default admin account is used. Create the first admin user below.</p><form method="post">'.csrf_field().'<div class="two"><div><label>Society name</label><input name="society_name" required value="Ham Radio Society"></div><div><label>Base URL</label><input name="base_url" placeholder="https://members.example.org"></div><div><label>First name</label><input name="first_name" required></div><div><label>Surname</label><input name="last_name" required></div><div><label>Callsign</label><input name="callsign"></div><div><label>Licence level</label><input name="licence_level"></div><div><label>Email</label><input type="email" name="email" required></div><div></div><div><label>Password</label><input type="password" name="password" required minlength="10"></div><div><label>Confirm password</label><input type="password" name="password_confirm" required minlength="10"></div></div><p><button>Create admin and lock installer</button></p></form></div>';
    page_footer(); exit;
}

if (route() === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $email = strtolower(trim($_POST['email'] ?? '')); $pass = $_POST['password'] ?? '';
        $u = first('SELECT * FROM users WHERE email=?',[$email]);
        if ($u && $u['status']==='active' && password_verify($pass,$u['password_hash'])) {
            $_SESSION['user_id'] = $u['id']; exec_sql('UPDATE users SET last_login_at=datetime("now") WHERE id=?',[$u['id']]); audit('login.success','user',(int)$u['id']); redirect('dashboard');
        }
        audit('login.failed','user',null,'failed','bad_credentials',['email'=>$email]); flash('Login failed.'); redirect('login');
    }
    page_header('Login'); echo '<div class="card"><h1>Login</h1><form method="post">'.csrf_field().'<label>Email</label><input type="email" name="email" required><label>Password</label><input type="password" name="password" required><p><button>Login</button></p></form></div>'; page_footer(); exit;
}
if (route() === 'logout') { audit('logout'); session_destroy(); header('Location: ?route=login'); exit; }

$u = require_login();
create_schema();
seed_roles_permissions();

if (route() === 'dashboard') {
    audit('dashboard.view','user',(int)$u['id']); page_header('Dashboard');
    $member = first('SELECT * FROM members WHERE id=?',[$u['member_id']]);
    $roles = user_roles((int)$u['id']); $perms = user_permissions((int)$u['id']);
    $notices = all('SELECT * FROM notifications WHERE (user_id=? OR member_id=? OR (user_id IS NULL AND member_id IS NULL)) AND read_at IS NULL AND (expires_at IS NULL OR expires_at > datetime("now")) ORDER BY created_at DESC LIMIT 10',[$u['id'],$u['member_id']]);
    echo '<h1>Dashboard</h1><div class="grid"><div class="card"><h2>Membership</h2><p><strong>Status:</strong> '.e($member['membership_status'] ?? '').'</p><p><strong>Renewal due:</strong> '.e($member['renewal_date'] ?: 'Not set').'</p><p><strong>Callsign:</strong> '.e($member['callsign'] ?: 'Not set').'</p></div>';
    $stats = attendance_stats((int)$u['member_id']);
    echo '<div class="card"><h2>Attendance</h2><p>Attended: '.e($stats['attended']).'</p><p>Signup attendance: '.e($stats['signup_percent'] === null ? 'N/A' : $stats['signup_percent'].'%').'</p><p>Overall attendance: '.e($stats['overall_percent'] === null ? 'N/A' : $stats['overall_percent'].'%').'</p></div>';
    echo '<div class="card"><h2>Roles</h2><p>'.e(implode(', ', $roles)).'</p></div></div>';
    echo '<div class="card"><h2>Notifications</h2>'; if (!$notices) echo '<p>No unread notifications.</p>'; else foreach ($notices as $n) echo '<p><strong>'.e($n['title']).'</strong><br>'.e($n['message']).'</p>'; echo '</div>';
    $totalCriteria = (int)(first('SELECT COUNT(*) c FROM brickworks_criteria WHERE active=1')['c'] ?? 0);
    $leaders = all('SELECT m.first_name,m.last_name,m.callsign,b.id participant_id,COUNT(CASE WHEN bp.status="complete" THEN 1 END) complete_count,COUNT(CASE WHEN bp.status="pending_approval" THEN 1 END) pending_count FROM brickworks_participants b JOIN members m ON m.id=b.member_id LEFT JOIN brickworks_progress bp ON bp.participant_id=b.id GROUP BY b.id,m.first_name,m.last_name,m.callsign ORDER BY complete_count DESC,pending_count DESC,m.callsign ASC LIMIT 10');
    echo '<div class="card"><h2>Brickworks leaderboard</h2>';
    if (!$leaders) { echo '<p>No Brickworks participants yet.</p>'; } else { echo '<div class="leaderboard">'; foreach ($leaders as $l) { $complete=(int)$l['complete_count']; $pct=$totalCriteria?min(100,round($complete/$totalCriteria*100)):0; $award=brickworks_award($complete) ?: 'No award yet'; $name=trim($l['first_name'].' '.$l['last_name']); echo '<div class="leaderboard-row"><div><strong>'.e($l['callsign'] ?: $name).'</strong><br><span class="muted">'.e($name).' • '.e($award).' • '.e($l['pending_count']).' pending</span><div class="progressbar"><span style="width:'.e($pct).'%"></span></div></div><div><strong>'.e($complete).'/'.e($totalCriteria).'</strong></div></div>'; } echo '</div>'; }
    echo '</div>';
    page_footer(); exit;
}

if (route() === 'profile') {
    $m = first('SELECT * FROM members WHERE id=?',[$u['member_id']]); if (!$m) exit('No member record linked.');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        require_csrf();
        $emergencySummary = trim(($_POST['emergency_contact_name'] ?? '') . ' | ' . ($_POST['emergency_contact_relationship'] ?? '') . ' | ' . ($_POST['emergency_contact_phone'] ?? ''));
        exec_sql('UPDATE members SET first_name=?, last_name=?, callsign=?, licence_level=?, email=?, phone_encrypted=?, address_encrypted=?, emergency_contact_encrypted=?, emergency_contact_name_encrypted=?, emergency_contact_relationship_encrypted=?, emergency_contact_phone_encrypted=?, data_last_confirmed_at=datetime("now"), updated_at=datetime("now") WHERE id=?', [trim($_POST['first_name']),trim($_POST['last_name']),trim($_POST['callsign']),trim($_POST['licence_level']),trim($_POST['email']),encrypt_value(trim($_POST['phone'] ?? '')),encrypt_value(trim($_POST['address'] ?? '')),encrypt_value($emergencySummary),encrypt_value(trim($_POST['emergency_contact_name'] ?? '')),encrypt_value(trim($_POST['emergency_contact_relationship'] ?? '')),encrypt_value(trim($_POST['emergency_contact_phone'] ?? '')),$m['id']]);
        exec_sql('UPDATE users SET email=?, updated_at=datetime("now") WHERE id=?',[trim($_POST['email']),$u['id']]);
        exec_sql('INSERT OR IGNORE INTO member_directory_preferences (member_id, created_at, updated_at) VALUES (?, datetime("now"), datetime("now"))',[$m['id']]);
        $directoryOptIn = isset($_POST['show_in_directory']) ? 1 : 0;
        exec_sql('UPDATE member_directory_preferences SET show_callsign=?, show_first_name=?, show_surname=?, show_licence_level=0, show_email=0, show_phone=0, consent_given_at=CASE WHEN ?=1 THEN COALESCE(consent_given_at, datetime("now")) ELSE consent_given_at END, consent_updated_at=datetime("now"), updated_at=datetime("now") WHERE member_id=?', [$directoryOptIn,$directoryOptIn,$directoryOptIn,$directoryOptIn,$m['id']]);
        exec_sql('INSERT OR IGNORE INTO member_email_preferences (member_id, created_at, updated_at) VALUES (?, datetime("now"), datetime("now"))',[$m['id']]);
        $emailConsent = isset($_POST['consent_email_comms']) ? 1 : 0;
        exec_sql('UPDATE member_email_preferences SET receive_admin_emails=?, receive_subs_emails=?, receive_event_emails=?, receive_newsletter_emails=?, receive_brickworks_emails=?, updated_at=datetime("now") WHERE member_id=?',[$emailConsent,$emailConsent,$emailConsent,$emailConsent,$emailConsent,$m['id']]);
        save_consent_post((int)$m['id'], (int)$u['id']);
        audit('profile.update','member',(int)$m['id'],'success',null,['fields_changed'=>['profile','directory_preferences','consents']]); flash('Profile updated.'); redirect('profile');
    }
    audit('profile.view','member',(int)$m['id']);
    $dp = first('SELECT * FROM member_directory_preferences WHERE member_id=?',[$m['id']]) ?: [];
    page_header('My Profile');
    echo '<div class="card"><h1>My Profile</h1><p><strong>Membership number:</strong> '.e($m['membership_number'] ?: 'Not set').'<br><strong>Date joined:</strong> '.e(member_joined_display($m)).'</p><form method="post">'.csrf_field().'<div class="two"><div><label>First name</label><input name="first_name" value="'.e($m['first_name']).'"></div><div><label>Surname</label><input name="last_name" value="'.e($m['last_name']).'"></div><div><label>Callsign</label><input name="callsign" value="'.e($m['callsign']).'"></div><div><label>Licence level</label><input name="licence_level" value="'.e($m['licence_level']).'"></div><div><label>Email</label><input type="email" name="email" value="'.e($m['email']).'"></div><div><label>Phone</label><input name="phone" value="'.e(decrypt_value($m['phone_encrypted'])).'"></div><div class="full"><label>Address</label><textarea name="address">'.e(decrypt_value($m['address_encrypted'])).'</textarea></div><div class="full"><h2>Emergency contact</h2></div><div><label>Emergency contact name</label><input name="emergency_contact_name" value="'.e(decrypt_value($m['emergency_contact_name_encrypted'] ?? '')).'"></div><div><label>Relationship to member</label><input name="emergency_contact_relationship" value="'.e(decrypt_value($m['emergency_contact_relationship_encrypted'] ?? '')).'"></div><div><label>Emergency contact phone</label><input name="emergency_contact_phone" value="'.e(decrypt_value($m['emergency_contact_phone_encrypted'] ?? '')).'"></div></div>';
    echo '<h2>Internal directory</h2><p class="muted">If enabled, the internal directory will show only your name and callsign to logged-in members.</p><label><input type="checkbox" name="show_in_directory" '.(!empty($dp['show_callsign'])?'checked':'').'> Show my name and callsign in the internal directory</label>';
    echo '<h2>Consents</h2><p class="muted">These control how the society may contact you.</p>'.render_consent_checkboxes((int)$m['id']).'<p><button>Save profile</button></p></form></div>';
    $payments = all('SELECT * FROM subscription_payments WHERE member_id=? ORDER BY subscription_year DESC',[$m['id']]);
    echo '<div class="card"><h2>My subscription/payment history</h2><table><tr><th>Year</th><th>Due</th><th>Paid</th><th>Date</th><th>Status</th></tr>'; foreach($payments as $p) echo '<tr><td>'.e($p['subscription_year']).'</td><td>£'.e(number_format($p['amount_due'],2)).'</td><td>£'.e(number_format($p['amount_paid'],2)).'</td><td>'.e($p['payment_date']).'</td><td>'.e($p['status']).'</td></tr>'; echo '</table></div>';
    page_footer(); exit;
}

if (route() === 'directory') {
    require_permission('view_internal_directory'); audit('directory.view'); page_header('Internal Directory');
    $rows = all('SELECT m.* FROM member_directory_preferences d JOIN members m ON m.id=d.member_id WHERE d.show_callsign=1 AND m.membership_status="active" ORDER BY m.last_name, m.first_name, m.callsign');
    echo '<div class="card"><h1>Internal directory</h1><p>Only members who opted in are listed. The directory shows name and callsign only.</p><table><tr><th>Name</th><th>Callsign</th></tr>';
    foreach($rows as $r){ $name=trim($r['first_name'].' '.$r['last_name']); echo '<tr><td>'.e($name).'</td><td>'.e($r['callsign']).'</td></tr>'; }
    echo '</table></div>'; page_footer(); exit;
}

if (route() === 'members') {
    require_permission('view_membership_db'); audit('member_database.view'); page_header('Membership Database');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        require_permission('edit_membership_db'); require_csrf();
        $membershipNumber = can_edit_membership_number() ? trim($_POST['membership_number'] ?? '') : null;
        $joinedBeforeSystem = isset($_POST['joined_before_system']) ? 1 : 0;
        $dateJoined = $joinedBeforeSystem ? '' : trim($_POST['date_joined'] ?? '');
        $emergencySummary = trim(($_POST['emergency_contact_name'] ?? '') . ' | ' . ($_POST['emergency_contact_relationship'] ?? '') . ' | ' . ($_POST['emergency_contact_phone'] ?? ''));
        exec_sql('INSERT INTO members (membership_number,first_name,last_name,callsign,licence_level,email,phone_encrypted,address_encrypted,emergency_contact_encrypted,emergency_contact_name_encrypted,emergency_contact_relationship_encrypted,emergency_contact_phone_encrypted,date_joined,joined_before_system,renewal_date,membership_status,membership_type,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, datetime("now"), datetime("now"))', [$membershipNumber ?: null,trim($_POST['first_name']),trim($_POST['last_name']),trim($_POST['callsign']),trim($_POST['licence_level']),trim($_POST['email']),encrypt_value(trim($_POST['phone'])),encrypt_value(trim($_POST['address'])),encrypt_value($emergencySummary),encrypt_value(trim($_POST['emergency_contact_name'] ?? '')),encrypt_value(trim($_POST['emergency_contact_relationship'] ?? '')),encrypt_value(trim($_POST['emergency_contact_phone'] ?? '')),$dateJoined,$joinedBeforeSystem,trim($_POST['renewal_date']),trim($_POST['membership_status']),trim($_POST['membership_type'])]);
        $mid=(int)db()->lastInsertId(); exec_sql('INSERT INTO member_directory_preferences (member_id, created_at, updated_at) VALUES (?,datetime("now"),datetime("now"))',[$mid]); exec_sql('INSERT INTO member_email_preferences (member_id, created_at, updated_at) VALUES (?,datetime("now"),datetime("now"))',[$mid]); save_consent_post($mid, (int)$u['id']); audit('member.create','member',$mid); flash('Member added.'); redirect('members');
    }
    $rows = all('SELECT * FROM members ORDER BY last_name, first_name');
    echo '<div class="card"><div class="toolbar"><h1 style="margin-right:auto">Membership database</h1><a class="btn" href="?route=member_export">Export all members spreadsheet</a></div><table><tr><th>No.</th><th>Name</th><th>Callsign</th><th>Status</th><th>Joined</th><th>Renewal</th><th>Attendance</th><th>Action</th></tr>';
    foreach($rows as $m){ $st=attendance_stats((int)$m['id']); echo '<tr><td>'.e($m['membership_number']).'</td><td>'.e($m['first_name'].' '.$m['last_name']).'</td><td>'.e($m['callsign']).'</td><td>'.e($m['membership_status']).'</td><td>'.e(member_joined_display($m)).'</td><td>'.e($m['renewal_date']).'</td><td>'.e($st['signup_percent']===null?'N/A':$st['signup_percent'].'%').'</td><td><a class="btn secondary" href="?route=member_view&id='.e($m['id']).'">Open</a></td></tr>'; }
    echo '</table></div><div class="card"><h2>Add member</h2><form method="post">'.csrf_field().'<div class="two">';
    if (can_edit_membership_number()) echo '<div><label>Membership number</label><input name="membership_number"></div>';
    echo '<div><label>Callsign</label><input name="callsign"></div><div><label>First name</label><input name="first_name" required></div><div><label>Surname</label><input name="last_name" required></div><div><label>Email</label><input name="email" type="email" required></div><div><label>Licence level</label><input name="licence_level"></div><div><label>Phone</label><input name="phone"></div><div class="full"><label>Address</label><textarea name="address"></textarea></div><div class="full"><h3>Emergency contact</h3></div><div><label>Emergency contact name</label><input name="emergency_contact_name"></div><div><label>Relationship to member</label><input name="emergency_contact_relationship"></div><div><label>Emergency contact phone</label><input name="emergency_contact_phone"></div><div><label>Membership type</label><input name="membership_type"></div><div><label>Date joined</label><input name="date_joined" type="date"></div><div><label>Renewal date</label><input name="renewal_date" type="date"></div><div><label>Membership status</label><select name="membership_status"><option>active</option><option>pending</option><option>expired</option><option>former</option><option>suspended</option><option>honorary</option></select></div></div><label><input type="checkbox" name="joined_before_system"> Joined before system / date not on record</label><h3>Consents</h3>'.render_consent_checkboxes(0).'<p class="muted">Consents can also be set after the member has been created.</p><button>Add member</button></form></div>';
    page_footer(); exit;
}


if (route() === 'member_export') {
    require_permission('export_member_data');
    audit('member.export_all');

    $headers = [
        'Membership number',
        'First name',
        'Surname',
        'Full name',
        'Callsign',
        'Licence level',
        'Email',
        'Phone',
        'Address',
        'Date joined',
        'Joined before system / date not on record',
        'Renewal date',
        'Membership status',
        'Membership type',
        'Emergency contact name',
        'Emergency contact relationship',
        'Emergency contact phone',
        'Email communications consent',
        'Text messages consent',
        'WhatsApp community consent',
        'Current user roles',
        'Events attended',
        'Events signed up',
        'Signup attendance %',
        'Overall attendance %',
        'Eligible events',
        'Payment/subs history',
        'Total paid',
        'Latest payment date'
    ];

    $rows = [];
    $members = all('SELECT * FROM members ORDER BY last_name, first_name, membership_number');
    foreach ($members as $m) {
        $mid = (int)$m['id'];
        $stats = attendance_stats($mid);
        $linkedUser = first('SELECT id FROM users WHERE member_id=? ORDER BY id LIMIT 1', [$mid]);
        $roles = $linkedUser ? implode(', ', user_roles((int)$linkedUser['id'])) : '';

        $payments = all('SELECT * FROM subscription_payments WHERE member_id=? ORDER BY subscription_year DESC, payment_date DESC, id DESC', [$mid]);
        $paymentSummaryParts = [];
        $totalPaid = 0.0;
        $latestPaymentDate = '';
        foreach ($payments as $p) {
            $amountPaid = (float)($p['amount_paid'] ?? 0);
            $totalPaid += $amountPaid;
            if (!$latestPaymentDate && !empty($p['payment_date'])) $latestPaymentDate = $p['payment_date'];
            $paymentSummaryParts[] = trim(($p['subscription_year'] ?? '') . ' ' . ($p['status'] ?? '') . ' - due £' . number_format((float)($p['amount_due'] ?? 0), 2) . ', paid £' . number_format($amountPaid, 2) . (!empty($p['payment_date']) ? ', date ' . $p['payment_date'] : '') . (!empty($p['payment_method']) ? ', method ' . $p['payment_method'] : '') . (!empty($p['payment_reference']) ? ', ref ' . $p['payment_reference'] : '') . (!empty($p['receipt_number']) ? ', receipt ' . $p['receipt_number'] : ''));
        }

        $rows[] = [
            $m['membership_number'] ?? '',
            $m['first_name'] ?? '',
            $m['last_name'] ?? '',
            trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')),
            $m['callsign'] ?? '',
            $m['licence_level'] ?? '',
            $m['email'] ?? '',
            decrypt_value($m['phone_encrypted'] ?? '') ?: '',
            decrypt_value($m['address_encrypted'] ?? '') ?: '',
            member_joined_display($m),
            !empty($m['joined_before_system']) ? 'Yes' : 'No',
            $m['renewal_date'] ?? '',
            $m['membership_status'] ?? '',
            $m['membership_type'] ?? '',
            decrypt_value($m['emergency_contact_name_encrypted'] ?? '') ?: '',
            decrypt_value($m['emergency_contact_relationship_encrypted'] ?? '') ?: '',
            decrypt_value($m['emergency_contact_phone_encrypted'] ?? '') ?: '',
            get_member_consent($mid, 'email_comms') ? 'Yes' : 'No',
            get_member_consent($mid, 'text_comms') ? 'Yes' : 'No',
            get_member_consent($mid, 'whatsapp_community') ? 'Yes' : 'No',
            $roles,
            $stats['attended'] ?? 0,
            $stats['signed_up'] ?? 0,
            $stats['signup_percent'] === null ? 'N/A' : $stats['signup_percent'],
            $stats['overall_percent'] === null ? 'N/A' : $stats['overall_percent'],
            $stats['eligible_events'] ?? 0,
            implode(' | ', $paymentSummaryParts),
            number_format($totalPaid, 2, '.', ''),
            $latestPaymentDate
        ];
    }

    csv_download('members-full-export-' . date('Y-m-d') . '.csv', $headers, $rows);
}

if (route() === 'member_view') {
    require_permission('view_membership_db'); $id=(int)($_GET['id']??0); $m=first('SELECT * FROM members WHERE id=?',[$id]); if(!$m) redirect('members'); audit('member.view','member',$id);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        require_permission('edit_membership_db'); require_csrf();
        if (isset($_POST['add_payment'])) { exec_sql('INSERT INTO subscription_payments (member_id,subscription_year,amount_due,amount_paid,payment_date,payment_method,payment_reference,receipt_number,status,recorded_by_user_id,notes_encrypted,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,datetime("now"),datetime("now"))',[$id,(int)$_POST['subscription_year'],(float)$_POST['amount_due'],(float)$_POST['amount_paid'],$_POST['payment_date'],$_POST['payment_method'],$_POST['payment_reference'],$_POST['receipt_number'],$_POST['status'],$u['id'],encrypt_value($_POST['notes']??'')]); audit('subscription.create','member',$id); flash('Payment/subs record added.'); redirect('member_view&id='.$id); }
        $membershipNumber = can_edit_membership_number() ? trim($_POST['membership_number'] ?? '') : $m['membership_number'];
        $joinedBeforeSystem = isset($_POST['joined_before_system']) ? 1 : 0;
        $dateJoined = $joinedBeforeSystem ? '' : trim($_POST['date_joined'] ?? '');
        $emergencySummary = trim(($_POST['emergency_contact_name'] ?? '') . ' | ' . ($_POST['emergency_contact_relationship'] ?? '') . ' | ' . ($_POST['emergency_contact_phone'] ?? ''));
        exec_sql('UPDATE members SET membership_number=?, first_name=?, last_name=?, callsign=?, licence_level=?, email=?, phone_encrypted=?, address_encrypted=?, emergency_contact_encrypted=?, emergency_contact_name_encrypted=?, emergency_contact_relationship_encrypted=?, emergency_contact_phone_encrypted=?, date_joined=?, joined_before_system=?, renewal_date=?, membership_status=?, membership_type=?, notes_encrypted=?, updated_at=datetime("now") WHERE id=?',[$membershipNumber ?: null,trim($_POST['first_name']),trim($_POST['last_name']),trim($_POST['callsign']),trim($_POST['licence_level']),trim($_POST['email']),encrypt_value(trim($_POST['phone'])),encrypt_value(trim($_POST['address'])),encrypt_value($emergencySummary),encrypt_value(trim($_POST['emergency_contact_name'] ?? '')),encrypt_value(trim($_POST['emergency_contact_relationship'] ?? '')),encrypt_value(trim($_POST['emergency_contact_phone'] ?? '')),$dateJoined,$joinedBeforeSystem,trim($_POST['renewal_date']),trim($_POST['membership_status']),trim($_POST['membership_type']),encrypt_value(trim($_POST['notes']??'')),$id]);
        save_consent_post($id, (int)$u['id']);
        audit('member.update','member',$id,'success',null,['membership_number_changed'=>can_edit_membership_number()]); flash('Member updated.'); redirect('member_view&id='.$id);
    }
    page_header('Member record'); $stats=attendance_stats($id);
    echo '<div class="card"><div class="toolbar"><h1 style="margin-right:auto">'.e($m['first_name'].' '.$m['last_name']).'</h1><a class="btn secondary" href="?route=member_export">Export all members spreadsheet</a></div><form method="post">'.csrf_field().'<div class="two">';
    if (can_edit_membership_number()) echo '<div><label>Membership number</label><input name="membership_number" value="'.e($m['membership_number']).'"></div>'; else echo '<div><label>Membership number</label><p><strong>'.e($m['membership_number'] ?: 'Not set').'</strong><br><span class="muted">Only admin users can change this.</span></p></div>';
    echo '<div><label>Callsign</label><input name="callsign" value="'.e($m['callsign']).'"></div><div><label>First name</label><input name="first_name" value="'.e($m['first_name']).'"></div><div><label>Surname</label><input name="last_name" value="'.e($m['last_name']).'"></div><div><label>Email address</label><input type="email" name="email" value="'.e($m['email']).'"></div><div><label>Licence level</label><input name="licence_level" value="'.e($m['licence_level']).'"></div><div><label>Phone number</label><input name="phone" value="'.e(decrypt_value($m['phone_encrypted'])).'"></div><div class="full"><label>Address</label><textarea name="address">'.e(decrypt_value($m['address_encrypted'])).'</textarea></div><div class="full"><h2>Emergency contact</h2></div><div><label>Emergency contact name</label><input name="emergency_contact_name" value="'.e(decrypt_value($m['emergency_contact_name_encrypted'] ?? '')).'"></div><div><label>Relationship to member</label><input name="emergency_contact_relationship" value="'.e(decrypt_value($m['emergency_contact_relationship_encrypted'] ?? '')).'"></div><div><label>Emergency contact phone</label><input name="emergency_contact_phone" value="'.e(decrypt_value($m['emergency_contact_phone_encrypted'] ?? '')).'"></div><div><label>Membership type</label><input name="membership_type" value="'.e($m['membership_type']).'"></div><div><label>Date joined</label><input type="date" name="date_joined" value="'.e($m['date_joined']).'"><label class="small"><input type="checkbox" name="joined_before_system" '.(!empty($m['joined_before_system'])?'checked':'').'> Not on record / joined before system</label></div><div><label>Renewal date</label><input type="date" name="renewal_date" value="'.e($m['renewal_date']).'"></div><div><label>Membership status</label><select name="membership_status">'; foreach(['pending','active','expired','former','suspended','honorary','life_member'] as $s) echo '<option '.($m['membership_status']===$s?'selected':'').'>'.e($s).'</option>'; echo '</select></div></div><h2>Consents</h2><p class="muted">Visible and editable by Member DB users and admins.</p>'.render_consent_checkboxes($id).'<label>Private notes</label><textarea name="notes">'.e(decrypt_value($m['notes_encrypted'])).'</textarea><button>Save member</button></form></div>';
    echo '<div class="grid"><div class="card"><h2>Attendance</h2><p>Attended: '.e($stats['attended']).'</p><p>Signed up: '.e($stats['signed_up']).'</p><p>Signup attendance: '.e($stats['signup_percent']===null?'N/A':$stats['signup_percent'].'%').'</p><p>Overall attendance: '.e($stats['overall_percent']===null?'N/A':$stats['overall_percent'].'%').'</p></div>';
    $payments=all('SELECT * FROM subscription_payments WHERE member_id=? ORDER BY subscription_year DESC',[$id]); echo '<div class="card"><h2>Payment/subs history</h2><table><tr><th>Year</th><th>Due</th><th>Paid</th><th>Date</th><th>Status</th></tr>'; foreach($payments as $p) echo '<tr><td>'.e($p['subscription_year']).'</td><td>£'.e(number_format($p['amount_due'],2)).'</td><td>£'.e(number_format($p['amount_paid'],2)).'</td><td>'.e($p['payment_date']).'</td><td>'.e($p['status']).'</td></tr>'; echo '</table></div></div>';
    echo '<div class="card"><h2>Add subs/payment record</h2><form method="post">'.csrf_field().'<input type="hidden" name="add_payment" value="1"><div class="two"><input type="number" name="subscription_year" value="'.date('Y').'" required><input type="number" step="0.01" name="amount_due" placeholder="Amount due" required><input type="number" step="0.01" name="amount_paid" placeholder="Amount paid" required><input type="date" name="payment_date"><input name="payment_method" placeholder="Payment method"><input name="payment_reference" placeholder="Payment reference"><input name="receipt_number" placeholder="Receipt number"><select name="status"><option>unpaid</option><option>part-paid</option><option>paid</option><option>waived</option><option>refunded</option></select></div><textarea name="notes" placeholder="Notes"></textarea><button>Add payment record</button></form></div>';
    $rh=all('SELECT urh.*,r.display_name FROM user_role_history urh JOIN users us ON us.id=urh.user_id JOIN roles r ON r.id=urh.role_id WHERE us.member_id=? ORDER BY changed_at DESC',[$id]); echo '<div class="card"><h2>Role history</h2><table><tr><th>Role</th><th>Action</th><th>Changed</th><th>Reason</th></tr>'; foreach($rh as $r) echo '<tr><td>'.e($r['display_name']).'</td><td>'.e($r['action']).'</td><td>'.e($r['changed_at']).'</td><td>'.e($r['reason']).'</td></tr>'; echo '</table></div>';
    page_footer(); exit;
}

if (route() === 'users') {
    require_permission('manage_users'); page_header('Users'); audit('users.view');
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_csrf();
        if (isset($_POST['reset_password'])) {
            require_permission('reset_passwords');
            $new=$_POST['new_password'];
            if(strlen($new)<10){flash('Password must be at least 10 chars.');redirect('users');}
            exec_sql('UPDATE users SET password_hash=?, force_password_change=1, updated_at=datetime("now") WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),(int)$_POST['user_id']]);
            audit('user.password_changed_by_admin','user',(int)$_POST['user_id']);
            flash('Password reset.'); redirect('users');
        }
        if (isset($_POST['update_roles'])) {
            require_permission('manage_roles');
            $targetUserId = (int)$_POST['user_id'];
            $selectedRoles = $_POST['roles'] ?? [];
            if (!is_array($selectedRoles)) $selectedRoles = [];
            $result = set_user_roles($targetUserId, array_map('strval', $selectedRoles), (int)$u['id'], trim($_POST['role_change_reason'] ?? 'Admin updated roles'));
            audit('user.roles_updated','user',$targetUserId,'success',null,['added'=>$result['added'],'removed'=>$result['removed'],'final'=>$result['final']]);
            flash('User roles updated.'); redirect('users');
        }
        if (isset($_POST['create_user'])) {
            $member_id = null;
            if (!empty($_POST['member_id'])) $member_id=(int)$_POST['member_id'];
            exec_sql('INSERT INTO users (member_id,email,password_hash,status,force_password_change,created_at,updated_at) VALUES (?,?,?,"active",1,datetime("now"),datetime("now"))',[$member_id,strtolower(trim($_POST['email'])),password_hash($_POST['password'],PASSWORD_DEFAULT)]);
            $uid=(int)db()->lastInsertId(); assign_role($uid,'member',(int)$u['id'],'Admin created user');
            audit('user.created','user',$uid); flash('User created.'); redirect('users');
        }
    }
    $canRoles = has_permission('manage_roles');
    $allRoles = all('SELECT name, display_name, description FROM roles ORDER BY CASE name WHEN "member" THEN 1 WHEN "committee" THEN 2 WHEN "member_db" THEN 3 WHEN "brickworks_reviewer" THEN 4 WHEN "equipment_manager" THEN 5 WHEN "event_manager" THEN 6 WHEN "treasurer" THEN 7 WHEN "admin" THEN 99 ELSE 50 END, display_name');
    $users=all('SELECT u.*,m.first_name,m.last_name,m.callsign FROM users u LEFT JOIN members m ON m.id=u.member_id ORDER BY u.created_at DESC');
    echo '<div class="card"><h1>Users</h1><p class="muted">Admins can create users, reset passwords and manage user roles. The Member role is kept as the base role for all active accounts.</p><table><tr><th>Email</th><th>Member</th><th>Status</th><th>Roles</th><th>Reset password</th></tr>';
    foreach($users as $usr){
        $currentRoles = user_roles((int)$usr['id']);
        echo '<tr><td>'.e($usr['email']).'</td><td>'.e(trim(($usr['first_name']??'').' '.($usr['last_name']??'')).' '.($usr['callsign']?'('.$usr['callsign'].')':'')).'</td><td>'.e($usr['status']).'</td><td>'.e(implode(', ',$currentRoles));
        if ($canRoles) {
            echo '<details class="role-editor"><summary>Change roles</summary><form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="update_roles" value="1"><input type="hidden" name="user_id" value="'.e($usr['id']).'"><div class="role-grid">';
            foreach($allRoles as $role){
                $checked = in_array($role['name'], $currentRoles, true) ? 'checked' : '';
                $disabled = ($role['name']==='member') ? 'disabled' : '';
                // Disabled checkboxes are not submitted, so add a hidden member role value.
                if ($role['name']==='member') {
                    echo '<input type="hidden" name="roles[]" value="member">';
                }
                echo '<label class="check"><input type="checkbox" name="roles[]" value="'.e($role['name']).'" '.$checked.' '.$disabled.'> '.e($role['display_name']).'</label>';
            }
            echo '</div><label>Reason / note</label><input name="role_change_reason" placeholder="Optional reason for role change"><button>Save roles</button></form></details>';
        }
        echo '</td><td><form method="post">'.csrf_field().'<input type="hidden" name="reset_password" value="1"><input type="hidden" name="user_id" value="'.e($usr['id']).'"><input name="new_password" placeholder="New temp password"><button>Reset</button></form></td></tr>';
    }
    echo '</table></div>';

    if ($canRoles) {
        echo '<div class="card"><h2>Role guide</h2><table><tr><th>Role</th><th>Purpose</th></tr>';
        foreach($allRoles as $role){ echo '<tr><td>'.e($role['display_name']).'</td><td>'.e($role['description'] ?: $role['name']).'</td></tr>'; }
        echo '</table></div>';
    }

    $members=all('SELECT id,first_name,last_name,callsign FROM members ORDER BY last_name');
    echo '<div class="card"><h2>Create user</h2><form method="post">'.csrf_field().'<input type="hidden" name="create_user" value="1"><label>Link to member</label><select name="member_id"><option value="">None</option>';
    foreach($members as $m) echo '<option value="'.e($m['id']).'">'.e($m['first_name'].' '.$m['last_name'].' '.$m['callsign']).'</option>';
    echo '</select><label>Email</label><input type="email" name="email" required><label>Temporary password</label><input name="password" required minlength="10"><button>Create user</button></form></div>';
    page_footer(); exit;
}

if (route() === 'equipment') {
    require_permission('view_equipment'); audit('equipment_database.view'); page_header('Equipment');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        require_permission('edit_equipment'); require_csrf();
        exec_sql('INSERT INTO equipment (asset_number,name,category,manufacturer,model,serial_number_encrypted,location,condition,purchase_date,purchase_amount,value,maintenance_due_at,notes_encrypted,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,datetime("now"),datetime("now"))',[
            trim($_POST['asset_number']),trim($_POST['name']),trim($_POST['category']),trim($_POST['manufacturer']),trim($_POST['model']),encrypt_value(trim($_POST['serial_number'])),trim($_POST['location']),trim($_POST['condition']),trim($_POST['purchase_date'] ?? ''),($_POST['purchase_amount'] !== '' ? (float)$_POST['purchase_amount'] : null),($_POST['value'] !== '' ? (float)$_POST['value'] : null),trim($_POST['maintenance_due_at']),encrypt_value(trim($_POST['notes']))
        ]);
        audit('equipment.create','equipment',(int)db()->lastInsertId()); flash('Equipment added.'); redirect('equipment');
    }
    $rows=all('SELECT * FROM equipment ORDER BY asset_number');
    echo '<div class="card"><h1>Equipment / asset database</h1><p class="muted">Open an asset to view details and track maintenance tickets/history.</p><table><tr><th>Asset</th><th>Name</th><th>Model</th><th>Location</th><th>Condition</th><th>Purchased</th><th>Purchase amount</th><th>Current value</th><th>Maintenance due</th><th>Action</th></tr>';
    foreach($rows as $r) echo '<tr><td>'.e($r['asset_number']).'</td><td>'.e($r['name']).'</td><td>'.e(trim(($r['manufacturer']??'').' '.($r['model']??''))).'</td><td>'.e($r['location']).'</td><td>'.e($r['condition']).'</td><td>'.e($r['purchase_date'] ?: 'Unknown').'</td><td>'.($r['purchase_amount']!==null && $r['purchase_amount']!=='' ? '£'.e(number_format((float)$r['purchase_amount'],2)) : 'Unknown').'</td><td>'.($r['value']!==null && $r['value']!=='' ? '£'.e(number_format((float)$r['value'],2)) : 'Unknown').'</td><td>'.e($r['maintenance_due_at']).'</td><td><a class="btn secondary" href="?route=equipment_view&id='.e($r['id']).'">Open</a></td></tr>';
    echo '</table></div>';
    if (has_permission('edit_equipment')) {
        echo '<div class="card"><h2>Add equipment / asset</h2><form method="post">'.csrf_field().'<div class="two"><div><label>Asset number</label><input name="asset_number" required></div><div><label>Item name</label><input name="name" required></div><div><label>Category</label><input name="category" placeholder="Radio, antenna, laptop, PSU, coax, etc."></div><div><label>Manufacturer</label><input name="manufacturer"></div><div><label>Model</label><input name="model"></div><div><label>Serial number</label><input name="serial_number"></div><div><label>Storage/location</label><input name="location"></div><div><label>Condition</label><input name="condition" placeholder="Good, fair, needs repair, etc."></div><div><label>Date of purchase, if known</label><input name="purchase_date" type="date"></div><div><label>Amount purchased for, if known</label><input name="purchase_amount" type="number" step="0.01" placeholder="0.00"></div><div><label>Current/insurance value</label><input name="value" type="number" step="0.01" placeholder="0.00"></div><div><label>Maintenance due date</label><input name="maintenance_due_at" type="date"></div></div><label>Notes</label><textarea name="notes" placeholder="Any useful asset notes"></textarea><button>Add equipment</button></form></div>';
    }
    page_footer(); exit;
}

if (route() === 'equipment_view') {
    require_permission('view_equipment');
    $id=(int)($_GET['id']??0); $item=first('SELECT * FROM equipment WHERE id=?',[$id]); if(!$item) redirect('equipment');
    audit('equipment.view','equipment',$id);
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_permission('edit_equipment'); require_csrf();
        if (isset($_POST['add_ticket'])) {
            exec_sql('INSERT INTO equipment_maintenance_tickets (equipment_id,title,status,priority,due_date,description_encrypted,action_taken_encrypted,cost,assigned_user_id,created_by_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,datetime("now"),datetime("now"))',[$id,trim($_POST['title']),'open',trim($_POST['priority'] ?? 'normal'),trim($_POST['due_date'] ?? ''),encrypt_value(trim($_POST['description'] ?? '')),encrypt_value(''),($_POST['cost'] !== '' ? (float)$_POST['cost'] : null),!empty($_POST['assigned_user_id'])?(int)$_POST['assigned_user_id']:null,$u['id']]);
            audit('equipment.ticket_create','equipment',$id); flash('Maintenance ticket added.'); redirect('equipment_view&id='.$id);
        }
        if (isset($_POST['update_ticket'])) {
            $tid=(int)$_POST['ticket_id']; $status=$_POST['status'] ?? 'open';
            exec_sql('UPDATE equipment_maintenance_tickets SET status=?, priority=?, due_date=?, description_encrypted=?, action_taken_encrypted=?, cost=?, assigned_user_id=?, closed_at=CASE WHEN ?="closed" THEN COALESCE(closed_at, datetime("now")) ELSE NULL END, updated_at=datetime("now") WHERE id=? AND equipment_id=?',[$status,trim($_POST['priority'] ?? 'normal'),trim($_POST['due_date'] ?? ''),encrypt_value(trim($_POST['description'] ?? '')),encrypt_value(trim($_POST['action_taken'] ?? '')),($_POST['cost'] !== '' ? (float)$_POST['cost'] : null),!empty($_POST['assigned_user_id'])?(int)$_POST['assigned_user_id']:null,$status,$tid,$id]);
            audit('equipment.ticket_update','equipment_maintenance_ticket',$tid); flash('Maintenance ticket updated.'); redirect('equipment_view&id='.$id);
        }
    }
    page_header('Asset details');
    echo '<div class="card"><div class="toolbar"><h1 style="margin-right:auto">'.e($item['asset_number']).' - '.e($item['name']).'</h1><a class="btn secondary" href="?route=equipment">Back to equipment</a></div><div class="asset-grid">';
    $fields=['Category'=>$item['category'],'Manufacturer'=>$item['manufacturer'],'Model'=>$item['model'],'Serial number'=>decrypt_value($item['serial_number_encrypted']),'Location'=>$item['location'],'Condition'=>$item['condition'],'Date purchased'=>$item['purchase_date'] ?: 'Unknown','Amount purchased for'=>($item['purchase_amount']!==null && $item['purchase_amount']!=='' ? '£'.number_format((float)$item['purchase_amount'],2) : 'Unknown'),'Current/insurance value'=>($item['value']!==null && $item['value']!=='' ? '£'.number_format((float)$item['value'],2) : 'Unknown'),'Maintenance due'=>$item['maintenance_due_at'] ?: 'Not set'];
    foreach($fields as $label=>$val) echo '<div class="asset-field"><span>'.e($label).'</span><strong>'.e($val).'</strong></div>';
    echo '</div>'; if($item['notes_encrypted']) echo '<h3>Notes</h3><p>'.nl2br(e(decrypt_value($item['notes_encrypted']))).'</p>'; echo '</div>';
    $users=all('SELECT u.id,u.email,m.first_name,m.last_name,m.callsign FROM users u LEFT JOIN members m ON m.id=u.member_id WHERE u.status="active" ORDER BY m.last_name,u.email');
    if (has_permission('edit_equipment')) {
        echo '<div class="card"><h2>Add maintenance ticket</h2><form method="post">'.csrf_field().'<input type="hidden" name="add_ticket" value="1"><div class="two"><div><label>Ticket title / fault</label><input name="title" required></div><div><label>Priority</label><select name="priority"><option>low</option><option selected>normal</option><option>high</option><option>urgent</option></select></div><div><label>Due date</label><input type="date" name="due_date"></div><div><label>Assign to user</label><select name="assigned_user_id"><option value="">Unassigned</option>'; foreach($users as $usr) echo '<option value="'.e($usr['id']).'">'.e(trim(($usr['first_name']??'').' '.($usr['last_name']??'')).($usr['callsign']?' - '.$usr['callsign']:'').' / '.$usr['email']).'</option>'; echo '</select></div><div><label>Estimated/current cost</label><input name="cost" type="number" step="0.01"></div></div><label>Description</label><textarea name="description" placeholder="Fault, maintenance needed, parts required, etc."></textarea><button>Create ticket</button></form></div>';
    }
    $tickets=all('SELECT t.*,u.email,m.first_name,m.last_name FROM equipment_maintenance_tickets t LEFT JOIN users u ON u.id=t.assigned_user_id LEFT JOIN members m ON m.id=u.member_id WHERE t.equipment_id=? ORDER BY CASE t.status WHEN "open" THEN 1 WHEN "in_progress" THEN 2 WHEN "closed" THEN 3 ELSE 4 END, datetime(t.created_at) DESC',[$id]);
    echo '<div class="card"><h2>Maintenance tickets / history</h2>'; if(!$tickets) echo '<p>No maintenance tickets have been recorded for this item yet.</p>'; foreach($tickets as $t){ $cls=str_replace(' ','_',strtolower($t['status'])); echo '<div class="ticket '.e($cls).'"><div class="ticket-head"><div><strong>'.e($t['title']).'</strong><br><span class="muted">Created '.e($t['created_at']).' • Due '.e($t['due_date'] ?: 'not set').' • Assigned to '.e(trim(($t['first_name']??'').' '.($t['last_name']??'')) ?: ($t['email'] ?: 'Unassigned')).'</span></div><span class="status-pill status-'.e($cls).'">'.e($t['status']).'</span></div><p>'.nl2br(e(decrypt_value($t['description_encrypted']))).'</p>'; if($t['action_taken_encrypted']) echo '<p><strong>Action/history:</strong><br>'.nl2br(e(decrypt_value($t['action_taken_encrypted']))).'</p>'; if(has_permission('edit_equipment')){ echo '<details><summary>Edit ticket</summary><form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="update_ticket" value="1"><input type="hidden" name="ticket_id" value="'.e($t['id']).'"><label>Status</label><select name="status"><option '.($t['status']==='open'?'selected':'').'>open</option><option '.($t['status']==='in_progress'?'selected':'').'>in_progress</option><option '.($t['status']==='closed'?'selected':'').'>closed</option><option '.($t['status']==='cancelled'?'selected':'').'>cancelled</option></select><label>Priority</label><select name="priority"><option '.($t['priority']==='low'?'selected':'').'>low</option><option '.($t['priority']==='normal'?'selected':'').'>normal</option><option '.($t['priority']==='high'?'selected':'').'>high</option><option '.($t['priority']==='urgent'?'selected':'').'>urgent</option></select><label>Due date</label><input type="date" name="due_date" value="'.e($t['due_date']).'"><label>Assign to user</label><select name="assigned_user_id"><option value="">Unassigned</option>'; foreach($users as $usr) echo '<option value="'.e($usr['id']).'" '.((int)$t['assigned_user_id']===(int)$usr['id']?'selected':'').'>'.e(trim(($usr['first_name']??'').' '.($usr['last_name']??'')).($usr['callsign']?' - '.$usr['callsign']:'').' / '.$usr['email']).'</option>'; echo '</select><label>Description</label><textarea name="description">'.e(decrypt_value($t['description_encrypted'])).'</textarea><label>Action taken / history</label><textarea name="action_taken">'.e(decrypt_value($t['action_taken_encrypted'])).'</textarea><label>Cost</label><input name="cost" type="number" step="0.01" value="'.e($t['cost']).'"><button>Update ticket</button></form></details>'; } echo '</div>'; }
    echo '</div>';
    page_footer(); exit;
}

if (route() === 'committee_actions') {
    require_permission('view_committee_actions'); audit('committee_actions.view'); page_header('Committee actions');
    $members=all('SELECT id,first_name,last_name,callsign FROM members WHERE membership_status IN ("active","honorary","life_member") ORDER BY last_name,first_name');
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_permission('manage_committee_actions'); require_csrf();
        if (isset($_POST['add_action'])) {
            exec_sql('INSERT INTO committee_actions (title,status,priority,action_required,description_encrypted,due_date,assigned_user_id,assigned_member_id,created_by_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,datetime("now"),datetime("now"))',[
                trim($_POST['title']),'open',trim($_POST['priority'] ?? 'normal'),trim($_POST['action_required']),encrypt_value(trim($_POST['description'] ?? '')),trim($_POST['due_date'] ?? ''),null,!empty($_POST['assigned_member_id'])?(int)$_POST['assigned_member_id']:null,$u['id']
            ]);
            $newId=(int)db()->lastInsertId();
            record_action_history($newId, 'created', 'Action created', ['status'=>['from'=>null,'to'=>'open']]);
            audit('committee_action.create','committee_action',$newId); flash('Committee action created.'); redirect('committee_actions');
        }
        if (isset($_POST['add_action_update'])) {
            $aid=(int)$_POST['action_id'];
            record_action_history($aid, 'update', trim($_POST['update_note'] ?? ''), []);
            exec_sql('UPDATE committee_actions SET updated_at=datetime("now") WHERE id=?',[$aid]);
            audit('committee_action.note','committee_action',$aid); flash('Update added.'); redirect('committee_actions');
        }
        if (isset($_POST['update_action'])) {
            $aid=(int)$_POST['action_id']; $status=$_POST['status'] ?? 'open';
            $old=first('SELECT * FROM committee_actions WHERE id=?',[$aid]);
            if (!$old) { flash('Action not found.'); redirect('committee_actions'); }
            $newVals=[
                'title'=>trim($_POST['title']),
                'status'=>$status,
                'priority'=>trim($_POST['priority'] ?? 'normal'),
                'action_required'=>trim($_POST['action_required']),
                'description'=>trim($_POST['description'] ?? ''),
                'due_date'=>trim($_POST['due_date'] ?? ''),
                'assigned_member_id'=>!empty($_POST['assigned_member_id'])?(int)$_POST['assigned_member_id']:null,
            ];
            $changes=[];
            foreach(['title','status','priority','action_required','due_date','assigned_member_id'] as $field){
                $oldVal=$old[$field] ?? null; $newVal=$newVals[$field] ?? null;
                if ((string)$oldVal !== (string)$newVal) $changes[$field]=['from'=>$oldVal,'to'=>$newVal];
            }
            $oldDesc=decrypt_value($old['description_encrypted'] ?? '') ?: '';
            if ($oldDesc !== $newVals['description']) $changes['description']=['from'=>'[previous description]','to'=>'[updated description]'];
            exec_sql('UPDATE committee_actions SET title=?, status=?, priority=?, action_required=?, description_encrypted=?, due_date=?, assigned_user_id=NULL, assigned_member_id=?, completed_at=CASE WHEN ?="closed" THEN COALESCE(completed_at, datetime("now")) ELSE NULL END, updated_at=datetime("now") WHERE id=?',[
                $newVals['title'],$newVals['status'],$newVals['priority'],$newVals['action_required'],encrypt_value($newVals['description']),$newVals['due_date'],$newVals['assigned_member_id'],$status,$aid
            ]);
            record_action_history($aid, 'field_change', trim($_POST['update_note'] ?? ''), $changes);
            audit('committee_action.update','committee_action',$aid,'success',null,['changes'=>array_keys($changes)]); flash('Committee action updated.'); redirect('committee_actions');
        }
    }
    echo '<div class="card"><h1>Committee actions</h1><p class="muted">Track committee tasks/actions in a simple ticket-style list. Actions are assigned to members, and each ticket keeps an update/history log.</p></div>';
    if (has_permission('manage_committee_actions')) {
        echo '<div class="card"><h2>Create action</h2><form method="post">'.csrf_field().'<input type="hidden" name="add_action" value="1"><div class="two"><div><label>Action title</label><input name="title" required></div><div><label>Priority</label><select name="priority"><option>low</option><option selected>normal</option><option>high</option><option>urgent</option></select></div><div><label>Due date</label><input type="date" name="due_date"></div><div><label>Assign to member</label><select name="assigned_member_id"><option value="">Unassigned</option>'; foreach($members as $m) echo '<option value="'.e($m['id']).'">'.e($m['first_name'].' '.$m['last_name'].($m['callsign']?' - '.$m['callsign']:'' )).'</option>'; echo '</select></div></div><label>Action required</label><input name="action_required" required placeholder="What needs doing?"><label>Description</label><textarea name="description" placeholder="Notes, background, decisions, next steps"></textarea><button>Create action</button></form></div>';
    }
    $actions=all('SELECT ca.*,am.first_name member_first,am.last_name member_last,am.callsign member_callsign FROM committee_actions ca LEFT JOIN members am ON am.id=ca.assigned_member_id ORDER BY CASE ca.status WHEN "open" THEN 1 WHEN "in_progress" THEN 2 WHEN "closed" THEN 3 ELSE 4 END, date(ca.due_date) ASC, datetime(ca.created_at) DESC');
    echo '<div class="card"><h2>Action tickets</h2><div class="actions-board">';
    if(!$actions) echo '<p>No committee actions have been created yet.</p>';
    foreach($actions as $a){
        $cls=str_replace(' ','_',strtolower($a['status']));
        $assignedMember=trim(($a['member_first']??'').' '.($a['member_last']??'')).($a['member_callsign']?' - '.$a['member_callsign']:'');
        $history=all('SELECT cu.*,u.email,m.first_name,m.last_name,m.callsign FROM committee_action_updates cu LEFT JOIN users u ON u.id=cu.created_by_user_id LEFT JOIN members m ON m.id=u.member_id WHERE cu.action_id=? ORDER BY datetime(cu.created_at) DESC, cu.id DESC',[$a['id']]);
        echo '<div class="ticket '.e($cls).'"><div class="ticket-head"><div><strong>'.e($a['title']).'</strong><br><span class="muted">Created '.e($a['created_at']).' • Due '.e($a['due_date'] ?: 'not set').' • Assigned member: '.e($assignedMember ?: 'Unassigned').'</span></div><span class="status-pill status-'.e($cls).'">'.e($a['status']).'</span></div><p><strong>Action:</strong> '.e($a['action_required']).'</p><p>'.nl2br(e(decrypt_value($a['description_encrypted']))).'</p>';
        echo '<details><summary>Updates / history ('.e(count($history)).')</summary>';
        if(!$history) echo '<p class="muted">No updates yet.</p>';
        foreach($history as $h){
            $by=trim(($h['first_name']??'').' '.($h['last_name']??'')) ?: ($h['email'] ?: 'System');
            echo '<div class="asset-field"><span>'.e($h['created_at']).' • '.e($h['update_type']).' • '.e($by).'</span>';
            $note=decrypt_value($h['update_encrypted'] ?? ''); if($note) echo '<strong>'.nl2br(e($note)).'</strong>';
            $changes=json_decode($h['changes_json'] ?? '', true);
            if($changes){ echo '<ul class="small">'; foreach($changes as $field=>$change){ echo '<li><strong>'.e($field).':</strong> '.e($change['from'] ?? '').' → '.e($change['to'] ?? '').'</li>'; } echo '</ul>'; }
            echo '</div>';
        }
        echo '</details>';
        if(has_permission('manage_committee_actions')){
            echo '<details><summary>Add update</summary><form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="add_action_update" value="1"><input type="hidden" name="action_id" value="'.e($a['id']).'"><label>Update note</label><textarea name="update_note" placeholder="Add progress update, decision, phone call note, etc"></textarea><button>Add update</button></form></details>';
            echo '<details><summary>Edit action</summary><form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="update_action" value="1"><input type="hidden" name="action_id" value="'.e($a['id']).'"><label>Title</label><input name="title" value="'.e($a['title']).'" required><label>Status</label><select name="status"><option '.($a['status']==='open'?'selected':'').'>open</option><option '.($a['status']==='in_progress'?'selected':'').'>in_progress</option><option '.($a['status']==='closed'?'selected':'').'>closed</option><option '.($a['status']==='cancelled'?'selected':'').'>cancelled</option></select><label>Priority</label><select name="priority"><option '.($a['priority']==='low'?'selected':'').'>low</option><option '.($a['priority']==='normal'?'selected':'').'>normal</option><option '.($a['priority']==='high'?'selected':'').'>high</option><option '.($a['priority']==='urgent'?'selected':'').'>urgent</option></select><label>Due date</label><input type="date" name="due_date" value="'.e($a['due_date']).'"><label>Assign to member</label><select name="assigned_member_id"><option value="">Unassigned</option>'; foreach($members as $m) echo '<option value="'.e($m['id']).'" '.((int)$a['assigned_member_id']===(int)$m['id']?'selected':'').'>'.e($m['first_name'].' '.$m['last_name'].($m['callsign']?' - '.$m['callsign']:'' )).'</option>'; echo '</select><label>Action required</label><input name="action_required" value="'.e($a['action_required']).'" required><label>Description</label><textarea name="description">'.e(decrypt_value($a['description_encrypted'])).'</textarea><label>Update note / reason for change</label><textarea name="update_note" placeholder="Optional explanation for this edit"></textarea><button>Update action</button></form></details>';
        }
        echo '</div>';
    }
    echo '</div></div>';
    page_footer(); exit;
}

if (route() === 'event_attachment') {
    require_permission('view_events');
    $id = (int)($_GET['id'] ?? 0);
    $att = first('SELECT * FROM event_attachments WHERE id=?', [$id]);
    if (!$att) { http_response_code(404); exit('Attachment not found'); }
    audit('event.attachment.download', 'event_attachment', $id);
    $path = PRIVATE_PATH . '/event-attachments/' . $att['stored_filename'];
    if (!is_file($path)) { http_response_code(404); exit('File missing'); }
    header('Content-Type: ' . ($att['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes($att['original_filename']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path); exit;
}

if (route() === 'events') {
    require_permission('view_events'); audit('events.view'); page_header('Programme');
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_csrf();
        if (isset($_POST['signup'])) {
            exec_sql('INSERT OR IGNORE INTO event_attendance (event_id,member_id,status,signed_up_at,created_at,updated_at) VALUES (?,?,"signed_up",datetime("now"),datetime("now"),datetime("now"))',[(int)$_POST['event_id'],$u['member_id']]);
            audit('event.signup','event',(int)$_POST['event_id']); flash('Signed up.'); redirect('event_view&id='.(int)$_POST['event_id']);
        }
        if (!can_manage_events()) { require_permission('manage_events'); }
        $category = in_array($_POST['event_type'] ?? '', event_categories(), true) ? $_POST['event_type'] : 'Other';
        exec_sql('INSERT INTO events (title,event_type,description,location,start_at,end_at,visibility,max_attendees,created_by_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,datetime("now"),datetime("now"))',[trim($_POST['title']),$category,trim($_POST['description']),trim($_POST['location']),trim($_POST['start_at']),trim($_POST['end_at']),'members',(int)($_POST['max_attendees']?:0),$u['id']]);
        $event_id=(int)db()->lastInsertId();
        if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error']===UPLOAD_ERR_OK) {
            $blocked=['application/x-php','application/x-msdownload','application/javascript','text/html'];
            $mime=mime_content_type($_FILES['attachment']['tmp_name']) ?: 'application/octet-stream';
            if (!in_array($mime,$blocked,true)) {
                $stored=bin2hex(random_bytes(18)).'-'.preg_replace('/[^A-Za-z0-9._-]/','_',$_FILES['attachment']['name']);
                $dest=PRIVATE_PATH.'/event-attachments/'.$stored; if(!is_dir(dirname($dest))) mkdir(dirname($dest),0750,true); move_uploaded_file($_FILES['attachment']['tmp_name'],$dest);
                exec_sql('INSERT INTO event_attachments (event_id,original_filename,stored_filename,mime_type,file_size,uploaded_by_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,datetime("now"),datetime("now"))',[$event_id,$_FILES['attachment']['name'],$stored,$mime,(int)$_FILES['attachment']['size'],$u['id']]);
                audit('event.attachment_uploaded','event',$event_id);
            }
        }
        audit('event.create','event',$event_id); flash('Event added and is now available in attendance tracking.'); redirect('event_view&id='.$event_id);
    }
    $view = $_GET['view'] ?? 'list';
    $month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
    echo '<div class="card"><div class="toolbar"><h1 style="margin-right:auto">Programme</h1><a class="btn secondary" href="?route=events&view=list">List view</a><a class="btn secondary" href="?route=events&view=calendar&month='.e($month).'">Calendar view</a>';
    if (can_manage_events()) echo '<button type="button" onclick="document.getElementById(\'addEventDialog\').showModal()">Add event</button>';
    echo '</div><p class="muted">Click an event to open the full details, attachments, timings and location.</p></div>';
    if ($view === 'calendar') {
        $start = new DateTime($month . '-01');
        $firstDay = clone $start;
        $gridStart = clone $firstDay; $gridStart->modify('-'.(((int)$firstDay->format('N'))-1).' days');
        $gridEnd = clone $gridStart; $gridEnd->modify('+41 days');
        $prev=(clone $start)->modify('-1 month')->format('Y-m'); $next=(clone $start)->modify('+1 month')->format('Y-m');
        $events = all('SELECT * FROM events WHERE date(start_at) BETWEEN ? AND ? ORDER BY start_at ASC',[$gridStart->format('Y-m-d'),$gridEnd->format('Y-m-d')]);
        $byDate=[]; foreach($events as $ev){ $byDate[substr($ev['start_at'],0,10)][]=$ev; }
        echo '<div class="card"><div class="toolbar"><a class="btn secondary" href="?route=events&view=calendar&month='.e($prev).'">‹ Previous</a><h2 style="margin-right:auto">'.e($start->format('F Y')).'</h2><a class="btn secondary" href="?route=events&view=calendar&month='.e($next).'">Next ›</a></div><div class="calendar">';
        foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d) echo '<div class="calendar-head">'.$d.'</div>';
        $day=clone $gridStart;
        for($i=0;$i<42;$i++){
            $date=$day->format('Y-m-d'); $muted=$day->format('Y-m')!==$month?' muted-day':'';
            echo '<div class="calendar-day'.$muted.'"><div class="calendar-date">'.e($day->format('j')).'</div>';
            foreach(($byDate[$date]??[]) as $ev) echo '<a class="calendar-event" href="?route=event_view&id='.e($ev['id']).'"><span class="small">'.e(date('H:i', strtotime($ev['start_at']))).'</span> '.e($ev['title']).'<br><span class="small">'.e($ev['event_type']).'</span></a>';
            echo '</div>'; $day->modify('+1 day');
        }
        echo '</div></div>';
    } else {
        $rows=all('SELECT e.*,COUNT(a.id) attendee_count FROM events e LEFT JOIN event_attendance a ON a.event_id=e.id AND a.status IN ("signed_up","attended") GROUP BY e.id ORDER BY e.start_at ASC');
        echo '<div class="card"><h2>Event list</h2><div class="event-list">';
        if (!$rows) echo '<p>No events have been added yet.</p>';
        foreach($rows as $ev){ echo '<div class="event-row"><div><h3><a href="?route=event_view&id='.e($ev['id']).'">'.e($ev['title']).'</a></h3><p class="muted"><span class="pill category-pill">'.e($ev['event_type'] ?: 'Other').'</span> • '.e(date('D j M Y H:i', strtotime($ev['start_at']))).($ev['end_at']?' to '.e(date('H:i', strtotime($ev['end_at']))):'').'</p><p>'.nl2br(e(mb_strimwidth((string)$ev['description'],0,220,'…'))).'</p><p><strong>Location:</strong> '.e($ev['location'] ?: 'TBC').' • <strong>Signed up:</strong> '.e($ev['attendee_count']).'</p></div><div class="event-actions"><a class="btn" href="?route=event_view&id='.e($ev['id']).'">Open</a></div></div>'; }
        echo '</div></div>';
    }
    if(can_manage_events()) {
        echo '<dialog class="modal" id="addEventDialog"><div class="card"><div class="modal-head"><h2>Add event</h2><form method="dialog"><button class="icon-btn" type="submit">✕</button></form></div><form method="post" enctype="multipart/form-data">'.csrf_field().'<div class="two"><div><label>Title</label><input name="title" placeholder="Title" required></div><div><label>Category</label><select name="event_type">'.event_category_options('Club Night').'</select></div><div><label>Location</label><input name="location" placeholder="Location"></div><div><label>Start</label><input name="start_at" type="datetime-local" required></div><div><label>End</label><input name="end_at" type="datetime-local"></div><div><label>Max attendees</label><input name="max_attendees" type="number" placeholder="Max attendees"></div></div><label>Description</label><textarea name="description" placeholder="Description"></textarea><label>Attachment</label><input type="file" name="attachment"><p><button>Add event</button> <button class="secondary" type="button" onclick="document.getElementById(\'addEventDialog\').close()">Cancel</button></p></form></div></dialog>';
    }
    page_footer(); exit;
}

if (route() === 'event_view') {
    require_permission('view_events');
    $id=(int)($_GET['id']??0); $ev=first('SELECT * FROM events WHERE id=?',[$id]); if(!$ev) redirect('events');
    audit('event.view','event',$id);
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_csrf();
        if (isset($_POST['signup'])) { exec_sql('INSERT OR IGNORE INTO event_attendance (event_id,member_id,status,signed_up_at,created_at,updated_at) VALUES (?,? ,"signed_up",datetime("now"),datetime("now"),datetime("now"))',[$id,$u['member_id']]); audit('event.signup','event',$id); flash('Signed up.'); redirect('event_view&id='.$id); }
        if (isset($_POST['add_member_attendance'])) { require_permission('track_attendance'); $memberId=(int)$_POST['member_id']; if($memberId>0){ exec_sql('INSERT OR IGNORE INTO event_attendance (event_id,member_id,status,signed_up_at,created_at,updated_at) VALUES (?,? ,"signed_up",datetime("now"),datetime("now"),datetime("now"))',[$id,$memberId]); audit('attendance.member_added','event',$id,'success',null,['member_id'=>$memberId]); flash('Member added to attendance list.'); } redirect('event_view&id='.$id); }
        if (isset($_POST['delete_event'])) { if (!can_manage_events()) require_permission('manage_events'); exec_sql('DELETE FROM event_attendance WHERE event_id=?',[$id]); exec_sql('DELETE FROM event_guests WHERE event_id=?',[$id]); exec_sql('DELETE FROM event_attachments WHERE event_id=?',[$id]); exec_sql('DELETE FROM events WHERE id=?',[$id]); audit('event.delete','event',$id); flash('Event deleted.'); redirect('events'); }
        if (isset($_POST['add_guest_attendance'])) { require_permission('track_attendance'); $guestName=trim($_POST['guest_name'] ?? ''); if($guestName!==''){ exec_sql('INSERT INTO event_guests (event_id,name,comment_encrypted,attended,added_by_user_id,created_at,updated_at) VALUES (?,?,?,?,?,datetime("now"),datetime("now"))',[$id,$guestName,encrypt_value(trim($_POST['guest_comment'] ?? '')),0,$u['id']]); audit('attendance.guest_added','event',$id); flash('Guest / visitor added.'); } redirect('event_view&id='.$id); }
        if (isset($_POST['mark_attendance'])) { require_permission('track_attendance');
            $checkedMembers=$_POST['member_attended'] ?? [];
            $activeMembers=all('SELECT m.id, ea.id attendance_id FROM members m LEFT JOIN event_attendance ea ON ea.member_id=m.id AND ea.event_id=? WHERE m.membership_status IN ("active","honorary","life_member")',[$id]);
            foreach($activeMembers as $row){
                $memberId=(int)$row['id'];
                $isAttended=isset($checkedMembers[$memberId]) ? 1 : 0;
                if ($isAttended) {
                    exec_sql('INSERT OR IGNORE INTO event_attendance (event_id,member_id,status,attended,signed_up_at,marked_at,marked_by_user_id,created_at,updated_at) VALUES (?, ?, "attended", 1, COALESCE((SELECT signed_up_at FROM event_attendance WHERE event_id=? AND member_id=?), datetime("now")), datetime("now"), ?, datetime("now"), datetime("now"))',[$id,$memberId,$id,$memberId,$u['id']]);
                    exec_sql('UPDATE event_attendance SET attended=1, status="attended", marked_at=datetime("now"), marked_by_user_id=?, updated_at=datetime("now") WHERE event_id=? AND member_id=?',[$u['id'],$id,$memberId]);
                } elseif (!empty($row['attendance_id'])) {
                    exec_sql('UPDATE event_attendance SET attended=0, status="did_not_attend", marked_at=datetime("now"), marked_by_user_id=?, updated_at=datetime("now") WHERE event_id=? AND member_id=?',[$u['id'],$id,$memberId]);
                }
            }
            $guestRows=all('SELECT id FROM event_guests WHERE event_id=?',[$id]); $checkedGuests=$_POST['guest_attended'] ?? []; foreach($guestRows as $row){ $guestId=(int)$row['id']; $isAttended=isset($checkedGuests[$guestId]) ? 1 : 0; $comment=trim($_POST['guest_comment_existing'][$guestId] ?? ''); exec_sql('UPDATE event_guests SET attended=?, comment_encrypted=?, updated_at=datetime("now") WHERE event_id=? AND id=?',[$isAttended,encrypt_value($comment),$id,$guestId]); }
            audit('attendance.update','event',$id); flash('Attendance updated.'); redirect('event_view&id='.$id); }
    }
    page_header($ev['title']);
    echo '<div class="card"><div class="toolbar"><h1 style="margin-right:auto">'.e($ev['title']).'</h1>';
    if (can_manage_events()) echo '<a class="btn secondary" href="?route=event_edit&id='.e($id).'">Edit</a><form method="post" onsubmit="return confirm(\'Delete this event?\')" style="display:inline">'.csrf_field().'<button class="danger" name="delete_event" value="1">Delete</button></form>';
    echo '</div><p class="muted">'.e($ev['event_type']).'</p><div class="grid"><div><strong>Starts</strong><br>'.e(date('D j M Y H:i', strtotime($ev['start_at']))).'</div><div><strong>Ends</strong><br>'.e($ev['end_at'] ? date('D j M Y H:i', strtotime($ev['end_at'])) : 'Not set').'</div><div><strong>Location</strong><br>'.e($ev['location'] ?: 'TBC').'</div><div><strong>Max attendees</strong><br>'.e($ev['max_attendees'] ?: 'No limit').'</div></div><h2>Description</h2><p>'.nl2br(e($ev['description'] ?: 'No description added.')).'</p>';
    $atts=all('SELECT * FROM event_attachments WHERE event_id=? ORDER BY created_at DESC',[$id]); echo '<h2>Attachments</h2>'; if(!$atts) echo '<p>No attachments.</p>'; else { echo '<ul>'; foreach($atts as $a) echo '<li><a href="?route=event_attachment&id='.e($a['id']).'">'.e($a['original_filename']).'</a> <span class="muted">'.e(round($a['file_size']/1024,1)).' KB</span></li>'; echo '</ul>'; }
    echo '<form method="post">'.csrf_field().'<input type="hidden" name="signup" value="1"><button>Sign up / mark me attending</button></form></div>';
    if (is_committee_or_admin()) {
        $membersForRegister=all('SELECT m.id,m.first_name,m.last_name,m.callsign,m.email,ea.status,ea.attended,ea.signed_up_at FROM members m LEFT JOIN event_attendance ea ON ea.member_id=m.id AND ea.event_id=? WHERE m.membership_status IN ("active","honorary","life_member") ORDER BY m.last_name,m.first_name',[$id]);
        $guests=all('SELECT * FROM event_guests WHERE event_id=? ORDER BY name',[$id]);
        $memberTotal=count($membersForRegister);
        $memberPresent=0;
        foreach($membersForRegister as $row){ if((string)$row['attended']==='1') $memberPresent++; }
        $guestPresent=0;
        foreach($guests as $row){ if((string)$row['attended']==='1') $guestPresent++; }
        $memberAbsent=max(0,$memberTotal-$memberPresent);
        echo '<div class="card attendance-modern" data-attendance-register>';
        echo '<div class="attendance-modern-head"><div><h2>'.e($ev['title']).'</h2><div class="attendance-date">'.e(date('l j F Y, H:i', strtotime($ev['start_at']))).'</div><p class="muted">Committee members can mark attendance here. Members can still self sign up in advance from the event page.</p></div><div class="attendance-counts"><div><span>Members</span><strong class="present" data-count-members>'.e($memberPresent).'</strong></div><div><span>Guests</span><strong class="guest" data-count-guests>'.e($guestPresent).'</strong></div><div><span>Absent</span><strong class="absent" data-count-absent>'.e($memberAbsent).'</strong></div></div></div>';
        echo '<form method="post">'.csrf_field().'<input type="hidden" name="mark_attendance" value="1">';
        echo '<div class="attendance-modern-controls"><div class="attendance-search-wrap"><input class="attendance-search" data-att-search placeholder="Search members..."></div><select class="attendance-filter" data-att-filter><option value="all">All</option><option value="members">Members</option><option value="guests">Guests</option><option value="present">Present</option><option value="absent">Absent</option><option value="signed_up">Signed up</option></select><button type="button" class="secondary" data-att-all>✓ All</button><button type="button" class="secondary" data-att-none>× None</button></div>';
        echo '<div class="attendance-modern-list">';
        if(!$membersForRegister && !$guests) echo '<div class="attendance-empty">No active members or guests found for this register.</div>';
        foreach($membersForRegister as $a){
            $present=(string)$a['attended']==='1';
            $signed=($a['status']==='signed_up');
            $filterStatus=$present?'present':($signed?'signed_up':'absent');
            $statusLabel=$present?'Present':($signed?'Signed up':'Absent');
            $secondary=$a['callsign'] ?: $a['email'];
            echo '<label class="attendance-modern-row" data-att-row data-kind="member" data-status="'.e($filterStatus).'" data-name="'.e(strtolower($a['first_name'].' '.$a['last_name'].' '.$a['callsign'].' '.$a['email'])).'"><input class="attendance-check" type="checkbox" name="member_attended['.e($a['id']).']" value="1" '.($present?'checked':'').'><span class="attendance-person"><strong>'.e($a['first_name'].' '.$a['last_name']).'</strong><span>'.e($secondary ?: 'No callsign/email recorded').'</span></span><span class="attendance-row-status '.($present?'present':'').'" data-row-status>'.e($statusLabel).'</span></label>';
        }
        foreach($guests as $g){
            $present=(string)$g['attended']==='1';
            $comment=decrypt_value($g['comment_encrypted']);
            echo '<label class="attendance-modern-row" data-att-row data-kind="guest" data-status="'.($present?'present':'absent').'" data-name="'.e(strtolower($g['name'].' '.$comment)).'"><input class="attendance-check" type="checkbox" name="guest_attended['.e($g['id']).']" value="1" '.($present?'checked':'').'><span class="attendance-person"><strong>'.e($g['name']).'</strong><span><input name="guest_comment_existing['.e($g['id']).']" value="'.e($comment).'" placeholder="Guest comments"></span></span><span class="attendance-row-status '.($present?'present':'guest').'" data-row-status>'.e($present?'Present':'Guest absent').'</span></label>';
        }
        echo '</div><div class="attendance-savebar"><span class="muted">Tick members/guests who attended, then save the register.</span><button>Save attendance register</button></div></form>';
        echo '<form method="post" class="attendance-modern-footer">'.csrf_field().'<input type="hidden" name="add_guest_attendance" value="1"><div><label>Add visitor / guest</label><input name="guest_name" placeholder="Guest / visitor name"></div><div><label>Guest comments</label><input name="guest_comment" placeholder="Notes, callsign, reason for attending, etc."></div><button>Add guest</button></form>';
        echo '</div>';
        echo '<script>(function(){var root=document.querySelector("[data-attendance-register]");if(!root)return;var search=root.querySelector("[data-att-search]"),filter=root.querySelector("[data-att-filter]");function rows(){return Array.prototype.slice.call(root.querySelectorAll("[data-att-row]"));}function update(){var q=(search.value||"").toLowerCase(),f=filter.value||"all",mp=0,gp=0,totalMembers=0;rows().forEach(function(r){var cb=r.querySelector(".attendance-check"),kind=r.dataset.kind,status=cb.checked?"present":(r.dataset.status==="signed_up"?"signed_up":"absent"),name=r.dataset.name||"";r.dataset.status=status;var okSearch=!q||name.indexOf(q)>-1;var okFilter=f==="all"||(f==="members"&&kind==="member")||(f==="guests"&&kind==="guest")||f===status; r.style.display=(okSearch&&okFilter)?"grid":"none";var label=r.querySelector("[data-row-status]");if(label){label.classList.toggle("present",cb.checked);if(kind==="guest"&&!cb.checked){label.classList.add("guest");}else{label.classList.remove("guest");}label.textContent=cb.checked?"Present":(kind==="guest"?"Guest absent":(status==="signed_up"?"Signed up":"Absent"));} if(kind==="member"){totalMembers++; if(cb.checked)mp++;} if(kind==="guest"&&cb.checked)gp++;});root.querySelector("[data-count-members]").textContent=mp;root.querySelector("[data-count-guests]").textContent=gp;root.querySelector("[data-count-absent]").textContent=Math.max(0,totalMembers-mp);}root.querySelector("[data-att-all]").addEventListener("click",function(){rows().forEach(function(r){if(r.style.display!=="none")r.querySelector(".attendance-check").checked=true;});update();});root.querySelector("[data-att-none]").addEventListener("click",function(){rows().forEach(function(r){if(r.style.display!=="none")r.querySelector(".attendance-check").checked=false;});update();});root.addEventListener("change",function(e){if(e.target.matches(".attendance-check,[data-att-filter]"))update();});search.addEventListener("input",update);update();})();</script>';
    }

    page_footer(); exit;
}

if (route() === 'event_edit') {
    if (!can_manage_events()) require_permission('manage_events');
    $id=(int)($_GET['id']??0); $ev=first('SELECT * FROM events WHERE id=?',[$id]); if(!$ev) redirect('events');
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_csrf();
        $category = in_array($_POST['event_type'] ?? '', event_categories(), true) ? $_POST['event_type'] : 'Other';
        exec_sql('UPDATE events SET title=?, event_type=?, description=?, location=?, start_at=?, end_at=?, max_attendees=?, updated_at=datetime("now") WHERE id=?',[trim($_POST['title']),$category,trim($_POST['description']),trim($_POST['location']),trim($_POST['start_at']),trim($_POST['end_at']),(int)($_POST['max_attendees']?:0),$id]);
        if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error']===UPLOAD_ERR_OK) { $mime=mime_content_type($_FILES['attachment']['tmp_name']) ?: 'application/octet-stream'; $stored=bin2hex(random_bytes(18)).'-'.preg_replace('/[^A-Za-z0-9._-]/','_',$_FILES['attachment']['name']); $dest=PRIVATE_PATH.'/event-attachments/'.$stored; if(!is_dir(dirname($dest))) mkdir(dirname($dest),0750,true); move_uploaded_file($_FILES['attachment']['tmp_name'],$dest); exec_sql('INSERT INTO event_attachments (event_id,original_filename,stored_filename,mime_type,file_size,uploaded_by_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,datetime("now"),datetime("now"))',[$id,$_FILES['attachment']['name'],$stored,$mime,(int)$_FILES['attachment']['size'],$u['id']]); audit('event.attachment_uploaded','event',$id); }
        audit('event.update','event',$id); flash('Event updated.'); redirect('event_view&id='.$id);
    }
    page_header('Edit event');
    echo '<div class="card"><h1>Edit event</h1><form method="post" enctype="multipart/form-data">'.csrf_field().'<div class="two"><div><label>Title</label><input name="title" value="'.e($ev['title']).'" required></div><div><label>Category</label><select name="event_type">'.event_category_options($ev['event_type']).'</select></div><div><label>Location</label><input name="location" value="'.e($ev['location']).'"></div><div><label>Start</label><input name="start_at" type="datetime-local" value="'.e(str_replace(' ','T',substr($ev['start_at'],0,16))).'" required></div><div><label>End</label><input name="end_at" type="datetime-local" value="'.e($ev['end_at']?str_replace(' ','T',substr($ev['end_at'],0,16)):'').'"></div><div><label>Max attendees</label><input name="max_attendees" type="number" value="'.e($ev['max_attendees']).'"></div></div><label>Description</label><textarea name="description">'.e($ev['description']).'</textarea><label>Add attachment</label><input type="file" name="attachment"><p><button>Save changes</button> <a class="btn secondary" href="?route=event_view&id='.e($id).'">Cancel</a></p></form></div>';
    page_footer(); exit;
}

if (route() === 'attendance') {
    require_permission('track_attendance'); audit('attendance.view'); page_header('Attendance tracking');
    $future=all('SELECT e.* FROM events e WHERE datetime(e.start_at) >= datetime("now") ORDER BY datetime(e.start_at) ASC');
    $past=all('SELECT e.* FROM events e WHERE datetime(e.start_at) < datetime("now") ORDER BY datetime(e.start_at) DESC');
    echo '<div class="card"><div class="toolbar"><h1 style="margin-right:auto">Attendance tracking</h1><a class="btn secondary" href="?route=attendance_stats">Attendance stats</a></div><p class="muted">Future meetings show closest first. Past meetings show most recent first.</p></div>';
    $render = function(array $events, string $title) {
        echo '<div class="card"><h2>'.e($title).'</h2>';
        if (!$events) { echo '<p>No events in this section.</p></div>'; return; }
        echo '<table><tr><th>Event</th><th>Category</th><th>Date</th><th>Members listed</th><th>Guests listed</th><th>Total attended</th><th>Action</th></tr>';
        foreach($events as $ev){ $c=event_attendance_counts((int)$ev['id']); echo '<tr><td>'.e($ev['title']).'</td><td>'.e($ev['event_type'] ?: 'Other').'</td><td>'.e(date('D j M Y H:i', strtotime($ev['start_at']))).'</td><td>'.e($c['member_total']).'</td><td>'.e($c['guest_total']).'</td><td>'.e($c['total_attended']).'</td><td><a class="btn secondary" href="?route=event_view&id='.e($ev['id']).'">Open register</a></td></tr>'; }
        echo '</table></div>';
    };
    $render($future, 'Upcoming events');
    $render($past, 'Past events');
    page_footer(); exit;
}

if (route() === 'attendance_stats') {
    require_permission('track_attendance'); audit('attendance.stats.view'); page_header('Attendance stats');
    echo '<div class="card"><h1>Attendance stats</h1><p class="muted">Stats include member attendance plus visitors/guests where recorded.</p></div>';
    $types = all('SELECT COALESCE(NULLIF(event_type,""),"Other") event_type, COUNT(*) event_count FROM events GROUP BY COALESCE(NULLIF(event_type,""),"Other") ORDER BY event_type');
    echo '<div class="card"><h2>Attendance by event type</h2><table><tr><th>Event type</th><th>Events</th><th>Total member attendance</th><th>Total guest attendance</th><th>Total attendance</th><th>Average total/event</th><th>Average members/event</th><th>Average guests/event</th></tr>';
    foreach($types as $t){ $type=$t['event_type']; $stats=first('SELECT COUNT(DISTINCT e.id) events, COUNT(CASE WHEN ea.attended=1 THEN 1 END) member_attended FROM events e LEFT JOIN event_attendance ea ON ea.event_id=e.id WHERE COALESCE(NULLIF(e.event_type,""),"Other")=?',[$type]); $guest=first('SELECT COUNT(CASE WHEN g.attended=1 THEN 1 END) guest_attended FROM events e LEFT JOIN event_guests g ON g.event_id=e.id WHERE COALESCE(NULLIF(e.event_type,""),"Other")=?',[$type]); $events=max(1,(int)$stats['events']); $ma=(int)($stats['member_attended'] ?? 0); $ga=(int)($guest['guest_attended'] ?? 0); echo '<tr><td>'.e($type).'</td><td>'.e($stats['events']).'</td><td>'.e($ma).'</td><td>'.e($ga).'</td><td>'.e($ma+$ga).'</td><td>'.e(round(($ma+$ga)/$events,1)).'</td><td>'.e(round($ma/$events,1)).'</td><td>'.e(round($ga/$events,1)).'</td></tr>'; }
    echo '</table></div>';
    $months = all('SELECT strftime("%Y-%m", start_at) ym, COUNT(*) event_count FROM events GROUP BY ym ORDER BY ym DESC');
    echo '<div class="card"><h2>Attendance by month & year</h2><table><tr><th>Month</th><th>Events</th><th>Member attendance</th><th>Guest attendance</th><th>Total attendance</th><th>Average/event</th></tr>';
    foreach($months as $mrow){ $ym=$mrow['ym']; $stats=first('SELECT COUNT(DISTINCT e.id) events, COUNT(CASE WHEN ea.attended=1 THEN 1 END) member_attended FROM events e LEFT JOIN event_attendance ea ON ea.event_id=e.id WHERE strftime("%Y-%m", e.start_at)=?',[$ym]); $guest=first('SELECT COUNT(CASE WHEN g.attended=1 THEN 1 END) guest_attended FROM events e LEFT JOIN event_guests g ON g.event_id=e.id WHERE strftime("%Y-%m", e.start_at)=?',[$ym]); $events=max(1,(int)$stats['events']); $ma=(int)($stats['member_attended'] ?? 0); $ga=(int)($guest['guest_attended'] ?? 0); echo '<tr><td>'.e(date('F Y', strtotime($ym.'-01'))).'</td><td>'.e($stats['events']).'</td><td>'.e($ma).'</td><td>'.e($ga).'</td><td>'.e($ma+$ga).'</td><td>'.e(round(($ma+$ga)/$events,1)).'</td></tr>'; }
    echo '</table></div>';
    $people=all('SELECT m.id,m.first_name,m.last_name,m.callsign, COUNT(ea.id) listed, SUM(CASE WHEN ea.attended=1 THEN 1 ELSE 0 END) attended FROM members m LEFT JOIN event_attendance ea ON ea.member_id=m.id GROUP BY m.id ORDER BY attended DESC, m.last_name, m.first_name');
    echo '<div class="card"><h2>Attendance by person</h2><table><tr><th>Member</th><th>Callsign</th><th>Listed/signed up</th><th>Attended</th><th>Attendance %</th></tr>';
    foreach($people as $p){ echo '<tr><td>'.e($p['first_name'].' '.$p['last_name']).'</td><td>'.e($p['callsign']).'</td><td>'.e((int)$p['listed']).'</td><td>'.e((int)$p['attended']).'</td><td>'.e(percent_display($p['attended'],$p['listed'])).'</td></tr>'; }
    echo '</table></div>';
    page_footer(); exit;
}

if (route() === 'brickworks') {
    require_permission('view_own_brickworks'); page_header('Brickworks'); audit('brickworks.progress.view');
    $participant=first('SELECT * FROM brickworks_participants WHERE member_id=?',[$u['member_id']]);
    if (!$participant && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['join'])) { require_csrf(); exec_sql('INSERT INTO brickworks_participants (member_id,status,joined_at,created_at,updated_at) VALUES (?,"active",datetime("now"),datetime("now"),datetime("now"))',[$u['member_id']]); $pid=(int)db()->lastInsertId(); foreach(all('SELECT id FROM brickworks_criteria WHERE active=1') as $c) exec_sql('INSERT OR IGNORE INTO brickworks_progress (participant_id,criterion_id,created_at,updated_at) VALUES (?,?,datetime("now"),datetime("now"))',[$pid,$c['id']]); assign_role((int)$u['id'],'brickworks_participant',(int)$u['id'],'Joined Brickworks'); audit('brickworks.join','member',(int)$u['member_id']); flash('You have joined Brickworks.'); redirect('brickworks'); }
    if (!$participant) { $manageButton = (has_permission('review_brickworks_evidence') || has_permission('manage_brickworks_criteria')) ? '<a class="btn secondary" href="?route=brickworks_manage">Brickworks management</a>' : ''; echo '<div class="card"><div class="toolbar"><h1 style="margin-right:auto">Brickworks Scheme</h1>'.$manageButton.'</div><p>You are not signed up yet.</p><form method="post">'.csrf_field().'<input type="hidden" name="join" value="1"><button>Join Brickworks</button></form></div>'; page_footer(); exit; }
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_csrf();
        $progress_id=(int)$_POST['progress_id']; $p=first('SELECT bp.* FROM brickworks_progress bp JOIN brickworks_participants b ON b.id=bp.participant_id WHERE bp.id=? AND b.member_id=?',[$progress_id,$u['member_id']]);
        if ($p && isset($_POST['submit_evidence'])) {
            exec_sql('UPDATE brickworks_progress SET status="pending_approval", member_comment_encrypted=?, submitted_at=datetime("now"), updated_at=datetime("now") WHERE id=?',[encrypt_value(trim($_POST['member_comment'] ?? '')),$progress_id]);
            if (!empty($_FILES['evidence']['name']) && $_FILES['evidence']['error']===UPLOAD_ERR_OK) {
                $allowed=['application/pdf','image/jpeg','image/png','text/plain','text/csv','application/octet-stream'];
                $mime=mime_content_type($_FILES['evidence']['tmp_name']) ?: 'application/octet-stream';
                $stored=bin2hex(random_bytes(18)).'-'.preg_replace('/[^A-Za-z0-9._-]/','_',$_FILES['evidence']['name']);
                $dest=PRIVATE_PATH.'/brickworks-evidence/'.$stored; if(!is_dir(dirname($dest))) mkdir(dirname($dest),0750,true); move_uploaded_file($_FILES['evidence']['tmp_name'],$dest);
                exec_sql('INSERT INTO brickworks_evidence (progress_id,uploaded_by_user_id,original_filename,stored_filename,mime_type,file_size,encrypted_file_path,evidence_comment_encrypted,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,"submitted",datetime("now"),datetime("now"))',[$progress_id,$u['id'],$_FILES['evidence']['name'],$stored,$mime,(int)$_FILES['evidence']['size'],encrypt_value($dest),encrypt_value($_POST['member_comment']??'')]);
            }
            audit('brickworks.evidence.upload','brickworks_progress',$progress_id); flash('Evidence submitted for approval.'); redirect('brickworks');
        }
    }
    $totalCriteria=(int)first('SELECT COUNT(*) c FROM brickworks_criteria WHERE active=1')['c'];
    $complete=(int)first('SELECT COUNT(*) c FROM brickworks_progress WHERE participant_id=? AND status="complete"',[$participant['id']])['c'];
    $pending=(int)first('SELECT COUNT(*) c FROM brickworks_progress WHERE participant_id=? AND status="pending_approval"',[$participant['id']])['c'];
    $award=brickworks_award($complete);
    $pct=$totalCriteria?min(100,round($complete/$totalCriteria*100)):0;
    $manageButton = (has_permission('review_brickworks_evidence') || has_permission('manage_brickworks_criteria')) ? '<a class="btn" href="?route=brickworks_manage">Brickworks management</a>' : '';
    echo '<div class="card bw-hero"><div><div class="toolbar"><h1 style="margin-right:auto">Brickworks Scheme</h1>'.$manageButton.'</div><p class="muted">Track your criteria, upload evidence and see reviewer feedback in one place.</p><div class="progressbar"><span style="width:'.e($pct).'%"></span></div><div class="bw-steps"><div class="bw-step"><span class="muted">Completed</span><br><strong>'.e($complete).' / '.e($totalCriteria).'</strong></div><div class="bw-step"><span class="muted">Pending approval</span><br><strong>'.e($pending).'</strong></div><div class="bw-step"><span class="muted">Current award</span><br><strong>'.e($award ?: 'None yet').'</strong></div></div></div><div class="bw-score">'.e($pct).'%</div></div>';
    $rows=all('SELECT bp.*,bc.title,bc.description,bc.evidence_guidance,bt.name theme FROM brickworks_progress bp JOIN brickworks_criteria bc ON bc.id=bp.criterion_id JOIN brickworks_themes bt ON bt.id=bc.theme_id WHERE bp.participant_id=? ORDER BY bt.sort_order,bc.sort_order',[$participant['id']]);
    echo '<div class="card"><div class="toolbar"><h2 style="margin-right:auto">Criteria</h2><span class="pill">'.e(count($rows)).' items</span></div><div class="bw-grid">';
    foreach($rows as $r){
        $isComplete=$r['status']==='complete';
        $isPending=$r['status']==='pending_approval';
        $status=$isComplete?'Complete - '.$r['completed_at']:($isPending?'In progress / Pending approval':'Not completed');
        $statusClass=$isComplete?'complete':($isPending?'pending':'none');
        $memberComment=decrypt_value($r['member_comment_encrypted']);
        $reviewerComment=decrypt_value($r['reviewer_comment_encrypted']);
        echo '<section class="bw-card"><div class="bw-card-head"><div><div class="bw-theme">'.e($r['theme']).'</div><h3>'.e($r['title']).'</h3></div><span class="bw-status '.e($statusClass).'">'.e($status).'</span></div><p>'.e($r['description']).'</p>';
        if(!empty($r['evidence_guidance'])) echo '<p class="bw-muted-line"><strong>Evidence guidance:</strong> '.e($r['evidence_guidance']).'</p>';
        if($memberComment || $reviewerComment){ echo '<div class="bw-comments">'; if($memberComment) echo '<p><strong>Your note:</strong><br>'.nl2br(e($memberComment)).'</p>'; if($reviewerComment) echo '<p><strong>Reviewer feedback:</strong><br><em>'.nl2br(e($reviewerComment)).'</em></p>'; echo '</div>'; }
        if(!$isComplete){ echo '<form class="bw-form" method="post" enctype="multipart/form-data">'.csrf_field().'<input type="hidden" name="submit_evidence" value="1"><input type="hidden" name="progress_id" value="'.e($r['id']).'"><label>Evidence notes / comments</label><textarea name="member_comment" placeholder="Add a short note for the reviewer">'.e($memberComment).'</textarea><label>Upload evidence</label><input type="file" name="evidence"><button>Submit evidence for approval</button></form>'; }
        else { echo '<p class="muted">This criterion has been approved. No further evidence is needed.</p>'; }
        echo '</section>';
    }
    echo '</div></div>';
    if(has_permission('review_brickworks_evidence')) echo '<div class="card"><p><a class="btn" href="?route=brickworks_manage">Open Brickworks management</a> <a class="btn secondary" href="?route=brickworks_review">Pending only</a></p></div>';
    page_footer(); exit;
}

if (route() === 'brickworks_evidence') {
    require_permission('review_brickworks_evidence');
    $id=(int)($_GET['id'] ?? 0);
    $ev=first('SELECT * FROM brickworks_evidence WHERE id=?',[$id]);
    if(!$ev){ http_response_code(404); exit('Evidence not found'); }
    audit('brickworks.evidence.download','brickworks_evidence',$id);
    $path=decrypt_value($ev['encrypted_file_path']);
    if(!$path || !is_file($path)){ http_response_code(404); exit('File missing'); }
    header('Content-Type: '.($ev['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="'.addslashes($ev['original_filename']).'"');
    header('Content-Length: '.filesize($path));
    readfile($path); exit;
}


if (route() === 'brickworks_export') {
    require_permission('export_brickworks_reports');
    audit('brickworks.export');
    $criteria=all('SELECT bc.*,bt.name theme FROM brickworks_criteria bc JOIN brickworks_themes bt ON bt.id=bc.theme_id WHERE bc.active=1 ORDER BY bt.sort_order,bc.sort_order');
    $participants=all('SELECT b.*,m.first_name,m.last_name,m.callsign,m.id member_id FROM brickworks_participants b JOIN members m ON m.id=b.member_id ORDER BY m.last_name,m.first_name');
    foreach($participants as $part){ foreach($criteria as $c){ exec_sql('INSERT OR IGNORE INTO brickworks_progress (participant_id,criterion_id,created_at,updated_at) VALUES (?,?,datetime("now"),datetime("now"))',[$part['id'],$c['id']]); } }
    $progressRows=all('SELECT bp.*,b.member_id FROM brickworks_progress bp JOIN brickworks_participants b ON b.id=bp.participant_id');
    $progress=[]; foreach($progressRows as $pr){ $progress[(int)$pr['participant_id']][(int)$pr['criterion_id']]=$pr; }
    $evidenceCounts=all('SELECT bp.participant_id,bp.criterion_id,COUNT(be.id) c FROM brickworks_progress bp LEFT JOIN brickworks_evidence be ON be.progress_id=bp.id GROUP BY bp.participant_id,bp.criterion_id');
    $ev=[]; foreach($evidenceCounts as $er){ $ev[(int)$er['participant_id']][(int)$er['criterion_id']]=(int)$er['c']; }
    $headers=['Name','Callsign','Completed','Pending','Current award'];
    foreach($criteria as $c) $headers[]=$c['theme'].' - '.$c['title'];
    $rows=[];
    foreach($participants as $part){
        $complete=0; $pending=0;
        $row=[trim($part['first_name'].' '.$part['last_name']),$part['callsign']];
        $cells=[];
        foreach($criteria as $c){
            $pr=$progress[(int)$part['id']][(int)$c['id']] ?? null;
            $status=$pr['status'] ?? 'not_completed';
            if($status==='complete') $complete++;
            if($status==='pending_approval') $pending++;
            $label=$status==='complete' ? 'Complete - '.($pr['completed_at'] ?? '') : ($status==='pending_approval' ? 'In progress / Pending approval' : 'Not completed');
            $evidence=(int)($ev[(int)$part['id']][(int)$c['id']] ?? 0);
            $comment=decrypt_value($pr['reviewer_comment_encrypted'] ?? '') ?: '';
            $cells[]=$label.' | Evidence: '.$evidence.($comment?' | Reviewer: '.$comment:'');
        }
        $row[]=$complete; $row[]=$pending; $row[]=brickworks_award($complete) ?: 'No award yet';
        $rows[]=array_merge($row,$cells);
    }
    csv_download('brickworks-progress-export.csv', $headers, $rows);
}

if (route() === 'brickworks_manage') {
    require_permission('review_brickworks_evidence');
    page_header('Brickworks Management'); audit('brickworks.manage.view');
    // Make sure each participant has a progress row for every active criterion.
    $criteria=all('SELECT bc.*,bt.name theme FROM brickworks_criteria bc JOIN brickworks_themes bt ON bt.id=bc.theme_id WHERE bc.active=1 ORDER BY bt.sort_order,bc.sort_order');
    $participants=all('SELECT b.*,m.first_name,m.last_name,m.callsign,m.id member_id FROM brickworks_participants b JOIN members m ON m.id=b.member_id ORDER BY m.last_name,m.first_name');
    foreach($participants as $part){ foreach($criteria as $c){ exec_sql('INSERT OR IGNORE INTO brickworks_progress (participant_id,criterion_id,created_at,updated_at) VALUES (?,?,datetime("now"),datetime("now"))',[$part['id'],$c['id']]); } }
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_csrf();
        $progressId=(int)($_POST['progress_id'] ?? 0);
        $status=$_POST['status'] ?? 'not_completed';
        if (!in_array($status, ['not_completed','pending_approval','complete'], true)) $status='not_completed';
        $completedAt = $status === 'complete' ? (trim($_POST['completed_at'] ?? '') ?: date('Y-m-d')) : null;
        exec_sql('UPDATE brickworks_progress SET status=?, reviewer_comment_encrypted=?, completed_at=?, reviewed_by_user_id=?, reviewed_at=datetime("now"), updated_at=datetime("now") WHERE id=?',[$status,encrypt_value(trim($_POST['reviewer_comment'] ?? '')),$completedAt,$u['id'],$progressId]);
        audit('brickworks.status.update','brickworks_progress',$progressId,'success',null,['status'=>$status]); flash('Brickworks progress updated.'); redirect('brickworks_manage');
    }
    $progressRows=all('SELECT bp.*,b.member_id,bc.title FROM brickworks_progress bp JOIN brickworks_participants b ON b.id=bp.participant_id JOIN brickworks_criteria bc ON bc.id=bp.criterion_id ORDER BY b.member_id,bc.sort_order');
    $progress=[]; foreach($progressRows as $pr){ $progress[(int)$pr['participant_id']][(int)$pr['criterion_id']]=$pr; }
    $evidenceRows=all('SELECT be.*,bp.participant_id,bp.criterion_id FROM brickworks_evidence be JOIN brickworks_progress bp ON bp.id=be.progress_id ORDER BY be.created_at DESC');
    $evidence=[]; foreach($evidenceRows as $er){ $evidence[(int)$er['participant_id']][(int)$er['criterion_id']][]=$er; }
    echo '<div class="card"><div class="toolbar"><h1 style="margin-right:auto">Brickworks management</h1><a class="btn secondary" href="?route=brickworks">Back to Brickworks</a><a class="btn secondary" href="?route=brickworks_review">Pending only</a><a class="btn" href="?route=brickworks_export">Export spreadsheet</a></div><p class="muted">Members are listed down the left. Criteria run left to right across the top. Use each cell to approve, return for more evidence, or reset a criterion.</p></div>';
    echo '<div class="card"><div class="matrix-wrap"><table class="matrix"><tr><th>Member</th>';
    foreach($criteria as $c){ echo '<th><span class="small">'.e($c['theme']).'</span><br>'.e($c['title']).'</th>'; }
    echo '</tr>';
    foreach($participants as $part){ $memberName=trim($part['first_name'].' '.$part['last_name']); echo '<tr><td><strong>'.e($memberName).'</strong><br><span class="muted">'.e($part['callsign']).'</span></td>';
        foreach($criteria as $c){ $pr=$progress[(int)$part['id']][(int)$c['id']] ?? null; if(!$pr){ echo '<td class="matrix-cell">Missing progress row</td>'; continue; }
            $status=$pr['status'] ?: 'not_completed'; $cls=str_replace(' ','_',strtolower($status)); $label=$status==='complete'?'Complete - '.$pr['completed_at']:($status==='pending_approval'?'Pending approval':'Not completed');
            $memberComment=decrypt_value($pr['member_comment_encrypted']); $reviewerComment=decrypt_value($pr['reviewer_comment_encrypted']); $files=$evidence[(int)$part['id']][(int)$c['id']] ?? [];
            echo '<td class="matrix-cell"><span class="status-pill status-'.e($cls).'">'.e($label).'</span>';
            if($memberComment) echo '<p class="small"><strong>Member note:</strong><br>'.nl2br(e($memberComment)).'</p>';
            if($reviewerComment) echo '<p class="small"><strong>Reviewer:</strong><br>'.nl2br(e($reviewerComment)).'</p>';
            if($files){ echo '<details><summary>Evidence ('.e(count($files)).')</summary><ul>'; foreach($files as $file) echo '<li><a href="?route=brickworks_evidence&id='.e($file['id']).'">'.e($file['original_filename']).'</a><br><span class="muted small">'.e($file['created_at']).'</span></li>'; echo '</ul></details>'; }
            echo '<form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="progress_id" value="'.e($pr['id']).'"><label>Status</label><select name="status"><option value="not_completed" '.($status==='not_completed'?'selected':'').'>Not completed</option><option value="pending_approval" '.($status==='pending_approval'?'selected':'').'>In progress / pending approval</option><option value="complete" '.($status==='complete'?'selected':'').'>Complete</option></select><label>Completed date</label><input type="date" name="completed_at" value="'.e($pr['completed_at'] ?: date('Y-m-d')).'"><label>Reviewer comment</label><textarea name="reviewer_comment">'.e($reviewerComment).'</textarea><button>Save</button></form></td>';
        }
        echo '</tr>';
    }
    echo '</table></div></div>';
    page_footer(); exit;
}

if (route() === 'brickworks_review') {
    require_permission('review_brickworks_evidence'); page_header('Brickworks Review'); audit('brickworks.review.view');
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_csrf(); $pid=(int)$_POST['progress_id']; $status=$_POST['decision']==='approve'?'complete':'pending_approval'; exec_sql('UPDATE brickworks_progress SET status=?, reviewer_comment_encrypted=?, completed_at=CASE WHEN ?="complete" THEN date("now") ELSE completed_at END, reviewed_by_user_id=?, reviewed_at=datetime("now"), updated_at=datetime("now") WHERE id=?',[$status,encrypt_value($_POST['reviewer_comment']??''),$status,$u['id'],$pid]); audit('brickworks.criteria.approve','brickworks_progress',$pid,'success',null,['decision'=>$_POST['decision']]); flash('Review saved.'); redirect('brickworks_review'); }
    $rows=all('SELECT bp.*,bc.title,m.first_name,m.last_name,m.callsign FROM brickworks_progress bp JOIN brickworks_participants b ON b.id=bp.participant_id JOIN members m ON m.id=b.member_id JOIN brickworks_criteria bc ON bc.id=bp.criterion_id WHERE bp.status="pending_approval" ORDER BY bp.submitted_at');
    echo '<div class="card"><h1>Pending Brickworks evidence</h1><table><tr><th>Member</th><th>Criteria</th><th>Comment</th><th>Decision</th></tr>'; foreach($rows as $r) echo '<tr><td>'.e($r['first_name'].' '.$r['last_name'].' '.$r['callsign']).'</td><td>'.e($r['title']).'</td><td>'.e(decrypt_value($r['member_comment_encrypted'])).'</td><td><form method="post">'.csrf_field().'<input type="hidden" name="progress_id" value="'.e($r['id']).'"><textarea name="reviewer_comment" placeholder="Reviewer comment"></textarea><select name="decision"><option value="approve">Approve</option><option value="more">Request more evidence</option></select><button>Save review</button></form></td></tr>'; echo '</table></div>'; page_footer(); exit;
}

if (route() === 'emails') {
    require_permission('send_member_emails'); page_header('Emails'); audit('emails.view');
    $cfg = app_config();
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_csrf();
        $recipientMode = $_POST['recipient_mode'] ?? 'selected';
        $selectedIds = array_values(array_filter(array_map('intval', $_POST['member_ids'] ?? [])));
        $recipientWhere = 'm.membership_status="active" AND c.consent_type="email_comms" AND c.granted=1 AND m.email IS NOT NULL AND m.email <> ""';
        $args = [];
        if ($recipientMode !== 'all') {
            if (!$selectedIds) { flash('No members selected. Select at least one member or choose Send all.'); redirect('emails'); }
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $recipientWhere .= ' AND m.id IN (' . $placeholders . ')';
            $args = $selectedIds;
        }
        $members=all('SELECT DISTINCT m.*,ep.allow_open_tracking FROM members m LEFT JOIN member_email_preferences ep ON ep.member_id=m.id JOIN member_consents c ON c.member_id=m.id WHERE '.$recipientWhere.' ORDER BY m.last_name,m.first_name', $args);
        if (!$members) { flash('No eligible recipients found. Members must be active, have an email address, and have email communications consent enabled.'); redirect('emails'); }
        exec_sql('INSERT INTO emails (subject,body_html,body_text,status,category,created_by_user_id,created_at,updated_at) VALUES (?,?,?,"draft",?,?,datetime("now"),datetime("now"))',[trim($_POST['subject']),$_POST['body_html'],strip_tags($_POST['body_html']),trim($_POST['category']),$u['id']]);
        $email_id=(int)db()->lastInsertId();
        if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error']===UPLOAD_ERR_OK) {
            $stored=bin2hex(random_bytes(18)).'-'.preg_replace('/[^A-Za-z0-9._-]/','_',$_FILES['attachment']['name']);
            $dest=PRIVATE_PATH.'/email-attachments/'.$stored;
            if(!is_dir(dirname($dest))) mkdir(dirname($dest),0750,true);
            move_uploaded_file($_FILES['attachment']['tmp_name'],$dest);
            exec_sql('INSERT INTO email_attachments (email_id,original_filename,stored_filename,mime_type,file_size,uploaded_by_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,datetime("now"),datetime("now"))',[$email_id,$_FILES['attachment']['name'],$stored,mime_content_type($dest),(int)$_FILES['attachment']['size'],$u['id']]);
            audit('email.attachment_uploaded','email',$email_id);
        }
        foreach($members as $m){
            $track=(int)($m['allow_open_tracking']??0);
            $tid=$track?bin2hex(random_bytes(24)):null;
            exec_sql('INSERT INTO email_recipients (email_id,member_id,email_address,recipient_name,tracking_enabled,tracking_id,status,created_at,updated_at) VALUES (?,?,?,?,?,?,"queued",datetime("now"),datetime("now"))',[$email_id,$m['id'],$m['email'],$m['first_name'].' '.$m['last_name'],$track,$tid]);
        }
        if (isset($_POST['send_now'])) {
            $recips=all('SELECT * FROM email_recipients WHERE email_id=?',[$email_id]);
            $fromAddress = trim($cfg['email_from_address'] ?: ('noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost')));
            $fromName = trim($cfg['email_from_name'] ?: ($cfg['society_name'] ?? 'Membership System'));
            $replyTo = trim($cfg['email_reply_to'] ?: $fromAddress);
            $safeFromName = str_replace(["\r","\n"], '', $fromName);
            $safeFromAddress = str_replace(["\r","\n"], '', $fromAddress);
            $safeReplyTo = str_replace(["\r","\n"], '', $replyTo);
            $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\n";
            $headers .= 'From: ' . $safeFromName . ' <' . $safeFromAddress . ">\r\n";
            $headers .= 'Reply-To: ' . $safeReplyTo . "\r\n";
            $body=$_POST['body_html'];
            if (count($recips) === 1) {
                $r=$recips[0];
                $singleBody=$body;
                if($r['tracking_enabled']){
                    $base=rtrim($cfg['base_url'] ?: ((isset($_SERVER['HTTPS'])?'https':'http').'://'.($_SERVER['HTTP_HOST']??'' ).dirname($_SERVER['SCRIPT_NAME'])), '/');
                    $singleBody.='<img src="'.$base.'/?route=email_open&id='.e($r['tracking_id']).'" width="1" height="1" alt="">';
                }
                $send=send_configured_email($cfg, [$r['email_address']], [], $_POST['subject'], $singleBody, $safeFromName, $safeFromAddress, $safeReplyTo);
                $ok=(bool)$send['ok']; $sendError=$send['error'] ?? null;
                exec_sql('UPDATE email_recipients SET status=?, sent_at=CASE WHEN ?=1 THEN datetime("now") ELSE sent_at END, failed_at=CASE WHEN ?=0 THEN datetime("now") ELSE failed_at END, failure_reason=CASE WHEN ?=0 THEN ? ELSE NULL END, updated_at=datetime("now") WHERE id=?',[$ok?'sent':'failed',$ok?1:0,$ok?1:0,$ok?1:0,$sendError,$r['id']]);
            } else {
                $bcc = array_map(fn($r) => str_replace(["\r","\n"], '', $r['email_address']), $recips);
                $send=send_configured_email($cfg, [$safeFromAddress], $bcc, $_POST['subject'], $body, $safeFromName, $safeFromAddress, $safeReplyTo);
                $ok=(bool)$send['ok']; $sendError=$send['error'] ?? null;
                foreach($recips as $r){
                    exec_sql('UPDATE email_recipients SET status=?, sent_at=CASE WHEN ?=1 THEN datetime("now") ELSE sent_at END, failed_at=CASE WHEN ?=0 THEN datetime("now") ELSE failed_at END, failure_reason=CASE WHEN ?=0 THEN ? ELSE NULL END, tracking_enabled=0, tracking_id=NULL, updated_at=datetime("now") WHERE id=?',[$ok?'sent_bcc':'failed',$ok?1:0,$ok?1:0,$ok?1:0,$sendError,$r['id']]);
                }
            }
            exec_sql('UPDATE emails SET status=?, sent_at=CASE WHEN ?=1 THEN datetime("now") ELSE sent_at END, updated_at=datetime("now") WHERE id=?',[$ok?'sent':'failed',$ok?1:0,$email_id]);
            audit('email.sent','email',$email_id,'success',null,['recipient_count'=>count($recips),'bcc_used'=>count($recips)>1,'method'=>$cfg['email_method'] ?? 'php_mail']);
            flash('Email created and send attempted using '.(($cfg['email_method'] ?? 'php_mail') === 'resend' ? 'Resend API' : 'PHP mail').'. If more than one recipient was selected, the email was sent using BCC.'); redirect('emails');
        }
        audit('email.draft_created','email',$email_id,'success',null,['recipient_count'=>count($members),'recipient_mode'=>$recipientMode]);
        flash('Email draft created with recipients.'); redirect('emails');
    }
    $emails=all('SELECT * FROM emails ORDER BY created_at DESC LIMIT 25');
    $eligible=all('SELECT DISTINCT m.id,m.first_name,m.last_name,m.callsign,m.email,m.membership_status, (SELECT sp.status FROM subscription_payments sp WHERE sp.member_id=m.id ORDER BY sp.subscription_year DESC, COALESCE(sp.payment_date, "") DESC, sp.id DESC LIMIT 1) AS latest_subs_status, EXISTS(SELECT 1 FROM users uu JOIN user_roles ur ON ur.user_id=uu.id JOIN roles rr ON rr.id=ur.role_id WHERE uu.member_id=m.id AND rr.name="committee" AND (ur.expires_at IS NULL OR ur.expires_at > datetime("now"))) AS is_committee FROM members m JOIN member_consents c ON c.member_id=m.id AND c.consent_type="email_comms" AND c.granted=1 WHERE m.membership_status="active" AND m.email IS NOT NULL AND m.email <> "" ORDER BY m.last_name,m.first_name');
    $fromAddress = trim($cfg['email_from_address'] ?: ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    $fromName = trim($cfg['email_from_name'] ?: ($cfg['society_name'] ?? 'Membership System'));

    echo '<div class="email-app"><form method="post" enctype="multipart/form-data" id="emailComposeForm">'.csrf_field().'<input type="hidden" name="category" value="admin"><input type="hidden" name="recipient_mode" value="selected"><div class="email-top"><div class="email-title"><span class="email-icon">✉</span><strong>New Email</strong></div><div class="email-top-actions">';
    if (is_admin_user()) echo '<a class="email-config-btn" href="?route=email_config">Email system config</a>';
    echo '</div></div><div class="email-layout"><aside class="email-sidebar"><div class="email-recipient-head"><span class="email-people">☷</span><strong>Recipients</strong><span class="email-count" id="emailSelectedCount">0</span></div><div class="email-filter-bar"><button type="button" class="email-chip active" data-email-filter="all">All</button><button type="button" class="email-chip paid" data-email-filter="paid">Paid</button><button type="button" class="email-chip unpaid" data-email-filter="unpaid">Unpaid</button><button type="button" class="email-chip pending" data-email-filter="pending">Pending</button><button type="button" class="email-chip committee" data-email-filter="committee">Committee</button><button type="button" class="email-chip none" data-email-filter="none">None</button></div><div class="email-search-wrap"><input id="emailMemberSearch" class="email-search" placeholder="Search members..."></div><div class="email-member-list">';
    if(!$eligible) echo '<p class="email-empty">No active members with email communications consent are available.</p>';
    foreach($eligible as $m){
        $subsStatus = strtolower(trim((string)($m['latest_subs_status'] ?: 'unpaid')));
        if (!in_array($subsStatus, ['paid','unpaid','pending','part-paid','part_paid','waived','refunded'], true)) $subsStatus = 'unpaid';
        $paymentGroup = str_contains($subsStatus, 'paid') && $subsStatus !== 'unpaid' ? 'paid' : ($subsStatus === 'pending' ? 'pending' : 'unpaid');
        $badgeText = $paymentGroup === 'paid' ? 'Paid' : ($paymentGroup === 'pending' ? 'Pending' : 'Unpaid');
        $name=e($m['first_name'].' '.$m['last_name']); $email=e($m['email']); $callsign=e($m['callsign'] ?: '');
        echo '<label class="email-member-card" data-name="'.strtolower(e($m['first_name'].' '.$m['last_name'].' '.$m['email'].' '.$m['callsign'])).'" data-payment="'.e($paymentGroup).'" data-committee="'.((int)$m['is_committee'] ? '1' : '0').'"><input class="email-member-check" type="checkbox" name="member_ids[]" value="'.e($m['id']).'" checked><span class="email-check-ui">✓</span><span class="email-member-main"><strong>'.$name.'</strong>'.($callsign?'<span class="email-callsign">'.$callsign.'</span>':'').'<small>'.$email.'</small></span><span class="email-badge '.e($paymentGroup).'">'.e($badgeText).'</span></label>';
    }
    echo '</div></aside><section class="email-compose"><div class="email-fields"><div class="email-row"><label>To:</label><input id="emailToSummary" value="0 members selected" readonly></div><div class="email-row"><label>From:</label><input value="'.e($fromName).' <'.e($fromAddress).'>" readonly></div><div class="email-row"><label>Subject:</label><input name="subject" placeholder="Email subject..." required></div></div><div class="email-toolbar"><label class="email-attach-btn">📎 Attach<input type="file" name="attachment" id="emailAttachment"></label><span id="emailAttachName" class="email-help">Use <code>{member_name}</code> to personalise each email.</span></div><textarea class="email-message" name="body_html" placeholder="Write your message here... Use {member_name} to personalise for each recipient." required></textarea><div class="email-sendbar"><span class="muted">Multiple-recipient emails are sent using BCC for member privacy.</span><div><button class="secondary" type="submit">Save draft</button> <button name="send_now" value="1">Send now</button></div></div></section></div></form></div>';

    echo '<div class="card"><h2>Recent emails</h2><table><tr><th>Subject</th><th>Status</th><th>Sent</th><th>Recipients</th><th>Opens</th></tr>';
    foreach($emails as $em){ $rc=first('SELECT COUNT(*) c, SUM(open_count) opens FROM email_recipients WHERE email_id=?',[$em['id']]); echo '<tr><td>'.e($em['subject']).'</td><td>'.e($em['status']).'</td><td>'.e($em['sent_at']).'</td><td>'.e($rc['c']).'</td><td>'.e($rc['opens'] ?: 0).'</td></tr>'; }
    echo '</table></div>';
    echo <<<'HTML'
<script>
(function(){
  const cards = Array.from(document.querySelectorAll('.email-member-card'));
  const checks = Array.from(document.querySelectorAll('.email-member-check'));
  const count = document.getElementById('emailSelectedCount');
  const toSummary = document.getElementById('emailToSummary');
  const search = document.getElementById('emailMemberSearch');
  const chips = Array.from(document.querySelectorAll('[data-email-filter]'));
  const attach = document.getElementById('emailAttachment');
  const attachName = document.getElementById('emailAttachName');
  let activeFilter = 'all';

  function updateCount(){
    const selected = checks.filter(c => c.checked).length;
    if (count) count.textContent = selected;
    if (toSummary) toSummary.value = selected + (selected === 1 ? ' member selected' : ' members selected');
  }
  function applyFilter(){
    const q = (search && search.value ? search.value : '').trim().toLowerCase();
    cards.forEach(card => {
      let show = true;
      if (activeFilter === 'paid') show = card.dataset.payment === 'paid';
      if (activeFilter === 'unpaid') show = card.dataset.payment === 'unpaid';
      if (activeFilter === 'pending') show = card.dataset.payment === 'pending';
      if (activeFilter === 'committee') show = card.dataset.committee === '1';
      if (q && !(card.dataset.name || '').includes(q)) show = false;
      card.style.display = show ? 'grid' : 'none';
    });
  }
  chips.forEach(chip => chip.addEventListener('click', () => {
    activeFilter = chip.dataset.emailFilter;
    chips.forEach(c => c.classList.toggle('active', c === chip));
    if (activeFilter === 'none') {
      checks.forEach(c => c.checked = false);
      activeFilter = 'all';
      chips.forEach(c => c.classList.toggle('active', c.dataset.emailFilter === 'all'));
    } else if (activeFilter === 'all') {
      checks.forEach(c => c.checked = true);
    } else {
      checks.forEach(c => {
        const card = c.closest('.email-member-card');
        let match = false;
        if (activeFilter === 'paid') match = card.dataset.payment === 'paid';
        if (activeFilter === 'unpaid') match = card.dataset.payment === 'unpaid';
        if (activeFilter === 'pending') match = card.dataset.payment === 'pending';
        if (activeFilter === 'committee') match = card.dataset.committee === '1';
        c.checked = match;
      });
    }
    applyFilter(); updateCount();
  }));
  checks.forEach(c => c.addEventListener('change', updateCount));
  if (search) search.addEventListener('input', applyFilter);
  if (attach) attach.addEventListener('change', () => { if (attach.files.length) attachName.textContent = attach.files[0].name; });
  updateCount(); applyFilter();
})();
</script>
HTML;
    page_footer(); exit;
}

if (route() === 'email_config') {
    if (!is_admin_user()) require_permission('system_admin');
    page_header('Email system config'); audit('email.config.view');
    $cfg = app_config();
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_csrf();
        save_app_config([
            'email_from_name' => trim($_POST['email_from_name'] ?? ''),
            'email_from_address' => trim($_POST['email_from_address'] ?? ''),
            'email_reply_to' => trim($_POST['email_reply_to'] ?? ''),
            'email_method' => trim($_POST['email_method'] ?? 'php_mail'),
            'smtp_host' => trim($_POST['smtp_host'] ?? ''),
            'smtp_port' => trim($_POST['smtp_port'] ?? '587'),
            'smtp_security' => trim($_POST['smtp_security'] ?? 'tls'),
            'smtp_username' => trim($_POST['smtp_username'] ?? ''),
            'smtp_password' => trim($_POST['smtp_password'] ?? ''),
            'resend_api_key' => trim($_POST['resend_api_key'] ?? ''),
        ]);
        audit('email.config.update'); flash('Email configuration saved.'); redirect('email_config');
    }
    echo '<div class="card"><div class="toolbar"><h1 style="margin-right:auto">Email system config</h1><a class="btn secondary" href="?route=emails">Back to emails</a></div><p class="muted">Choose how the system sends email. PHP mail uses the server mail setup. Resend API sends via Resend using the API key below. SMTP fields are stored for future SMTP wiring.</p><form method="post">'.csrf_field().'<div class="two"><div><label>From name</label><input name="email_from_name" value="'.e($cfg['email_from_name']).'"></div><div><label>From email address</label><input type="email" name="email_from_address" value="'.e($cfg['email_from_address']).'" placeholder="noreply@example.org"></div><div><label>Reply-to email</label><input type="email" name="email_reply_to" value="'.e($cfg['email_reply_to']).'"></div><div><label>Mail method</label><select name="email_method"><option value="php_mail" '.($cfg['email_method']==='php_mail'?'selected':'').'>PHP mail()</option><option value="resend" '.($cfg['email_method']==='resend'?'selected':'').'>Resend API</option><option value="smtp" '.($cfg['email_method']==='smtp'?'selected':'').'>SMTP settings stored</option></select></div><div class="full"><label>Resend API key</label><input type="password" name="resend_api_key" value="'.e($cfg['resend_api_key'] ?? '').'" placeholder="re_..."><p class="muted small">Used only when Mail method is set to Resend API. The From email must be allowed/verified in your Resend account.</p></div><div><label>SMTP host</label><input name="smtp_host" value="'.e($cfg['smtp_host']).'"></div><div><label>SMTP port</label><input name="smtp_port" value="'.e($cfg['smtp_port']).'"></div><div><label>SMTP security</label><select name="smtp_security"><option value="tls" '.($cfg['smtp_security']==='tls'?'selected':'').'>TLS</option><option value="ssl" '.($cfg['smtp_security']==='ssl'?'selected':'').'>SSL</option><option value="none" '.($cfg['smtp_security']==='none'?'selected':'').'>None</option></select></div><div><label>SMTP username</label><input name="smtp_username" value="'.e($cfg['smtp_username']).'"></div><div><label>SMTP password</label><input type="password" name="smtp_password" value="'.e($cfg['smtp_password']).'"></div></div><p><button>Save email config</button></p></form></div>';
    page_footer(); exit;
}

if (route() === 'audit') {
    require_permission('view_audit_logs'); audit('audit.view'); page_header('Audit Logs');
    $rows=all('SELECT a.*,u.email FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_user_id ORDER BY a.created_at DESC LIMIT 250');
    echo '<div class="card"><h1>Audit logs</h1><table><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Result</th><th>Reason</th><th>IP</th></tr>'; foreach($rows as $r) echo '<tr><td>'.e($r['created_at']).'</td><td>'.e($r['email']).'</td><td>'.e($r['action']).'</td><td>'.e($r['entity_type'].' #'.$r['entity_id']).'</td><td>'.e($r['result']).'</td><td>'.e($r['reason']).'</td><td>'.e($r['ip_address']).'</td></tr>'; echo '</table></div>'; page_footer(); exit;
}

page_header('Not found'); echo '<div class="card"><h1>Page not found</h1></div>'; page_footer();
