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
    if (file_exists(CONFIG_PATH)) return include CONFIG_PATH;
    return ['app_key' => null, 'society_name' => 'Ham Radio Society', 'base_url' => ''];
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
function redirect(string $route): void { header('Location: ?route=' . urlencode($route)); exit; }
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
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            session_id(),
            $metadata ? json_encode($metadata) : null
        ]);
    } catch (Throwable $e) { /* never break app because logging failed */ }
}
function first(string $sql, array $args=[]): ?array { $s=db()->prepare($sql); $s->execute($args); $r=$s->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
function all(string $sql, array $args=[]): array { $s=db()->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC); }
function exec_sql(string $sql, array $args=[]): void { $s=db()->prepare($sql); $s->execute($args); }

function page_header(string $title): void {
    $u = current_user(); $cfg = app_config();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title><link rel="stylesheet" href="?route=assets.css"></head><body>';
    echo '<header><div><strong>' . e($cfg['society_name'] ?? 'Ham Radio Society') . '</strong><span>Membership System</span></div>';
    if ($u) echo '<nav><a href="?route=dashboard">Dashboard</a><a href="?route=profile">My Profile</a><a href="?route=events">Events</a><a href="?route=brickworks">Brickworks</a><a href="?route=directory">Directory</a><a href="?route=equipment">Equipment</a><a href="?route=emails">Emails</a><a href="?route=members">Members</a><a href="?route=users">Users</a><a href="?route=audit">Audit</a><a href="?route=logout">Logout</a></nav>';
    echo '</header><main>';
    if (!empty($_SESSION['flash'])) { echo '<div class="flash">' . e($_SESSION['flash']) . '</div>'; unset($_SESSION['flash']); }
}
function page_footer(): void { echo '</main><footer>Audit logging enabled • Private uploads are stored outside web root • Open tracking is opt-in only</footer></body></html>'; }
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
 date_joined TEXT NULL,
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
        'manage_events','track_attendance','view_equipment','edit_equipment','manage_equipment_loans','view_membership_db','edit_membership_db','manage_subscriptions','export_member_data','manage_users','manage_roles','reset_passwords','view_audit_logs','view_security_logs','view_member_audit_logs','view_equipment_audit_logs','export_audit_logs','view_brickworks_participants','review_brickworks_evidence','approve_brickworks_criteria','manage_brickworks_criteria','export_brickworks_reports','send_member_emails','send_role_emails','send_event_emails','send_subs_reminders','send_brickworks_emails','manage_email_templates','view_email_reports','view_email_open_tracking','manage_email_attachments','system_admin'
    ];
    foreach ($roles as $name=>$display) exec_sql('INSERT OR IGNORE INTO roles (name, display_name, description, created_at, updated_at) VALUES (?, ?, ?, datetime("now"), datetime("now"))', [$name,$display,$display]);
    foreach ($permissions as $p) exec_sql('INSERT OR IGNORE INTO permissions (name, description, created_at, updated_at) VALUES (?, ?, datetime("now"), datetime("now"))', [$p,$p]);
    $rolePerms = [
        'member' => ['view_own_profile','edit_own_profile','view_events','signup_events','view_internal_directory','search_internal_directory','manage_own_directory_preferences','manage_own_email_preferences','view_own_brickworks','join_brickworks','submit_brickworks_evidence'],
        'committee' => ['view_events','manage_events','track_attendance','view_equipment','edit_equipment','manage_equipment_loans'],
        'equipment_manager' => ['view_equipment','edit_equipment','manage_equipment_loans','view_equipment_audit_logs'],
        'event_manager' => ['manage_events','track_attendance','send_event_emails'],
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
    exec_sql('INSERT OR IGNORE INTO user_roles (user_id, role_id, assigned_by_user_id, assigned_at) VALUES (?,?,?,datetime("now"))', [$user_id,$role['id'],$by]);
    exec_sql('INSERT INTO user_role_history (user_id, role_id, action, changed_by_user_id, changed_at, reason) VALUES (?,?,"assigned",?,datetime("now"),?)', [$user_id,$role['id'],$by,$reason]);
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
    echo 'body{margin:0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f6f7fb;color:#18202a}header{background:#101827;color:white;padding:18px 24px}header div{display:flex;gap:12px;align-items:end}header span{opacity:.7}nav{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}nav a{color:white;background:#24324a;padding:8px 10px;border-radius:8px;text-decoration:none}main{max-width:1180px;margin:24px auto;padding:0 18px}.card{background:white;border-radius:14px;padding:18px;margin:16px 0;box-shadow:0 1px 4px #0001}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}label{display:block;margin:10px 0 4px;font-weight:600}input,select,textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #ccd3df;border-radius:8px}textarea{min-height:110px}button,.btn{background:#1d4ed8;color:white;border:0;border-radius:8px;padding:10px 14px;text-decoration:none;display:inline-block;cursor:pointer}button.secondary,.btn.secondary{background:#475569}table{width:100%;border-collapse:collapse;background:white}th,td{border-bottom:1px solid #e5e7eb;padding:10px;text-align:left;vertical-align:top}th{background:#f1f5f9}.flash{background:#dcfce7;border:1px solid #86efac;padding:12px;border-radius:10px}.danger{background:#fee2e2;border:1px solid #fecaca}.pill{display:inline-block;padding:4px 8px;border-radius:999px;background:#e0e7ff}.muted{color:#64748b}.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}footer{text-align:center;color:#64748b;padding:24px}.small{font-size:.9rem}.status-complete{background:#dcfce7}.status-pending{background:#fef3c7}.status-none{background:#f1f5f9}@media(max-width:800px){.two{grid-template-columns:1fr}table{font-size:.9rem}}';
    exit;
}
if (route() === 'email_open') {
    $tid = $_GET['id'] ?? '';
    if ($tid) {
        $r = first('SELECT * FROM email_recipients WHERE tracking_id=? AND tracking_enabled=1',[$tid]);
        if ($r) {
            exec_sql('UPDATE email_recipients SET open_count=open_count+1, opened_at=COALESCE(opened_at, datetime("now")), last_opened_at=datetime("now"), updated_at=datetime("now") WHERE id=?',[$r['id']]);
            exec_sql('INSERT INTO email_opens (email_recipient_id, opened_at, ip_address, user_agent, created_at) VALUES (?, datetime("now"), ?, ?, datetime("now"))',[$r['id'], $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
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
    page_footer(); exit;
}

if (route() === 'profile') {
    $m = first('SELECT * FROM members WHERE id=?',[$u['member_id']]); if (!$m) exit('No member record linked.');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        require_csrf();
        exec_sql('UPDATE members SET first_name=?, last_name=?, callsign=?, licence_level=?, email=?, phone_encrypted=?, address_encrypted=?, emergency_contact_encrypted=?, data_last_confirmed_at=datetime("now"), updated_at=datetime("now") WHERE id=?', [trim($_POST['first_name']),trim($_POST['last_name']),trim($_POST['callsign']),trim($_POST['licence_level']),trim($_POST['email']),encrypt_value(trim($_POST['phone'] ?? '')),encrypt_value(trim($_POST['address'] ?? '')),encrypt_value(trim($_POST['emergency_contact'] ?? '')),$m['id']]);
        exec_sql('UPDATE users SET email=?, updated_at=datetime("now") WHERE id=?',[trim($_POST['email']),$u['id']]);
        exec_sql('INSERT OR IGNORE INTO member_directory_preferences (member_id, created_at, updated_at) VALUES (?, datetime("now"), datetime("now"))',[$m['id']]);
        exec_sql('UPDATE member_directory_preferences SET show_callsign=?, show_first_name=?, show_surname=?, show_licence_level=?, show_email=?, show_phone=?, consent_given_at=CASE WHEN ?=1 THEN COALESCE(consent_given_at, datetime("now")) ELSE consent_given_at END, consent_updated_at=datetime("now"), updated_at=datetime("now") WHERE member_id=?', [isset($_POST['show_callsign'])?1:0,isset($_POST['show_first_name'])?1:0,isset($_POST['show_surname'])?1:0,isset($_POST['show_licence_level'])?1:0,isset($_POST['show_email'])?1:0,isset($_POST['show_phone'])?1:0,isset($_POST['show_callsign'])?1:0,$m['id']]);
        exec_sql('INSERT OR IGNORE INTO member_email_preferences (member_id, created_at, updated_at) VALUES (?, datetime("now"), datetime("now"))',[$m['id']]);
        exec_sql('UPDATE member_email_preferences SET receive_admin_emails=?, receive_subs_emails=?, receive_event_emails=?, receive_newsletter_emails=?, receive_brickworks_emails=?, allow_open_tracking=?, open_tracking_consented_at=CASE WHEN ?=1 THEN COALESCE(open_tracking_consented_at, datetime("now")) ELSE NULL END, updated_at=datetime("now") WHERE member_id=?',[isset($_POST['receive_admin_emails'])?1:0,isset($_POST['receive_subs_emails'])?1:0,isset($_POST['receive_event_emails'])?1:0,isset($_POST['receive_newsletter_emails'])?1:0,isset($_POST['receive_brickworks_emails'])?1:0,isset($_POST['allow_open_tracking'])?1:0,isset($_POST['allow_open_tracking'])?1:0,$m['id']]);
        audit('profile.update','member',(int)$m['id'],'success',null,['fields_changed'=>['profile','directory_preferences','email_preferences']]); flash('Profile updated.'); redirect('profile');
    }
    audit('profile.view','member',(int)$m['id']);
    $dp = first('SELECT * FROM member_directory_preferences WHERE member_id=?',[$m['id']]) ?: [];
    $ep = first('SELECT * FROM member_email_preferences WHERE member_id=?',[$m['id']]) ?: [];
    page_header('My Profile');
    echo '<div class="card"><h1>My Profile</h1><form method="post">'.csrf_field().'<div class="two"><div><label>First name</label><input name="first_name" value="'.e($m['first_name']).'"></div><div><label>Surname</label><input name="last_name" value="'.e($m['last_name']).'"></div><div><label>Callsign</label><input name="callsign" value="'.e($m['callsign']).'"></div><div><label>Licence level</label><input name="licence_level" value="'.e($m['licence_level']).'"></div><div><label>Email</label><input type="email" name="email" value="'.e($m['email']).'"></div><div><label>Phone</label><input name="phone" value="'.e(decrypt_value($m['phone_encrypted'])).'"></div></div><label>Address</label><textarea name="address">'.e(decrypt_value($m['address_encrypted'])).'</textarea><label>Emergency contact</label><textarea name="emergency_contact">'.e(decrypt_value($m['emergency_contact_encrypted'])).'</textarea>';
    echo '<h2>Internal callsign directory opt-in</h2><p class="muted">Only tick what you want other logged-in members to see.</p>'; foreach (['show_callsign'=>'Show callsign','show_first_name'=>'Show first name','show_surname'=>'Show surname','show_licence_level'=>'Show licence level','show_email'=>'Show email','show_phone'=>'Show phone'] as $name=>$label) echo '<label><input type="checkbox" name="'.$name.'" '.(!empty($dp[$name])?'checked':'').'> '.$label.'</label>';
    echo '<h2>Email preferences</h2>'; foreach (['receive_admin_emails'=>'Admin emails','receive_subs_emails'=>'Subs emails','receive_event_emails'=>'Event emails','receive_newsletter_emails'=>'Newsletter emails','receive_brickworks_emails'=>'Brickworks emails'] as $name=>$label) echo '<label><input type="checkbox" name="'.$name.'" '.((!array_key_exists($name,$ep) || $ep[$name])?'checked':'').'> Receive '.$label.'</label>'; echo '<label><input type="checkbox" name="allow_open_tracking" '.(!empty($ep['allow_open_tracking'])?'checked':'').'> Allow open/read tracking pixels for society emails</label><p><button>Save profile</button></p></form></div>';
    $payments = all('SELECT * FROM subscription_payments WHERE member_id=? ORDER BY subscription_year DESC',[$m['id']]);
    echo '<div class="card"><h2>My subscription/payment history</h2><table><tr><th>Year</th><th>Due</th><th>Paid</th><th>Date</th><th>Status</th></tr>'; foreach($payments as $p) echo '<tr><td>'.e($p['subscription_year']).'</td><td>£'.e(number_format($p['amount_due'],2)).'</td><td>£'.e(number_format($p['amount_paid'],2)).'</td><td>'.e($p['payment_date']).'</td><td>'.e($p['status']).'</td></tr>'; echo '</table></div>';
    page_footer(); exit;
}

if (route() === 'directory') {
    require_permission('view_internal_directory'); audit('directory.view'); page_header('Internal Directory');
    $rows = all('SELECT m.*, d.* FROM member_directory_preferences d JOIN members m ON m.id=d.member_id WHERE d.show_callsign=1 AND m.membership_status="active" ORDER BY m.callsign, m.last_name');
    echo '<div class="card"><h1>Internal callsign directory</h1><p>Only members who opted in are listed.</p><table><tr><th>Callsign</th><th>Name</th><th>Licence</th><th>Email</th><th>Phone</th></tr>';
    foreach($rows as $r){ $name=trim(($r['show_first_name']?$r['first_name']:'').' '.($r['show_surname']?$r['last_name']:'')); echo '<tr><td>'.e($r['callsign']).'</td><td>'.e($name).'</td><td>'.e($r['show_licence_level']?$r['licence_level']:'').'</td><td>'.e($r['show_email']?$r['email']:'').'</td><td>'.e($r['show_phone']?decrypt_value($r['phone_encrypted']):'').'</td></tr>'; }
    echo '</table></div>'; page_footer(); exit;
}

if (route() === 'members') {
    require_permission('view_membership_db'); audit('member_database.view'); page_header('Membership Database');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        require_permission('edit_membership_db'); require_csrf();
        exec_sql('INSERT INTO members (membership_number,first_name,last_name,callsign,licence_level,email,phone_encrypted,address_encrypted,date_joined,renewal_date,membership_status,membership_type,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?, ?, datetime("now"), datetime("now"))', [trim($_POST['membership_number']),trim($_POST['first_name']),trim($_POST['last_name']),trim($_POST['callsign']),trim($_POST['licence_level']),trim($_POST['email']),encrypt_value(trim($_POST['phone'])),encrypt_value(trim($_POST['address'])),trim($_POST['date_joined']),trim($_POST['renewal_date']),trim($_POST['membership_status']),trim($_POST['membership_type'])]);
        $mid=(int)db()->lastInsertId(); exec_sql('INSERT INTO member_directory_preferences (member_id, created_at, updated_at) VALUES (?,datetime("now"),datetime("now"))',[$mid]); exec_sql('INSERT INTO member_email_preferences (member_id, created_at, updated_at) VALUES (?,datetime("now"),datetime("now"))',[$mid]); audit('member.create','member',$mid); flash('Member added.'); redirect('members');
    }
    $rows = all('SELECT * FROM members ORDER BY last_name, first_name');
    echo '<div class="card"><h1>Membership database</h1><table><tr><th>No.</th><th>Name</th><th>Callsign</th><th>Status</th><th>Joined</th><th>Renewal</th><th>Attendance</th><th>Action</th></tr>';
    foreach($rows as $m){ $st=attendance_stats((int)$m['id']); echo '<tr><td>'.e($m['membership_number']).'</td><td>'.e($m['first_name'].' '.$m['last_name']).'</td><td>'.e($m['callsign']).'</td><td>'.e($m['membership_status']).'</td><td>'.e($m['date_joined']).'</td><td>'.e($m['renewal_date']).'</td><td>'.e($st['signup_percent']===null?'N/A':$st['signup_percent'].'%').'</td><td><a class="btn secondary" href="?route=member_view&id='.e($m['id']).'">Open</a></td></tr>'; }
    echo '</table></div><div class="card"><h2>Add member</h2><form method="post">'.csrf_field().'<div class="two"><input name="membership_number" placeholder="Membership number"><input name="callsign" placeholder="Callsign"><input name="first_name" placeholder="First name" required><input name="last_name" placeholder="Surname" required><input name="email" type="email" placeholder="Email" required><input name="licence_level" placeholder="Licence level"><input name="phone" placeholder="Phone"><input name="membership_type" placeholder="Membership type"><input name="date_joined" type="date"><input name="renewal_date" type="date"><select name="membership_status"><option>active</option><option>pending</option><option>expired</option><option>former</option><option>suspended</option><option>honorary</option></select></div><label>Address</label><textarea name="address"></textarea><button>Add member</button></form></div>';
    page_footer(); exit;
}

if (route() === 'member_view') {
    require_permission('view_membership_db'); $id=(int)($_GET['id']??0); $m=first('SELECT * FROM members WHERE id=?',[$id]); if(!$m) redirect('members'); audit('member.view','member',$id);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        require_permission('edit_membership_db'); require_csrf();
        if (isset($_POST['add_payment'])) { exec_sql('INSERT INTO subscription_payments (member_id,subscription_year,amount_due,amount_paid,payment_date,payment_method,payment_reference,receipt_number,status,recorded_by_user_id,notes_encrypted,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,datetime("now"),datetime("now"))',[$id,(int)$_POST['subscription_year'],(float)$_POST['amount_due'],(float)$_POST['amount_paid'],$_POST['payment_date'],$_POST['payment_method'],$_POST['payment_reference'],$_POST['receipt_number'],$_POST['status'],$u['id'],encrypt_value($_POST['notes']??'')]); audit('subscription.create','member',$id); flash('Payment/subs record added.'); redirect('member_view&id='.$id); }
        exec_sql('UPDATE members SET membership_number=?, first_name=?, last_name=?, callsign=?, licence_level=?, email=?, phone_encrypted=?, address_encrypted=?, date_joined=?, renewal_date=?, membership_status=?, membership_type=?, notes_encrypted=?, updated_at=datetime("now") WHERE id=?',[trim($_POST['membership_number']),trim($_POST['first_name']),trim($_POST['last_name']),trim($_POST['callsign']),trim($_POST['licence_level']),trim($_POST['email']),encrypt_value(trim($_POST['phone'])),encrypt_value(trim($_POST['address'])),trim($_POST['date_joined']),trim($_POST['renewal_date']),trim($_POST['membership_status']),trim($_POST['membership_type']),encrypt_value(trim($_POST['notes']??'')),$id]); audit('member.update','member',$id); flash('Member updated.'); redirect('member_view&id='.$id);
    }
    page_header('Member record'); $stats=attendance_stats($id);
    echo '<div class="card"><h1>'.e($m['first_name'].' '.$m['last_name']).'</h1><form method="post">'.csrf_field().'<div class="two"><input name="membership_number" value="'.e($m['membership_number']).'" placeholder="Membership number"><input name="callsign" value="'.e($m['callsign']).'" placeholder="Callsign"><input name="first_name" value="'.e($m['first_name']).'"><input name="last_name" value="'.e($m['last_name']).'"><input type="email" name="email" value="'.e($m['email']).'"><input name="licence_level" value="'.e($m['licence_level']).'"><input name="phone" value="'.e(decrypt_value($m['phone_encrypted'])).'"><input name="membership_type" value="'.e($m['membership_type']).'"><input type="date" name="date_joined" value="'.e($m['date_joined']).'"><input type="date" name="renewal_date" value="'.e($m['renewal_date']).'"><select name="membership_status">'; foreach(['pending','active','expired','former','suspended','honorary','life_member'] as $s) echo '<option '.($m['membership_status']===$s?'selected':'').'>'.e($s).'</option>'; echo '</select></div><label>Address</label><textarea name="address">'.e(decrypt_value($m['address_encrypted'])).'</textarea><label>Private notes</label><textarea name="notes">'.e(decrypt_value($m['notes_encrypted'])).'</textarea><button>Save member</button></form></div>';
    echo '<div class="grid"><div class="card"><h2>Attendance</h2><p>Attended: '.e($stats['attended']).'</p><p>Signed up: '.e($stats['signed_up']).'</p><p>Signup attendance: '.e($stats['signup_percent']===null?'N/A':$stats['signup_percent'].'%').'</p><p>Overall attendance: '.e($stats['overall_percent']===null?'N/A':$stats['overall_percent'].'%').'</p></div>';
    $payments=all('SELECT * FROM subscription_payments WHERE member_id=? ORDER BY subscription_year DESC',[$id]); echo '<div class="card"><h2>Payment/subs history</h2><table><tr><th>Year</th><th>Due</th><th>Paid</th><th>Date</th><th>Status</th></tr>'; foreach($payments as $p) echo '<tr><td>'.e($p['subscription_year']).'</td><td>£'.e(number_format($p['amount_due'],2)).'</td><td>£'.e(number_format($p['amount_paid'],2)).'</td><td>'.e($p['payment_date']).'</td><td>'.e($p['status']).'</td></tr>'; echo '</table></div></div>';
    echo '<div class="card"><h2>Add subs/payment record</h2><form method="post">'.csrf_field().'<input type="hidden" name="add_payment" value="1"><div class="two"><input type="number" name="subscription_year" value="'.date('Y').'" required><input type="number" step="0.01" name="amount_due" placeholder="Amount due" required><input type="number" step="0.01" name="amount_paid" placeholder="Amount paid" required><input type="date" name="payment_date"><input name="payment_method" placeholder="Payment method"><input name="payment_reference" placeholder="Payment reference"><input name="receipt_number" placeholder="Receipt number"><select name="status"><option>unpaid</option><option>part-paid</option><option>paid</option><option>waived</option><option>refunded</option></select></div><textarea name="notes" placeholder="Notes"></textarea><button>Add payment record</button></form></div>';
    $rh=all('SELECT urh.*,r.display_name FROM user_role_history urh JOIN users us ON us.id=urh.user_id JOIN roles r ON r.id=urh.role_id WHERE us.member_id=? ORDER BY changed_at DESC',[$id]); echo '<div class="card"><h2>Role history</h2><table><tr><th>Role</th><th>Action</th><th>Changed</th><th>Reason</th></tr>'; foreach($rh as $r) echo '<tr><td>'.e($r['display_name']).'</td><td>'.e($r['action']).'</td><td>'.e($r['changed_at']).'</td><td>'.e($r['reason']).'</td></tr>'; echo '</table></div>';
    page_footer(); exit;
}

if (route() === 'users') {
    require_permission('manage_users'); page_header('Users'); audit('users.view');
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_csrf();
        if (isset($_POST['reset_password'])) { require_permission('reset_passwords'); $new=$_POST['new_password']; if(strlen($new)<10){flash('Password must be at least 10 chars.');redirect('users');} exec_sql('UPDATE users SET password_hash=?, force_password_change=1, updated_at=datetime("now") WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),(int)$_POST['user_id']]); audit('user.password_changed_by_admin','user',(int)$_POST['user_id']); flash('Password reset.'); redirect('users'); }
        $member_id = null;
        if (!empty($_POST['member_id'])) $member_id=(int)$_POST['member_id'];
        exec_sql('INSERT INTO users (member_id,email,password_hash,status,force_password_change,created_at,updated_at) VALUES (?,?,?,"active",1,datetime("now"),datetime("now"))',[$member_id,strtolower(trim($_POST['email'])),password_hash($_POST['password'],PASSWORD_DEFAULT)]);
        $uid=(int)db()->lastInsertId(); assign_role($uid,'member',(int)$u['id'],'Admin created user');
        audit('user.created','user',$uid); flash('User created.'); redirect('users');
    }
    $users=all('SELECT u.*,m.first_name,m.last_name,m.callsign FROM users u LEFT JOIN members m ON m.id=u.member_id ORDER BY u.created_at DESC');
    echo '<div class="card"><h1>Users</h1><table><tr><th>Email</th><th>Member</th><th>Status</th><th>Roles</th><th>Reset password</th></tr>'; foreach($users as $usr){ echo '<tr><td>'.e($usr['email']).'</td><td>'.e(trim(($usr['first_name']??'').' '.($usr['last_name']??'')).' '.($usr['callsign']?'('.$usr['callsign'].')':'')).'</td><td>'.e($usr['status']).'</td><td>'.e(implode(', ',user_roles((int)$usr['id']))).'</td><td><form method="post">'.csrf_field().'<input type="hidden" name="reset_password" value="1"><input type="hidden" name="user_id" value="'.e($usr['id']).'"><input name="new_password" placeholder="New temp password"><button>Reset</button></form></td></tr>'; } echo '</table></div>';
    $members=all('SELECT id,first_name,last_name,callsign FROM members ORDER BY last_name'); echo '<div class="card"><h2>Create user</h2><form method="post">'.csrf_field().'<label>Link to member</label><select name="member_id"><option value="">None</option>'; foreach($members as $m) echo '<option value="'.e($m['id']).'">'.e($m['first_name'].' '.$m['last_name'].' '.$m['callsign']).'</option>'; echo '</select><label>Email</label><input type="email" name="email" required><label>Temporary password</label><input name="password" required minlength="10"><button>Create user</button></form></div>';
    page_footer(); exit;
}

if (route() === 'equipment') {
    require_permission('view_equipment'); audit('equipment_database.view'); page_header('Equipment');
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_permission('edit_equipment'); require_csrf(); exec_sql('INSERT INTO equipment (asset_number,name,category,manufacturer,model,serial_number_encrypted,location,condition,value,maintenance_due_at,notes_encrypted,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,datetime("now"),datetime("now"))',[trim($_POST['asset_number']),trim($_POST['name']),trim($_POST['category']),trim($_POST['manufacturer']),trim($_POST['model']),encrypt_value(trim($_POST['serial_number'])),trim($_POST['location']),trim($_POST['condition']),(float)($_POST['value']?:0),trim($_POST['maintenance_due_at']),encrypt_value(trim($_POST['notes']))]); audit('equipment.create','equipment',(int)db()->lastInsertId()); flash('Equipment added.'); redirect('equipment'); }
    $rows=all('SELECT * FROM equipment ORDER BY asset_number'); echo '<div class="card"><h1>Equipment database</h1><table><tr><th>Asset</th><th>Name</th><th>Model</th><th>Location</th><th>Condition</th><th>Maintenance due</th></tr>'; foreach($rows as $r) echo '<tr><td>'.e($r['asset_number']).'</td><td>'.e($r['name']).'</td><td>'.e($r['manufacturer'].' '.$r['model']).'</td><td>'.e($r['location']).'</td><td>'.e($r['condition']).'</td><td>'.e($r['maintenance_due_at']).'</td></tr>'; echo '</table></div><div class="card"><h2>Add equipment</h2><form method="post">'.csrf_field().'<div class="two"><input name="asset_number" placeholder="Asset number" required><input name="name" placeholder="Name" required><input name="category" placeholder="Category"><input name="manufacturer" placeholder="Manufacturer"><input name="model" placeholder="Model"><input name="serial_number" placeholder="Serial number"><input name="location" placeholder="Location"><input name="condition" placeholder="Condition"><input name="value" type="number" step="0.01" placeholder="Value"><input name="maintenance_due_at" type="date"></div><textarea name="notes" placeholder="Notes"></textarea><button>Add equipment</button></form></div>'; page_footer(); exit;
}

if (route() === 'events') {
    require_permission('view_events'); audit('events.view'); page_header('Events');
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_csrf();
        if (isset($_POST['signup'])) { exec_sql('INSERT OR IGNORE INTO event_attendance (event_id,member_id,status,signed_up_at,created_at,updated_at) VALUES (?,?,"signed_up",datetime("now"),datetime("now"),datetime("now"))',[(int)$_POST['event_id'],$u['member_id']]); audit('event.signup','event',(int)$_POST['event_id']); flash('Signed up.'); redirect('events'); }
        require_permission('manage_events'); exec_sql('INSERT INTO events (title,event_type,description,location,start_at,end_at,visibility,max_attendees,created_by_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,datetime("now"),datetime("now"))',[trim($_POST['title']),trim($_POST['event_type']),trim($_POST['description']),trim($_POST['location']),trim($_POST['start_at']),trim($_POST['end_at']),'members',(int)($_POST['max_attendees']?:0),$u['id']]); audit('event.create','event',(int)db()->lastInsertId()); flash('Event added.'); redirect('events');
    }
    $rows=all('SELECT * FROM events ORDER BY start_at DESC'); echo '<div class="card"><h1>Events</h1><table><tr><th>Event</th><th>Date</th><th>Location</th><th>Action</th></tr>'; foreach($rows as $ev){ echo '<tr><td><strong>'.e($ev['title']).'</strong><br>'.e($ev['description']).'</td><td>'.e($ev['start_at']).'</td><td>'.e($ev['location']).'</td><td><form method="post">'.csrf_field().'<input type="hidden" name="signup" value="1"><input type="hidden" name="event_id" value="'.e($ev['id']).'"><button>Sign up</button></form></td></tr>'; } echo '</table></div>';
    if(has_permission('manage_events')) echo '<div class="card"><h2>Add event</h2><form method="post">'.csrf_field().'<div class="two"><input name="title" placeholder="Title" required><input name="event_type" placeholder="Type"><input name="location" placeholder="Location"><input name="start_at" type="datetime-local" required><input name="end_at" type="datetime-local"><input name="max_attendees" type="number" placeholder="Max attendees"></div><textarea name="description" placeholder="Description"></textarea><button>Add event</button></form></div>';
    page_footer(); exit;
}

if (route() === 'brickworks') {
    require_permission('view_own_brickworks'); page_header('Brickworks'); audit('brickworks.progress.view');
    $participant=first('SELECT * FROM brickworks_participants WHERE member_id=?',[$u['member_id']]);
    if (!$participant && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['join'])) { require_csrf(); exec_sql('INSERT INTO brickworks_participants (member_id,status,joined_at,created_at,updated_at) VALUES (?,"active",datetime("now"),datetime("now"),datetime("now"))',[$u['member_id']]); $pid=(int)db()->lastInsertId(); foreach(all('SELECT id FROM brickworks_criteria WHERE active=1') as $c) exec_sql('INSERT OR IGNORE INTO brickworks_progress (participant_id,criterion_id,created_at,updated_at) VALUES (?,?,datetime("now"),datetime("now"))',[$pid,$c['id']]); assign_role((int)$u['id'],'brickworks_participant',(int)$u['id'],'Joined Brickworks'); audit('brickworks.join','member',(int)$u['member_id']); flash('You have joined Brickworks.'); redirect('brickworks'); }
    if (!$participant) { echo '<div class="card"><h1>Brickworks Scheme</h1><p>You are not signed up yet.</p><form method="post">'.csrf_field().'<input type="hidden" name="join" value="1"><button>Join Brickworks</button></form></div>'; page_footer(); exit; }
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
    $complete=(int)first('SELECT COUNT(*) c FROM brickworks_progress WHERE participant_id=? AND status="complete"',[$participant['id']])['c']; $award=brickworks_award($complete);
    echo '<div class="card"><h1>Brickworks progress</h1><p><strong>Completed:</strong> '.e($complete).' / '.e(first('SELECT COUNT(*) c FROM brickworks_criteria WHERE active=1')['c']).'</p><p><strong>Current award:</strong> '.e($award ?: 'None yet').'</p></div>';
    $rows=all('SELECT bp.*,bc.title,bc.description,bc.evidence_guidance,bt.name theme FROM brickworks_progress bp JOIN brickworks_criteria bc ON bc.id=bp.criterion_id JOIN brickworks_themes bt ON bt.id=bc.theme_id WHERE bp.participant_id=? ORDER BY bt.sort_order,bc.sort_order',[$participant['id']]);
    echo '<div class="card"><table><tr><th>Theme</th><th>Criteria</th><th>Status</th><th>Comments/evidence</th><th>Submit</th></tr>'; foreach($rows as $r){ $status=$r['status']==='complete'?'Complete - '.$r['completed_at']:($r['status']==='pending_approval'?'In progress / Pending approval':'Not completed'); echo '<tr><td>'.e($r['theme']).'</td><td><strong>'.e($r['title']).'</strong><br>'.e($r['description']).'<br><span class="muted">'.e($r['evidence_guidance']).'</span></td><td>'.e($status).'</td><td>'.e(decrypt_value($r['member_comment_encrypted'])).'<br><em>'.e(decrypt_value($r['reviewer_comment_encrypted'])).'</em></td><td><form method="post" enctype="multipart/form-data">'.csrf_field().'<input type="hidden" name="submit_evidence" value="1"><input type="hidden" name="progress_id" value="'.e($r['id']).'"><textarea name="member_comment" placeholder="Comments/evidence notes"></textarea><input type="file" name="evidence"><button>Submit evidence</button></form></td></tr>'; } echo '</table></div>';
    if(has_permission('review_brickworks_evidence')) echo '<div class="card"><p><a class="btn" href="?route=brickworks_review">Review Brickworks evidence</a></p></div>';
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
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_csrf();
        exec_sql('INSERT INTO emails (subject,body_html,body_text,status,category,created_by_user_id,created_at,updated_at) VALUES (?,?,?,"draft",?,?,datetime("now"),datetime("now"))',[trim($_POST['subject']),$_POST['body_html'],strip_tags($_POST['body_html']),trim($_POST['category']),$u['id']]);
        $email_id=(int)db()->lastInsertId();
        if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error']===UPLOAD_ERR_OK) { $stored=bin2hex(random_bytes(18)).'-'.preg_replace('/[^A-Za-z0-9._-]/','_',$_FILES['attachment']['name']); $dest=PRIVATE_PATH.'/email-attachments/'.$stored; if(!is_dir(dirname($dest))) mkdir(dirname($dest),0750,true); move_uploaded_file($_FILES['attachment']['tmp_name'],$dest); exec_sql('INSERT INTO email_attachments (email_id,original_filename,stored_filename,mime_type,file_size,uploaded_by_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,datetime("now"),datetime("now"))',[$email_id,$_FILES['attachment']['name'],$stored,mime_content_type($dest),(int)$_FILES['attachment']['size'],$u['id']]); audit('email.attachment_uploaded','email',$email_id); }
        $members=all('SELECT m.*,ep.allow_open_tracking FROM members m LEFT JOIN member_email_preferences ep ON ep.member_id=m.id WHERE m.membership_status="active"');
        foreach($members as $m){ $track=(int)($m['allow_open_tracking']??0); $tid=$track?bin2hex(random_bytes(24)):null; exec_sql('INSERT INTO email_recipients (email_id,member_id,email_address,recipient_name,tracking_enabled,tracking_id,status,created_at,updated_at) VALUES (?,?,?,?,?,?,"queued",datetime("now"),datetime("now"))',[$email_id,$m['id'],$m['email'],$m['first_name'].' '.$m['last_name'],$track,$tid]); }
        if (isset($_POST['send_now'])) {
            $cfg=app_config(); $recips=all('SELECT * FROM email_recipients WHERE email_id=?',[$email_id]);
            foreach($recips as $r){ $body=$_POST['body_html']; if($r['tracking_enabled']){ $base=rtrim($cfg['base_url'] ?: ((isset($_SERVER['HTTPS'])?'https':'http').'://'.($_SERVER['HTTP_HOST']??'' ).dirname($_SERVER['SCRIPT_NAME'])), '/'); $body.='<img src="'.$base.'/?route=email_open&id='.e($r['tracking_id']).'" width="1" height="1" alt="">'; }
                $headers="MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\n"; $ok=@mail($r['email_address'],$_POST['subject'],$body,$headers); exec_sql('UPDATE email_recipients SET status=?, sent_at=CASE WHEN ?=1 THEN datetime("now") ELSE sent_at END, failed_at=CASE WHEN ?=0 THEN datetime("now") ELSE failed_at END, failure_reason=CASE WHEN ?=0 THEN "mail() failed or not configured" ELSE NULL END, updated_at=datetime("now") WHERE id=?',[$ok?'sent':'failed',$ok?1:0,$ok?1:0,$ok?1:0,$r['id']]); }
            exec_sql('UPDATE emails SET status="sent", sent_at=datetime("now"), updated_at=datetime("now") WHERE id=?',[$email_id]); audit('email.sent','email',$email_id); flash('Email created and send attempted. Configure server mail/SMTP for real delivery.'); redirect('emails');
        }
        audit('email.draft_created','email',$email_id); flash('Email draft created.'); redirect('emails');
    }
    $emails=all('SELECT * FROM emails ORDER BY created_at DESC LIMIT 25'); echo '<div class="card"><h1>Email communications</h1><p class="muted">This starter uses PHP mail(). For production, wire this to SMTP/Mailgun/SES/Postfix. Open tracking is only inserted for members who opted in.</p><table><tr><th>Subject</th><th>Status</th><th>Sent</th><th>Recipients</th><th>Opens</th></tr>'; foreach($emails as $em){ $rc=first('SELECT COUNT(*) c, SUM(open_count) opens FROM email_recipients WHERE email_id=?',[$em['id']]); echo '<tr><td>'.e($em['subject']).'</td><td>'.e($em['status']).'</td><td>'.e($em['sent_at']).'</td><td>'.e($rc['c']).'</td><td>'.e($rc['opens'] ?: 0).'</td></tr>'; } echo '</table></div>';
    echo '<div class="card"><h2>Compose email to all active members</h2><form method="post" enctype="multipart/form-data">'.csrf_field().'<label>Category</label><select name="category"><option>admin</option><option>subs</option><option>events</option><option>newsletter</option><option>brickworks</option></select><label>Subject</label><input name="subject" required><label>Body HTML</label><textarea name="body_html" placeholder="Use basic HTML formatting" required></textarea><label>Attachment</label><input type="file" name="attachment"><p><button name="send_now" value="1">Send now</button> <button class="secondary">Save draft</button></p></form></div>';
    page_footer(); exit;
}

if (route() === 'audit') {
    require_permission('view_audit_logs'); audit('audit.view'); page_header('Audit Logs');
    $rows=all('SELECT a.*,u.email FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_user_id ORDER BY a.created_at DESC LIMIT 250');
    echo '<div class="card"><h1>Audit logs</h1><table><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Result</th><th>Reason</th><th>IP</th></tr>'; foreach($rows as $r) echo '<tr><td>'.e($r['created_at']).'</td><td>'.e($r['email']).'</td><td>'.e($r['action']).'</td><td>'.e($r['entity_type'].' #'.$r['entity_id']).'</td><td>'.e($r['result']).'</td><td>'.e($r['reason']).'</td><td>'.e($r['ip_address']).'</td></tr>'; echo '</table></div>'; page_footer(); exit;
}

page_header('Not found'); echo '<div class="card"><h1>Page not found</h1></div>'; page_footer();
