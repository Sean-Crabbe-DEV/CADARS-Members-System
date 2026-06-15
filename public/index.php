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
        'email_open_tracking_enabled' => '1',
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

function normalise_email_list($emails): array {
    if (is_array($emails)) $parts = $emails;
    else $parts = preg_split('/[,;]+/', (string)$emails) ?: [];
    $clean = [];
    foreach ($parts as $email) {
        $email = trim((string)$email);
        $email = str_replace(["\r","\n"], '', $email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $clean[strtolower($email)] = $email;
        }
    }
    return array_values($clean);
}

function email_reply_to_for_sender(array $senderUser, array $cfg, string $fallbackFrom): string {
    $emails = [];

    $senderEmail = trim((string)($senderUser['email'] ?? ''));
    if ($senderEmail !== '') $emails[] = $senderEmail;

    // Include any active user/member with the Secretary role. Prefer the linked
    // member email where available, falling back to the user account email.
    try {
        $secretaries = all('SELECT DISTINCT COALESCE(NULLIF(m.email,""), u.email) email
            FROM users u
            JOIN user_roles ur ON ur.user_id=u.id
            JOIN roles r ON r.id=ur.role_id
            LEFT JOIN members m ON m.id=u.member_id
            WHERE u.status="active"
              AND r.name="secretary"
              AND (ur.expires_at IS NULL OR ur.expires_at > datetime("now"))');
        foreach ($secretaries as $sec) {
            if (!empty($sec['email'])) $emails[] = $sec['email'];
        }
    } catch (Throwable $e) {}

    // Fallbacks only apply if neither the sender nor secretary has a usable email.
    if (!normalise_email_list($emails)) {
        if (!empty($cfg['email_reply_to'])) $emails[] = $cfg['email_reply_to'];
        $emails[] = $fallbackFrom;
    }

    return implode(', ', normalise_email_list($emails));
}

function send_configured_email(array $cfg, array $to, array $bcc, string $subject, string $html, string $fromName, string $fromAddress, string $replyTo, array $attachments=[]): array {
    $method = $cfg['email_method'] ?? 'php_mail';
    $to = array_values(array_filter(array_map('trim', $to)));
    $bcc = array_values(array_filter(array_map('trim', $bcc)));
    $subject = str_replace(["\r","\n"], '', $subject);
    $fromName = str_replace(["\r","\n"], '', $fromName);
    $fromAddress = str_replace(["\r","\n"], '', $fromAddress);
    $replyTo = str_replace(["\r","\n"], '', $replyTo);
    $replyToAddresses = normalise_email_list($replyTo);
    $replyTo = implode(', ', $replyToAddresses);

    if (!$to) return ['ok' => false, 'error' => 'No To recipient supplied.'];

    $preparedAttachments = [];
    foreach ($attachments as $a) {
        $path = $a['path'] ?? $a['file_path'] ?? '';
        if (!$path && !empty($a['stored_filename'])) $path = PRIVATE_PATH . '/email-attachments/' . $a['stored_filename'];
        if (!$path || !is_file($path)) continue;
        $preparedAttachments[] = [
            'filename' => preg_replace('/[\r\n"]+/', '', (string)($a['filename'] ?? $a['original_filename'] ?? basename($path))),
            'mime_type' => (string)($a['mime_type'] ?? mime_content_type($path) ?: 'application/octet-stream'),
            'path' => $path,
            'content' => file_get_contents($path),
        ];
    }

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
        if ($replyToAddresses) $payload['reply_to'] = count($replyToAddresses) === 1 ? $replyToAddresses[0] : $replyToAddresses;
        if ($preparedAttachments) {
            $payload['attachments'] = [];
            foreach ($preparedAttachments as $a) {
                $payload['attachments'][] = [
                    'filename' => $a['filename'],
                    'content' => base64_encode((string)$a['content']),
                    'content_type' => $a['mime_type'],
                ];
            }
        }

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

    if ($preparedAttachments) {
        $boundary = '=_CADARS_' . bin2hex(random_bytes(16));
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= 'From: ' . $fromName . ' <' . $fromAddress . ">\r\n";
        $headers .= 'Reply-To: ' . $replyTo . "\r\n";
        if ($bcc) $headers .= 'Bcc: ' . implode(', ', $bcc) . "\r\n";
        $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . "\r\n";

        $message = "--{$boundary}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $html . "\r\n";

        foreach ($preparedAttachments as $a) {
            $message .= "--{$boundary}\r\n";
            $message .= 'Content-Type: ' . $a['mime_type'] . '; name="' . $a['filename'] . '"' . "\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n";
            $message .= 'Content-Disposition: attachment; filename="' . $a['filename'] . '"' . "\r\n\r\n";
            $message .= chunk_split(base64_encode((string)$a['content'])) . "\r\n";
        }
        $message .= "--{$boundary}--\r\n";

        $ok = @mail(implode(', ', $to), $subject, $message, $headers);
        return ['ok' => (bool)$ok, 'error' => $ok ? null : 'mail() failed or is not configured.'];
    }

    $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\n";
    $headers .= 'From: ' . $fromName . ' <' . $fromAddress . ">\r\n";
    $headers .= 'Reply-To: ' . $replyTo . "\r\n";
    if ($bcc) $headers .= 'Bcc: ' . implode(', ', $bcc) . "\r\n";

    $ok = @mail(implode(', ', $to), $subject, $html, $headers);
    return ['ok' => (bool)$ok, 'error' => $ok ? null : 'mail() failed or is not configured.'];
}

function app_base_url(): string {
    $cfg = app_config();
    if (!empty($cfg['base_url'])) return rtrim((string)$cfg['base_url'], '/');
    $scheme = 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) $scheme = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
    elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') $scheme = 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    if ($dir === '' || $dir === '.') $dir = '';
    return rtrim($scheme . '://' . $host . $dir, '/');
}
function create_user_password_token(int $user_id, string $purpose, ?int $created_by_user_id=null, string $expiry='+48 hours'): string {
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    exec_sql('UPDATE user_password_tokens SET used_at=datetime("now") WHERE user_id=? AND purpose=? AND used_at IS NULL', [$user_id, $purpose]);
    exec_sql('INSERT INTO user_password_tokens (user_id, token_hash, purpose, expires_at, created_by_user_id, created_at) VALUES (?, ?, ?, datetime("now", ?), ?, datetime("now"))', [$user_id, $hash, $purpose, $expiry, $created_by_user_id]);
    return $token;
}
function send_user_access_email(array $targetUser, string $purpose, ?int $created_by_user_id=null): array {
    $cfg = app_config();
    $token = create_user_password_token((int)$targetUser['id'], $purpose, $created_by_user_id);
    $link = app_base_url() . '/?route=set_password&token=' . urlencode($token);
    $fromAddress = trim($cfg['email_from_address'] ?: ('noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    $fromName = trim($cfg['email_from_name'] ?: ($cfg['society_name'] ?? 'Membership System'));
    $replyTo = trim($cfg['email_reply_to'] ?: $fromAddress);
    $society = e($cfg['society_name'] ?? 'Membership System');
    $subject = $purpose === 'invite' ? 'Set up your membership system account' : 'Reset your membership system password';
    $intro = $purpose === 'invite'
        ? 'An account has been created for you on the society membership system.'
        : 'A password reset has been requested for your society membership system account.';
    $html = '<p>Hello,</p><p>' . e($intro) . '</p><p><a href="' . e($link) . '">Click here to set your password</a></p><p>This link expires in 48 hours. If the button does not work, copy this link into your browser:</p><p>' . e($link) . '</p><p>Kind regards,<br>' . $society . '</p>';
    $send = send_configured_email($cfg, [$targetUser['email']], [], $subject, $html, $fromName, $fromAddress, $replyTo);
    audit($purpose === 'invite' ? 'user.invite_email_sent' : 'user.password_reset_email_sent', 'user', (int)$targetUser['id'], !empty($send['ok']) ? 'success' : 'failed', $send['error'] ?? null, ['purpose'=>$purpose]);
    return $send;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA temp_store = MEMORY');
    $pdo->exec('PRAGMA cache_size = -20000');
    $pdo->exec('PRAGMA busy_timeout = 5000');
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

function audit_value($value) {
    if ($value === null) return null;
    if (is_bool($value)) return $value ? 'Yes' : 'No';
    if (is_array($value)) return $value;
    return (string)$value;
}
function audit_field_changes(array $old, array $new, array $fieldLabels=[]): array {
    $changes = [];
    foreach ($new as $field => $newValue) {
        $oldValue = $old[$field] ?? null;
        if (audit_value($oldValue) !== audit_value($newValue)) {
            $changes[$field] = [
                'label' => $fieldLabels[$field] ?? ucwords(str_replace('_', ' ', (string)$field)),
                'old' => audit_value($oldValue),
                'new' => audit_value($newValue),
            ];
        }
    }
    return $changes;
}
function audit_metadata_html(?string $json): string {
    if (!$json) return '';
    $data = json_decode($json, true);
    if (!is_array($data)) return '<pre class="audit-meta">'.e($json).'</pre>';
    $html = '';
    $changes = $data['field_changes'] ?? $data['changes'] ?? null;
    if (is_array($changes) && $changes) {
        $html .= '<details class="audit-details"><summary>View changes</summary><table class="audit-change-table"><tr><th>Field</th><th>Old</th><th>New</th></tr>';
        foreach ($changes as $field=>$change) {
            if (is_array($change) && (array_key_exists('old', $change) || array_key_exists('new', $change) || array_key_exists('from', $change) || array_key_exists('to', $change))) {
                $label = $change['label'] ?? ucwords(str_replace('_',' ',(string)$field));
                $old = $change['old'] ?? ($change['from'] ?? null);
                $new = $change['new'] ?? ($change['to'] ?? null);
                $html .= '<tr><td>'.e($label).'</td><td>'.nl2br(e(is_array($old) ? json_encode($old) : (string)$old)).'</td><td>'.nl2br(e(is_array($new) ? json_encode($new) : (string)$new)).'</td></tr>';
            }
        }
        $html .= '</table></details>';
        unset($data['field_changes'], $data['changes']);
    }
    $clean = $data;
    unset($clean['ip_details']);
    if ($clean) $html .= '<details class="audit-details"><summary>More data</summary><pre class="audit-meta">'.e(json_encode($clean, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre></details>';
    if (!empty($data['ip_details'])) $html .= '<details class="audit-details"><summary>IP details</summary><pre class="audit-meta">'.e(json_encode($data['ip_details'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre></details>';
    return $html;
}
function first(string $sql, array $args=[]): ?array { $s=db()->prepare($sql); $s->execute($args); $r=$s->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
function all(string $sql, array $args=[]): array { $s=db()->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC); }
function exec_sql(string $sql, array $args=[]): void { $s=db()->prepare($sql); $s->execute($args); }
function app_meta_get(string $key): ?string {
    try { $r = first('SELECT value FROM app_meta WHERE key=?', [$key]); return $r['value'] ?? null; }
    catch (Throwable $e) { return null; }
}
function app_meta_set(string $key, string $value): void {
    exec_sql('INSERT INTO app_meta (key,value,updated_at) VALUES (?,?,datetime("now")) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=datetime("now")', [$key,$value]);
}
function ensure_performance_indexes(): void {
    static $done = false; if ($done) return; $done = true;
    $indexes = [
        'CREATE INDEX IF NOT EXISTS idx_users_member ON users(member_id)',
        'CREATE INDEX IF NOT EXISTS idx_users_status ON users(status)',
        'CREATE INDEX IF NOT EXISTS idx_user_roles_user ON user_roles(user_id)',
        'CREATE INDEX IF NOT EXISTS idx_user_roles_role ON user_roles(role_id)',
        'CREATE INDEX IF NOT EXISTS idx_role_permissions_role ON role_permissions(role_id)',
        'CREATE INDEX IF NOT EXISTS idx_members_email ON members(email)',
        'CREATE INDEX IF NOT EXISTS idx_members_membership_number ON members(membership_number)',
        'CREATE INDEX IF NOT EXISTS idx_members_status_name ON members(membership_status,last_name,first_name)',
        'CREATE INDEX IF NOT EXISTS idx_events_start ON events(start_at)',
        'CREATE INDEX IF NOT EXISTS idx_events_type_start ON events(event_type,start_at)',
        'CREATE INDEX IF NOT EXISTS idx_event_attendance_event ON event_attendance(event_id)',
        'CREATE INDEX IF NOT EXISTS idx_event_attendance_member ON event_attendance(member_id)',
        'CREATE INDEX IF NOT EXISTS idx_event_guests_event ON event_guests(event_id)',
        'CREATE INDEX IF NOT EXISTS idx_equipment_asset ON equipment(asset_number)',
        'CREATE INDEX IF NOT EXISTS idx_equipment_category ON equipment(category)',
        'CREATE INDEX IF NOT EXISTS idx_equipment_maintenance_equipment ON equipment_maintenance_tickets(equipment_id)',
        'CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at)',
        'CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_logs(action)',
        'CREATE INDEX IF NOT EXISTS idx_audit_result ON audit_logs(result)',
        'CREATE INDEX IF NOT EXISTS idx_emails_created ON emails(created_at)',
        'CREATE INDEX IF NOT EXISTS idx_email_recipients_email ON email_recipients(email_id)',
        'CREATE INDEX IF NOT EXISTS idx_brickworks_participant_member ON brickworks_participants(member_id)',
        'CREATE INDEX IF NOT EXISTS idx_brickworks_progress_participant ON brickworks_progress(participant_id)',
        'CREATE INDEX IF NOT EXISTS idx_committee_actions_due ON committee_actions(due_date,status)'
    ];
    foreach ($indexes as $sql) { try { db()->exec($sql); } catch (Throwable $e) {} }
    try { db()->exec('PRAGMA optimize'); } catch (Throwable $e) {}
}
function ensure_runtime_setup(): void {
    create_schema();
    $target = '2026-06-15-v3-role-menu-programme-performance';
    if (app_meta_get('runtime_setup_version') !== $target) {
        seed_roles_permissions();
        seed_brickworks();
        ensure_performance_indexes();
        app_meta_set('runtime_setup_version', $target);
    }
}
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
function is_officer(): bool { return has_role('chair') || has_role('vice_chair') || has_role('secretary') || has_role('treasurer'); }
function is_committee_or_admin(): bool { return has_role('committee') || is_officer() || has_role('admin'); }
function can_manage_events(): bool { return has_permission('manage_events'); }
function can_view_committee_menu(): bool {
    foreach (['track_attendance','send_member_emails','view_equipment','view_committee_actions','view_membership_db','manage_events'] as $p) {
        if (has_permission($p)) return true;
    }
    return false;
}
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
function user_is_admin(int $user_id): bool { return in_array('admin', user_roles($user_id), true); }
function active_admin_count(): int {
    return (int)(first('SELECT COUNT(DISTINCT ur.user_id) AS c FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE r.name="admin" AND u.status="active" AND (ur.expires_at IS NULL OR ur.expires_at > datetime("now"))')['c'] ?? 0);
}
function can_edit_membership_number(): bool { return is_admin_user(); }
function member_joined_display(array $m): string {
    if (!empty($m['joined_before_system']) && empty($m['date_joined'])) return 'Not on record - joined before system';
    if (!empty($m['joined_before_system'])) return ($m['date_joined'] ?: 'Not on record') . ' - joined before system';
    return $m['date_joined'] ?: 'Not on record';
}
function consent_labels(): array {
    return [
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


function import_yes_value($value): bool {
    $v = strtolower(trim((string)$value));
    return in_array($v, ['1','yes','y','true','on','paid','active','complete','completed'], true);
}
function import_clean_text($value): string {
    if ($value === null) return '';
    if (is_bool($value)) return $value ? 'Yes' : 'No';
    return trim((string)$value);
}
function import_header_key($header): string {
    $h = strtolower(trim((string)$header));
    $h = str_replace(['/', '&', '+'], ' ', $h);
    $h = preg_replace('/[^a-z0-9]+/', ' ', $h);
    return trim(preg_replace('/\s+/', ' ', $h));
}
function import_excel_serial_date($value): string {
    if (!is_numeric($value)) return '';
    $serial = (float)$value;
    if ($serial < 20000 || $serial > 80000) return '';
    return gmdate('Y-m-d', (int)(($serial - 25569) * 86400));
}
function import_normalize_date($value): string {
    $v = import_clean_text($value);
    if ($v === '') return '';
    if (is_numeric($v)) {
        $d = import_excel_serial_date($v);
        if ($d) return $d;
    }
    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/', $v, $m)) {
        $year = (int)$m[3];
        if ($year < 100) $year += 2000;
        return sprintf('%04d-%02d-%02d', $year, (int)$m[2], (int)$m[1]);
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : '';
}
function import_split_name(string $fullName): array {
    $fullName = trim(preg_replace('/\s+/', ' ', $fullName));
    if ($fullName === '') return ['', ''];
    $parts = explode(' ', $fullName);
    if (count($parts) === 1) return [$parts[0], ''];
    $last = array_pop($parts);
    return [implode(' ', $parts), $last];
}
function import_xlsx_rows(string $path): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('XLSX import requires the PHP zip extension. Install with: apt install php-zip && systemctl restart php8.1-fpm');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Could not open XLSX file.');

    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx) {
            foreach ($sx->si as $si) {
                if (isset($si->t)) $shared[] = (string)$si->t;
                else {
                    $txt = '';
                    foreach ($si->r as $r) $txt .= (string)$r->t;
                    $shared[] = $txt;
                }
            }
        }
    }

    $sheetName = 'xl/worksheets/sheet1.xml';
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml !== false && $relsXml !== false) {
        $wb = @simplexml_load_string($workbookXml);
        $rels = @simplexml_load_string($relsXml);
        if ($wb && $rels && isset($wb->sheets->sheet[0])) {
            $attrs = $wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rid = (string)($attrs['id'] ?? '');
            if ($rid) {
                foreach ($rels->Relationship as $rel) {
                    $a = $rel->attributes();
                    if ((string)$a['Id'] === $rid) {
                        $target = (string)$a['Target'];
                        $sheetName = 'xl/' . ltrim($target, '/');
                        if (!str_starts_with($sheetName, 'xl/worksheets/') && str_contains($target, 'worksheets/')) $sheetName = 'xl/' . $target;
                        break;
                    }
                }
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetName);
    if ($sheetXml === false) $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) throw new RuntimeException('Could not find the first worksheet in the XLSX file.');

    $sx = @simplexml_load_string($sheetXml);
    if (!$sx) throw new RuntimeException('Could not read worksheet XML.');
    $rows = [];
    foreach ($sx->sheetData->row as $row) {
        $out = [];
        foreach ($row->c as $cell) {
            $attrs = $cell->attributes();
            $ref = (string)($attrs['r'] ?? 'A1');
            preg_match('/^([A-Z]+)/i', $ref, $m);
            $letters = strtoupper($m[1] ?? 'A');
            $col = 0;
            for ($i=0; $i<strlen($letters); $i++) $col = $col * 26 + (ord($letters[$i]) - 64);
            $col--;
            $type = (string)($attrs['t'] ?? '');
            $val = '';
            if ($type === 's') {
                $idx = (int)($cell->v ?? 0);
                $val = $shared[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string)($cell->is->t ?? '');
            } elseif ($type === 'b') {
                $val = ((string)($cell->v ?? '0')) === '1' ? 'Yes' : 'No';
            } else {
                $val = (string)($cell->v ?? '');
            }
            $out[$col] = $val;
        }
        if ($out) {
            ksort($out);
            $max = max(array_keys($out));
            $dense = [];
            for ($i=0; $i<=$max; $i++) $dense[] = import_clean_text($out[$i] ?? '');
            if (implode('', $dense) !== '') $rows[] = $dense;
        }
    }
    return $rows;
}
function import_csv_rows(string $path): array {
    $rows = [];
    $handle = fopen($path, 'r');
    if (!$handle) throw new RuntimeException('Could not open CSV file.');
    while (($row = fgetcsv($handle)) !== false) {
        if (!$rows && isset($row[0])) $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$row[0]);
        $clean = array_map('import_clean_text', $row);
        if (implode('', $clean) !== '') $rows[] = $clean;
    }
    fclose($handle);
    return $rows;
}
function import_spreadsheet_rows(string $path, string $filename): array {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') return import_xlsx_rows($path);
    if (in_array($ext, ['csv','txt'], true)) return import_csv_rows($path);
    throw new RuntimeException('Unsupported file type. Please upload .xlsx or .csv.');
}
function import_member_column_aliases(): array {
    return [
        'membership_number' => ['membership number','member number','membership no','member no','no'],
        'full_name' => ['full name','name','member name'],
        'first_name' => ['first name','firstname','forename'],
        'last_name' => ['surname','last name','lastname'],
        'email' => ['email','email address'],
        'phone' => ['phone','phone number','mobile','mobile number','telephone'],
        'address' => ['address'],
        'callsign' => ['callsign','call sign','call'],
        'licence_level' => ['licence level','license level','licence class','license class','class'],
        'society_role' => ['society role','club role','role'],
        'membership_type' => ['membership type','type'],
        'payment_status' => ['payment status','subs status','subscription status'],
        'payment_date' => ['payment date','paid date','latest payment date'],
        'date_joined' => ['date joined','membership start','join date','joined'],
        'joined_before_system' => ['joined before system date not on record','joined before system','date not on record'],
        'active' => ['active'],
        'renewal_date' => ['renewal date','renewal due'],
        'membership_status' => ['membership status','status'],
        'emergency_contact_name' => ['emergency contact name','emergency contact'],
        'emergency_contact_relationship' => ['emergency contact relationship','relationship to member','relationship'],
        'emergency_contact_phone' => ['emergency contact phone','emergency phone'],
        'email_comms' => ['email communications consent','email comms','email communications'],
        'text_comms' => ['text messages consent','text comms','text messages'],
        'whatsapp_community' => ['whatsapp community consent','whatsapp community','whatsapp opt in'],
    ];
}
function import_member_map_headers(array $headers): array {
    $keys = array_map('import_header_key', $headers);
    $map = [];
    foreach (import_member_column_aliases() as $field=>$aliases) {
        foreach ($aliases as $alias) {
            $idx = array_search(import_header_key($alias), $keys, true);
            if ($idx !== false) { $map[$field] = $idx; break; }
        }
    }
    return $map;
}
function import_get(array $row, array $map, string $field): string {
    return array_key_exists($field, $map) ? import_clean_text($row[$map[$field]] ?? '') : '';
}
function import_member_status(string $status, string $active): string {
    $s = strtolower(trim($status));
    $allowed = ['pending','active','expired','former','suspended','honorary','life_member'];
    if (in_array($s, $allowed, true)) return $s;
    if ($active !== '') return import_yes_value($active) ? 'active' : 'former';
    return $s ?: 'active';
}
function ensure_member_support_rows(int $member_id): void {
    exec_sql('INSERT OR IGNORE INTO member_directory_preferences (member_id, created_at, updated_at) VALUES (?,datetime("now"),datetime("now"))', [$member_id]);
    exec_sql('INSERT OR IGNORE INTO member_email_preferences (member_id, created_at, updated_at) VALUES (?,datetime("now"),datetime("now"))', [$member_id]);
}
function import_members_from_rows(array $rows, bool $updateExisting, bool $importPayments, int $actorUserId): array {
    $summary = ['created'=>0,'updated'=>0,'skipped'=>0,'payments'=>0,'errors'=>[]];
    if (!$rows) { $summary['errors'][] = 'The spreadsheet appears to be empty.'; return $summary; }
    $headerIndex = null; $map = [];
    foreach ($rows as $i=>$r) {
        $candidate = import_member_map_headers($r);
        if (isset($candidate['email']) || isset($candidate['full_name']) || isset($candidate['first_name'])) { $headerIndex = $i; $map = $candidate; break; }
    }
    if ($headerIndex === null) { $summary['errors'][] = 'Could not find a header row. Expected columns such as Full Name, Email, Member Number, Phone or Callsign.'; return $summary; }
    if (!isset($map['email']) && !isset($map['full_name']) && !isset($map['first_name'])) { $summary['errors'][] = 'The spreadsheet does not contain enough member data to import.'; return $summary; }

    db()->beginTransaction();
    try {
        foreach (array_slice($rows, $headerIndex + 1) as $offset=>$row) {
            $line = $headerIndex + $offset + 2;
            $membershipNumber = import_get($row, $map, 'membership_number');
            $fullName = import_get($row, $map, 'full_name');
            $first = import_get($row, $map, 'first_name');
            $last = import_get($row, $map, 'last_name');
            if (($first === '' || $last === '') && $fullName !== '') {
                [$splitFirst, $splitLast] = import_split_name($fullName);
                if ($first === '') $first = $splitFirst;
                if ($last === '') $last = $splitLast;
            }
            $email = import_get($row, $map, 'email');
            $callsign = import_get($row, $map, 'callsign');
            if ($first === '' && $last === '' && $email === '' && $callsign === '') continue;
            if ($first === '' && $last === '') { $summary['skipped']++; $summary['errors'][] = "Row $line skipped: missing name."; continue; }
            if ($email === '') { $email = strtolower(preg_replace('/[^a-z0-9]+/i', '.', trim($first.'.'.$last))) . '.missing-email@example.invalid'; }

            $existing = null;
            if ($membershipNumber !== '') $existing = first('SELECT * FROM members WHERE membership_number=?', [$membershipNumber]);
            if (!$existing && $email !== '') $existing = first('SELECT * FROM members WHERE lower(email)=lower(?)', [$email]);

            $licence = import_get($row, $map, 'licence_level');
            $phone = import_get($row, $map, 'phone');
            $address = import_get($row, $map, 'address');
            $emName = import_get($row, $map, 'emergency_contact_name');
            $emRel = import_get($row, $map, 'emergency_contact_relationship');
            $emPhone = import_get($row, $map, 'emergency_contact_phone');
            $emergencySummary = trim($emName . ' | ' . $emRel . ' | ' . $emPhone);
            $dateJoined = import_normalize_date(import_get($row, $map, 'date_joined'));
            $joinedBeforeSystem = import_yes_value(import_get($row, $map, 'joined_before_system')) || $dateJoined === '';
            $renewalDate = import_normalize_date(import_get($row, $map, 'renewal_date'));
            $membershipType = import_get($row, $map, 'membership_type') ?: import_get($row, $map, 'society_role');
            $status = import_member_status(import_get($row, $map, 'membership_status'), import_get($row, $map, 'active'));

            if ($existing) {
                if (!$updateExisting) { $summary['skipped']++; continue; }
                $oldAudit = [
                    'membership_number'=>$existing['membership_number'] ?? '', 'first_name'=>$existing['first_name'] ?? '', 'last_name'=>$existing['last_name'] ?? '', 'callsign'=>$existing['callsign'] ?? '', 'licence_level'=>$existing['licence_level'] ?? '', 'email'=>$existing['email'] ?? '', 'phone'=>decrypt_value($existing['phone_encrypted'] ?? '') ?: '', 'address'=>decrypt_value($existing['address_encrypted'] ?? '') ?: '', 'emergency_contact_name'=>decrypt_value($existing['emergency_contact_name_encrypted'] ?? '') ?: '', 'emergency_contact_relationship'=>decrypt_value($existing['emergency_contact_relationship_encrypted'] ?? '') ?: '', 'emergency_contact_phone'=>decrypt_value($existing['emergency_contact_phone_encrypted'] ?? '') ?: '', 'date_joined'=>$existing['date_joined'] ?? '', 'joined_before_system'=>!empty($existing['joined_before_system']) ? 'Yes' : 'No', 'renewal_date'=>$existing['renewal_date'] ?? '', 'membership_status'=>$existing['membership_status'] ?? '', 'membership_type'=>$existing['membership_type'] ?? ''
                ];
                $newAudit = ['membership_number'=>$membershipNumber ?: ($existing['membership_number'] ?? ''), 'first_name'=>$first, 'last_name'=>$last, 'callsign'=>$callsign, 'licence_level'=>$licence, 'email'=>$email, 'phone'=>$phone, 'address'=>$address, 'emergency_contact_name'=>$emName, 'emergency_contact_relationship'=>$emRel, 'emergency_contact_phone'=>$emPhone, 'date_joined'=>$dateJoined, 'joined_before_system'=>$joinedBeforeSystem ? 'Yes' : 'No', 'renewal_date'=>$renewalDate, 'membership_status'=>$status, 'membership_type'=>$membershipType];
                $finalNumber = can_edit_membership_number() && $membershipNumber !== '' ? $membershipNumber : ($existing['membership_number'] ?? null);
                exec_sql('UPDATE members SET membership_number=?, first_name=?, last_name=?, callsign=?, licence_level=?, email=?, phone_encrypted=?, address_encrypted=?, emergency_contact_encrypted=?, emergency_contact_name_encrypted=?, emergency_contact_relationship_encrypted=?, emergency_contact_phone_encrypted=?, date_joined=?, joined_before_system=?, renewal_date=?, membership_status=?, membership_type=?, updated_at=datetime("now") WHERE id=?', [$finalNumber ?: null,$first,$last,$callsign,$licence,$email,encrypt_value($phone),encrypt_value($address),encrypt_value($emergencySummary),encrypt_value($emName),encrypt_value($emRel),encrypt_value($emPhone),$dateJoined,$joinedBeforeSystem?1:0,$renewalDate,$status,$membershipType,(int)$existing['id']]);
                $mid = (int)$existing['id'];
                ensure_member_support_rows($mid);
                audit('member.import_update','member',$mid,'success',null,['row'=>$line,'field_changes'=>audit_field_changes($oldAudit,$newAudit)]);
                $summary['updated']++;
            } else {
                exec_sql('INSERT INTO members (membership_number,first_name,last_name,callsign,licence_level,email,phone_encrypted,address_encrypted,emergency_contact_encrypted,emergency_contact_name_encrypted,emergency_contact_relationship_encrypted,emergency_contact_phone_encrypted,date_joined,joined_before_system,renewal_date,membership_status,membership_type,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime("now"),datetime("now"))', [$membershipNumber ?: null,$first,$last,$callsign,$licence,$email,encrypt_value($phone),encrypt_value($address),encrypt_value($emergencySummary),encrypt_value($emName),encrypt_value($emRel),encrypt_value($emPhone),$dateJoined,$joinedBeforeSystem?1:0,$renewalDate,$status,$membershipType]);
                $mid = (int)db()->lastInsertId();
                ensure_member_support_rows($mid);
                audit('member.import_create','member',$mid,'success',null,['row'=>$line]);
                $summary['created']++;
            }

            foreach (['text_comms','whatsapp_community'] as $consentType) {
                if (array_key_exists($consentType, $map)) set_member_consent($mid, $consentType, import_yes_value(import_get($row,$map,$consentType)), $actorUserId);
            }

            if ($importPayments) {
                $paymentStatus = strtolower(import_get($row, $map, 'payment_status'));
                $paymentDate = import_normalize_date(import_get($row, $map, 'payment_date'));
                if ($paymentStatus !== '' || $paymentDate !== '') {
                    $year = $paymentDate ? (int)substr($paymentDate, 0, 4) : (int)date('Y');
                    $statusClean = in_array($paymentStatus, ['paid','unpaid','pending','part-paid','part_paid','waived','refunded'], true) ? str_replace('_','-',$paymentStatus) : ($paymentStatus ?: 'unpaid');
                    $amountDue = 30.00;
                    $amountPaid = $statusClean === 'paid' ? 30.00 : 0.00;
                    $dup = first('SELECT id FROM subscription_payments WHERE member_id=? AND subscription_year=? AND status=? AND COALESCE(payment_date,"")=? AND payment_reference="Imported member spreadsheet"', [$mid,$year,$statusClean,$paymentDate]);
                    if (!$dup) {
                        exec_sql('INSERT INTO subscription_payments (member_id,subscription_year,amount_due,amount_paid,payment_date,payment_method,payment_reference,status,recorded_by_user_id,notes_encrypted,created_at,updated_at) VALUES (?,?,?,?,?,"Imported","Imported member spreadsheet",?,?,?,datetime("now"),datetime("now"))', [$mid,$year,$amountDue,$amountPaid,$paymentDate ?: null,$statusClean,$actorUserId,encrypt_value('Imported from member spreadsheet')]);
                        $summary['payments']++;
                    }
                }
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $summary['errors'][] = 'Import failed: ' . $e->getMessage();
    }
    return $summary;
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
        if (can_view_committee_menu()) {
            echo '<div class="dropdown"><button type="button" class="nav-drop" aria-haspopup="true">Committee ▾</button><div class="dropdown-menu">';
            if (has_permission('view_membership_db')) echo '<a href="?route=members">Members</a>';
            if (has_permission('track_attendance')) echo '<a href="?route=attendance">Attendance</a><a href="?route=attendance_stats">Attendance stats</a>';
            if (has_permission('send_member_emails')) echo '<a href="?route=emails">Emails</a>';
            if (has_permission('view_equipment')) echo '<a href="?route=equipment">Equipment / assets</a>';
            if (has_permission('view_committee_actions')) echo '<a href="?route=committee_actions">Actions</a>';
            echo '</div></div>';
        }
        if (is_admin_user()) {
            echo '<div class="dropdown"><button type="button" class="nav-drop" aria-haspopup="true">Admin ▾</button><div class="dropdown-menu">';
            echo '<a href="?route=users">Users</a>';
            echo '<a href="?route=audit">Audit logs</a>';
            echo '<a href="?route=email_config">Email settings</a>';
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
</main>
<footer class="site-footer">
  <div class="footer-main">
    <strong>Created and Maintained By Sean Crabbe</strong>
    <a href="mailto:sean@defenderonfrequency.uk">sean@defenderonfrequency.uk</a>
  </div>
  <div class="footer-links">
    <a href="?route=gdpr_policy">GDPR policy</a>
    <span>•</span>
    <a href="?route=data_retention_policy">Data retention policy</a>
  </div>
  <div class="footer-version">V2 Club Management and Information System • Last updated: 13/06/2026</div>
</footer>
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

function auth_page_header(string $title): void {
    $cfg = app_config();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title><link rel="stylesheet" href="?route=assets.css"></head><body class="auth-body"><main class="auth-main">';
    if (!empty($_SESSION['flash'])) { echo '<div class="auth-flash">' . e($_SESSION['flash']) . '</div>'; unset($_SESSION['flash']); }
}
function auth_page_footer(): void {
    echo '</main><footer class="auth-footer">V2 Club Management and Information System • <a href="?route=gdpr_policy">GDPR policy</a> • <a href="?route=data_retention_policy">Data retention policy</a></footer></body></html>';
}
function auth_card_open(string $title, string $subtitle=''): void {
    $cfg = app_config();
    echo '<div class="auth-card"><div class="auth-logo" aria-hidden="true">CADARS</div><h1>'.e($title).'</h1>';
    if ($subtitle !== '') echo '<p class="auth-subtitle">'.e($subtitle).'</p>';
}
function auth_card_close(): void { echo '</div>'; }


function create_schema(): void {
    $pdo = db();
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS app_meta (
 key TEXT PRIMARY KEY,
 value TEXT NOT NULL,
 updated_at TEXT NOT NULL
);
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
CREATE TABLE IF NOT EXISTS user_password_tokens (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL,
 token_hash TEXT NOT NULL UNIQUE,
 purpose TEXT NOT NULL,
 expires_at TEXT NOT NULL,
 used_at TEXT NULL,
 created_by_user_id INTEGER NULL,
 created_at TEXT NOT NULL
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
        'chair' => 'Chair',
        'vice_chair' => 'Vice Chair',
        'secretary' => 'Secretary',
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
        'committee' => ['view_events','manage_events','track_attendance','view_committee_actions','manage_committee_actions'],
        'chair' => ['view_events','manage_events','track_attendance','view_committee_actions','manage_committee_actions','view_equipment','edit_equipment','manage_equipment_loans','view_membership_db','edit_membership_db','manage_subscriptions','export_member_data','send_member_emails','send_role_emails','send_event_emails','send_subs_reminders','send_brickworks_emails','view_email_reports','view_email_open_tracking','manage_email_attachments'],
        'vice_chair' => ['view_events','manage_events','track_attendance','view_committee_actions','manage_committee_actions','view_equipment','edit_equipment','manage_equipment_loans','view_membership_db','edit_membership_db','manage_subscriptions','export_member_data','send_member_emails','send_role_emails','send_event_emails','send_subs_reminders','send_brickworks_emails','view_email_reports','view_email_open_tracking','manage_email_attachments'],
        'secretary' => ['view_events','manage_events','track_attendance','view_committee_actions','manage_committee_actions','view_equipment','edit_equipment','manage_equipment_loans','view_membership_db','edit_membership_db','manage_subscriptions','export_member_data','send_member_emails','send_role_emails','send_event_emails','send_subs_reminders','send_brickworks_emails','view_email_reports','view_email_open_tracking','manage_email_attachments'],
        'treasurer' => ['view_events','manage_events','track_attendance','view_committee_actions','manage_committee_actions','view_equipment','edit_equipment','manage_equipment_loans','view_membership_db','edit_membership_db','manage_subscriptions','export_member_data','send_member_emails','send_role_emails','send_event_emails','send_subs_reminders','send_brickworks_emails','view_email_reports','view_email_open_tracking','manage_email_attachments'],
        'equipment_manager' => ['view_equipment','edit_equipment','manage_equipment_loans','view_equipment_audit_logs'],
        'event_manager' => ['view_events','manage_events'],
        'member_db' => ['view_membership_db','edit_membership_db','manage_subscriptions','export_member_data','send_member_emails','send_subs_reminders','view_email_reports','view_email_open_tracking'],
        'brickworks_reviewer' => ['view_brickworks_participants','review_brickworks_evidence','approve_brickworks_criteria','manage_brickworks_criteria','export_brickworks_reports','send_brickworks_emails'],
        'brickworks_participant' => ['view_own_brickworks','submit_brickworks_evidence'],
        'admin' => $permissions
    ];
    $controlledRoles = array_keys($rolePerms);
    foreach ($controlledRoles as $roleName) {
        $roleRow = first('SELECT id FROM roles WHERE name=?', [$roleName]);
        if ($roleRow) exec_sql('DELETE FROM role_permissions WHERE role_id=?', [$roleRow['id']]);
    }
    foreach ($rolePerms as $role=>$perms) {
        $rid = first('SELECT id FROM roles WHERE name=?',[$role])['id'];
        foreach ($perms as $perm) {
            $pid = first('SELECT id FROM permissions WHERE name=?',[$perm])['id'] ?? null;
            if ($pid) exec_sql('INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (?,?)',[$rid,$pid]);
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

    // Safety: the system must always keep at least one active admin.
    // If this user is currently an admin and the submitted role list would remove that role,
    // keep admin when they are the last active admin.
    if (in_array('admin', $targetCurrent, true) && !in_array('admin', $role_names, true)) {
        if (active_admin_count() <= 1) $role_names[] = 'admin';
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

function can_delete_user_account(int $user_id, int $actor_user_id): array {
    $target = first('SELECT * FROM users WHERE id=?', [$user_id]);
    if (!$target) return [false, 'User not found.'];

    // Only admin users can delete user accounts at all.
    if (!user_is_admin($actor_user_id)) return [false, 'Only admin users can delete user accounts.'];

    if ($user_id === $actor_user_id) return [false, 'You cannot delete your own user account while logged in.'];

    // Admin accounts may only be deleted by another admin, and never if it would leave zero admins.
    if (user_is_admin($user_id)) {
        if (!user_is_admin($actor_user_id)) return [false, 'Only another admin can delete an admin user.'];
        if (active_admin_count() <= 1) return [false, 'You cannot delete the last active admin user.'];
    }

    return [true, ''];
}
function delete_user_account(int $user_id, int $actor_user_id): array {
    [$ok, $reason] = can_delete_user_account($user_id, $actor_user_id);
    if (!$ok) return [false, $reason];
    $target = first('SELECT * FROM users WHERE id=?', [$user_id]);
    audit('user.delete_requested','user',$user_id);
    exec_sql('DELETE FROM user_password_tokens WHERE user_id=?', [$user_id]);
    exec_sql('DELETE FROM user_roles WHERE user_id=?', [$user_id]);
    exec_sql('DELETE FROM user_role_history WHERE user_id=?', [$user_id]);
    exec_sql('UPDATE event_attendance SET marked_by_user_id=NULL WHERE marked_by_user_id=?', [$user_id]);
    exec_sql('UPDATE events SET created_by_user_id=NULL WHERE created_by_user_id=?', [$user_id]);
    exec_sql('UPDATE event_attachments SET uploaded_by_user_id=0 WHERE uploaded_by_user_id=?', [$user_id]);
    exec_sql('UPDATE equipment_loans SET approved_by_user_id=NULL WHERE approved_by_user_id=?', [$user_id]);
    exec_sql('UPDATE maintenance_logs SET completed_by_user_id=NULL WHERE completed_by_user_id=?', [$user_id]);
    exec_sql('UPDATE equipment_maintenance_tickets SET assigned_user_id=NULL WHERE assigned_user_id=?', [$user_id]);
    exec_sql('UPDATE equipment_maintenance_tickets SET created_by_user_id=NULL WHERE created_by_user_id=?', [$user_id]);
    exec_sql('UPDATE committee_actions SET assigned_user_id=NULL WHERE assigned_user_id=?', [$user_id]);
    exec_sql('UPDATE committee_actions SET created_by_user_id=NULL WHERE created_by_user_id=?', [$user_id]);
    exec_sql('UPDATE committee_action_updates SET created_by_user_id=NULL WHERE created_by_user_id=?', [$user_id]);
    exec_sql('UPDATE brickworks_progress SET reviewed_by_user_id=NULL WHERE reviewed_by_user_id=?', [$user_id]);
    exec_sql('UPDATE brickworks_evidence SET uploaded_by_user_id=0 WHERE uploaded_by_user_id=?', [$user_id]);
    exec_sql('UPDATE brickworks_awards SET awarded_by_user_id=NULL WHERE awarded_by_user_id=?', [$user_id]);
    exec_sql('UPDATE subscription_payments SET recorded_by_user_id=NULL WHERE recorded_by_user_id=?', [$user_id]);
    exec_sql('UPDATE member_consents SET recorded_by_user_id=NULL WHERE recorded_by_user_id=?', [$user_id]);
    exec_sql('UPDATE member_status_history SET changed_by_user_id=NULL WHERE changed_by_user_id=?', [$user_id]);
    exec_sql('UPDATE emails SET created_by_user_id=0 WHERE created_by_user_id=?', [$user_id]);
    exec_sql('UPDATE email_attachments SET uploaded_by_user_id=0 WHERE uploaded_by_user_id=?', [$user_id]);
    exec_sql('DELETE FROM notifications WHERE user_id=?', [$user_id]);
    exec_sql('DELETE FROM users WHERE id=?', [$user_id]);
    audit('user.deleted','user',$user_id,'success',null,['email'=>$target['email'] ?? null]);
    return [true, 'User deleted.'];
}
function can_delete_member_record(int $member_id, int $actor_user_id): array {
    $member = first('SELECT * FROM members WHERE id=?', [$member_id]);
    if (!$member) return [false, 'Member not found.'];
    if (!has_permission('edit_membership_db')) return [false, 'You do not have permission to delete member records.'];
    $linkedUsers = all('SELECT id FROM users WHERE member_id=?', [$member_id]);
    foreach ($linkedUsers as $lu) {
        $uid = (int)$lu['id'];
        if ($uid === $actor_user_id) return [false, 'You cannot delete the member record linked to your own logged-in account.'];
        $roles = user_roles($uid);
        if (in_array('admin', $roles, true)) {
            if (!user_is_admin($actor_user_id)) return [false, 'This member is linked to an admin user. Only another admin can delete it.'];
            if (active_admin_count() <= 1) return [false, 'This member is linked to the last active admin user, so it cannot be deleted.'];
        }
        if (!user_is_admin($actor_user_id)) return [false, 'This member has a linked user account. Only admins can delete linked users.'];
    }
    return [true, ''];
}
function delete_member_record(int $member_id, int $actor_user_id): array {
    [$ok, $reason] = can_delete_member_record($member_id, $actor_user_id);
    if (!$ok) return [false, $reason];
    $member = first('SELECT * FROM members WHERE id=?', [$member_id]);
    audit('member.delete_requested','member',$member_id);

    $linkedUsers = all('SELECT id FROM users WHERE member_id=?', [$member_id]);
    foreach ($linkedUsers as $lu) {
        [$uok, $ureason] = delete_user_account((int)$lu['id'], $actor_user_id);
        if (!$uok) return [false, $ureason];
    }

    $participant = first('SELECT id FROM brickworks_participants WHERE member_id=?', [$member_id]);
    if ($participant) {
        $progressIds = array_column(all('SELECT id FROM brickworks_progress WHERE participant_id=?', [(int)$participant['id']]), 'id');
        foreach ($progressIds as $pid) {
            $evidenceRows = all('SELECT encrypted_file_path FROM brickworks_evidence WHERE progress_id=?', [(int)$pid]);
            foreach ($evidenceRows as $er) {
                $file = decrypt_value($er['encrypted_file_path'] ?? '');
                $rp = $file ? realpath($file) : false;
                $private = realpath(PRIVATE_PATH) ?: PRIVATE_PATH;
                if ($rp && is_file($rp) && str_starts_with($rp, $private)) @unlink($rp);
            }
            exec_sql('DELETE FROM brickworks_evidence WHERE progress_id=?', [(int)$pid]);
        }
        exec_sql('DELETE FROM brickworks_progress WHERE participant_id=?', [(int)$participant['id']]);
        exec_sql('DELETE FROM brickworks_awards WHERE participant_id=?', [(int)$participant['id']]);
        exec_sql('DELETE FROM brickworks_participants WHERE id=?', [(int)$participant['id']]);
    }

    exec_sql('DELETE FROM member_consents WHERE member_id=?', [$member_id]);
    exec_sql('DELETE FROM member_directory_preferences WHERE member_id=?', [$member_id]);
    exec_sql('DELETE FROM member_email_preferences WHERE member_id=?', [$member_id]);
    exec_sql('DELETE FROM member_status_history WHERE member_id=?', [$member_id]);
    exec_sql('DELETE FROM subscription_payments WHERE member_id=?', [$member_id]);
    exec_sql('DELETE FROM event_attendance WHERE member_id=?', [$member_id]);
    exec_sql('DELETE FROM equipment_loans WHERE member_id=?', [$member_id]);
    exec_sql('UPDATE committee_actions SET assigned_member_id=NULL WHERE assigned_member_id=?', [$member_id]);
    exec_sql('DELETE FROM notifications WHERE member_id=?', [$member_id]);
    exec_sql('DELETE FROM email_recipients WHERE member_id=?', [$member_id]);
    exec_sql('DELETE FROM members WHERE id=?', [$member_id]);
    audit('member.deleted','member',$member_id,'success',null,['name'=>trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')), 'callsign'=>$member['callsign'] ?? null]);
    return [true, 'Member deleted.'];
}

function attendance_stats(int $member_id): array {
    // Attendance is calculated as:
    // attended completed sessions / total completed sessions since the member's start date.
    //
    // Future sessions are deliberately excluded. A session is only counted once it has
    // ended. If end_at is empty, start_at is used as the fallback completion time.
    // DISTINCT event IDs are used so duplicated legacy attendance rows cannot create
    // impossible percentages.
    $member = first('SELECT date_joined, joined_before_system FROM members WHERE id=?', [$member_id]) ?: [];
    $startDate = trim((string)($member['date_joined'] ?? ''));
    $hasStartDate = $startDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate);

    $completedExpr = 'datetime(COALESCE(NULLIF(end_at, ""), start_at))';
    $eventWhere = 'visibility="members" AND start_at IS NOT NULL AND ' . $completedExpr . ' <= datetime("now")';
    $eventParams = [];
    if ($hasStartDate) {
        $eventWhere .= ' AND date(start_at) >= date(?)';
        $eventParams[] = $startDate;
    }

    $events = (int)(first('SELECT COUNT(*) c FROM events WHERE ' . $eventWhere, $eventParams)['c'] ?? 0);

    $attCompletedExpr = 'datetime(COALESCE(NULLIF(e.end_at, ""), e.start_at))';
    $attWhere = 'ea.member_id=? AND ea.attended=1 AND e.visibility="members" AND e.start_at IS NOT NULL AND ' . $attCompletedExpr . ' <= datetime("now")';
    $attParams = [$member_id];
    if ($hasStartDate) {
        $attWhere .= ' AND date(e.start_at) >= date(?)';
        $attParams[] = $startDate;
    }
    $att = (int)(first('SELECT COUNT(DISTINCT ea.event_id) c FROM event_attendance ea JOIN events e ON e.id=ea.event_id WHERE ' . $attWhere, $attParams)['c'] ?? 0);

    $signedWhere = 'ea.member_id=? AND ea.status IN ("signed_up","attended","did_not_attend") AND e.visibility="members" AND e.start_at IS NOT NULL AND ' . $attCompletedExpr . ' <= datetime("now")';
    $signedParams = [$member_id];
    if ($hasStartDate) {
        $signedWhere .= ' AND date(e.start_at) >= date(?)';
        $signedParams[] = $startDate;
    }
    $signed = (int)(first('SELECT COUNT(DISTINCT ea.event_id) c FROM event_attendance ea JOIN events e ON e.id=ea.event_id WHERE ' . $signedWhere, $signedParams)['c'] ?? 0);

    if ($att > $events) $att = $events;
    if ($signed > $events) $signed = $events;

    $attendancePercent = $events ? min(100, round($att/$events*100,1)) : null;

    return [
        'signed_up' => $signed,
        'attended' => $att,
        'sessions_since_start' => $events,
        'eligible_events' => $events,
        'attendance_percent' => $attendancePercent,
        'signup_percent' => $attendancePercent,
        'overall_percent' => $attendancePercent,
        'attendance_start_date' => $hasStartDate ? $startDate : null,
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
    echo 'body{margin:0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f6f7fb;color:#18202a}header{background:#101827;color:white;padding:18px 24px}header div{display:flex;gap:12px;align-items:end}header span{opacity:.7}.main-nav{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;align-items:center}.main-nav a,.nav-drop{color:white;background:#24324a;padding:8px 10px;border-radius:8px;text-decoration:none;border:0;font:inherit;cursor:pointer}.dropdown{position:relative;padding-bottom:14px;margin-bottom:-14px}.dropdown::after{content:"";position:absolute;left:0;right:0;top:100%;height:14px}.dropdown-menu{display:none;position:absolute;z-index:999;top:calc(100% - 6px);left:0;min-width:220px;background:white;border-radius:10px;box-shadow:0 10px 25px #0003;padding:8px;margin-top:0}.dropdown:hover .dropdown-menu,.dropdown:focus-within .dropdown-menu,.dropdown.open .dropdown-menu{display:block}.dropdown-menu a{display:block;color:#18202a;background:white;padding:10px;border-radius:8px}.dropdown-menu a:hover{background:#f1f5f9}main{max-width:1180px;margin:24px auto;padding:0 18px}.site-footer{max-width:1180px;margin:28px auto 0;padding:18px;color:#64748b;text-align:center;border-top:1px solid #e5e7eb}.site-footer a{color:#1d4ed8;text-decoration:none}.site-footer a:hover{text-decoration:underline}.footer-main,.footer-links,.footer-version{margin:5px 0}.footer-main{display:flex;gap:8px;justify-content:center;flex-wrap:wrap}.footer-version{font-size:.92rem}.card{background:white;border-radius:14px;padding:18px;margin:16px 0;box-shadow:0 1px 4px #0001}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}label{display:block;margin:10px 0 4px;font-weight:600}input,select,textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #ccd3df;border-radius:8px}textarea{min-height:110px}button,.btn{background:#1d4ed8;color:white;border:0;border-radius:8px;padding:10px 14px;text-decoration:none;display:inline-block;cursor:pointer}button.secondary,.btn.secondary{background:#475569}.btn.danger,button.danger{background:#b91c1c}table{width:100%;border-collapse:collapse;background:white}th,td{border-bottom:1px solid #e5e7eb;padding:10px;text-align:left;vertical-align:top}th{background:#f1f5f9}.flash{background:#dcfce7;border:1px solid #86efac;padding:12px;border-radius:10px}.danger-box,.card.danger{background:#fee2e2;border:1px solid #fecaca}.pill{display:inline-block;padding:4px 8px;border-radius:999px;background:#e0e7ff}.muted{color:#64748b}.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}.event-list{display:grid;gap:12px}.event-row{display:flex;gap:18px;justify-content:space-between;align-items:flex-start;border:1px solid #e5e7eb;border-radius:12px;padding:14px;background:#fff}.event-actions{white-space:nowrap}.toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.calendar{display:grid;grid-template-columns:repeat(7,1fr);gap:8px}.calendar-head{font-weight:700;text-align:center;background:#e2e8f0;border-radius:8px;padding:8px}.calendar-day{min-height:110px;background:white;border:1px solid #e5e7eb;border-radius:10px;padding:8px}.calendar-day.muted-day{background:#f8fafc;color:#94a3b8}.calendar-date{font-weight:700;margin-bottom:6px}.calendar-event{display:block;background:#dbeafe;color:#1e3a8a;text-decoration:none;border-radius:8px;padding:5px;margin:4px 0;font-size:.88rem}.leaderboard{counter-reset:rank}.leaderboard-row{display:grid;grid-template-columns:42px 1fr auto;gap:10px;align-items:center;border-bottom:1px solid #e5e7eb;padding:10px 0}.leaderboard-row:before{counter-increment:rank;content:counter(rank);background:#e0e7ff;border-radius:999px;width:30px;height:30px;display:grid;place-items:center;font-weight:700}.progressbar{height:10px;background:#e5e7eb;border-radius:999px;overflow:hidden}.progressbar span{display:block;height:100%;background:#1d4ed8}.small{font-size:.9rem}.status-complete{background:#dcfce7}.status-pending{background:#fef3c7}.status-none{background:#f1f5f9}.user-menu{margin-left:auto}.dropdown-right{right:0;left:auto}.modal{border:0;border-radius:16px;padding:0;max-width:820px;width:calc(100% - 32px);box-shadow:0 24px 80px #0005}.modal::backdrop{background:#0f172acc}.modal .card{margin:0;box-shadow:none}.modal-head{display:flex;align-items:center;gap:12px}.modal-head h2{margin-right:auto}.icon-btn{background:#e2e8f0;color:#0f172a;border-radius:999px;padding:8px 12px}.category-pill{background:#eef2ff;color:#312e81}.attendance-tools{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:end}.attendance-list{display:grid;gap:8px}.attendance-item{display:grid;grid-template-columns:32px 1fr 160px;gap:10px;align-items:center;border:1px solid #e5e7eb;border-radius:10px;padding:10px}.attendance-item input[type=checkbox]{width:auto}.attendance-modern{padding:0;overflow:hidden;border-radius:18px}.attendance-modern-head{display:grid;grid-template-columns:1fr auto;gap:18px;padding:28px 32px 18px;align-items:start}.attendance-modern-head h2{font-size:1.9rem;margin:.1rem 0 .35rem}.attendance-date{font-size:1.25rem;color:#64748b;font-weight:650}.attendance-counts{display:flex;gap:28px;text-align:center;align-items:start}.attendance-counts span{display:block;color:#64748b;font-weight:650}.attendance-counts strong{font-size:1.45rem}.attendance-counts .present{color:#16a34a}.attendance-counts .guest{color:#ea580c}.attendance-counts .absent{color:#dc2626}.attendance-modern-controls{display:grid;grid-template-columns:1fr 220px auto auto;gap:14px;padding:18px 32px 28px;align-items:center}.attendance-search-wrap{position:relative}.attendance-search-wrap:before{content:"⌕";position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:1.35rem;color:#94a3b8}.attendance-search{font-size:1.05rem;padding-left:46px}.attendance-filter,.attendance-search{height:44px;box-shadow:0 2px 7px #00000012}.attendance-modern-list{border-top:1px solid #e5e7eb}.attendance-modern-row{display:grid;grid-template-columns:44px 1fr auto;gap:14px;align-items:center;padding:18px 32px;border-bottom:1px solid #e5e7eb;background:#fff}.attendance-modern-row:hover{background:#f8fafc}.attendance-modern-row input[type=checkbox]{width:24px;height:24px;accent-color:#1d4ed8}.attendance-person strong{display:block;font-size:1.05rem}.attendance-person span{display:block;color:#64748b;margin-top:2px}.attendance-row-status{font-weight:700;color:#94a3b8}.attendance-row-status.present{color:#16a34a}.attendance-row-status.guest{color:#ea580c}.attendance-modern-footer{display:grid;grid-template-columns:1fr 1fr auto;gap:12px;padding:18px 32px;align-items:end;background:#f8fafc}.attendance-modern-footer input{background:white}.attendance-savebar{padding:18px 32px;display:flex;gap:10px;justify-content:space-between;align-items:center;background:#fff}.attendance-empty{padding:22px 32px;color:#64748b}.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.stat-tile{background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:12px}.full{grid-column:1/-1}.bw-hero{display:grid;grid-template-columns:1fr auto;gap:18px;align-items:center}.bw-score{font-size:2.2rem;font-weight:800}.bw-steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:12px}.bw-step{background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:10px}.bw-grid{display:grid;gap:14px}.bw-card{border:1px solid #e5e7eb;border-radius:14px;padding:14px;background:#fff}.bw-card-head{display:flex;gap:10px;align-items:flex-start;justify-content:space-between}.bw-card h3{margin:.1rem 0 .35rem}.bw-theme{font-size:.85rem;color:#475569;font-weight:700;text-transform:uppercase;letter-spacing:.03em}.bw-status{display:inline-block;border-radius:999px;padding:5px 9px;font-weight:700;font-size:.85rem;white-space:nowrap}.bw-status.complete{background:#dcfce7;color:#166534}.bw-status.pending{background:#fef3c7;color:#92400e}.bw-status.none{background:#f1f5f9;color:#334155}.bw-form{display:grid;gap:8px;margin-top:12px;background:#f8fafc;border-radius:12px;padding:12px}.bw-comments{margin-top:10px;border-left:4px solid #e2e8f0;padding-left:10px}.bw-muted-line{color:#64748b;font-size:.92rem}.ticket{border:1px solid #e5e7eb;border-radius:12px;padding:12px;background:#fff;margin:10px 0}.ticket-head{display:flex;gap:10px;align-items:center;justify-content:space-between}.ticket.open{border-left:5px solid #2563eb}.ticket.in_progress{border-left:5px solid #f59e0b}.ticket.closed{border-left:5px solid #16a34a}.ticket.cancelled{border-left:5px solid #64748b}.matrix-wrap{overflow:auto;max-width:100%;border:1px solid #e5e7eb;border-radius:12px}.matrix{min-width:980px}.matrix th{position:sticky;top:0;z-index:3}.matrix th:first-child,.matrix td:first-child{position:sticky;left:0;background:#fff;z-index:2;box-shadow:2px 0 0 #e5e7eb}.matrix th:first-child{z-index:4;background:#f1f5f9}.matrix-cell{min-width:220px}.inline-form{display:grid;gap:6px}.inline-form select,.inline-form textarea,.inline-form input{font-size:.9rem;padding:7px}.status-pill{display:inline-block;border-radius:999px;padding:4px 8px;font-size:.82rem;font-weight:700}.status-open{background:#dbeafe;color:#1e40af}.status-in_progress,.status-pending_approval{background:#fef3c7;color:#92400e}.status-complete,.status-closed{background:#dcfce7;color:#166534}.status-not_completed,.status-cancelled{background:#f1f5f9;color:#334155}.asset-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.asset-field{background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:10px}.asset-field span{display:block;color:#64748b;font-size:.85rem}.asset-field strong{display:block;margin-top:3px}.actions-board{display:grid;gap:12px}.recipient-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:8px;max-height:360px;overflow:auto;border:1px solid #e5e7eb;border-radius:12px;padding:10px;background:#f8fafc}.recipient-item{display:flex;gap:10px;align-items:flex-start;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:9px;margin:0;font-weight:400}.recipient-item input{width:auto;margin-top:4px}.email-app{background:#fff;border:1px solid #dde4ef;border-radius:14px;overflow:hidden;box-shadow:0 1px 4px #0001;margin:16px 0}.email-top{background:#4638cf;color:white;padding:16px 18px;display:flex;align-items:center;gap:12px}.email-title{display:flex;align-items:center;gap:10px;font-size:1.25rem}.email-icon{font-size:1.3rem}.email-top-actions{margin-left:auto}.email-config-btn{background:#ffffff22;color:white;border:1px solid #ffffff55;border-radius:9px;padding:8px 10px;text-decoration:none;font-weight:700}.email-layout{display:grid;grid-template-columns:330px 1fr;min-height:560px}.email-sidebar{border-right:1px solid #dde4ef;background:#f8fafc}.email-recipient-head{display:flex;align-items:center;gap:10px;padding:15px 14px;border-bottom:1px solid #dde4ef;font-size:1.05rem}.email-people{color:#4f46e5}.email-count{background:#e0e7ff;color:#4338ca;border-radius:9px;padding:3px 12px;font-weight:800;box-shadow:0 2px 6px #0001}.email-filter-bar{display:flex;gap:7px;flex-wrap:wrap;padding:12px 14px;border-bottom:1px solid #e7edf6}.email-chip{width:auto;border-radius:7px;padding:8px 10px;background:#eef2ff;color:#4338ca;font-weight:800}.email-chip.active{background:#4f46e5;color:#fff}.email-chip.paid{background:#dcfce7;color:#166534}.email-chip.unpaid{background:#fee2e2;color:#b91c1c}.email-chip.pending{background:#fef3c7;color:#92400e}.email-chip.committee{background:#f3e8ff;color:#7e22ce}.email-chip.none{background:#e2e8f0;color:#64748b}.email-search-wrap{padding:12px 14px}.email-search{background:#fff;padding-left:14px}.email-member-list{max-height:430px;overflow:auto}.email-member-card{display:grid;grid-template-columns:24px 1fr auto;gap:10px;align-items:center;padding:12px 14px;border-top:1px solid #edf2f7;background:#eef2ff;cursor:pointer;margin:0;font-weight:400}.email-member-card:hover{background:#e0e7ff}.email-member-card input{display:none}.email-check-ui{width:18px;height:18px;border-radius:6px;background:#4f46e5;color:#fff;display:grid;place-items:center;font-size:.78rem;font-weight:900}.email-member-card input:not(:checked)+.email-check-ui{background:#fff;color:transparent;border:2px solid #cbd5e1}.email-member-main strong{display:block;color:#1e293b}.email-member-main small,.email-callsign{display:block;color:#94a3b8;font-weight:700;margin-top:2px}.email-badge{border-radius:7px;padding:6px 9px;font-weight:800;font-size:.82rem}.email-badge.paid{background:#dcfce7;color:#166534}.email-badge.unpaid{background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5}.email-badge.pending{background:#fef3c7;color:#92400e}.email-empty{padding:14px}.email-compose{display:flex;flex-direction:column;background:#fff}.email-fields{padding:16px 22px;border-bottom:1px solid #dde4ef}.email-row{display:grid;grid-template-columns:70px 1fr;gap:12px;align-items:center;margin:8px 0}.email-row label{margin:0;text-align:right;color:#64748b}.email-row input{background:#f8fafc}.email-toolbar{display:flex;gap:14px;align-items:center;padding:12px 22px;border-bottom:1px solid #eef2f7}.email-attach-btn{display:inline-flex;align-items:center;gap:8px;width:auto;margin:0;padding:9px 12px;border:1px solid #dbe3ee;border-radius:9px;background:#fff;box-shadow:0 2px 5px #0001;cursor:pointer}.email-attach-btn input{display:none}.email-help{color:#94a3b8;font-weight:700}.email-help code{background:#eef2ff;color:#64748b;border-radius:5px;padding:2px 5px}.email-message{border:0;border-radius:0;min-height:330px;padding:22px;font-size:1rem;resize:vertical}.email-message:focus{outline:2px solid #c7d2fe;outline-offset:-2px}.email-sendbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:auto;padding:14px 22px;border-top:1px solid #eef2f7;background:#fbfdff}@media(max-width:900px){.email-layout{grid-template-columns:1fr}.email-sidebar{border-right:0;border-bottom:1px solid #dde4ef}.email-member-list{max-height:260px}.email-row{grid-template-columns:1fr}.email-row label{text-align:left}.email-sendbar{display:block}.email-sendbar div{margin-top:10px}}.role-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin:8px 0 12px}.role-grid .check{display:flex;align-items:center;gap:8px;margin:0;padding:8px 10px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;font-weight:600;line-height:1.2;cursor:pointer}.role-grid .check:hover{background:#eef2ff;border-color:#c7d2fe}.role-grid .check input[type=checkbox]{width:18px;height:18px;min-width:18px;margin:0;accent-color:#1d4ed8}.role-grid .check span{display:inline-block}.role-grid .check input[disabled]+span{color:#64748b}.role-editor .role-grid{grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}.users-hero{display:grid;grid-template-columns:1fr auto;gap:18px;align-items:center}.users-hero h1{margin:.1rem 0}.users-stats{display:grid;grid-template-columns:repeat(4,120px);gap:10px}.users-stats div{background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:10px;text-align:center}.users-stats strong{display:block;font-size:1.35rem}.users-stats span{display:block;color:#64748b;font-size:.85rem}.users-layout{display:grid;grid-template-columns:340px 1fr;gap:16px;align-items:start}.users-side{display:grid;gap:0}.users-main{min-width:0}.users-list-card{padding:0;overflow:hidden}.users-list-card>.toolbar{padding:18px;border-bottom:1px solid #e5e7eb}.users-admin-list{display:grid}.user-admin-card{display:grid;grid-template-columns:1.1fr .9fr auto;gap:16px;align-items:start;padding:18px;border-bottom:1px solid #e5e7eb;background:#fff}.user-admin-card:hover{background:#f8fafc}.user-admin-top{display:flex;gap:12px;align-items:flex-start;min-width:0}.user-avatar{width:44px;height:44px;min-width:44px;border-radius:999px;background:#e0e7ff;color:#1e3a8a;display:grid;place-items:center;font-weight:800}.user-summary h3{margin:0 0 4px;font-size:1rem;word-break:break-word}.user-summary p{margin:0 0 8px}.user-pills,.role-chip-row{display:flex;gap:6px;flex-wrap:wrap}.role-chip{display:inline-flex;align-items:center;background:#eef2ff;color:#312e81;border-radius:999px;padding:4px 8px;font-size:.82rem;font-weight:700}.user-role-summary{min-width:0}.user-role-summary strong{display:block;margin-bottom:8px}.user-actions-panel{display:grid;gap:8px;min-width:190px}.user-actions-panel form{margin:0}.user-actions-panel button{width:100%}.user-detail-action{background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:8px}.user-detail-action summary,.user-role-editor summary{cursor:pointer;font-weight:700;color:#1d4ed8}.user-role-editor{grid-column:1/-1;background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:12px}.role-grid.compact{grid-template-columns:1fr}.role-grid .check span small{display:block;color:#64748b;font-weight:500;margin-top:2px}.role-guide-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-top:12px}@media(max-width:980px){.users-hero{grid-template-columns:1fr}.users-stats{grid-template-columns:repeat(2,1fr)}.users-layout{grid-template-columns:1fr}.user-admin-card{grid-template-columns:1fr}.user-actions-panel{grid-template-columns:1fr 1fr;min-width:0}.user-detail-action{grid-column:1/-1}}@media(max-width:560px){.users-stats{grid-template-columns:1fr 1fr}.user-actions-panel{grid-template-columns:1fr}.user-avatar{width:38px;height:38px;min-width:38px}.users-list-card>.toolbar{display:block}.role-chip-row{margin-bottom:6px}}.audit-details{margin:4px 0}.audit-details summary{cursor:pointer;color:#1d4ed8;font-weight:700}.audit-change-table{margin-top:8px;font-size:.86rem}.audit-change-table th,.audit-change-table td{padding:6px;border:1px solid #e5e7eb;white-space:normal;max-width:260px}.audit-meta{white-space:pre-wrap;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:8px;max-width:420px;overflow:auto;font-size:.82rem}.auth-body{min-height:100vh;background:linear-gradient(135deg,#f8fafc 0%,#eef2f7 100%);display:flex;flex-direction:column}.auth-main{flex:1;display:grid;place-items:center;padding:34px 16px}.auth-card{width:min(100%,430px);background:#fff;border-radius:20px;padding:42px 38px;box-shadow:0 30px 80px #0f172a26;border-top:5px solid #e2e8f0;text-align:center}.auth-logo{width:88px;height:88px;border-radius:999px;margin:0 auto 24px;display:grid;place-items:center;background:radial-gradient(circle at 45% 30%,#fff 0,#fff 35%,#dbeafe 36%,#1e40af 58%,#ef4444 59%,#fff 62%);border:2px solid #e2e8f0;box-shadow:0 14px 34px #1e293b1c;color:#0f172a;font-weight:900;font-size:.8rem;line-height:1.05}.auth-card h1{font-size:2rem;line-height:1.08;margin:0 0 10px;color:#0f172a}.auth-subtitle{color:#64748b;font-weight:700;margin:0 0 28px}.auth-form{text-align:left}.auth-form label{text-align:center;color:#334155;margin:18px 0 8px}.auth-input-wrap{position:relative}.auth-input-wrap span{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8}.auth-input-wrap input{height:48px;padding-left:44px;border-radius:13px;background:#f8fafc;border-color:#dbe3ee}.auth-submit{width:100%;margin-top:22px;background:#0f172a;border-radius:13px;padding:14px 16px;font-weight:800}.auth-submit:hover{background:#1e293b}.auth-links{display:flex;justify-content:space-between;gap:16px;margin-top:18px;font-weight:650}.auth-links a{color:#64748b;text-decoration:none}.auth-links a strong{color:#0f172a}.auth-links a:hover{text-decoration:underline}.auth-note{background:#f8fafc;border:1px solid #e2e8f0;border-radius:13px;padding:12px 14px;color:#64748b;text-align:left}.auth-footer{padding:14px 16px;text-align:center;color:#64748b;font-size:.9rem}.auth-footer a{color:#1d4ed8;text-decoration:none}.auth-footer a:hover{text-decoration:underline}.auth-flash{width:min(100%,430px);margin:0 auto 14px;background:#dcfce7;border:1px solid #86efac;border-radius:13px;padding:12px;text-align:left}@media(max-width:520px){.auth-main{padding:18px 12px}.auth-card{padding:28px 20px;border-radius:16px}.auth-logo{width:76px;height:76px;margin-bottom:18px}.auth-card h1{font-size:1.65rem}.auth-links{display:block;text-align:center}.auth-links a{display:block;margin-top:10px}.auth-form label{text-align:left}.auth-submit{width:100%}}footer{text-align:center;color:#64748b;padding:24px}@media(max-width:800px){.two{grid-template-columns:1fr}.event-row{display:block}.calendar{grid-template-columns:1fr}.calendar-head{display:none}table{font-size:.9rem}}@media(max-width:720px){  header{padding:14px 12px}  header div{display:block}  header h1{font-size:1.25rem;margin:.2rem 0}  header span{display:block;font-size:.88rem;margin-top:2px}  .main-nav{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px}  .main-nav a,.nav-drop{display:block;width:100%;box-sizing:border-box;text-align:center;padding:11px 10px}  .user-menu{margin-left:0}  .dropdown{padding-bottom:0;margin-bottom:0}  .dropdown::after{display:none}  .dropdown-menu,.dropdown-right{position:static;min-width:0;width:100%;box-sizing:border-box;margin-top:6px;box-shadow:none;border:1px solid #e5e7eb}  .dropdown-menu a{text-align:left}  main{margin:14px auto;padding:0 10px}  .card{border-radius:12px;padding:14px;margin:12px 0}  h1{font-size:1.55rem}  h2{font-size:1.25rem}  .grid,.two,.asset-grid,.stat-grid,.bw-steps{grid-template-columns:1fr}  .toolbar{display:grid;grid-template-columns:1fr;gap:8px}  .toolbar .btn,.toolbar button,.toolbar select{width:100%;box-sizing:border-box;text-align:center}  button,.btn{width:100%;box-sizing:border-box;text-align:center;margin:3px 0}  input,select,textarea{font-size:16px}  table{display:block;width:100%;overflow-x:auto;white-space:nowrap;font-size:.88rem}  th,td{padding:8px}  .event-row{display:block}  .event-actions{white-space:normal;margin-top:10px}  .calendar{display:block}  .calendar-head{display:none}  .calendar-day{min-height:auto;margin-bottom:8px}  .leaderboard-row{grid-template-columns:36px 1fr;align-items:start}  .leaderboard-row > .pill,.leaderboard-row > span:last-child{grid-column:2}  .bw-hero{grid-template-columns:1fr;text-align:left}  .bw-card-head{display:block}  .bw-status{margin-top:8px}  .bw-form button{width:100%}  .matrix-wrap{border-radius:10px}  .matrix{min-width:760px}  .modal{width:calc(100% - 18px);max-height:92vh;overflow:auto}  .modal-head{display:grid;grid-template-columns:1fr auto}  .attendance-modern-head{grid-template-columns:1fr;padding:18px 16px 10px}  .attendance-modern-head h2{font-size:1.45rem}  .attendance-date{font-size:1rem}  .attendance-counts{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}  .attendance-counts strong{font-size:1.15rem}  .attendance-modern-controls{grid-template-columns:1fr;padding:14px 16px;gap:9px}  .attendance-modern-row{grid-template-columns:34px 1fr;padding:14px 16px}  .attendance-row-status{grid-column:2;margin-top:4px}  .attendance-modern-footer{grid-template-columns:1fr;padding:14px 16px}  .attendance-savebar{display:block;padding:14px 16px}  .email-top{display:block}  .email-top-actions{margin-left:0;margin-top:10px}  .email-layout{grid-template-columns:1fr;min-height:0}  .email-sidebar{border-right:0;border-bottom:1px solid #dde4ef}  .email-filter-bar{display:grid;grid-template-columns:1fr 1fr}  .email-chip{width:100%}  .email-member-list{max-height:300px}  .email-member-card{grid-template-columns:24px 1fr;align-items:start}  .email-badge{grid-column:2;width:max-content}  .email-fields,.email-toolbar,.email-sendbar{padding:12px 14px}  .email-row{grid-template-columns:1fr}  .email-row label{text-align:left}  .email-toolbar{display:block}  .email-message{min-height:240px;padding:14px}  .role-grid{grid-template-columns:1fr}  .ticket-head{display:block}  .site-footer{margin-top:18px;padding:14px 10px;font-size:.88rem}}@media(max-width:420px){  .main-nav{grid-template-columns:1fr}  .attendance-counts{grid-template-columns:1fr}  .email-filter-bar{grid-template-columns:1fr}}.programme-hero{display:grid;grid-template-columns:1fr auto;gap:18px;align-items:center;background:linear-gradient(135deg,#101827,#263858);color:#fff;border-radius:16px;padding:24px;margin-bottom:16px;box-shadow:0 12px 30px #0f172a22}.programme-hero h1{margin:.15rem 0;font-size:2rem}.programme-hero p{margin:0;color:#dbeafe}.eyebrow{text-transform:uppercase;letter-spacing:.08em;font-size:.78rem;color:#bfdbfe;font-weight:700}.programme-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.programme-filter-card{padding:0;overflow:hidden}.programme-tabs{display:flex;gap:8px;padding:14px 16px;border-bottom:1px solid #e5e7eb;background:#f8fafc}.programme-tabs a{padding:10px 14px;border-radius:999px;text-decoration:none;color:#334155;font-weight:700}.programme-tabs a.active{background:#1d4ed8;color:#fff}.programme-filter{display:grid;grid-template-columns:1fr 240px auto;gap:12px;align-items:end;padding:16px}.programme-search input{padding-left:14px}.programme-filter-actions{display:flex;gap:8px}.programme-list-card{padding:0;overflow:hidden}.programme-list-card>.toolbar{padding:18px;border-bottom:1px solid #e5e7eb}.programme-event-grid{display:grid;gap:0}.programme-event-card{background:#fff;border-bottom:1px solid #e5e7eb}.programme-event-card:hover{background:#f8fafc}.programme-card-link{display:grid;grid-template-columns:1fr auto;gap:14px;padding:18px;text-decoration:none;color:inherit}.programme-card-main{display:grid;grid-template-columns:72px 1fr;gap:16px;min-width:0}.programme-datebox{width:68px;height:68px;border-radius:16px;background:#eff6ff;color:#1d4ed8;display:grid;place-items:center;text-align:center;border:1px solid #bfdbfe}.programme-datebox strong{font-size:1.55rem;line-height:1}.programme-datebox span{font-size:.8rem;text-transform:uppercase;font-weight:800}.programme-card-top{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:6px}.programme-event-card h3{margin:4px 0 6px}.programme-event-card p{margin:0 0 8px;color:#475569}.programme-meta{display:flex;gap:14px;flex-wrap:wrap;color:#64748b;font-size:.92rem}.programme-open{align-self:center;color:#2563eb;font-weight:800}.table-wrap{overflow-x:auto}.table-wrap table{min-width:680px}
@media(max-width:720px){.programme-hero{grid-template-columns:1fr;padding:18px;border-radius:14px}.programme-hero h1{font-size:1.6rem}.programme-actions{display:grid;grid-template-columns:1fr;width:100%}.programme-filter{grid-template-columns:1fr;padding:14px}.programme-filter-actions{display:grid;grid-template-columns:1fr 1fr}.programme-tabs{display:grid;grid-template-columns:1fr 1fr}.programme-tabs a{text-align:center}.programme-card-link{grid-template-columns:1fr;padding:14px}.programme-card-main{grid-template-columns:56px 1fr;gap:12px}.programme-datebox{width:54px;height:54px;border-radius:13px}.programme-datebox strong{font-size:1.2rem}.programme-open{display:none}.programme-meta{display:grid;gap:4px}.users-list-card>.toolbar{display:grid;grid-template-columns:1fr;gap:10px}.users-list-card>.toolbar .btn{width:100%;box-sizing:border-box}.table-wrap table{font-size:.9rem}details summary{padding:8px 0}.card{overflow-wrap:anywhere}.programme-card-top .muted{font-size:.86rem}}
@media(max-width:720px){.asset-grid,.grid,.two,.stat-grid,.users-stats{grid-template-columns:1fr!important}.user-admin-card{grid-template-columns:1fr!important}.user-actions-panel{display:grid;grid-template-columns:1fr}.role-grid{grid-template-columns:1fr!important}.toolbar{gap:8px}.toolbar h1,.toolbar h2{width:100%}.calendar{grid-template-columns:1fr!important}.calendar-day{min-height:auto}.calendar-head{display:none}.modal{width:calc(100vw - 24px);max-height:90vh;overflow:auto}.email-layout,.attendance-modern-head,.attendance-modern-controls,.attendance-modern-row{grid-template-columns:1fr!important}.email-member-list{max-height:360px}.main-nav{gap:8px}.dropdown-menu{z-index:2000}}.dashboard-hero,.directory-hero,.brickworks-hero{display:grid;grid-template-columns:1fr auto;gap:20px;align-items:center;background:linear-gradient(135deg,#101827,#263858);color:#fff;border-radius:16px;padding:24px;margin-bottom:16px;box-shadow:0 12px 30px #0f172a22}.dashboard-hero h1,.directory-hero h1,.brickworks-hero h1{margin:.15rem 0;font-size:2rem}.dashboard-hero p,.directory-hero p,.brickworks-hero p{margin:0;color:#dbeafe}.dashboard-hero-actions,.brickworks-join{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.dashboard-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.dash-card{background:#fff;border-radius:16px;border:1px solid #e5e7eb;box-shadow:0 1px 8px #0000000d;padding:18px;margin-bottom:16px}.dash-card-head{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;margin-bottom:12px}.dash-card-head h2{margin:0}.dash-card-head p{margin:.25rem 0 0}.dash-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px}.dash-meta div{background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:12px}.dash-meta span{display:block;color:#64748b;font-size:.86rem}.dash-meta strong{display:block;margin-top:4px}.notice-list{display:grid;gap:10px}.notice-item{border:1px solid #e5e7eb;background:#f8fafc;border-radius:12px;padding:12px}.notice-item strong{display:block}.notice-item span{display:block;color:#475569;margin-top:4px}.empty-state{display:grid;gap:4px;padding:18px;border:1px dashed #cbd5e1;background:#f8fafc;border-radius:14px;color:#64748b}.empty-state strong{color:#0f172a}.dash-event-grid{display:grid;gap:10px}.dash-event-card{display:grid;grid-template-columns:auto 1fr auto;gap:14px;align-items:center;border:1px solid #e5e7eb;border-radius:14px;padding:14px;text-decoration:none;color:inherit;background:#fff}.dash-event-card:hover{background:#f8fafc}.dash-event-card h3{margin:6px 0 4px}.modern-leaderboard .leaderboard-row{border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin-bottom:10px}.directory-count{display:grid;place-items:center;background:#ffffff22;border:1px solid #ffffff40;border-radius:16px;padding:16px 22px}.directory-count strong{font-size:2rem}.directory-count span{text-transform:uppercase;font-size:.78rem;letter-spacing:.06em;color:#dbeafe}.directory-filter{padding:0;overflow:hidden}.directory-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}.directory-card-item{display:flex;gap:12px;align-items:center;border:1px solid #e5e7eb;border-radius:14px;padding:14px;background:#fff}.directory-card-item:hover{background:#f8fafc}.directory-avatar{width:44px;height:44px;min-width:44px;border-radius:999px;background:#e0e7ff;color:#3730a3;display:grid;place-items:center;font-weight:800}.directory-card-item strong{display:block}.directory-card-item span{display:block;color:#64748b;margin-top:3px;font-weight:700}.brickworks-score{width:150px;height:150px;border-radius:999px;background:#ffffff18;border:1px solid #ffffff44;display:grid;place-items:center;text-align:center}.brickworks-score strong{font-size:2.4rem}.brickworks-score span{display:block;color:#dbeafe;text-transform:uppercase;font-size:.8rem;letter-spacing:.06em}.brickworks-progress{background:#334155;margin:14px 0}.brickworks-progress span{background:#60a5fa}.brickworks-hero .bw-step{background:#ffffff14;border-color:#ffffff30}.brickworks-hero .bw-step span{display:block;color:#dbeafe;font-size:.86rem}.brickworks-hero .bw-step strong{display:block;margin-top:4px;color:#fff}.brickworks-list-card{padding:0;overflow:hidden}.brickworks-list-card>.dash-card-head{padding:18px;border-bottom:1px solid #e5e7eb;margin:0}.brickworks-list-card .bw-grid{padding:16px}.bw-card{transition:.15s ease}.bw-card:hover{box-shadow:0 8px 22px #0f172a12;transform:translateY(-1px)}.bw-card.complete{border-left:5px solid #22c55e}.bw-card.pending{border-left:5px solid #f59e0b}.bw-card.none{border-left:5px solid #94a3b8}.brickworks-manager-card .toolbar{align-items:center}
@media(max-width:720px){.dashboard-hero,.directory-hero,.brickworks-hero{grid-template-columns:1fr;padding:18px;border-radius:14px}.dashboard-hero h1,.directory-hero h1,.brickworks-hero h1{font-size:1.55rem}.dashboard-hero-actions,.brickworks-join{display:grid;grid-template-columns:1fr;width:100%}.dashboard-grid{grid-template-columns:1fr}.dash-card{padding:14px;border-radius:14px}.dash-card-head{display:grid;grid-template-columns:1fr;gap:8px}.dash-event-card{grid-template-columns:56px 1fr}.dash-event-card .programme-open{display:none}.directory-count{place-items:start}.directory-grid{grid-template-columns:1fr}.directory-card-item{padding:12px}.brickworks-score{width:100%;height:auto;border-radius:14px;padding:14px;display:block}.brickworks-score strong{font-size:2rem}.brickworks-list-card .bw-grid{padding:12px}.bw-card-head{display:grid;grid-template-columns:1fr}.bw-status{width:max-content}.brickworks-manager-card .toolbar{display:grid}.programme-filter{grid-template-columns:1fr}.programme-filter-actions{grid-template-columns:1fr 1fr;display:grid}.leaderboard-row{grid-template-columns:36px 1fr!important}.leaderboard-row>div:last-child{grid-column:2}.empty-state{padding:14px}}.asset-hero,.action-hero{display:grid;grid-template-columns:1fr auto;gap:20px;align-items:center;background:linear-gradient(135deg,#101827,#263858);color:#fff;border-radius:16px;padding:24px;margin-bottom:16px;box-shadow:0 12px 30px #0f172a22}.asset-hero h1,.action-hero h1{margin:.15rem 0;font-size:2rem}.asset-hero p,.action-hero p{margin:0;color:#dbeafe}.asset-hero-stats,.action-mini-stats{display:flex;gap:10px;flex-wrap:wrap}.asset-hero-stats div{background:#ffffff18;border:1px solid #ffffff40;border-radius:14px;padding:12px 16px;min-width:110px}.asset-hero-stats strong{display:block;font-size:1.35rem}.asset-hero-stats span{display:block;color:#dbeafe;font-size:.78rem;text-transform:uppercase;letter-spacing:.05em}.asset-list-card,.action-list-card,.maintenance-list-card{padding:0;overflow:hidden}.asset-list-card>.dash-card-head,.action-list-card>.dash-card-head,.maintenance-list-card>.dash-card-head{padding:18px;border-bottom:1px solid #e5e7eb;margin:0}.asset-card-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;padding:16px}.asset-card-modern{border:1px solid #e5e7eb;border-radius:16px;background:#fff;overflow:hidden;transition:.15s ease}.asset-card-modern:hover{box-shadow:0 10px 25px #0f172a14;transform:translateY(-1px);background:#f8fafc}.asset-card-modern a{display:block;color:inherit;text-decoration:none;padding:16px}.asset-card-top{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;margin-bottom:12px}.asset-card-top h3{margin:.25rem 0 0}.asset-number{font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:800}.asset-card-meta{display:grid;grid-template-columns:110px 1fr;gap:7px 10px;margin-bottom:12px}.asset-card-meta span{color:#64748b;font-size:.86rem}.asset-card-meta strong{font-size:.92rem}.asset-form-card{border-top:4px solid #2563eb}.asset-detail-hero .asset-hero-stats div{min-width:150px}.asset-detail-card{margin-top:0}.maintenance-list-card .ticket{margin:14px 16px}.action-hero-icon{width:76px;height:76px;border-radius:999px;background:#ffffff18;border:1px solid #ffffff40;display:grid;place-items:center;font-size:2rem;font-weight:900}.action-form-card{border-top:4px solid #2563eb}.action-list-card .actions-board{padding:16px}.action-mini-stats span{background:#f8fafc;border:1px solid #e5e7eb;border-radius:999px;padding:8px 11px;color:#475569;font-size:.9rem}.action-mini-stats strong{color:#0f172a}.action-ticket{border-radius:16px;padding:16px;margin:0;background:#fff;transition:.15s ease}.action-ticket:hover{box-shadow:0 10px 24px #0f172a12;transform:translateY(-1px)}.ticket-kicker{display:block;font-size:.76rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:900;margin-bottom:3px}.action-ticket-body{background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:12px;margin:12px 0}.action-ticket details{border-top:1px solid #e5e7eb;padding-top:10px;margin-top:10px}.status-good{background:#dcfce7;color:#166534}.status-needs_repair,.status-needs_attention,.status-faulty,.status-poor{background:#fee2e2;color:#991b1b}.status-fair{background:#fef3c7;color:#92400e}.status-unknown{background:#f1f5f9;color:#334155}
@media(max-width:720px){.asset-hero,.action-hero{grid-template-columns:1fr;padding:18px;border-radius:14px}.asset-hero h1,.action-hero h1{font-size:1.55rem}.asset-hero-stats,.action-mini-stats{display:grid;grid-template-columns:1fr;width:100%}.asset-hero-stats div{min-width:0}.asset-card-grid{grid-template-columns:1fr;padding:12px}.asset-card-meta{grid-template-columns:1fr;gap:3px}.asset-card-meta strong{margin-bottom:8px}.asset-card-top{display:grid;grid-template-columns:1fr;gap:8px}.action-list-card .actions-board{padding:12px}.action-ticket{padding:13px}.ticket-head{display:grid!important;grid-template-columns:1fr;gap:8px}.action-ticket-body{padding:10px}.maintenance-list-card .ticket{margin:12px}.action-hero-icon{display:none}.asset-form-card .two,.action-form-card .two{grid-template-columns:1fr!important}}.action-header-side{display:grid;gap:10px;justify-items:end}.action-header-side .btn{width:max-content}@media(max-width:720px){.action-header-side{justify-items:stretch}.action-header-side .btn{width:100%;box-sizing:border-box}.asset-list-card>.dash-card-head{display:grid;grid-template-columns:1fr;gap:10px}.asset-list-card>.dash-card-head .btn{width:100%;box-sizing:border-box}}.member-hero{display:grid;grid-template-columns:1fr auto;gap:20px;align-items:center;background:linear-gradient(135deg,#101827,#263858);color:#fff;border-radius:16px;padding:24px;margin-bottom:16px;box-shadow:0 12px 30px #0f172a22}.member-hero h1{margin:.15rem 0;font-size:2rem}.member-hero p{margin:0;color:#dbeafe}.member-hero-stats{display:flex;gap:10px;flex-wrap:wrap}.member-hero-stats div{background:#ffffff18;border:1px solid #ffffff40;border-radius:14px;padding:12px 16px;min-width:105px}.member-hero-stats strong{display:block;font-size:1.35rem}.member-hero-stats span{display:block;color:#dbeafe;font-size:.78rem;text-transform:uppercase;letter-spacing:.05em}.member-filter-card{padding:0;overflow:hidden}.member-list-card{padding:0;overflow:hidden}.member-list-card>.dash-card-head{padding:18px;border-bottom:1px solid #e5e7eb;margin:0}.member-header-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.member-card-grid{display:grid;gap:12px;padding:16px}.member-card-modern{display:grid;grid-template-columns:1fr auto;gap:14px;align-items:start;border:1px solid #e5e7eb;border-radius:16px;background:#fff;padding:16px;transition:.15s ease}.member-card-modern:hover{box-shadow:0 10px 25px #0f172a14;transform:translateY(-1px);background:#f8fafc}.member-card-main{display:grid;grid-template-columns:54px 1fr;gap:14px;min-width:0}.member-avatar{width:54px;height:54px;border-radius:999px;background:#e0e7ff;color:#3730a3;display:grid;place-items:center;font-weight:900}.member-card-top{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.member-card-top h3{margin:0}.member-card-meta{display:grid;grid-template-columns:90px 1fr;gap:5px 10px;margin-top:10px}.member-card-meta span{color:#64748b;font-size:.86rem}.member-card-meta strong{font-size:.92rem}.member-card-actions{display:grid;gap:8px;justify-items:end}.member-attendance{display:grid;grid-template-columns:130px 1fr;gap:10px;align-items:center;margin-top:12px}.member-attendance span{display:block;color:#64748b;font-size:.86rem}.member-attendance strong{display:block}.member-form-card{border-top:4px solid #2563eb}.member-detail-hero .member-hero-stats div{min-width:140px}.member-detail-card{margin-top:0}.status-active,.status-honorary,.status-life_member{background:#dcfce7;color:#166534}.status-pending{background:#fef3c7;color:#92400e}.status-expired,.status-suspended{background:#fee2e2;color:#991b1b}.status-former{background:#f1f5f9;color:#334155}
@media(max-width:720px){.member-hero{grid-template-columns:1fr;padding:18px;border-radius:14px}.member-hero h1{font-size:1.55rem}.member-hero-stats{display:grid;grid-template-columns:1fr;width:100%}.member-hero-stats div{min-width:0}.member-list-card>.dash-card-head{display:grid;grid-template-columns:1fr;gap:10px}.member-header-actions{display:grid;grid-template-columns:1fr;width:100%}.member-header-actions .btn{width:100%;box-sizing:border-box}.member-card-grid{padding:12px}.member-card-modern{grid-template-columns:1fr;padding:13px}.member-card-main{grid-template-columns:44px 1fr;gap:11px}.member-avatar{width:44px;height:44px}.member-card-actions{display:grid;grid-template-columns:1fr;justify-items:stretch}.member-card-actions .btn,.member-card-actions button{width:100%;box-sizing:border-box}.member-card-meta{grid-template-columns:1fr;gap:3px}.member-card-meta strong{margin-bottom:7px}.member-attendance{grid-template-columns:1fr}.member-form-card .two{grid-template-columns:1fr!important}}.member-attendance small{display:block;color:#64748b;margin-top:2px;font-size:.78rem}.email-member-card.disabled{opacity:.65;background:#f8fafc}.email-member-card.disabled .email-check-ui{background:#e5e7eb;color:#94a3b8}.email-member-card.disabled input{cursor:not-allowed}.email-member-card.disabled *{cursor:not-allowed}';
    exit;
}
if (route() === 'email_open') {
    $tid = preg_replace('/[^a-f0-9]/i', '', (string)($_GET['id'] ?? ''));
    if ($tid !== '') {
        $r = first('SELECT * FROM email_recipients WHERE tracking_id=? AND tracking_enabled=1',[$tid]);
        if ($r) {
            exec_sql('UPDATE email_recipients SET open_count=open_count+1, opened_at=COALESCE(opened_at, datetime("now")), last_opened_at=datetime("now"), updated_at=datetime("now") WHERE id=?',[$r['id']]);
            exec_sql('INSERT INTO email_opens (email_recipient_id, opened_at, ip_address, user_agent, created_at) VALUES (?, datetime("now"), ?, ?, datetime("now"))',[$r['id'], client_ip(), $_SERVER['HTTP_USER_AGENT'] ?? null]);
        }
    }
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='); exit;
}

if (!installed() && route() !== 'install') redirect('install');
if (installed()) { ensure_runtime_setup(); }

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


if (route() === 'set_password') {
    if (!installed()) redirect('install');
    $rawToken = preg_replace('/[^a-f0-9]/i', '', (string)($_GET['token'] ?? $_POST['token'] ?? ''));
    $tokenHash = $rawToken ? hash('sha256', $rawToken) : '';
    $tokenRow = $tokenHash ? first('SELECT t.*,u.email,u.status FROM user_password_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.used_at IS NULL AND t.expires_at > datetime("now")', [$tokenHash]) : null;
    if (!$tokenRow) {
        auth_page_header('Set password');
        auth_card_open('Invalid or expired link', 'This password setup/reset link is invalid, expired, or has already been used.');
        echo '<p class="auth-note">Ask an admin to send another invite, or use the forgot password page if your account already exists.</p><p><a class="btn secondary" href="?route=login">Back to login</a></p>';
        auth_card_close(); auth_page_footer(); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $pass = $_POST['password'] ?? ''; $confirm = $_POST['password_confirm'] ?? '';
        if (strlen($pass) < 10 || $pass !== $confirm) {
            flash('Passwords must match and be at least 10 characters.');
            header('Location: ?route=set_password&token=' . urlencode($rawToken)); exit;
        }
        exec_sql('UPDATE users SET password_hash=?, force_password_change=0, status="active", updated_at=datetime("now") WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), (int)$tokenRow['user_id']]);
        exec_sql('UPDATE user_password_tokens SET used_at=datetime("now") WHERE id=?', [(int)$tokenRow['id']]);
        $_SESSION['user_id'] = (int)$tokenRow['user_id'];
        audit($tokenRow['purpose'] === 'invite' ? 'user.invite_accepted' : 'user.password_reset_completed', 'user', (int)$tokenRow['user_id']);
        flash('Password set. You are now logged in.'); redirect('dashboard');
    }
    auth_page_header('Set password');
    $title = $tokenRow['purpose'] === 'invite' ? 'Set up your account' : 'Reset your password';
    auth_card_open($title, 'Choose a secure password to continue.');
    echo '<p class="auth-note">Account: '.e($tokenRow['email']).'</p><form method="post" class="auth-form">'.csrf_field().'<input type="hidden" name="token" value="'.e($rawToken).'"><label>New password</label><div class="auth-input-wrap"><span>🔒</span><input type="password" name="password" required minlength="10" placeholder="At least 10 characters"></div><label>Confirm password</label><div class="auth-input-wrap"><span>🔒</span><input type="password" name="password_confirm" required minlength="10" placeholder="Repeat password"></div><button class="auth-submit">Save password and login</button></form>';
    auth_card_close(); auth_page_footer(); exit;
}


if (route() === 'forgot_password') {
    if (!installed()) redirect('install');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $target = first('SELECT * FROM users WHERE email=? AND status="active"', [$email]);
            if ($target) {
                send_user_access_email($target, 'reset', null);
            } else {
                audit('user.password_reset_self_requested','user',null,'failed','account_not_found_or_inactive',['email'=>$email]);
            }
        }
        flash('If an active account exists for that email address, a password reset link has been sent.');
        redirect('login');
    }
    auth_page_header('Forgot password');
    auth_card_open('Reset your password', 'Enter your account email and we will send a recovery link.');
    echo '<form method="post" class="auth-form">'.csrf_field().'<label>Email</label><div class="auth-input-wrap"><span>✉</span><input type="email" name="email" required placeholder="you@example.com"></div><button class="auth-submit">Send reset link</button><div class="auth-links"><a href="?route=login">Back to sign in</a><a href="mailto:sean@defenderonfrequency.uk">Need help?</a></div></form>';
    auth_card_close(); auth_page_footer(); exit;
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
    auth_page_header('Login');
    auth_card_open('Welcome to CADARS Members', 'Sign in to continue');
    echo '<form method="post" class="auth-form">'.csrf_field().'<label>Email</label><div class="auth-input-wrap"><span>✉</span><input type="email" name="email" required autocomplete="email" placeholder="you@example.com"></div><label>Password</label><div class="auth-input-wrap"><span>🔒</span><input type="password" name="password" required autocomplete="current-password" placeholder="••••••••"></div><button class="auth-submit">Sign in</button><div class="auth-links"><a href="?route=forgot_password">Forgot password?</a><a href="mailto:sean@defenderonfrequency.uk">Need an account? <strong>Contact admin</strong></a></div></form>';
    auth_card_close(); auth_page_footer(); exit;
}
if (route() === 'logout') { audit('logout'); session_destroy(); header('Location: ?route=login'); exit; }

$u = require_login();
create_schema();
seed_roles_permissions();
if ((int)($u['force_password_change'] ?? 0) === 1 && !in_array(route(), ['change_password','logout'], true)) redirect('change_password');

if (route() === 'change_password') {
    page_header('Change password');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $pass = $_POST['password'] ?? ''; $confirm = $_POST['password_confirm'] ?? '';
        if (strlen($pass) < 10 || $pass !== $confirm) { flash('Passwords must match and be at least 10 characters.'); redirect('change_password'); }
        exec_sql('UPDATE users SET password_hash=?, force_password_change=0, updated_at=datetime("now") WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), (int)$u['id']]);
        audit('user.force_password_changed','user',(int)$u['id']);
        flash('Password changed.'); redirect('dashboard');
    }
    echo '<div class="card"><h1>Set your password</h1><p class="muted">You need to set a new password before continuing.</p><form method="post">'.csrf_field().'<label>New password</label><input type="password" name="password" required minlength="10"><label>Confirm password</label><input type="password" name="password_confirm" required minlength="10"><p><button>Save password</button></p></form></div>';
    page_footer(); exit;
}

if (route() === 'dashboard') {
    audit('dashboard.view','user',(int)$u['id']); page_header('Dashboard');
    $member = first('SELECT * FROM members WHERE id=?',[$u['member_id']]);
    $notices = all('SELECT * FROM notifications WHERE (user_id=? OR member_id=? OR (user_id IS NULL AND member_id IS NULL)) AND read_at IS NULL AND (expires_at IS NULL OR expires_at > datetime("now")) ORDER BY created_at DESC LIMIT 10',[$u['id'],$u['member_id']]);
    $upcomingEvents = all('SELECT * FROM events WHERE start_at >= datetime("now") AND start_at < datetime("now", "+1 month") ORDER BY start_at ASC LIMIT 3');

    $display = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
    if ($display === '') $display = $member['callsign'] ?? 'Member';

    echo '<section class="dashboard-hero"><div><span class="eyebrow">Member dashboard</span><h1>Welcome back'.($display ? ', '.e($display) : '').'</h1><p>Quick view of your membership, notices, upcoming events and Brickworks progress.</p></div><div class="dashboard-hero-actions"><a class="btn secondary" href="?route=profile">My profile</a><a class="btn secondary" href="?route=events">View programme</a></div></section>';

    echo '<div class="dashboard-grid">';
    echo '<section class="dash-card membership-card"><div class="dash-card-head"><h2>Membership</h2><span class="pill">'.e($member['membership_status'] ?? 'Unknown').'</span></div><div class="dash-meta"><div><span>Renewal due</span><strong>'.e(($member['renewal_date'] ?? '') ?: 'Not set').'</strong></div><div><span>Callsign</span><strong>'.e(($member['callsign'] ?? '') ?: 'Not set').'</strong></div><div><span>Member no.</span><strong>'.e(($member['membership_number'] ?? '') ?: 'Not set').'</strong></div></div></section>';

    echo '<section class="dash-card notifications-card"><div class="dash-card-head"><h2>Notifications</h2><span class="pill">'.e(count($notices)).' unread</span></div>';
    if (!$notices) {
        echo '<div class="empty-state"><strong>All clear</strong><span>No unread notifications.</span></div>';
    } else {
        echo '<div class="notice-list">';
        foreach ($notices as $n) echo '<article class="notice-item"><strong>'.e($n['title']).'</strong><span>'.e($n['message']).'</span></article>';
        echo '</div>';
    }
    echo '</section></div>';

    echo '<section class="dash-card dashboard-events"><div class="dash-card-head"><div><h2>Next events</h2><p class="muted">Next 3 events within the next month.</p></div><a class="btn secondary" href="?route=events">View programme</a></div>';
    if (!$upcomingEvents) {
        echo '<div class="empty-state"><strong>No upcoming events</strong><span>Nothing is currently scheduled in the next month.</span></div>';
    } else {
        echo '<div class="dash-event-grid">';
        foreach ($upcomingEvents as $ev) {
            $ts = strtotime($ev['start_at']);
            echo '<a class="dash-event-card" href="?route=event_view&id='.e($ev['id']).'"><div class="programme-datebox"><strong>'.e($ts ? date('d',$ts) : '?').'</strong><span>'.e($ts ? date('M',$ts) : 'TBC').'</span></div><div><span class="pill category-pill">'.e($ev['event_type'] ?: 'Other').'</span><h3>'.e($ev['title']).'</h3><p class="muted">'.e($ts ? date('D j M Y H:i',$ts) : 'Date TBC').($ev['location'] ? ' • '.e($ev['location']) : '').'</p></div><span class="programme-open">Open ›</span></a>';
        }
        echo '</div>';
    }
    echo '</section>';

    $totalCriteria = (int)(first('SELECT COUNT(*) c FROM brickworks_criteria WHERE active=1')['c'] ?? 0);
    $leaders = all('SELECT m.first_name,m.last_name,m.callsign,b.id participant_id,COUNT(CASE WHEN bp.status="complete" THEN 1 END) complete_count,COUNT(CASE WHEN bp.status="pending_approval" THEN 1 END) pending_count FROM brickworks_participants b JOIN members m ON m.id=b.member_id LEFT JOIN brickworks_progress bp ON bp.participant_id=b.id GROUP BY b.id,m.first_name,m.last_name,m.callsign ORDER BY complete_count DESC,pending_count DESC,m.callsign ASC LIMIT 10');
    echo '<section class="dash-card"><div class="dash-card-head"><div><h2>Brickworks leaderboard</h2><p class="muted">Top member progress across Brickworks criteria.</p></div><a class="btn secondary" href="?route=brickworks">Open Brickworks</a></div>';
    if (!$leaders) {
        echo '<div class="empty-state"><strong>No participants yet</strong><span>Brickworks progress will appear here once members join.</span></div>';
    } else {
        echo '<div class="leaderboard modern-leaderboard">';
        foreach ($leaders as $l) {
            $complete=(int)$l['complete_count'];
            $pct=$totalCriteria?min(100,round($complete/$totalCriteria*100)):0;
            $award=brickworks_award($complete) ?: 'No award yet';
            $name=trim($l['first_name'].' '.$l['last_name']);
            echo '<div class="leaderboard-row"><div><strong>'.e($l['callsign'] ?: $name).'</strong><br><span class="muted">'.e($name).' • '.e($award).' • '.e($l['pending_count']).' pending</span><div class="progressbar"><span style="width:'.e($pct).'%"></span></div></div><div><strong>'.e($complete).'/'.e($totalCriteria).'</strong></div></div>';
        }
        echo '</div>';
    }
    echo '</section>';
    page_footer(); exit;
}

if (route() === 'profile') {
    $m = first('SELECT * FROM members WHERE id=?',[$u['member_id']]); if (!$m) exit('No member record linked.');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        require_csrf();
        $emergencySummary = trim(($_POST['emergency_contact_name'] ?? '') . ' | ' . ($_POST['emergency_contact_relationship'] ?? '') . ' | ' . ($_POST['emergency_contact_phone'] ?? ''));
        $oldDp = first('SELECT * FROM member_directory_preferences WHERE member_id=?',[$m['id']]) ?: [];
        $oldAudit = [
            'first_name'=>$m['first_name'] ?? '', 'last_name'=>$m['last_name'] ?? '', 'callsign'=>$m['callsign'] ?? '', 'licence_level'=>$m['licence_level'] ?? '', 'email'=>$m['email'] ?? '',
            'phone'=>decrypt_value($m['phone_encrypted'] ?? '') ?: '', 'address'=>decrypt_value($m['address_encrypted'] ?? '') ?: '',
            'emergency_contact_name'=>decrypt_value($m['emergency_contact_name_encrypted'] ?? '') ?: '', 'emergency_contact_relationship'=>decrypt_value($m['emergency_contact_relationship_encrypted'] ?? '') ?: '', 'emergency_contact_phone'=>decrypt_value($m['emergency_contact_phone_encrypted'] ?? '') ?: '',
            'show_in_directory'=>!empty($oldDp['show_callsign']) ? 'Yes' : 'No',
            'email_comms'=>get_member_consent((int)$m['id'],'email_comms') ? 'Yes' : 'No', 'text_comms'=>get_member_consent((int)$m['id'],'text_comms') ? 'Yes' : 'No', 'whatsapp_community'=>get_member_consent((int)$m['id'],'whatsapp_community') ? 'Yes' : 'No'
        ];
        exec_sql('UPDATE members SET first_name=?, last_name=?, callsign=?, licence_level=?, email=?, phone_encrypted=?, address_encrypted=?, emergency_contact_encrypted=?, emergency_contact_name_encrypted=?, emergency_contact_relationship_encrypted=?, emergency_contact_phone_encrypted=?, data_last_confirmed_at=datetime("now"), updated_at=datetime("now") WHERE id=?', [trim($_POST['first_name']),trim($_POST['last_name']),trim($_POST['callsign']),trim($_POST['licence_level']),trim($_POST['email']),encrypt_value(trim($_POST['phone'] ?? '')),encrypt_value(trim($_POST['address'] ?? '')),encrypt_value($emergencySummary),encrypt_value(trim($_POST['emergency_contact_name'] ?? '')),encrypt_value(trim($_POST['emergency_contact_relationship'] ?? '')),encrypt_value(trim($_POST['emergency_contact_phone'] ?? '')),$m['id']]);
        exec_sql('UPDATE users SET email=?, updated_at=datetime("now") WHERE id=?',[trim($_POST['email']),$u['id']]);
        exec_sql('INSERT OR IGNORE INTO member_directory_preferences (member_id, created_at, updated_at) VALUES (?, datetime("now"), datetime("now"))',[$m['id']]);
        $directoryOptIn = isset($_POST['show_in_directory']) ? 1 : 0;
        exec_sql('UPDATE member_directory_preferences SET show_callsign=?, show_first_name=?, show_surname=?, show_licence_level=0, show_email=0, show_phone=0, consent_given_at=CASE WHEN ?=1 THEN COALESCE(consent_given_at, datetime("now")) ELSE consent_given_at END, consent_updated_at=datetime("now"), updated_at=datetime("now") WHERE member_id=?', [$directoryOptIn,$directoryOptIn,$directoryOptIn,$directoryOptIn,$m['id']]);
        exec_sql('INSERT OR IGNORE INTO member_email_preferences (member_id, created_at, updated_at) VALUES (?, datetime("now"), datetime("now"))',[$m['id']]);
        $emailConsent = isset($_POST['consent_email_comms']) ? 1 : 0;
        exec_sql('UPDATE member_email_preferences SET receive_admin_emails=?, receive_subs_emails=?, receive_event_emails=?, receive_newsletter_emails=?, receive_brickworks_emails=?, updated_at=datetime("now") WHERE member_id=?',[$emailConsent,$emailConsent,$emailConsent,$emailConsent,$emailConsent,$m['id']]);
        save_consent_post((int)$m['id'], (int)$u['id']);
        $newAudit = [
            'first_name'=>trim($_POST['first_name'] ?? ''), 'last_name'=>trim($_POST['last_name'] ?? ''), 'callsign'=>trim($_POST['callsign'] ?? ''), 'licence_level'=>trim($_POST['licence_level'] ?? ''), 'email'=>trim($_POST['email'] ?? ''),
            'phone'=>trim($_POST['phone'] ?? ''), 'address'=>trim($_POST['address'] ?? ''),
            'emergency_contact_name'=>trim($_POST['emergency_contact_name'] ?? ''), 'emergency_contact_relationship'=>trim($_POST['emergency_contact_relationship'] ?? ''), 'emergency_contact_phone'=>trim($_POST['emergency_contact_phone'] ?? ''),
            'show_in_directory'=>isset($_POST['show_in_directory']) ? 'Yes' : 'No',
            'email_comms'=>isset($_POST['consent_email_comms']) ? 'Yes' : 'No', 'text_comms'=>isset($_POST['consent_text_comms']) ? 'Yes' : 'No', 'whatsapp_community'=>isset($_POST['consent_whatsapp_community']) ? 'Yes' : 'No'
        ];
        audit('profile.update','member',(int)$m['id'],'success',null,['field_changes'=>audit_field_changes($oldAudit,$newAudit)]); flash('Profile updated.'); redirect('profile');
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
    $q = trim((string)($_GET['q'] ?? ''));
    $sql = 'SELECT m.* FROM member_directory_preferences d JOIN members m ON m.id=d.member_id WHERE d.show_callsign=1 AND m.membership_status="active"';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (m.first_name LIKE ? OR m.last_name LIKE ? OR m.callsign LIKE ?)';
        $like = '%' . $q . '%';
        $params = [$like,$like,$like];
    }
    $sql .= ' ORDER BY m.last_name, m.first_name, m.callsign';
    $rows = all($sql, $params);

    echo '<section class="directory-hero"><div><span class="eyebrow">Internal directory</span><h1>Member directory</h1><p>Opt-in directory showing member name and callsign only.</p></div><div class="directory-count"><strong>'.e(count($rows)).'</strong><span>listed</span></div></section>';

    echo '<div class="card directory-filter"><form method="get" class="programme-filter"><input type="hidden" name="route" value="directory"><div class="programme-search"><label>Search directory</label><input name="q" value="'.e($q).'" placeholder="Search name or callsign"></div><div class="programme-filter-actions"><button>Search</button><a class="btn secondary" href="?route=directory">Clear</a></div></form></div>';

    echo '<section class="card directory-card"><div class="dash-card-head"><div><h2>Opted-in members</h2><p class="muted">Only members who have chosen to show their name and callsign appear here.</p></div></div>';
    if (!$rows) {
        echo '<div class="empty-state"><strong>No members found</strong><span>No opted-in members match that search.</span></div>';
    } else {
        echo '<div class="directory-grid">';
        foreach($rows as $r){
            $name=trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? ''));
            $initials = strtoupper(substr(trim($r['first_name'] ?? ''),0,1).substr(trim($r['last_name'] ?? ''),0,1));
            if ($initials === '') $initials = strtoupper(substr($r['callsign'] ?: '?',0,2));
            echo '<article class="directory-card-item"><div class="directory-avatar">'.e($initials).'</div><div><strong>'.e($name ?: 'Unnamed member').'</strong><span>'.e($r['callsign'] ?: 'No callsign').'</span></div></article>';
        }
        echo '</div>';
    }
    echo '</section>';
    page_footer(); exit;
}


if (route() === 'member_create') {
    require_permission('edit_membership_db');
    page_header('Add member');
    audit('member_create.view');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $membershipNumber = can_edit_membership_number() ? trim($_POST['membership_number'] ?? '') : null;
        $joinedBeforeSystem = isset($_POST['joined_before_system']) ? 1 : 0;
        $dateJoined = $joinedBeforeSystem ? '' : trim($_POST['date_joined'] ?? '');
        $emergencySummary = trim(($_POST['emergency_contact_name'] ?? '') . ' | ' . ($_POST['emergency_contact_relationship'] ?? '') . ' | ' . ($_POST['emergency_contact_phone'] ?? ''));
        exec_sql('INSERT INTO members (membership_number,first_name,last_name,callsign,licence_level,email,phone_encrypted,address_encrypted,emergency_contact_encrypted,emergency_contact_name_encrypted,emergency_contact_relationship_encrypted,emergency_contact_phone_encrypted,date_joined,joined_before_system,renewal_date,membership_status,membership_type,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, datetime("now"), datetime("now"))', [
            $membershipNumber ?: null,
            trim($_POST['first_name']),
            trim($_POST['last_name']),
            trim($_POST['callsign']),
            trim($_POST['licence_level']),
            trim($_POST['email']),
            encrypt_value(trim($_POST['phone'])),
            encrypt_value(trim($_POST['address'])),
            encrypt_value($emergencySummary),
            encrypt_value(trim($_POST['emergency_contact_name'] ?? '')),
            encrypt_value(trim($_POST['emergency_contact_relationship'] ?? '')),
            encrypt_value(trim($_POST['emergency_contact_phone'] ?? '')),
            $dateJoined,
            $joinedBeforeSystem,
            trim($_POST['renewal_date']),
            trim($_POST['membership_status']),
            trim($_POST['membership_type'])
        ]);
        $mid=(int)db()->lastInsertId();
        exec_sql('INSERT INTO member_directory_preferences (member_id, created_at, updated_at) VALUES (?,datetime("now"),datetime("now"))',[$mid]);
        exec_sql('INSERT INTO member_email_preferences (member_id, created_at, updated_at) VALUES (?,datetime("now"),datetime("now"))',[$mid]);
        save_consent_post($mid, (int)$u['id']);
        audit('member.create','member',$mid);
        flash('Member added.');
        redirect('member_view&id='.$mid);
    }

    echo '<section class="member-hero"><div><span class="eyebrow">New member</span><h1>Add member</h1><p>Create a new membership record, emergency contact and consent preferences.</p></div><div class="member-hero-stats"><div><strong>+</strong><span>New record</span></div></div></section>';
    echo '<div class="card member-form-card"><div class="toolbar"><h2 style="margin-right:auto">Member details</h2><a class="btn secondary" href="?route=members">Back to members</a></div><form method="post">'.csrf_field().'<div class="two">';
    if (can_edit_membership_number()) echo '<div><label>Membership number</label><input name="membership_number"></div>';
    echo '<div><label>Callsign</label><input name="callsign"></div><div><label>First name</label><input name="first_name" required></div><div><label>Surname</label><input name="last_name" required></div><div><label>Email</label><input name="email" type="email" required></div><div><label>Licence level</label><input name="licence_level"></div><div><label>Phone</label><input name="phone"></div><div class="full"><label>Address</label><textarea name="address"></textarea></div><div class="full"><h3>Emergency contact</h3></div><div><label>Emergency contact name</label><input name="emergency_contact_name"></div><div><label>Relationship to member</label><input name="emergency_contact_relationship"></div><div><label>Emergency contact phone</label><input name="emergency_contact_phone"></div><div><label>Membership type</label><input name="membership_type"></div><div><label>Date joined</label><input name="date_joined" type="date"></div><div><label>Renewal date</label><input name="renewal_date" type="date"></div><div><label>Membership status</label><select name="membership_status"><option>active</option><option>pending</option><option>expired</option><option>former</option><option>suspended</option><option>honorary</option></select></div></div><label class="check"><input type="checkbox" name="joined_before_system"><span><strong>Joined before system / date not on record</strong><small>Use this when the original join date is unknown.</small></span></label><h3>Consents</h3>'.render_consent_checkboxes(0).'<p class="muted">Consents can also be changed later from the member record.</p><div class="toolbar"><button>Add member</button><a class="btn secondary" href="?route=members">Cancel</a></div></form></div>';
    page_footer(); exit;
}

if (route() === 'members') {
    require_permission('view_membership_db'); audit('member_database.view'); page_header('Membership Database');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        require_permission('edit_membership_db'); require_csrf();
        if (isset($_POST['delete_member'])) {
            $deleteId = (int)($_POST['member_id'] ?? 0);
            [$ok, $msg] = delete_member_record($deleteId, (int)$u['id']);
            flash($msg);
            redirect('members');
        }
        redirect('member_create');
    }
    $q = trim((string)($_GET['q'] ?? ''));
    $statusFilter = trim((string)($_GET['status'] ?? ''));

    $where = '1=1';
    $params = [];
    if ($q !== '') {
        $where .= ' AND (first_name LIKE ? OR last_name LIKE ? OR callsign LIKE ? OR email LIKE ? OR membership_number LIKE ?)';
        $like = '%' . $q . '%';
        $params = [$like,$like,$like,$like,$like];
    }
    if ($statusFilter !== '') {
        $where .= ' AND membership_status = ?';
        $params[] = $statusFilter;
    }

    $rows = all('SELECT * FROM members WHERE '.$where.' ORDER BY last_name, first_name', $params);
    $totalMembers = (int)(first('SELECT COUNT(*) c FROM members')['c'] ?? 0);
    $activeMembers = (int)(first('SELECT COUNT(*) c FROM members WHERE membership_status IN ("active","honorary","life_member")')['c'] ?? 0);
    $dueSoon = (int)(first('SELECT COUNT(*) c FROM members WHERE renewal_date IS NOT NULL AND renewal_date != "" AND date(renewal_date) <= date("now", "+30 days") AND membership_status IN ("active","honorary","life_member")')['c'] ?? 0);
    $expiredMembers = (int)(first('SELECT COUNT(*) c FROM members WHERE membership_status="expired"')['c'] ?? 0);

    echo '<section class="member-hero"><div><span class="eyebrow">Membership database</span><h1>Members</h1><p>Manage member records, consents, emergency contacts, subscriptions and attendance summaries.</p></div><div class="member-hero-stats"><div><strong>'.e($totalMembers).'</strong><span>Total</span></div><div><strong>'.e($activeMembers).'</strong><span>Active</span></div><div><strong>'.e($dueSoon).'</strong><span>Due soon</span></div><div><strong>'.e($expiredMembers).'</strong><span>Expired</span></div></div></section>';

    echo '<section class="card member-filter-card"><form method="get" class="programme-filter"><input type="hidden" name="route" value="members"><div class="programme-search"><label>Search members</label><input name="q" value="'.e($q).'" placeholder="Search name, callsign, email or member number"></div><div><label>Status</label><select name="status"><option value="">All statuses</option>';
    foreach(['active','pending','expired','former','suspended','honorary','life_member'] as $stOpt) echo '<option value="'.e($stOpt).'" '.($statusFilter===$stOpt?'selected':'').'>'.e($stOpt).'</option>';
    echo '</select></div><div class="programme-filter-actions"><button>Apply</button><a class="btn secondary" href="?route=members">Clear</a></div></form></section>';

    echo '<section class="card member-list-card"><div class="dash-card-head"><div><h2>Member list</h2><p class="muted">Open a member to edit details, consents, payments and role history.</p></div><div class="member-header-actions"><a class="btn" href="?route=member_export">Export all members spreadsheet</a>';
    if (has_permission('edit_membership_db')) echo '<a class="btn secondary" href="?route=member_import">Import members</a><a class="btn" href="?route=member_create">Add member</a>';
    echo '</div></div>';

    if (!$rows) {
        echo '<div class="empty-state"><strong>No members found</strong><span>No records match the current search or status filter.</span></div>';
    } else {
        echo '<div class="member-card-grid">';
        foreach($rows as $m){
            $st=attendance_stats((int)$m['id']);
            $pct = $st['signup_percent'];
            $pctText = $pct===null ? 'N/A' : $pct.'%';
            $pctWidth = $pct===null ? 0 : min(100, max(0, (float)$pct));
            $name=trim(($m['first_name'] ?? '').' '.($m['last_name'] ?? ''));
            $initials=strtoupper(substr(trim($m['first_name'] ?? ''),0,1).substr(trim($m['last_name'] ?? ''),0,1));
            if($initials==='') $initials=strtoupper(substr($m['callsign'] ?: '?',0,2));
            $statusClass=preg_replace('/[^a-z0-9_]+/','_',strtolower($m['membership_status'] ?: 'unknown'));
            echo '<article class="member-card-modern"><div class="member-card-main"><div class="member-avatar">'.e($initials).'</div><div><div class="member-card-top"><h3>'.e($name ?: 'Unnamed member').'</h3><span class="status-pill status-'.e($statusClass).'">'.e($m['membership_status'] ?: 'unknown').'</span></div><p class="muted">'.e($m['callsign'] ?: 'No callsign').' • Member no. '.e($m['membership_number'] ?: 'not set').'</p><div class="member-card-meta"><span>Joined</span><strong>'.e(member_joined_display($m)).'</strong><span>Renewal</span><strong>'.e($m['renewal_date'] ?: 'Not set').'</strong><span>Email</span><strong>'.e($m['email'] ?: 'Not set').'</strong></div><div class="member-attendance"><div><span>Attendance</span><strong>'.e($pctText).'</strong><small>'.e($st['attended']).' / '.e($st['sessions_since_start']).' sessions</small></div><div class="progressbar"><span style="width:'.e($pctWidth).'%"></span></div></div></div></div><div class="member-card-actions"><a class="btn secondary" href="?route=member_view&id='.e($m['id']).'">Open</a>';
            if (has_permission('edit_membership_db')) echo '<form method="post" onsubmit="return confirm(&quot;Delete this member and all linked records? This cannot be undone.&quot;)">'.csrf_field().'<input type="hidden" name="delete_member" value="1"><input type="hidden" name="member_id" value="'.e($m['id']).'"><button class="danger">Delete</button></form>';
            echo '</div></article>';
        }
        echo '</div>';
    }
    echo '</section>';
    page_footer(); exit;
}


if (route() === 'member_import') {
    require_permission('edit_membership_db');
    page_header('Import members');
    $summary = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        if (empty($_FILES['member_file']['tmp_name']) || !is_uploaded_file($_FILES['member_file']['tmp_name'])) {
            $summary = ['created'=>0,'updated'=>0,'skipped'=>0,'payments'=>0,'errors'=>['No file was uploaded.']];
        } else {
            try {
                $rows = import_spreadsheet_rows($_FILES['member_file']['tmp_name'], $_FILES['member_file']['name'] ?? 'members.xlsx');
                $summary = import_members_from_rows($rows, !empty($_POST['update_existing']), !empty($_POST['import_payments']), (int)$u['id']);
                audit('member.import', null, null, empty($summary['errors']) ? 'success' : 'partial', null, ['created'=>$summary['created'],'updated'=>$summary['updated'],'skipped'=>$summary['skipped'],'payments'=>$summary['payments'],'errors'=>$summary['errors']]);
                flash('Import complete: '.$summary['created'].' created, '.$summary['updated'].' updated, '.$summary['skipped'].' skipped.');
            } catch (Throwable $e) {
                $summary = ['created'=>0,'updated'=>0,'skipped'=>0,'payments'=>0,'errors'=>[$e->getMessage()]];
                audit('member.import', null, null, 'failed', $e->getMessage());
            }
        }
    }
    echo '<div class="card"><div class="toolbar"><h1 style="margin-right:auto">Import members</h1><a class="btn secondary" href="?route=members">Back to members</a></div><p>Upload a <strong>.xlsx</strong> or <strong>.csv</strong> spreadsheet. The importer supports the current system export format and the CADARS member spreadsheet format.</p>';
    echo '<div class="grid"><div><h3>Supported CADARS import headers</h3><p class="muted">Member Number, Full Name, Email, Phone, Callsign, License Class, Society Role, Payment Status, Payment Date, Membership Start, Active, Emergency Contact, Emergency Phone.</p></div><div><h3>Supported export headers</h3><p class="muted">Membership number, First name, Surname, Full name, Callsign, Licence level, Email, Phone, Address, Date joined, Renewal date, Membership status, Membership type, emergency contact fields and consent fields.</p></div></div>';
    echo '<form method="post" enctype="multipart/form-data">'.csrf_field().'<div class="two"><div><label>Spreadsheet file</label><input type="file" name="member_file" accept=".xlsx,.csv,text/csv" required></div><div><label>Import options</label><label><input type="checkbox" name="update_existing" checked> Update existing members matched by membership number or email</label><label><input type="checkbox" name="import_payments" checked> Import payment status/date into subs history</label></div></div><p><button>Import members</button></p></form>';
    echo '<p class="muted">For .xlsx imports the server needs the PHP zip extension. If import fails with a ZipArchive message, run <code>apt install php-zip -y && systemctl restart php8.1-fpm</code>.</p></div>';
    if ($summary) {
        echo '<div class="card"><h2>Import result</h2><div class="stat-grid"><div class="stat"><strong>'.e($summary['created']).'</strong><span>Created</span></div><div class="stat"><strong>'.e($summary['updated']).'</strong><span>Updated</span></div><div class="stat"><strong>'.e($summary['skipped']).'</strong><span>Skipped</span></div><div class="stat"><strong>'.e($summary['payments']).'</strong><span>Payment rows</span></div></div>';
        if (!empty($summary['errors'])) { echo '<h3>Messages</h3><ul>'; foreach ($summary['errors'] as $err) echo '<li>'.e($err).'</li>'; echo '</ul>'; }
        echo '</div>';
    }
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
        if (isset($_POST['delete_member'])) {
            [$ok, $msg] = delete_member_record($id, (int)$u['id']);
            flash($msg);
            redirect('members');
        }
        if (isset($_POST['add_payment'])) { exec_sql('INSERT INTO subscription_payments (member_id,subscription_year,amount_due,amount_paid,payment_date,payment_method,payment_reference,receipt_number,status,recorded_by_user_id,notes_encrypted,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,datetime("now"),datetime("now"))',[$id,(int)$_POST['subscription_year'],(float)$_POST['amount_due'],(float)$_POST['amount_paid'],$_POST['payment_date'],$_POST['payment_method'],$_POST['payment_reference'],$_POST['receipt_number'],$_POST['status'],$u['id'],encrypt_value($_POST['notes']??'')]); audit('subscription.create','member',$id); flash('Payment/subs record added.'); redirect('member_view&id='.$id); }
        $membershipNumber = can_edit_membership_number() ? trim($_POST['membership_number'] ?? '') : $m['membership_number'];
        $joinedBeforeSystem = isset($_POST['joined_before_system']) ? 1 : 0;
        $dateJoined = $joinedBeforeSystem ? '' : trim($_POST['date_joined'] ?? '');
        $emergencySummary = trim(($_POST['emergency_contact_name'] ?? '') . ' | ' . ($_POST['emergency_contact_relationship'] ?? '') . ' | ' . ($_POST['emergency_contact_phone'] ?? ''));
        $oldAudit = [
            'membership_number'=>$m['membership_number'] ?? '', 'first_name'=>$m['first_name'] ?? '', 'last_name'=>$m['last_name'] ?? '', 'callsign'=>$m['callsign'] ?? '', 'licence_level'=>$m['licence_level'] ?? '', 'email'=>$m['email'] ?? '',
            'phone'=>decrypt_value($m['phone_encrypted'] ?? '') ?: '', 'address'=>decrypt_value($m['address_encrypted'] ?? '') ?: '',
            'emergency_contact_name'=>decrypt_value($m['emergency_contact_name_encrypted'] ?? '') ?: '', 'emergency_contact_relationship'=>decrypt_value($m['emergency_contact_relationship_encrypted'] ?? '') ?: '', 'emergency_contact_phone'=>decrypt_value($m['emergency_contact_phone_encrypted'] ?? '') ?: '',
            'date_joined'=>$m['date_joined'] ?? '', 'joined_before_system'=>!empty($m['joined_before_system']) ? 'Yes' : 'No', 'renewal_date'=>$m['renewal_date'] ?? '', 'membership_status'=>$m['membership_status'] ?? '', 'membership_type'=>$m['membership_type'] ?? '', 'notes'=>decrypt_value($m['notes_encrypted'] ?? '') ?: '',
            'email_comms'=>get_member_consent($id,'email_comms') ? 'Yes' : 'No', 'text_comms'=>get_member_consent($id,'text_comms') ? 'Yes' : 'No', 'whatsapp_community'=>get_member_consent($id,'whatsapp_community') ? 'Yes' : 'No'
        ];
        exec_sql('UPDATE members SET membership_number=?, first_name=?, last_name=?, callsign=?, licence_level=?, email=?, phone_encrypted=?, address_encrypted=?, emergency_contact_encrypted=?, emergency_contact_name_encrypted=?, emergency_contact_relationship_encrypted=?, emergency_contact_phone_encrypted=?, date_joined=?, joined_before_system=?, renewal_date=?, membership_status=?, membership_type=?, notes_encrypted=?, updated_at=datetime("now") WHERE id=?',[$membershipNumber ?: null,trim($_POST['first_name']),trim($_POST['last_name']),trim($_POST['callsign']),trim($_POST['licence_level']),trim($_POST['email']),encrypt_value(trim($_POST['phone'])),encrypt_value(trim($_POST['address'])),encrypt_value($emergencySummary),encrypt_value(trim($_POST['emergency_contact_name'] ?? '')),encrypt_value(trim($_POST['emergency_contact_relationship'] ?? '')),encrypt_value(trim($_POST['emergency_contact_phone'] ?? '')),$dateJoined,$joinedBeforeSystem,trim($_POST['renewal_date']),trim($_POST['membership_status']),trim($_POST['membership_type']),encrypt_value(trim($_POST['notes']??'')),$id]);
        save_consent_post($id, (int)$u['id']);
        $newAudit = [
            'membership_number'=>$membershipNumber ?: '', 'first_name'=>trim($_POST['first_name'] ?? ''), 'last_name'=>trim($_POST['last_name'] ?? ''), 'callsign'=>trim($_POST['callsign'] ?? ''), 'licence_level'=>trim($_POST['licence_level'] ?? ''), 'email'=>trim($_POST['email'] ?? ''),
            'phone'=>trim($_POST['phone'] ?? ''), 'address'=>trim($_POST['address'] ?? ''),
            'emergency_contact_name'=>trim($_POST['emergency_contact_name'] ?? ''), 'emergency_contact_relationship'=>trim($_POST['emergency_contact_relationship'] ?? ''), 'emergency_contact_phone'=>trim($_POST['emergency_contact_phone'] ?? ''),
            'date_joined'=>$dateJoined, 'joined_before_system'=>$joinedBeforeSystem ? 'Yes' : 'No', 'renewal_date'=>trim($_POST['renewal_date'] ?? ''), 'membership_status'=>trim($_POST['membership_status'] ?? ''), 'membership_type'=>trim($_POST['membership_type'] ?? ''), 'notes'=>trim($_POST['notes'] ?? ''),
            'email_comms'=>isset($_POST['consent_email_comms']) ? 'Yes' : 'No', 'text_comms'=>isset($_POST['consent_text_comms']) ? 'Yes' : 'No', 'whatsapp_community'=>isset($_POST['consent_whatsapp_community']) ? 'Yes' : 'No'
        ];
        audit('member.update','member',$id,'success',null,['membership_number_changed'=>can_edit_membership_number(),'field_changes'=>audit_field_changes($oldAudit,$newAudit)]); flash('Member updated.'); redirect('member_view&id='.$id);
    }
    page_header('Member record'); $stats=attendance_stats($id);
    echo '<div class="card"><div class="toolbar"><h1 style="margin-right:auto">'.e($m['first_name'].' '.$m['last_name']).'</h1><a class="btn secondary" href="?route=member_export">Export all members spreadsheet</a>';
    if (has_permission('edit_membership_db')) echo '<form method="post" onsubmit="return confirm(&quot;Delete this member and all linked records? This cannot be undone.&quot;)" style="display:inline">'.csrf_field().'<input type="hidden" name="delete_member" value="1"><button class="danger">Delete member</button></form>';
    echo '</div><form method="post">'.csrf_field().'<div class="two">';
    if (can_edit_membership_number()) echo '<div><label>Membership number</label><input name="membership_number" value="'.e($m['membership_number']).'"></div>'; else echo '<div><label>Membership number</label><p><strong>'.e($m['membership_number'] ?: 'Not set').'</strong><br><span class="muted">Only admin users can change this.</span></p></div>';
    echo '<div><label>Callsign</label><input name="callsign" value="'.e($m['callsign']).'"></div><div><label>First name</label><input name="first_name" value="'.e($m['first_name']).'"></div><div><label>Surname</label><input name="last_name" value="'.e($m['last_name']).'"></div><div><label>Email address</label><input type="email" name="email" value="'.e($m['email']).'"></div><div><label>Licence level</label><input name="licence_level" value="'.e($m['licence_level']).'"></div><div><label>Phone number</label><input name="phone" value="'.e(decrypt_value($m['phone_encrypted'])).'"></div><div class="full"><label>Address</label><textarea name="address">'.e(decrypt_value($m['address_encrypted'])).'</textarea></div><div class="full"><h2>Emergency contact</h2></div><div><label>Emergency contact name</label><input name="emergency_contact_name" value="'.e(decrypt_value($m['emergency_contact_name_encrypted'] ?? '')).'"></div><div><label>Relationship to member</label><input name="emergency_contact_relationship" value="'.e(decrypt_value($m['emergency_contact_relationship_encrypted'] ?? '')).'"></div><div><label>Emergency contact phone</label><input name="emergency_contact_phone" value="'.e(decrypt_value($m['emergency_contact_phone_encrypted'] ?? '')).'"></div><div><label>Membership type</label><input name="membership_type" value="'.e($m['membership_type']).'"></div><div><label>Date joined</label><input type="date" name="date_joined" value="'.e($m['date_joined']).'"><label class="small"><input type="checkbox" name="joined_before_system" '.(!empty($m['joined_before_system'])?'checked':'').'> Not on record / joined before system</label></div><div><label>Renewal date</label><input type="date" name="renewal_date" value="'.e($m['renewal_date']).'"></div><div><label>Membership status</label><select name="membership_status">'; foreach(['pending','active','expired','former','suspended','honorary','life_member'] as $s) echo '<option '.($m['membership_status']===$s?'selected':'').'>'.e($s).'</option>'; echo '</select></div></div><h2>Consents</h2><p class="muted">Visible and editable by Member DB users and admins.</p>'.render_consent_checkboxes($id).'<label>Private notes</label><textarea name="notes">'.e(decrypt_value($m['notes_encrypted'])).'</textarea><button>Save member</button></form></div>';
    echo '<div class="grid"><div class="card"><h2>Attendance</h2><p>Attended sessions: '.e($stats['attended']).'</p><p>Completed sessions since start date: '.e($stats['sessions_since_start']).'</p><p>Attendance: '.e($stats['attendance_percent']===null?'N/A':$stats['attendance_percent'].'%').'</p><p class="muted">Start date used: '.e($stats['attendance_start_date'] ?: 'No join date recorded - using all past sessions').'</p></div>';
    $payments=all('SELECT * FROM subscription_payments WHERE member_id=? ORDER BY subscription_year DESC',[$id]); echo '<div class="card"><h2>Payment/subs history</h2><table><tr><th>Year</th><th>Due</th><th>Paid</th><th>Date</th><th>Status</th></tr>'; foreach($payments as $p) echo '<tr><td>'.e($p['subscription_year']).'</td><td>£'.e(number_format($p['amount_due'],2)).'</td><td>£'.e(number_format($p['amount_paid'],2)).'</td><td>'.e($p['payment_date']).'</td><td>'.e($p['status']).'</td></tr>'; echo '</table></div></div>';
    echo '<div class="card"><h2>Add subs/payment record</h2><form method="post">'.csrf_field().'<input type="hidden" name="add_payment" value="1"><div class="two"><input type="number" name="subscription_year" value="'.date('Y').'" required><input type="number" step="0.01" name="amount_due" placeholder="Amount due" required><input type="number" step="0.01" name="amount_paid" placeholder="Amount paid" required><input type="date" name="payment_date"><input name="payment_method" placeholder="Payment method"><input name="payment_reference" placeholder="Payment reference"><input name="receipt_number" placeholder="Receipt number"><select name="status"><option>unpaid</option><option>part-paid</option><option>paid</option><option>waived</option><option>refunded</option></select></div><textarea name="notes" placeholder="Notes"></textarea><button>Add payment record</button></form></div>';
    $rh=all('SELECT urh.*,r.display_name FROM user_role_history urh JOIN users us ON us.id=urh.user_id JOIN roles r ON r.id=urh.role_id WHERE us.member_id=? ORDER BY changed_at DESC',[$id]); echo '<div class="card"><h2>Role history</h2><table><tr><th>Role</th><th>Action</th><th>Changed</th><th>Reason</th></tr>'; foreach($rh as $r) echo '<tr><td>'.e($r['display_name']).'</td><td>'.e($r['action']).'</td><td>'.e($r['changed_at']).'</td><td>'.e($r['reason']).'</td></tr>'; echo '</table></div>';
    page_footer(); exit;
}


if (route() === 'user_invite') {
    require_permission('manage_users');
    page_header('Invite new user');
    audit('user_invite.view');

    $canRoles = has_permission('manage_roles');
    $allRoles = all('SELECT name, display_name, description FROM roles ORDER BY CASE name WHEN "member" THEN 1 WHEN "committee" THEN 2 WHEN "chair" THEN 3 WHEN "vice_chair" THEN 4 WHEN "secretary" THEN 5 WHEN "member_db" THEN 6 WHEN "brickworks_reviewer" THEN 7 WHEN "equipment_manager" THEN 8 WHEN "event_manager" THEN 9 WHEN "treasurer" THEN 10 WHEN "admin" THEN 99 ELSE 50 END, display_name');
    $members = all('SELECT id,first_name,last_name,callsign,email FROM members ORDER BY last_name,first_name');

    echo '<div class="card"><div class="toolbar"><h1 style="margin-right:auto">Invite new user</h1><a class="btn secondary" href="?route=users">Back to users</a></div><p class="muted">Creates the account and emails a secure setup link. The user sets their own password on first login.</p></div>';
    echo '<div class="card"><form method="post" action="?route=users">'.csrf_field().'<input type="hidden" name="invite_user" value="1"><div class="two"><div><label>Link to member</label><select name="member_id"><option value="">None</option>';
    foreach($members as $m) echo '<option value="'.e($m['id']).'">'.e($m['first_name'].' '.$m['last_name'].($m['callsign']?' - '.$m['callsign']:'').' / '.$m['email']).'</option>';
    echo '</select></div><div><label>Email address</label><input type="email" name="email" required placeholder="user@example.com"></div></div>';
    if ($canRoles) {
        echo '<h2>Initial roles</h2><p class="muted small">The Member role is always included. Add any extra roles needed.</p><div class="role-grid">';
        foreach($allRoles as $role){
            $checked = $role['name']==='member' ? 'checked disabled' : '';
            if($role['name']==='member') echo '<input type="hidden" name="roles[]" value="member">';
            echo '<label class="check"><input type="checkbox" name="roles[]" value="'.e($role['name']).'" '.$checked.'><span><strong>'.e($role['display_name']).'</strong><small>'.e($role['description'] ?: $role['name']).'</small></span></label>';
        }
        echo '</div>';
    }
    echo '<button>Send invite</button></form></div>';
    page_footer(); exit;
}

if (route() === 'user_create') {
    require_permission('manage_users');
    page_header('Create user');
    audit('user_create.view');

    $members = all('SELECT id,first_name,last_name,callsign,email FROM members ORDER BY last_name,first_name');

    echo '<div class="card"><div class="toolbar"><h1 style="margin-right:auto">Create user manually</h1><a class="btn secondary" href="?route=users">Back to users</a></div><p class="muted">Fallback option only. Prefer sending an invite where possible. Manual users are forced to change their temporary password after login.</p></div>';
    echo '<div class="card"><form method="post" action="?route=users">'.csrf_field().'<input type="hidden" name="create_user" value="1"><div class="two"><div><label>Link to member</label><select name="member_id"><option value="">None</option>';
    foreach($members as $m) echo '<option value="'.e($m['id']).'">'.e($m['first_name'].' '.$m['last_name'].($m['callsign']?' - '.$m['callsign']:'').' / '.$m['email']).'</option>';
    echo '</select></div><div><label>Email address</label><input type="email" name="email" required placeholder="user@example.com"></div><div><label>Temporary password</label><input name="password" required minlength="10" placeholder="Minimum 10 characters"></div></div><button>Create user</button></form></div>';
    page_footer(); exit;
}

if (route() === 'users') {
    require_permission('manage_users'); page_header('Users'); audit('users.view');
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_csrf();
        if (isset($_POST['delete_user'])) {
            require_permission('manage_users');
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            [$ok, $msg] = delete_user_account($targetUserId, (int)$u['id']);
            flash($msg);
            redirect('users');
        }
        if (isset($_POST['reset_password'])) {
            require_permission('reset_passwords');
            $new=$_POST['new_password'];
            if(strlen($new)<10){flash('Password must be at least 10 chars.');redirect('users');}
            exec_sql('UPDATE users SET password_hash=?, force_password_change=1, updated_at=datetime("now") WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),(int)$_POST['user_id']]);
            audit('user.password_changed_by_admin','user',(int)$_POST['user_id']);
            flash('Password reset. User will need to change it after login.'); redirect('users');
        }
        if (isset($_POST['send_reset_email'])) {
            require_permission('reset_passwords');
            $target = first('SELECT * FROM users WHERE id=?',[(int)$_POST['user_id']]);
            if (!$target) { flash('User not found.'); redirect('users'); }
            $send = send_user_access_email($target, 'reset', (int)$u['id']);
            flash(!empty($send['ok']) ? 'Password reset email sent.' : ('Password reset email failed: ' . ($send['error'] ?? 'unknown error')));
            redirect('users');
        }
        if (isset($_POST['send_invite_existing'])) {
            require_permission('manage_users');
            $target = first('SELECT * FROM users WHERE id=?',[(int)$_POST['user_id']]);
            if (!$target) { flash('User not found.'); redirect('users'); }
            exec_sql('UPDATE users SET force_password_change=1, updated_at=datetime("now") WHERE id=?',[(int)$target['id']]);
            $send = send_user_access_email($target, 'invite', (int)$u['id']);
            flash(!empty($send['ok']) ? 'Invite email sent.' : ('Invite email failed: ' . ($send['error'] ?? 'unknown error')));
            redirect('users');
        }
        if (isset($_POST['update_roles'])) {
            require_permission('manage_roles');
            $targetUserId = (int)$_POST['user_id'];
            $selectedRoles = $_POST['roles'] ?? [];
            if (!is_array($selectedRoles)) $selectedRoles = [];
            $oldRoles = user_roles($targetUserId);
            $result = set_user_roles($targetUserId, array_map('strval', $selectedRoles), (int)$u['id'], trim($_POST['role_change_reason'] ?? 'Admin updated roles'));
            audit('user.roles_updated','user',$targetUserId,'success',null,['added'=>$result['added'],'removed'=>$result['removed'],'final'=>$result['final'],'field_changes'=>['roles'=>['label'=>'Roles','old'=>implode(', ', $oldRoles),'new'=>implode(', ', $result['final'])]]]);
            flash('User roles updated.'); redirect('users');
        }
        if (isset($_POST['create_user'])) {
            $member_id = null;
            if (!empty($_POST['member_id'])) $member_id=(int)$_POST['member_id'];
            $email = strtolower(trim($_POST['email']));
            if (first('SELECT id FROM users WHERE email=?',[$email])) { flash('A user with that email already exists.'); redirect('users'); }
            exec_sql('INSERT INTO users (member_id,email,password_hash,status,force_password_change,created_at,updated_at) VALUES (?,?,?,"active",1,datetime("now"),datetime("now"))',[$member_id,$email,password_hash($_POST['password'],PASSWORD_DEFAULT)]);
            $uid=(int)db()->lastInsertId(); assign_role($uid,'member',(int)$u['id'],'Admin created user');
            audit('user.created','user',$uid); flash('User created.'); redirect('users');
        }
        if (isset($_POST['invite_user'])) {
            $member_id = null;
            if (!empty($_POST['member_id'])) $member_id=(int)$_POST['member_id'];
            $email = strtolower(trim($_POST['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('Enter a valid email address.'); redirect('users'); }
            if (first('SELECT id FROM users WHERE email=?',[$email])) { flash('A user with that email already exists. Use Send invite on the existing user row.'); redirect('users'); }
            $randomPassword = bin2hex(random_bytes(24));
            exec_sql('INSERT INTO users (member_id,email,password_hash,status,force_password_change,created_at,updated_at) VALUES (?,?,?,"active",1,datetime("now"),datetime("now"))',[$member_id,$email,password_hash($randomPassword,PASSWORD_DEFAULT)]);
            $uid=(int)db()->lastInsertId();
            $selectedRoles = $_POST['roles'] ?? ['member'];
            if (!is_array($selectedRoles)) $selectedRoles = ['member'];
            if (!has_permission('manage_roles')) $selectedRoles = ['member'];
            $result = set_user_roles($uid, array_map('strval', $selectedRoles), (int)$u['id'], 'Admin invited new user');
            $target = first('SELECT * FROM users WHERE id=?',[$uid]);
            $send = send_user_access_email($target, 'invite', (int)$u['id']);
            audit('user.invited','user',$uid,!empty($send['ok'])?'success':'failed',$send['error'] ?? null,['roles'=>$result['final']]);
            flash(!empty($send['ok']) ? 'User created and invite email sent.' : ('User created, but invite email failed: ' . ($send['error'] ?? 'unknown error')));
            redirect('users');
        }
    }
    $canRoles = has_permission('manage_roles');
    $canReset = has_permission('reset_passwords');
    $allRoles = all('SELECT name, display_name, description FROM roles ORDER BY CASE name WHEN "member" THEN 1 WHEN "committee" THEN 2 WHEN "chair" THEN 3 WHEN "vice_chair" THEN 4 WHEN "secretary" THEN 5 WHEN "member_db" THEN 6 WHEN "brickworks_reviewer" THEN 7 WHEN "equipment_manager" THEN 8 WHEN "event_manager" THEN 9 WHEN "treasurer" THEN 10 WHEN "admin" THEN 99 ELSE 50 END, display_name');
    $users=all('SELECT u.*,m.first_name,m.last_name,m.callsign FROM users u LEFT JOIN members m ON m.id=u.member_id ORDER BY u.created_at DESC');
    $members=all('SELECT id,first_name,last_name,callsign,email FROM members ORDER BY last_name,first_name');
    $activeCount = count(array_filter($users, fn($x) => ($x['status'] ?? '') === 'active'));
    $adminCount = count(array_filter($users, fn($x) => in_array('admin', user_roles((int)$x['id']), true)));
    $setupRequired = count(array_filter($users, fn($x) => !empty($x['force_password_change'])));

    echo '<div class="card users-hero"><div><h1>Users</h1><p class="muted">Create accounts, send invites, reset passwords and manage user roles. Invite/reset links let users set their own password securely.</p></div><div class="users-stats"><div><strong>'.e(count($users)).'</strong><span>Total users</span></div><div><strong>'.e($activeCount).'</strong><span>Active</span></div><div><strong>'.e($adminCount).'</strong><span>Admins</span></div><div><strong>'.e($setupRequired).'</strong><span>Setup required</span></div></div></div>';

    echo '<div class="users-layout" style="grid-template-columns:1fr">';
    echo '<section class="users-main"><div class="card users-list-card"><div class="toolbar"><h2 style="margin-right:auto">User accounts</h2><a class="btn" href="?route=user_invite">Invite new user</a><a class="btn secondary" href="?route=user_create">Create manual user</a></div><div class="users-admin-list">';
    foreach($users as $usr){
        $currentRoles = user_roles((int)$usr['id']);
        $memberName = trim(($usr['first_name']??'').' '.($usr['last_name']??''));
        $memberLabel = $memberName !== '' ? $memberName . ($usr['callsign']?' ('.$usr['callsign'].')':'') : 'No linked member';
        $initials = $memberName !== '' ? strtoupper(substr(trim($usr['first_name'] ?? ''),0,1).substr(trim($usr['last_name'] ?? ''),0,1)) : strtoupper(substr($usr['email'],0,2));
        $roleLabels = [];
        foreach($allRoles as $role) if (in_array($role['name'], $currentRoles, true)) $roleLabels[] = $role['display_name'];
        echo '<article class="user-admin-card">';
        echo '<div class="user-admin-top"><div class="user-avatar">'.e($initials).'</div><div class="user-summary"><h3>'.e($usr['email']).'</h3><p class="muted">'.e($memberLabel).'</p><div class="user-pills"><span class="pill">'.e($usr['status']).'</span>'.($usr['force_password_change']?'<span class="pill status-pending_approval">password setup required</span>':'').'</div></div></div>';
        echo '<div class="user-role-summary"><strong>Roles</strong><div class="role-chip-row">';
        foreach($roleLabels as $label) echo '<span class="role-chip">'.e($label).'</span>';
        if (!$roleLabels) echo '<span class="muted">No roles assigned</span>';
        echo '</div></div>';
        echo '<div class="user-actions-panel">';
        echo '<form method="post">'.csrf_field().'<input type="hidden" name="send_invite_existing" value="1"><input type="hidden" name="user_id" value="'.e($usr['id']).'"><button class="secondary">Send invite</button></form>';
        if ($canReset) echo '<form method="post">'.csrf_field().'<input type="hidden" name="send_reset_email" value="1"><input type="hidden" name="user_id" value="'.e($usr['id']).'"><button>Send password reset</button></form>';
        if ($canReset) echo '<details class="user-detail-action"><summary>Manual temp password</summary><form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="reset_password" value="1"><input type="hidden" name="user_id" value="'.e($usr['id']).'"><label>New temporary password</label><input name="new_password" placeholder="Minimum 10 characters"><button>Set temp password</button></form></details>';
        if (is_admin_user() && (int)$usr['id'] !== (int)$u['id']) echo '<form method="post" onsubmit="return confirm(&quot;Delete this user account? This cannot be undone. The linked member record will not be deleted.&quot;)">'.csrf_field().'<input type="hidden" name="delete_user" value="1"><input type="hidden" name="user_id" value="'.e($usr['id']).'"><button class="danger">Delete user</button></form>';
        echo '</div>';
        if ($canRoles) {
            echo '<details class="role-editor user-role-editor"><summary>Manage roles</summary><form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="update_roles" value="1"><input type="hidden" name="user_id" value="'.e($usr['id']).'"><div class="role-grid">';
            foreach($allRoles as $role){
                $checked = in_array($role['name'], $currentRoles, true) ? 'checked' : '';
                $disabled = ($role['name']==='member') ? 'disabled' : '';
                if ($role['name']==='member') echo '<input type="hidden" name="roles[]" value="member">';
                echo '<label class="check"><input type="checkbox" name="roles[]" value="'.e($role['name']).'" '.$checked.' '.$disabled.'><span><strong>'.e($role['display_name']).'</strong><small>'.e($role['description'] ?: $role['name']).'</small></span></label>';
            }
            echo '</div><label>Reason / note</label><input name="role_change_reason" placeholder="Optional reason for role change"><button>Save roles</button></form></details>';
        }
        echo '</article>';
    }
    echo '</div></div></section></div>';

    if ($canRoles) {
        $roleGuide = [
            'member' => 'Standard member access: own profile, own consents, programme, directory and own Brickworks progress.',
            'committee' => 'Committee operations: attendance, actions and event creation/editing/deletion.',
            'chair' => 'Officer access to all non-admin areas including membership, events, attendance, emails, equipment and actions.',
            'vice_chair' => 'Officer access to all non-admin areas including membership, events, attendance, emails, equipment and actions.',
            'secretary' => 'Officer access to all non-admin areas including membership, events, attendance, emails, equipment and actions.',
            'treasurer' => 'Officer access to all non-admin areas including membership, payments/subs, events, attendance, emails, equipment and actions.',
            'member_db' => 'Can view and edit the membership database, subscriptions and member exports.',
            'equipment_manager' => 'Can view and edit equipment/assets, loans and maintenance records.',
            'event_manager' => 'Can create, edit and delete Programme events.',
            'brickworks_participant' => 'Member is signed up to Brickworks and can submit evidence.',
            'brickworks_reviewer' => 'Can review Brickworks evidence, add comments and approve criteria.',
            'admin' => 'Full system access including Users, Audit logs, Email settings and all permissions.'
        ];
        echo '<div class="card"><details open><summary><strong>Role guide</strong></summary><div class="table-wrap"><table><tr><th>Role</th><th>Access description</th></tr>';
        foreach($allRoles as $role){
            echo '<tr><td><strong>'.e($role['display_name']).'</strong><br><span class="muted small">'.e($role['name']).'</span></td><td>'.e($roleGuide[$role['name']] ?? ($role['description'] ?: $role['name'])).'</td></tr>';
        }
        echo '</table></div></details></div>';
    }
    page_footer(); exit;
}


if (route() === 'equipment_create') {
    require_permission('edit_equipment');
    page_header('Add equipment / asset');
    audit('equipment_create.view');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        exec_sql('INSERT INTO equipment (asset_number,name,category,manufacturer,model,serial_number_encrypted,location,condition,purchase_date,purchase_amount,value,maintenance_due_at,notes_encrypted,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,datetime("now"),datetime("now"))',[
            trim($_POST['asset_number']),trim($_POST['name']),trim($_POST['category']),trim($_POST['manufacturer']),trim($_POST['model']),encrypt_value(trim($_POST['serial_number'])),trim($_POST['location']),trim($_POST['condition']),trim($_POST['purchase_date'] ?? ''),($_POST['purchase_amount'] !== '' ? (float)$_POST['purchase_amount'] : null),($_POST['value'] !== '' ? (float)$_POST['value'] : null),trim($_POST['maintenance_due_at'] ?? ''),encrypt_value(trim($_POST['notes'] ?? ''))
        ]);
        $newId=(int)db()->lastInsertId();
        audit('equipment.create','equipment',$newId);
        flash('Equipment / asset added.');
        redirect('equipment_view&id='.$newId);
    }

    echo '<section class="asset-hero"><div><span class="eyebrow">New asset</span><h1>Add equipment / asset</h1><p>Add a new club asset. Values and serials are kept within the protected system.</p></div><div class="asset-hero-stats"><div><strong>+</strong><span>New record</span></div></div></section>';
    echo '<div class="card asset-form-card"><div class="toolbar"><h2 style="margin-right:auto">Asset details</h2><a class="btn secondary" href="?route=equipment">Back to asset list</a></div><form method="post">'.csrf_field().'<div class="two"><div><label>Asset number</label><input name="asset_number" required></div><div><label>Item name</label><input name="name" required></div><div><label>Category</label><input name="category" placeholder="Radio, antenna, laptop, PSU, coax, etc."></div><div><label>Manufacturer</label><input name="manufacturer"></div><div><label>Model</label><input name="model"></div><div><label>Serial number</label><input name="serial_number"></div><div><label>Storage/location</label><input name="location"></div><div><label>Condition</label><input name="condition" placeholder="Good, fair, needs repair, etc."></div><div><label>Date of purchase, if known</label><input name="purchase_date" type="date"></div><div><label>Amount purchased for, if known</label><input name="purchase_amount" type="number" step="0.01" placeholder="0.00"></div><div><label>Current/insurance value</label><input name="value" type="number" step="0.01" placeholder="0.00"></div><div><label>Maintenance due date</label><input name="maintenance_due_at" type="date"></div></div><label>Notes</label><textarea name="notes" placeholder="Any useful asset notes"></textarea><div class="toolbar"><button>Add equipment</button><a class="btn secondary" href="?route=equipment">Cancel</a></div></form></div>';
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
    $totalAssets=count($rows);
    $dueSoon=0; $needsAttention=0;
    foreach($rows as $r){
        if(!empty($r['maintenance_due_at']) && strtotime($r['maintenance_due_at']) !== false && strtotime($r['maintenance_due_at']) <= strtotime('+30 days')) $dueSoon++;
        if(stripos((string)$r['condition'],'repair')!==false || stripos((string)$r['condition'],'fault')!==false || stripos((string)$r['condition'],'poor')!==false) $needsAttention++;
    }
    echo '<section class="asset-hero"><div><span class="eyebrow">Club assets</span><h1>Equipment / asset database</h1><p>Track club equipment, locations, values and maintenance history in one place.</p></div><div class="asset-hero-stats"><div><strong>'.e($totalAssets).'</strong><span>Total assets</span></div><div><strong>'.e($dueSoon).'</strong><span>Due soon</span></div><div><strong>'.e($needsAttention).'</strong><span>Attention</span></div></div></section>';

    echo '<section class="card asset-list-card"><div class="dash-card-head"><div><h2>Asset list</h2><p class="muted">Open an asset to view details and track maintenance tickets/history.</p></div>';
    if (has_permission('edit_equipment')) echo '<a class="btn" href="?route=equipment_create">Add equipment / asset</a>';
    echo '</div>';
    if(!$rows) {
        echo '<div class="empty-state"><strong>No assets yet</strong><span>Add the first asset below.</span></div>';
    } else {
        echo '<div class="asset-card-grid">';
        foreach($rows as $r) {
            $value = ($r['value']!==null && $r['value']!=='' ? '£'.number_format((float)$r['value'],2) : 'Unknown');
            $purchase = ($r['purchase_amount']!==null && $r['purchase_amount']!=='' ? '£'.number_format((float)$r['purchase_amount'],2) : 'Unknown');
            $conditionClass = preg_replace('/[^a-z0-9_]+/','_',strtolower($r['condition'] ?: 'unknown'));
            echo '<article class="asset-card-modern"><a href="?route=equipment_view&id='.e($r['id']).'"><div class="asset-card-top"><div><span class="asset-number">'.e($r['asset_number'] ?: 'No asset no.').'</span><h3>'.e($r['name']).'</h3></div><span class="status-pill status-'.e($conditionClass).'">'.e($r['condition'] ?: 'Unknown').'</span></div><div class="asset-card-meta"><span>Model</span><strong>'.e(trim(($r['manufacturer']??'').' '.($r['model']??'')) ?: 'Unknown').'</strong><span>Location</span><strong>'.e($r['location'] ?: 'Unknown').'</strong><span>Purchased</span><strong>'.e($r['purchase_date'] ?: 'Unknown').' • '.e($purchase).'</strong><span>Value</span><strong>'.e($value).'</strong><span>Maintenance due</span><strong>'.e($r['maintenance_due_at'] ?: 'Not set').'</strong></div><span class="programme-open">Open ›</span></a></article>';
        }
        echo '</div>';
    }
    echo '</section>';
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
            $oldTicket = first('SELECT * FROM equipment_maintenance_tickets WHERE id=? AND equipment_id=?',[$tid,$id]) ?: [];
            $oldAudit = ['status'=>$oldTicket['status'] ?? '', 'priority'=>$oldTicket['priority'] ?? '', 'due_date'=>$oldTicket['due_date'] ?? '', 'description'=>decrypt_value($oldTicket['description_encrypted'] ?? '') ?: '', 'action_taken'=>decrypt_value($oldTicket['action_taken_encrypted'] ?? '') ?: '', 'cost'=>$oldTicket['cost'] ?? '', 'assigned_user_id'=>$oldTicket['assigned_user_id'] ?? ''];
            $newAudit = ['status'=>$status, 'priority'=>trim($_POST['priority'] ?? 'normal'), 'due_date'=>trim($_POST['due_date'] ?? ''), 'description'=>trim($_POST['description'] ?? ''), 'action_taken'=>trim($_POST['action_taken'] ?? ''), 'cost'=>($_POST['cost'] !== '' ? (float)$_POST['cost'] : null), 'assigned_user_id'=>!empty($_POST['assigned_user_id'])?(int)$_POST['assigned_user_id']:null];
            exec_sql('UPDATE equipment_maintenance_tickets SET status=?, priority=?, due_date=?, description_encrypted=?, action_taken_encrypted=?, cost=?, assigned_user_id=?, closed_at=CASE WHEN ?="closed" THEN COALESCE(closed_at, datetime("now")) ELSE NULL END, updated_at=datetime("now") WHERE id=? AND equipment_id=?',[$status,trim($_POST['priority'] ?? 'normal'),trim($_POST['due_date'] ?? ''),encrypt_value(trim($_POST['description'] ?? '')),encrypt_value(trim($_POST['action_taken'] ?? '')),($_POST['cost'] !== '' ? (float)$_POST['cost'] : null),!empty($_POST['assigned_user_id'])?(int)$_POST['assigned_user_id']:null,$status,$tid,$id]);
            audit('equipment.ticket_update','equipment_maintenance_ticket',$tid,'success',null,['equipment_id'=>$id,'field_changes'=>audit_field_changes($oldAudit,$newAudit)]); flash('Maintenance ticket updated.'); redirect('equipment_view&id='.$id);
        }
    }
    page_header('Asset details');
    echo '<section class="asset-hero asset-detail-hero"><div><span class="eyebrow">Asset details</span><h1>'.e($item['asset_number']).' - '.e($item['name']).'</h1><p>'.e(trim(($item['manufacturer']??'').' '.($item['model']??'')) ?: 'Asset record').'</p></div><div class="asset-hero-stats"><div><strong>'.e($item['condition'] ?: 'Unknown').'</strong><span>Condition</span></div><div><strong>'.e($item['location'] ?: 'Unknown').'</strong><span>Location</span></div></div></section>';
    echo '<div class="card asset-detail-card"><div class="toolbar"><h2 style="margin-right:auto">Asset information</h2><a class="btn secondary" href="?route=equipment">Back to equipment</a></div><div class="asset-grid">';
    $fields=['Category'=>$item['category'],'Manufacturer'=>$item['manufacturer'],'Model'=>$item['model'],'Serial number'=>decrypt_value($item['serial_number_encrypted']),'Location'=>$item['location'],'Condition'=>$item['condition'],'Date purchased'=>$item['purchase_date'] ?: 'Unknown','Amount purchased for'=>($item['purchase_amount']!==null && $item['purchase_amount']!=='' ? '£'.number_format((float)$item['purchase_amount'],2) : 'Unknown'),'Current/insurance value'=>($item['value']!==null && $item['value']!=='' ? '£'.number_format((float)$item['value'],2) : 'Unknown'),'Maintenance due'=>$item['maintenance_due_at'] ?: 'Not set'];
    foreach($fields as $label=>$val) echo '<div class="asset-field"><span>'.e($label).'</span><strong>'.e($val).'</strong></div>';
    echo '</div>'; if($item['notes_encrypted']) echo '<h3>Notes</h3><p>'.nl2br(e(decrypt_value($item['notes_encrypted']))).'</p>'; echo '</div>';
    $users=all('SELECT u.id,u.email,m.first_name,m.last_name,m.callsign FROM users u LEFT JOIN members m ON m.id=u.member_id WHERE u.status="active" ORDER BY m.last_name,u.email');
    if (has_permission('edit_equipment')) {
        echo '<div class="card asset-form-card"><h2>Add maintenance ticket</h2><p class="muted">Create a maintenance/history ticket for this asset.</p><form method="post">'.csrf_field().'<input type="hidden" name="add_ticket" value="1"><div class="two"><div><label>Ticket title / fault</label><input name="title" required></div><div><label>Priority</label><select name="priority"><option>low</option><option selected>normal</option><option>high</option><option>urgent</option></select></div><div><label>Due date</label><input type="date" name="due_date"></div><div><label>Assign to user</label><select name="assigned_user_id"><option value="">Unassigned</option>'; foreach($users as $usr) echo '<option value="'.e($usr['id']).'">'.e(trim(($usr['first_name']??'').' '.($usr['last_name']??'')).($usr['callsign']?' - '.$usr['callsign']:'').' / '.$usr['email']).'</option>'; echo '</select></div><div><label>Estimated/current cost</label><input name="cost" type="number" step="0.01"></div></div><label>Description</label><textarea name="description" placeholder="Fault, maintenance needed, parts required, etc."></textarea><button>Create ticket</button></form></div>';
    }
    $tickets=all('SELECT t.*,u.email,m.first_name,m.last_name FROM equipment_maintenance_tickets t LEFT JOIN users u ON u.id=t.assigned_user_id LEFT JOIN members m ON m.id=u.member_id WHERE t.equipment_id=? ORDER BY CASE t.status WHEN "open" THEN 1 WHEN "in_progress" THEN 2 WHEN "closed" THEN 3 ELSE 4 END, datetime(t.created_at) DESC',[$id]);
    echo '<div class="card maintenance-list-card"><div class="dash-card-head"><div><h2>Maintenance tickets / history</h2><p class="muted">Ticket-style repair, inspection and maintenance log.</p></div></div>'; if(!$tickets) echo '<p>No maintenance tickets have been recorded for this item yet.</p>'; foreach($tickets as $t){ $cls=str_replace(' ','_',strtolower($t['status'])); echo '<div class="ticket '.e($cls).'"><div class="ticket-head"><div><strong>'.e($t['title']).'</strong><br><span class="muted">Created '.e($t['created_at']).' • Due '.e($t['due_date'] ?: 'not set').' • Assigned to '.e(trim(($t['first_name']??'').' '.($t['last_name']??'')) ?: ($t['email'] ?: 'Unassigned')).'</span></div><span class="status-pill status-'.e($cls).'">'.e($t['status']).'</span></div><p>'.nl2br(e(decrypt_value($t['description_encrypted']))).'</p>'; if($t['action_taken_encrypted']) echo '<p><strong>Action/history:</strong><br>'.nl2br(e(decrypt_value($t['action_taken_encrypted']))).'</p>'; if(has_permission('edit_equipment')){ echo '<details><summary>Edit ticket</summary><form method="post" class="inline-form">'.csrf_field().'<input type="hidden" name="update_ticket" value="1"><input type="hidden" name="ticket_id" value="'.e($t['id']).'"><label>Status</label><select name="status"><option '.($t['status']==='open'?'selected':'').'>open</option><option '.($t['status']==='in_progress'?'selected':'').'>in_progress</option><option '.($t['status']==='closed'?'selected':'').'>closed</option><option '.($t['status']==='cancelled'?'selected':'').'>cancelled</option></select><label>Priority</label><select name="priority"><option '.($t['priority']==='low'?'selected':'').'>low</option><option '.($t['priority']==='normal'?'selected':'').'>normal</option><option '.($t['priority']==='high'?'selected':'').'>high</option><option '.($t['priority']==='urgent'?'selected':'').'>urgent</option></select><label>Due date</label><input type="date" name="due_date" value="'.e($t['due_date']).'"><label>Assign to user</label><select name="assigned_user_id"><option value="">Unassigned</option>'; foreach($users as $usr) echo '<option value="'.e($usr['id']).'" '.((int)$t['assigned_user_id']===(int)$usr['id']?'selected':'').'>'.e(trim(($usr['first_name']??'').' '.($usr['last_name']??'')).($usr['callsign']?' - '.$usr['callsign']:'').' / '.$usr['email']).'</option>'; echo '</select><label>Description</label><textarea name="description">'.e(decrypt_value($t['description_encrypted'])).'</textarea><label>Action taken / history</label><textarea name="action_taken">'.e(decrypt_value($t['action_taken_encrypted'])).'</textarea><label>Cost</label><input name="cost" type="number" step="0.01" value="'.e($t['cost']).'"><button>Update ticket</button></form></details>'; } echo '</div>'; }
    echo '</div>';
    page_footer(); exit;
}


if (route() === 'committee_action_create') {
    require_permission('manage_committee_actions');
    page_header('Create committee action');
    audit('committee_action_create.view');
    $members=all('SELECT id,first_name,last_name,callsign FROM members WHERE membership_status IN ("active","honorary","life_member") ORDER BY last_name,first_name');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        exec_sql('INSERT INTO committee_actions (title,status,priority,action_required,description_encrypted,due_date,assigned_user_id,assigned_member_id,created_by_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,datetime("now"),datetime("now"))',[
            trim($_POST['title']),'open',trim($_POST['priority'] ?? 'normal'),trim($_POST['action_required']),encrypt_value(trim($_POST['description'] ?? '')),trim($_POST['due_date'] ?? ''),null,!empty($_POST['assigned_member_id'])?(int)$_POST['assigned_member_id']:null,$u['id']
        ]);
        $newId=(int)db()->lastInsertId();
        record_action_history($newId, 'created', 'Action created', ['status'=>['from'=>null,'to'=>'open']]);
        audit('committee_action.create','committee_action',$newId);
        flash('Committee action created.');
        redirect('committee_actions');
    }

    echo '<section class="action-hero"><div><span class="eyebrow">New committee action</span><h1>Create action</h1><p>Create a committee task and assign it to a member for follow-up.</p></div><div class="action-hero-icon">+</div></section>';
    echo '<div class="card action-form-card"><div class="toolbar"><h2 style="margin-right:auto">Action details</h2><a class="btn secondary" href="?route=committee_actions">Back to actions</a></div><form method="post">'.csrf_field().'<div class="two"><div><label>Action title</label><input name="title" required></div><div><label>Priority</label><select name="priority"><option>low</option><option selected>normal</option><option>high</option><option>urgent</option></select></div><div><label>Due date</label><input type="date" name="due_date"></div><div><label>Assign to member</label><select name="assigned_member_id"><option value="">Unassigned</option>';
    foreach($members as $m) echo '<option value="'.e($m['id']).'">'.e($m['first_name'].' '.$m['last_name'].($m['callsign']?' - '.$m['callsign']:'' )).'</option>';
    echo '</select></div></div><label>Action required</label><input name="action_required" required placeholder="What needs doing?"><label>Description</label><textarea name="description" placeholder="Notes, background, decisions, next steps"></textarea><div class="toolbar"><button>Create action</button><a class="btn secondary" href="?route=committee_actions">Cancel</a></div></form></div>';
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
            if ($oldDesc !== $newVals['description']) $changes['description']=['label'=>'Description','old'=>$oldDesc,'new'=>$newVals['description']];
            exec_sql('UPDATE committee_actions SET title=?, status=?, priority=?, action_required=?, description_encrypted=?, due_date=?, assigned_user_id=NULL, assigned_member_id=?, completed_at=CASE WHEN ?="closed" THEN COALESCE(completed_at, datetime("now")) ELSE NULL END, updated_at=datetime("now") WHERE id=?',[
                $newVals['title'],$newVals['status'],$newVals['priority'],$newVals['action_required'],encrypt_value($newVals['description']),$newVals['due_date'],$newVals['assigned_member_id'],$status,$aid
            ]);
            record_action_history($aid, 'field_change', trim($_POST['update_note'] ?? ''), $changes);
            audit('committee_action.update','committee_action',$aid,'success',null,['field_changes'=>$changes]); flash('Committee action updated.'); redirect('committee_actions');
        }
    }
    echo '<section class="action-hero"><div><span class="eyebrow">Committee workflow</span><h1>Committee actions</h1><p>Track tasks, decisions and follow-ups with ticket-style updates and history.</p></div><div class="action-hero-icon">✓</div></section>';
    $actions=all('SELECT ca.*,am.first_name member_first,am.last_name member_last,am.callsign member_callsign FROM committee_actions ca LEFT JOIN members am ON am.id=ca.assigned_member_id ORDER BY CASE ca.status WHEN "open" THEN 1 WHEN "in_progress" THEN 2 WHEN "closed" THEN 3 ELSE 4 END, date(ca.due_date) ASC, datetime(ca.created_at) DESC');
    $openCount=0; $progressCount=0; $closedCount=0;
    foreach($actions as $aCount){ if(($aCount['status'] ?? '')==='open') $openCount++; elseif(($aCount['status'] ?? '')==='in_progress') $progressCount++; elseif(($aCount['status'] ?? '')==='closed') $closedCount++; }
    echo '<section class="card action-list-card"><div class="dash-card-head"><div><h2>Action tickets</h2><p class="muted">Open, in-progress and completed committee actions.</p></div><div class="action-header-side"><div class="action-mini-stats"><span><strong>'.e($openCount).'</strong> open</span><span><strong>'.e($progressCount).'</strong> in progress</span><span><strong>'.e($closedCount).'</strong> closed</span></div>';
    if (has_permission('manage_committee_actions')) echo '<a class="btn" href="?route=committee_action_create">Create action</a>';
    echo '</div></div><div class="actions-board">';
    if(!$actions) echo '<p>No committee actions have been created yet.</p>';
    foreach($actions as $a){
        $cls=str_replace(' ','_',strtolower($a['status']));
        $assignedMember=trim(($a['member_first']??'').' '.($a['member_last']??'')).($a['member_callsign']?' - '.$a['member_callsign']:'');
        $history=all('SELECT cu.*,u.email,m.first_name,m.last_name,m.callsign FROM committee_action_updates cu LEFT JOIN users u ON u.id=cu.created_by_user_id LEFT JOIN members m ON m.id=u.member_id WHERE cu.action_id=? ORDER BY datetime(cu.created_at) DESC, cu.id DESC',[$a['id']]);
        echo '<article class="ticket action-ticket '.e($cls).'"><div class="ticket-head"><div><span class="ticket-kicker">Committee action</span><strong>'.e($a['title']).'</strong><br><span class="muted">Created '.e($a['created_at']).' • Due '.e($a['due_date'] ?: 'not set').' • Assigned member: '.e($assignedMember ?: 'Unassigned').'</span></div><span class="status-pill status-'.e($cls).'">'.e($a['status']).'</span></div><div class="action-ticket-body"><p><strong>Action:</strong> '.e($a['action_required']).'</p><p>'.nl2br(e(decrypt_value($a['description_encrypted']))).'</p></div>';
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
        echo '</article>';
    }
    echo '</div></section>';
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
    $view = in_array($view, ['list','calendar'], true) ? $view : 'list';

    $section = $_GET['section'] ?? 'current';
    $section = in_array($section, ['current','past'], true) ? $section : 'current';

    $month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');

    $search = trim((string)($_GET['q'] ?? ''));
    $selectedType = trim((string)($_GET['type'] ?? ''));
    if ($selectedType !== '' && !in_array($selectedType, event_categories(), true)) {
        $selectedType = '';
    }

    $baseFilters = [];
    if ($selectedType !== '') $baseFilters['type'] = $selectedType;
    if ($search !== '') $baseFilters['q'] = $search;

    $listLink = '?'.http_build_query(array_merge(['route'=>'events','view'=>'list','section'=>$section], $baseFilters));
    $calendarLink = '?'.http_build_query(array_merge(['route'=>'events','view'=>'calendar','section'=>$section,'month'=>$month], $baseFilters));
    $currentLink = '?'.http_build_query(array_merge(['route'=>'events','view'=>$view,'section'=>'current','month'=>$month], $baseFilters));
    $pastLink = '?'.http_build_query(array_merge(['route'=>'events','view'=>$view,'section'=>'past','month'=>$month], $baseFilters));

    echo '<section class="programme-hero"><div><span class="eyebrow">Club programme</span><h1>Programme</h1><p>Browse upcoming and past club nights, talks, rallies and special event stations.</p></div><div class="programme-actions"><a class="btn secondary" href="'.e($listLink).'">List view</a><a class="btn secondary" href="'.e($calendarLink).'">Calendar view</a>';
    if (can_manage_events()) echo '<button type="button" onclick="document.getElementById(\'addEventDialog\').showModal()">Add event</button>';
    echo '</div></section>';

    echo '<div class="card programme-filter-card">';
    echo '<div class="programme-tabs">';
    echo '<a class="'.($section === 'current' ? 'active' : '').'" href="'.e($currentLink).'">Current & future</a>';
    echo '<a class="'.($section === 'past' ? 'active' : '').'" href="'.e($pastLink).'">Past</a>';
    echo '</div>';
    echo '<form method="get" class="programme-filter">';
    echo '<input type="hidden" name="route" value="events">';
    echo '<input type="hidden" name="view" value="'.e($view).'">';
    echo '<input type="hidden" name="section" value="'.e($section).'">';
    if ($view === 'calendar') echo '<input type="hidden" name="month" value="'.e($month).'">';
    echo '<div class="programme-search"><label>Search events</label><input name="q" value="'.e($search).'" placeholder="Search title, description or location"></div>';
    echo '<div><label>Event type</label><select name="type"><option value="">All event types</option>'.event_category_options($selectedType).'</select></div>';
    echo '<div class="programme-filter-actions"><button>Apply</button><a class="btn secondary" href="?route=events&view='.e($view).'&section='.e($section).($view==='calendar'?'&month='.e($month):'').'">Clear</a></div>';
    echo '</form>';
    echo '</div>';

    $filterSql = '';
    $filterParams = [];
    if ($selectedType !== '') {
        $filterSql .= ' AND e.event_type = ?';
        $filterParams[] = $selectedType;
    }
    if ($search !== '') {
        $filterSql .= ' AND (e.title LIKE ? OR e.description LIKE ? OR e.location LIKE ?)';
        $like = '%' . $search . '%';
        $filterParams[] = $like;
        $filterParams[] = $like;
        $filterParams[] = $like;
    }

    if ($view === 'calendar') {
        $start = new DateTime($month . '-01');
        $firstDay = clone $start;
        $gridStart = clone $firstDay; $gridStart->modify('-'.(((int)$firstDay->format('N'))-1).' days');
        $gridEnd = clone $gridStart; $gridEnd->modify('+41 days');
        $prev=(clone $start)->modify('-1 month')->format('Y-m'); $next=(clone $start)->modify('+1 month')->format('Y-m');

        $prevLink='?'.http_build_query(array_merge(['route'=>'events','view'=>'calendar','section'=>$section,'month'=>$prev], $baseFilters));
        $nextLink='?'.http_build_query(array_merge(['route'=>'events','view'=>'calendar','section'=>$section,'month'=>$next], $baseFilters));

        $events = all('SELECT e.* FROM events e WHERE date(e.start_at) BETWEEN ? AND ?'.$filterSql.' ORDER BY e.start_at ASC', array_merge([$gridStart->format('Y-m-d'),$gridEnd->format('Y-m-d')], $filterParams));
        $byDate=[]; foreach($events as $ev){ $byDate[substr($ev['start_at'],0,10)][]=$ev; }

        echo '<div class="card"><div class="toolbar"><a class="btn secondary" href="'.e($prevLink).'">‹ Previous</a><h2 style="margin-right:auto">'.e($start->format('F Y')).'</h2><a class="btn secondary" href="'.e($nextLink).'">Next ›</a></div><div class="calendar">';
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
        if ($section === 'past') {
            $dateSql = 'e.start_at < datetime("now")';
            $orderSql = 'e.start_at DESC';
            $heading = 'Past events';
            $empty = 'No past events match the selected filters.';
        } else {
            $dateSql = 'e.start_at >= datetime("now")';
            $orderSql = 'e.start_at ASC';
            $heading = 'Current & future events';
            $empty = 'No current or future events match the selected filters.';
        }

        $rows=all('SELECT e.*,COUNT(a.id) attendee_count FROM events e LEFT JOIN event_attendance a ON a.event_id=e.id AND a.status IN ("signed_up","attended") WHERE '.$dateSql.$filterSql.' GROUP BY e.id ORDER BY '.$orderSql, $filterParams);

        echo '<div class="card programme-list-card"><div class="toolbar"><div><h2>'.e($heading).'</h2>';
        if ($search || $selectedType) {
            echo '<p class="muted">Filtered';
            if ($selectedType) echo ' by <strong>'.e($selectedType).'</strong>';
            if ($search) echo ' matching <strong>'.e($search).'</strong>';
            echo '</p>';
        } else {
            echo '<p class="muted">Click any card to view full details, attachments and attendance options.</p>';
        }
        echo '</div><span class="pill">'.e(count($rows)).' events</span></div>';
        echo '<div class="programme-event-grid">';
        if (!$rows) echo '<p>'.e($empty).'</p>';
        foreach($rows as $ev){
            $startTs = strtotime($ev['start_at']);
            $dateBox = $startTs ? '<div class="programme-datebox"><strong>'.e(date('d', $startTs)).'</strong><span>'.e(date('M', $startTs)).'</span></div>' : '<div class="programme-datebox"><strong>?</strong><span>TBC</span></div>';
            echo '<article class="programme-event-card"><a class="programme-card-link" href="?route=event_view&id='.e($ev['id']).'"><div class="programme-card-main">'.$dateBox.'<div><div class="programme-card-top"><span class="pill category-pill">'.e($ev['event_type'] ?: 'Other').'</span><span class="muted">'.e($startTs ? date('D j M Y H:i', $startTs) : 'Date TBC').($ev['end_at']?' - '.e(date('H:i', strtotime($ev['end_at']))):'').'</span></div><h3>'.e($ev['title']).'</h3><p>'.nl2br(e(mb_strimwidth((string)$ev['description'],0,180,'…'))).'</p><div class="programme-meta"><span>📍 '.e($ev['location'] ?: 'TBC').'</span><span>👥 '.e($ev['attendee_count']).' signed up</span></div></div></div><span class="programme-open">Open ›</span></a></article>';
        }
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
            $oldMemberRows=all('SELECT ea.member_id,ea.attended,ea.status,m.first_name,m.last_name,m.callsign FROM event_attendance ea JOIN members m ON m.id=ea.member_id WHERE ea.event_id=?',[$id]);
            $oldMemberState=[]; foreach($oldMemberRows as $om){ $oldMemberState[(int)$om['member_id']]=$om; }
            $memberChanges=[]; $guestChanges=[];
            $activeMembers=all('SELECT m.id,m.first_name,m.last_name,m.callsign, ea.id attendance_id FROM members m LEFT JOIN event_attendance ea ON ea.member_id=m.id AND ea.event_id=? WHERE m.membership_status IN ("active","honorary","life_member")',[$id]);
            foreach($activeMembers as $row){
                $memberId=(int)$row['id'];
                $isAttended=isset($checkedMembers[$memberId]) ? 1 : 0;
                $oldPresent = !empty($oldMemberState[$memberId]) && (int)($oldMemberState[$memberId]['attended'] ?? 0) === 1;
                if ($oldPresent !== (bool)$isAttended) {
                    $memberChanges['member_'.$memberId] = ['label'=>trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? '')).(($row['callsign'] ?? '') ? ' ('.$row['callsign'].')' : ''),'old'=>$oldPresent ? 'Present' : 'Absent','new'=>$isAttended ? 'Present' : 'Absent'];
                }
                if ($isAttended) {
                    exec_sql('INSERT OR IGNORE INTO event_attendance (event_id,member_id,status,attended,signed_up_at,marked_at,marked_by_user_id,created_at,updated_at) VALUES (?, ?, "attended", 1, COALESCE((SELECT signed_up_at FROM event_attendance WHERE event_id=? AND member_id=?), datetime("now")), datetime("now"), ?, datetime("now"), datetime("now"))',[$id,$memberId,$id,$memberId,$u['id']]);
                    exec_sql('UPDATE event_attendance SET attended=1, status="attended", marked_at=datetime("now"), marked_by_user_id=?, updated_at=datetime("now") WHERE event_id=? AND member_id=?',[$u['id'],$id,$memberId]);
                } elseif (!empty($row['attendance_id'])) {
                    exec_sql('UPDATE event_attendance SET attended=0, status="did_not_attend", marked_at=datetime("now"), marked_by_user_id=?, updated_at=datetime("now") WHERE event_id=? AND member_id=?',[$u['id'],$id,$memberId]);
                }
            }
            $guestRows=all('SELECT id,name,attended,comment_encrypted FROM event_guests WHERE event_id=?',[$id]); $checkedGuests=$_POST['guest_attended'] ?? []; foreach($guestRows as $row){ $guestId=(int)$row['id']; $isAttended=isset($checkedGuests[$guestId]) ? 1 : 0; $comment=trim($_POST['guest_comment_existing'][$guestId] ?? ''); $oldPresent=(int)($row['attended'] ?? 0)===1; $oldComment=decrypt_value($row['comment_encrypted'] ?? '') ?: ''; if($oldPresent !== (bool)$isAttended || $oldComment !== $comment){ $guestChanges['guest_'.$guestId]=['label'=>'Guest: '.($row['name'] ?? $guestId),'old'=>($oldPresent?'Present':'Absent').($oldComment!==''?' / '.$oldComment:''),'new'=>($isAttended?'Present':'Absent').($comment!==''?' / '.$comment:'')]; } exec_sql('UPDATE event_guests SET attended=?, comment_encrypted=?, updated_at=datetime("now") WHERE event_id=? AND id=?',[$isAttended,encrypt_value($comment),$id,$guestId]); }
            audit('attendance.update','event',$id,'success',null,['field_changes'=>array_merge($memberChanges,$guestChanges),'member_changes'=>count($memberChanges),'guest_changes'=>count($guestChanges)]); flash('Attendance updated.'); redirect('event_view&id='.$id); }
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
        $oldAudit = ['title'=>$ev['title'] ?? '', 'event_type'=>$ev['event_type'] ?? '', 'description'=>$ev['description'] ?? '', 'location'=>$ev['location'] ?? '', 'start_at'=>$ev['start_at'] ?? '', 'end_at'=>$ev['end_at'] ?? '', 'max_attendees'=>$ev['max_attendees'] ?? ''];
        $newAudit = ['title'=>trim($_POST['title'] ?? ''), 'event_type'=>$category, 'description'=>trim($_POST['description'] ?? ''), 'location'=>trim($_POST['location'] ?? ''), 'start_at'=>trim($_POST['start_at'] ?? ''), 'end_at'=>trim($_POST['end_at'] ?? ''), 'max_attendees'=>(int)($_POST['max_attendees']?:0)];
        exec_sql('UPDATE events SET title=?, event_type=?, description=?, location=?, start_at=?, end_at=?, max_attendees=?, updated_at=datetime("now") WHERE id=?',[trim($_POST['title']),$category,trim($_POST['description']),trim($_POST['location']),trim($_POST['start_at']),trim($_POST['end_at']),(int)($_POST['max_attendees']?:0),$id]);
        if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error']===UPLOAD_ERR_OK) { $mime=mime_content_type($_FILES['attachment']['tmp_name']) ?: 'application/octet-stream'; $stored=bin2hex(random_bytes(18)).'-'.preg_replace('/[^A-Za-z0-9._-]/','_',$_FILES['attachment']['name']); $dest=PRIVATE_PATH.'/event-attachments/'.$stored; if(!is_dir(dirname($dest))) mkdir(dirname($dest),0750,true); move_uploaded_file($_FILES['attachment']['tmp_name'],$dest); exec_sql('INSERT INTO event_attachments (event_id,original_filename,stored_filename,mime_type,file_size,uploaded_by_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,datetime("now"),datetime("now"))',[$id,$_FILES['attachment']['name'],$stored,$mime,(int)$_FILES['attachment']['size'],$u['id']]); audit('event.attachment_uploaded','event',$id); }
        audit('event.update','event',$id,'success',null,['field_changes'=>audit_field_changes($oldAudit,$newAudit)]); flash('Event updated.'); redirect('event_view&id='.$id);
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
    if (!$participant) { $manageButton = (has_permission('review_brickworks_evidence') || has_permission('manage_brickworks_criteria')) ? '<a class="btn secondary" href="?route=brickworks_manage">Brickworks management</a>' : ''; echo '<section class="brickworks-hero"><div><span class="eyebrow">Brickworks Scheme</span><h1>Start your Brickworks journey</h1><p>Track criteria, upload evidence and build practical amateur radio experience with the club.</p></div><div class="brickworks-join">'.$manageButton.'<form method="post">'.csrf_field().'<input type="hidden" name="join" value="1"><button>Join Brickworks</button></form></div></section>'; page_footer(); exit; }
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
    echo '<section class="brickworks-hero"><div><span class="eyebrow">Brickworks Scheme</span><div class="toolbar"><h1 style="margin-right:auto">Your Brickworks progress</h1>'.$manageButton.'</div><p>Track criteria, upload evidence and see reviewer feedback in one place.</p><div class="progressbar brickworks-progress"><span style="width:'.e($pct).'%"></span></div><div class="bw-steps"><div class="bw-step"><span>Completed</span><strong>'.e($complete).' / '.e($totalCriteria).'</strong></div><div class="bw-step"><span>Pending approval</span><strong>'.e($pending).'</strong></div><div class="bw-step"><span>Current award</span><strong>'.e($award ?: 'None yet').'</strong></div></div></div><div class="brickworks-score"><strong>'.e($pct).'%</strong><span>complete</span></div></section>';
    $rows=all('SELECT bp.*,bc.title,bc.description,bc.evidence_guidance,bt.name theme FROM brickworks_progress bp JOIN brickworks_criteria bc ON bc.id=bp.criterion_id JOIN brickworks_themes bt ON bt.id=bc.theme_id WHERE bp.participant_id=? ORDER BY bt.sort_order,bc.sort_order',[$participant['id']]);
    echo '<section class="card brickworks-list-card"><div class="dash-card-head"><div><h2>Criteria</h2><p class="muted">Open items to add evidence notes and upload files for review.</p></div><span class="pill">'.e(count($rows)).' items</span></div><div class="bw-grid">';
    foreach($rows as $r){
        $isComplete=$r['status']==='complete';
        $isPending=$r['status']==='pending_approval';
        $status=$isComplete?'Complete - '.$r['completed_at']:($isPending?'In progress / Pending approval':'Not completed');
        $statusClass=$isComplete?'complete':($isPending?'pending':'none');
        $memberComment=decrypt_value($r['member_comment_encrypted']);
        $reviewerComment=decrypt_value($r['reviewer_comment_encrypted']);
        echo '<section class="bw-card '.e($statusClass).'"><div class="bw-card-head"><div><div class="bw-theme">'.e($r['theme']).'</div><h3>'.e($r['title']).'</h3></div><span class="bw-status '.e($statusClass).'">'.e($status).'</span></div><p>'.e($r['description']).'</p>';
        if(!empty($r['evidence_guidance'])) echo '<p class="bw-muted-line"><strong>Evidence guidance:</strong> '.e($r['evidence_guidance']).'</p>';
        if($memberComment || $reviewerComment){ echo '<div class="bw-comments">'; if($memberComment) echo '<p><strong>Your note:</strong><br>'.nl2br(e($memberComment)).'</p>'; if($reviewerComment) echo '<p><strong>Reviewer feedback:</strong><br><em>'.nl2br(e($reviewerComment)).'</em></p>'; echo '</div>'; }
        if(!$isComplete){ echo '<form class="bw-form" method="post" enctype="multipart/form-data">'.csrf_field().'<input type="hidden" name="submit_evidence" value="1"><input type="hidden" name="progress_id" value="'.e($r['id']).'"><label>Evidence notes / comments</label><textarea name="member_comment" placeholder="Add a short note for the reviewer">'.e($memberComment).'</textarea><label>Upload evidence</label><input type="file" name="evidence"><button>Submit evidence for approval</button></form>'; }
        else { echo '<p class="muted">This criterion has been approved. No further evidence is needed.</p>'; }
        echo '</section>';
    }
    echo '</div></section>';
    if(has_permission('review_brickworks_evidence')) echo '<div class="card brickworks-manager-card"><div class="toolbar"><div><h2>Reviewer tools</h2><p class="muted">Review submitted evidence and approve criteria.</p></div><a class="btn" href="?route=brickworks_manage">Open Brickworks management</a><a class="btn secondary" href="?route=brickworks_review">Pending only</a></div></div>';
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
        $oldProgress = first('SELECT * FROM brickworks_progress WHERE id=?',[$progressId]) ?: [];
        $oldAudit = ['status'=>$oldProgress['status'] ?? '', 'reviewer_comment'=>decrypt_value($oldProgress['reviewer_comment_encrypted'] ?? '') ?: '', 'completed_at'=>$oldProgress['completed_at'] ?? ''];
        $newAudit = ['status'=>$status, 'reviewer_comment'=>trim($_POST['reviewer_comment'] ?? ''), 'completed_at'=>$completedAt ?: ''];
        exec_sql('UPDATE brickworks_progress SET status=?, reviewer_comment_encrypted=?, completed_at=?, reviewed_by_user_id=?, reviewed_at=datetime("now"), updated_at=datetime("now") WHERE id=?',[$status,encrypt_value(trim($_POST['reviewer_comment'] ?? '')),$completedAt,$u['id'],$progressId]);
        audit('brickworks.status.update','brickworks_progress',$progressId,'success',null,['status'=>$status,'field_changes'=>audit_field_changes($oldAudit,$newAudit)]); flash('Brickworks progress updated.'); redirect('brickworks_manage');
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
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_csrf(); $pid=(int)$_POST['progress_id']; $status=$_POST['decision']==='approve'?'complete':'pending_approval'; $oldProgress=first('SELECT * FROM brickworks_progress WHERE id=?',[$pid]) ?: []; $oldAudit=['status'=>$oldProgress['status'] ?? '', 'reviewer_comment'=>decrypt_value($oldProgress['reviewer_comment_encrypted'] ?? '') ?: '', 'completed_at'=>$oldProgress['completed_at'] ?? '']; $newAudit=['status'=>$status, 'reviewer_comment'=>$_POST['reviewer_comment'] ?? '', 'completed_at'=>$status==='complete'?date('Y-m-d'):($oldProgress['completed_at'] ?? '')]; exec_sql('UPDATE brickworks_progress SET status=?, reviewer_comment_encrypted=?, completed_at=CASE WHEN ?="complete" THEN date("now") ELSE completed_at END, reviewed_by_user_id=?, reviewed_at=datetime("now"), updated_at=datetime("now") WHERE id=?',[$status,encrypt_value($_POST['reviewer_comment']??''),$status,$u['id'],$pid]); audit('brickworks.criteria.approve','brickworks_progress',$pid,'success',null,['decision'=>$_POST['decision'],'field_changes'=>audit_field_changes($oldAudit,$newAudit)]); flash('Review saved.'); redirect('brickworks_review'); }
    $rows=all('SELECT bp.*,bc.title,m.first_name,m.last_name,m.callsign FROM brickworks_progress bp JOIN brickworks_participants b ON b.id=bp.participant_id JOIN members m ON m.id=b.member_id JOIN brickworks_criteria bc ON bc.id=bp.criterion_id WHERE bp.status="pending_approval" ORDER BY bp.submitted_at');
    echo '<div class="card"><h1>Pending Brickworks evidence</h1><table><tr><th>Member</th><th>Criteria</th><th>Comment</th><th>Decision</th></tr>'; foreach($rows as $r) echo '<tr><td>'.e($r['first_name'].' '.$r['last_name'].' '.$r['callsign']).'</td><td>'.e($r['title']).'</td><td>'.e(decrypt_value($r['member_comment_encrypted'])).'</td><td><form method="post">'.csrf_field().'<input type="hidden" name="progress_id" value="'.e($r['id']).'"><textarea name="reviewer_comment" placeholder="Reviewer comment"></textarea><select name="decision"><option value="approve">Approve</option><option value="more">Request more evidence</option></select><button>Save review</button></form></td></tr>'; echo '</table></div>'; page_footer(); exit;
}

if (route() === 'emails') {
    require_permission('send_member_emails'); page_header('Emails'); audit('emails.view');
    $cfg = app_config();
    if ($_SERVER['REQUEST_METHOD']==='POST') { require_csrf();
        $recipientMode = $_POST['recipient_mode'] ?? 'selected';
        $selectedIds = array_values(array_filter(array_map('intval', $_POST['member_ids'] ?? [])));
        $recipientWhere = 'm.email IS NOT NULL AND m.email <> ""';
        $args = [];
        if ($recipientMode !== 'all') {
            if (!$selectedIds) { flash('No members selected. Select at least one member or choose Send all.'); redirect('emails'); }
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $recipientWhere .= ' AND m.id IN (' . $placeholders . ')';
            $args = $selectedIds;
        }
        $members=all('SELECT DISTINCT m.*,ep.allow_open_tracking FROM members m LEFT JOIN member_email_preferences ep ON ep.member_id=m.id WHERE '.$recipientWhere.' ORDER BY m.last_name,m.first_name', $args);
        if (!$members) { flash('No eligible recipients found. Selected members need an email address. Members without email remain visible but cannot be sent to.'); redirect('emails'); }
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
        $trackingGloballyEnabledForEmail = (($cfg['email_open_tracking_enabled'] ?? '1') === '1');
        foreach($members as $m){
            // Open/read tracking is controlled from Email system config.
            // It records image loads only, not guaranteed human reads.
            $track=$trackingGloballyEnabledForEmail ? 1 : 0;
            $tid=$track?bin2hex(random_bytes(24)):null;
            exec_sql('INSERT INTO email_recipients (email_id,member_id,email_address,recipient_name,tracking_enabled,tracking_id,status,created_at,updated_at) VALUES (?,?,?,?,?,?,"queued",datetime("now"),datetime("now"))',[$email_id,$m['id'],$m['email'],$m['first_name'].' '.$m['last_name'],$track,$tid]);
        }
        if (isset($_POST['send_now'])) {
            $recips=all('SELECT * FROM email_recipients WHERE email_id=?',[$email_id]);
            $emailAttachments=all('SELECT * FROM email_attachments WHERE email_id=? ORDER BY id ASC',[$email_id]);
            $fromAddress = trim($cfg['email_from_address'] ?: ('noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost')));
            $fromName = trim($cfg['email_from_name'] ?: ($cfg['society_name'] ?? 'Membership System'));
            $replyTo = email_reply_to_for_sender($u, $cfg, $fromAddress);
            $safeFromName = str_replace(["\r","\n"], '', $fromName);
            $safeFromAddress = str_replace(["\r","\n"], '', $fromAddress);
            $safeReplyTo = str_replace(["\r","\n"], '', $replyTo);
            $body=$_POST['body_html'];
            $base=rtrim($cfg['base_url'] ?: (((($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || isset($_SERVER['HTTPS'])) ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST']??'' ).dirname($_SERVER['SCRIPT_NAME'])), '/');
            $trackingGloballyEnabled = (($cfg['email_open_tracking_enabled'] ?? '1') === '1');
            $successCount = 0;
            $failCount = 0;
            $lastError = null;
            foreach ($recips as $r) {
                $recipientName = trim((string)($r['recipient_name'] ?? 'Member'));
                $personalBody = str_replace(['{member_name}','{{member_name}}'], e($recipientName), $body);
                if ($trackingGloballyEnabled && $r['tracking_enabled'] && $r['tracking_id']) {
                    $personalBody .= '<img src="'.$base.'/?route=email_open&id='.e($r['tracking_id']).'" width="1" height="1" style="display:none" alt="">';
                }
                if (count($recips) === 1) {
                    $send=send_configured_email($cfg, [$r['email_address']], [], $_POST['subject'], $personalBody, $safeFromName, $safeFromAddress, $safeReplyTo, $emailAttachments);
                    $statusOk='sent';
                } else {
                    // Privacy rule: when multiple members are selected, each member is sent an individual BCC email.
                    // This keeps recipient addresses hidden while still allowing a unique tracking pixel per recipient.
                    $send=send_configured_email($cfg, [$safeFromAddress], [$r['email_address']], $_POST['subject'], $personalBody, $safeFromName, $safeFromAddress, $safeReplyTo, $emailAttachments);
                    $statusOk='sent_bcc';
                }
                $sentOk=(bool)($send['ok'] ?? false);
                $sendError=$send['error'] ?? null;
                if ($sentOk) $successCount++; else { $failCount++; $lastError=$sendError; }
                exec_sql('UPDATE email_recipients SET status=?, sent_at=CASE WHEN ?=1 THEN datetime("now") ELSE sent_at END, failed_at=CASE WHEN ?=0 THEN datetime("now") ELSE failed_at END, failure_reason=CASE WHEN ?=0 THEN ? ELSE NULL END, updated_at=datetime("now") WHERE id=?',[$sentOk?$statusOk:'failed',$sentOk?1:0,$sentOk?1:0,$sentOk?1:0,$sendError,$r['id']]);
            }
            $overallStatus = $failCount === 0 ? 'sent' : ($successCount > 0 ? 'partial_failed' : 'failed');
            exec_sql('UPDATE emails SET status=?, sent_at=CASE WHEN ?=1 THEN datetime("now") ELSE sent_at END, updated_at=datetime("now") WHERE id=?',[$overallStatus,$successCount>0?1:0,$email_id]);
            audit('email.sent','email',$email_id,$successCount>0?'success':'failed',$lastError,['recipient_count'=>count($recips),'success_count'=>$successCount,'fail_count'=>$failCount,'bcc_used'=>count($recips)>1,'individual_bcc'=>count($recips)>1,'open_tracking_enabled'=>$trackingGloballyEnabled,'method'=>$cfg['email_method'] ?? 'php_mail','attachment_count'=>count($emailAttachments),'reply_to'=>$safeReplyTo]);
            flash('Email send attempted using '.(($cfg['email_method'] ?? 'php_mail') === 'resend' ? 'Resend API' : 'PHP mail').'. Multiple-recipient sends are sent as individual BCC emails so recipient addresses stay private and open tracking can work per member.'); redirect('emails');
        }
        audit('email.draft_created','email',$email_id,'success',null,['recipient_count'=>count($members),'recipient_mode'=>$recipientMode]);
        flash('Email draft created with recipients.'); redirect('emails');
    }
    $emails=all('SELECT * FROM emails ORDER BY created_at DESC LIMIT 25');
    $eligible=all('SELECT DISTINCT m.id,m.first_name,m.last_name,m.callsign,m.email,m.membership_status, CASE WHEN m.email IS NOT NULL AND m.email <> "" THEN 1 ELSE 0 END AS can_email, (SELECT sp.status FROM subscription_payments sp WHERE sp.member_id=m.id ORDER BY sp.subscription_year DESC, COALESCE(sp.payment_date, "") DESC, sp.id DESC LIMIT 1) AS latest_subs_status, EXISTS(SELECT 1 FROM users uu JOIN user_roles ur ON ur.user_id=uu.id JOIN roles rr ON rr.id=ur.role_id WHERE uu.member_id=m.id AND rr.name IN ("committee","chair","vice_chair","secretary","treasurer") AND (ur.expires_at IS NULL OR ur.expires_at > datetime("now"))) AS is_committee FROM members m ORDER BY m.last_name,m.first_name');
    $fromAddress = trim($cfg['email_from_address'] ?: ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    $fromName = trim($cfg['email_from_name'] ?: ($cfg['society_name'] ?? 'Membership System'));

    echo '<div class="email-app"><form method="post" enctype="multipart/form-data" id="emailComposeForm">'.csrf_field().'<input type="hidden" name="category" value="admin"><input type="hidden" name="recipient_mode" value="selected"><div class="email-top"><div class="email-title"><span class="email-icon">✉</span><strong>New Email</strong></div><div class="email-top-actions">';
    if (is_admin_user()) echo '<a class="email-config-btn" href="?route=email_config">Email system config</a>';
    echo '</div></div><div class="email-layout"><aside class="email-sidebar"><div class="email-recipient-head"><span class="email-people">☷</span><strong>Recipients</strong><span class="email-count" id="emailSelectedCount">0</span></div><div class="email-filter-bar"><button type="button" class="email-chip active" data-email-filter="all">All</button><button type="button" class="email-chip paid" data-email-filter="paid">Paid</button><button type="button" class="email-chip unpaid" data-email-filter="unpaid">Unpaid</button><button type="button" class="email-chip pending" data-email-filter="pending">Pending</button><button type="button" class="email-chip committee" data-email-filter="committee">Committee</button><button type="button" class="email-chip none" data-email-filter="none">None</button></div><div class="email-search-wrap"><input id="emailMemberSearch" class="email-search" placeholder="Search members..."></div><div class="email-member-list">';
    if(!$eligible) echo '<p class="email-empty">No member records found.</p>';
    foreach($eligible as $m){
        $subsStatus = strtolower(trim((string)($m['latest_subs_status'] ?: 'unpaid')));
        if (!in_array($subsStatus, ['paid','unpaid','pending','part-paid','part_paid','waived','refunded'], true)) $subsStatus = 'unpaid';
        $paymentGroup = str_contains($subsStatus, 'paid') && $subsStatus !== 'unpaid' ? 'paid' : ($subsStatus === 'pending' ? 'pending' : 'unpaid');
        $badgeText = $paymentGroup === 'paid' ? 'Paid' : ($paymentGroup === 'pending' ? 'Pending' : 'Unpaid');
        $name=e($m['first_name'].' '.$m['last_name']); $email=e($m['email'] ?: 'No email address'); $callsign=e($m['callsign'] ?: '');
        $canEmail = (int)($m['can_email'] ?? 0) === 1;
        $emailStatus = !$m['email'] ? 'No email' : 'Email available';
        $disabled = $canEmail ? '' : 'disabled';
        $disabledClass = $canEmail ? '' : ' disabled';
        echo '<label class="email-member-card'.$disabledClass.'" data-name="'.strtolower(e($m['first_name'].' '.$m['last_name'].' '.$m['email'].' '.$m['callsign'].' '.$m['membership_status'])).'" data-payment="'.e($paymentGroup).'" data-committee="'.((int)$m['is_committee'] ? '1' : '0').'" data-can-email="'.($canEmail?'1':'0').'"><input class="email-member-check" type="checkbox" name="member_ids[]" value="'.e($m['id']).'" '.$disabled.'><span class="email-check-ui">✓</span><span class="email-member-main"><strong>'.$name.'</strong>'.($callsign?'<span class="email-callsign">'.$callsign.'</span>':'').'<small>'.$email.' • '.e($m['membership_status'] ?: 'unknown').' • '.e($emailStatus).'</small></span><span class="email-badge '.e($paymentGroup).'">'.e($badgeText).'</span></label>';
    }
    echo '</div></aside><section class="email-compose"><div class="email-fields"><div class="email-row"><label>To:</label><input id="emailToSummary" value="0 members selected" readonly></div><div class="email-row"><label>From:</label><input value="'.e($fromName).' <'.e($fromAddress).'>" readonly></div><div class="email-row"><label>Reply-To:</label><input value="'.e(email_reply_to_for_sender($u, $cfg, $fromAddress)).'" readonly></div><div class="email-row"><label>Subject:</label><input name="subject" placeholder="Email subject..." required></div></div><div class="email-toolbar"><label class="email-attach-btn">📎 Attach<input type="file" name="attachment" id="emailAttachment"></label><span id="emailAttachName" class="email-help">Use <code>{member_name}</code> to personalise each email.</span></div><textarea class="email-message" name="body_html" placeholder="Write your message here... Use {member_name} to personalise for each recipient." required></textarea><div class="email-sendbar"><span class="muted">All member records are shown automatically. Members without an email address are visible but cannot be selected. No recipients are selected by default. Use All or filters to select recipients.</span><div><button class="secondary" type="submit">Save draft</button> <button name="send_now" value="1">Send now</button></div></div></section></div></form></div>';

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
    const selected = checks.filter(c => !c.disabled && c.checked).length;
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
            'email_open_tracking_enabled' => isset($_POST['email_open_tracking_enabled']) ? '1' : '0',
        ]);
        audit('email.config.update'); flash('Email configuration saved.'); redirect('email_config');
    }
    echo '<div class="card"><div class="toolbar"><h1 style="margin-right:auto">Email system config</h1><a class="btn secondary" href="?route=emails">Back to emails</a></div><p class="muted">Choose how the system sends email. PHP mail uses the server mail setup. Resend API sends via Resend using the API key below. SMTP fields are stored for future SMTP wiring.</p><form method="post">'.csrf_field().'<div class="two"><div><label>From name</label><input name="email_from_name" value="'.e($cfg['email_from_name']).'"></div><div><label>From email address</label><input type="email" name="email_from_address" value="'.e($cfg['email_from_address']).'" placeholder="noreply@example.org"></div><div><label>Reply-to email</label><input type="email" name="email_reply_to" value="'.e($cfg['email_reply_to']).'"></div><div><label>Mail method</label><select name="email_method"><option value="php_mail" '.($cfg['email_method']==='php_mail'?'selected':'').'>PHP mail()</option><option value="resend" '.($cfg['email_method']==='resend'?'selected':'').'>Resend API</option><option value="smtp" '.($cfg['email_method']==='smtp'?'selected':'').'>SMTP settings stored</option></select></div><div class="full"><label>Resend API key</label><input type="password" name="resend_api_key" value="'.e($cfg['resend_api_key'] ?? '').'" placeholder="re_..."><p class="muted small">Used only when Mail method is set to Resend API. The From email must be allowed/verified in your Resend account.</p></div><div class="full"><label><input type="checkbox" name="email_open_tracking_enabled" value="1" '.(($cfg['email_open_tracking_enabled'] ?? '1')==='1'?'checked':'').'> Enable open/read tracking pixels</label><p class="muted small">This records when a recipient loads the tiny tracking image. It is not guaranteed proof the email was read because some mail apps block or pre-load images.</p></div><div><label>SMTP host</label><input name="smtp_host" value="'.e($cfg['smtp_host']).'"></div><div><label>SMTP port</label><input name="smtp_port" value="'.e($cfg['smtp_port']).'"></div><div><label>SMTP security</label><select name="smtp_security"><option value="tls" '.($cfg['smtp_security']==='tls'?'selected':'').'>TLS</option><option value="ssl" '.($cfg['smtp_security']==='ssl'?'selected':'').'>SSL</option><option value="none" '.($cfg['smtp_security']==='none'?'selected':'').'>None</option></select></div><div><label>SMTP username</label><input name="smtp_username" value="'.e($cfg['smtp_username']).'"></div><div><label>SMTP password</label><input type="password" name="smtp_password" value="'.e($cfg['smtp_password']).'"></div></div><p><button>Save email config</button></p></form></div>';
    page_footer(); exit;
}

if (route() === 'audit') {
    require_permission('view_audit_logs'); audit('audit.view'); page_header('Audit Logs');
    $rows=all('SELECT a.*,u.email FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_user_id ORDER BY a.created_at DESC LIMIT 250');
    echo '<div class="card"><h1>Audit logs</h1><p class="muted">Change logs show old and new values where the system captured them. Treat this page as sensitive member data.</p><table><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Result</th><th>Reason</th><th>IP</th><th>Details</th></tr>'; foreach($rows as $r) echo '<tr><td>'.e($r['created_at']).'</td><td>'.e($r['email']).'</td><td>'.e($r['action']).'</td><td>'.e($r['entity_type'].' #'.$r['entity_id']).'</td><td>'.e($r['result']).'</td><td>'.e($r['reason']).'</td><td>'.e($r['ip_address']).'</td><td>'.audit_metadata_html($r['metadata']).'</td></tr>'; echo '</table></div>'; page_footer(); exit;
}


if (route() === 'gdpr_policy') {
    page_header('GDPR policy');
    echo '<div class="card"><h1>GDPR policy</h1><p class="muted">This page explains how the club membership system handles personal data.</p><h2>Data we hold</h2><p>The system may hold member contact details, callsign, membership status, subscription/payment history, attendance records, emergency contact details, consents, Brickworks progress, equipment loans, committee actions and audit logs.</p><h2>Why we hold it</h2><p>Data is used to administer membership, subscriptions, club events, equipment, Brickworks Scheme progress, safety/contact needs and society communications.</p><h2>Access control</h2><p>Members can view and update their own profile data. Only authorised users such as Admins and Member Database users can view or manage the full membership database. Committee users can access committee tools where their role allows it.</p><h2>Audit logging</h2><p>The system records important actions such as viewing, creating, editing, deleting and exporting data. Audit logs help protect members and support accountability.</p><h2>Member rights</h2><p>Members can ask the club to correct inaccurate data, provide a copy of their data, or review data held about them. Some records may need to be retained for legitimate club administration, accounting, safeguarding, dispute handling or legal reasons.</p></div>';
    page_footer(); exit;
}

if (route() === 'data_retention_policy') {
    page_header('Data retention policy');
    echo '<div class="card"><h1>Data retention policy</h1><p class="muted">This page summarises how long the club membership system should keep different types of records.</p><table><tr><th>Data type</th><th>Suggested retention</th><th>Notes</th></tr><tr><td>Active member profile data</td><td>While membership remains active</td><td>Reviewed and updated by the member or authorised administrators.</td></tr><tr><td>Former member contact data</td><td>Normally 1–2 years after leaving</td><td>Unless needed for outstanding administration, disputes or legal/accounting reasons.</td></tr><tr><td>Subscription/payment records</td><td>Normally up to 6 years</td><td>Useful for accounting and financial record keeping.</td></tr><tr><td>Attendance records</td><td>Normally up to 6 years</td><td>May be retained for club administration, reporting and safety records.</td></tr><tr><td>Equipment loan and maintenance records</td><td>Normally up to 6 years or life of asset</td><td>Supports asset history, maintenance and accountability.</td></tr><tr><td>Brickworks evidence and comments</td><td>While participating, then review after completion/leaving</td><td>Evidence should be removed when no longer needed.</td></tr><tr><td>Audit logs</td><td>Normally 3–6 years</td><td>Used for security, accountability and incident investigation.</td></tr></table><p>This is a default system policy page and should be reviewed by the club committee before relying on it as the official club policy.</p></div>';
    page_footer(); exit;
}

page_header('Not found'); echo '<div class="card"><h1>Page not found</h1></div>'; page_footer();
