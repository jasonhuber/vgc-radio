<?php
declare(strict_types=1);

// N7WGP radio API — single-file router backed by SQLite.
//
// Routes (all under /api):
//   GET    /api                  — discovery (self-describing)
//   GET    /api/health           — liveness + db status
//   POST   /api/auth/redeem      — redeem an invite, create an account
//   POST   /api/auth/login       — email + password -> session token
//   POST   /api/auth/logout      — revoke the calling token          (Bearer)
//   GET    /api/auth/me          — validate a cached token           (Bearer)
//   GET    /api/coverage         — pull this account's coverage log  (Bearer)
//   POST   /api/coverage         — push sessions/rows/track          (Bearer)
//   DELETE /api/coverage         — delete one session, or all        (Bearer)
//   POST   /api/check-list       — AI review of an imported channel list (Bearer)
//   POST   /api/admin/invite     — mint invite codes         (admin Bearer)
//   GET    /api/admin/users      — list accounts             (admin Bearer)
//   DELETE /api/admin/users      — delete an account + its data (admin Bearer)
//
// Registration is INVITE ONLY. There is no open signup route, by design:
// every account can spend model tokens through the prompt proxy.
//
// Storage is a single SQLite file in RADIO_DATA_DIR. No server, no Docker.

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// CORS + baseline headers
// ---------------------------------------------------------------------------

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, RADIO_ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------------------------------------------------------------------------
// Response helpers
// ---------------------------------------------------------------------------

function r_send_json(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function r_error(string $message, int $code = 400, array $extra = []): never {
    r_send_json(['ok' => false, 'error' => $message] + $extra, $code);
}

function r_now_iso(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

/** Decoded JSON body, or [] for an empty body. Rejects anything not an object. */
function r_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) r_error('Body must be a JSON object.', 400);
    return $data;
}

function r_client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------

function r_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (!is_dir(RADIO_DATA_DIR) && !@mkdir(RADIO_DATA_DIR, 0770, true) && !is_dir(RADIO_DATA_DIR)) {
        r_error('Data directory is not writable.', 500);
    }
    try {
        $pdo = new PDO('sqlite:' . RADIO_DATA_DIR . '/radio.db', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        r_error('Database unavailable.', 500);
    }
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS users (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            email       TEXT NOT NULL UNIQUE COLLATE NOCASE,
            pass_hash   TEXT NOT NULL,
            callsign    TEXT NOT NULL DEFAULT "",
            created_at  TEXT NOT NULL,
            invite_code TEXT
        )');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS invites (
            code       TEXT PRIMARY KEY,
            note       TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL,
            used_by    INTEGER,
            used_at    TEXT
        )');
    /* Session tokens are stored hashed. A database copy must not be usable
       as a set of live logins. */
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS sessions (
            token_hash TEXT PRIMARY KEY,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL,
            last_seen  TEXT NOT NULL,
            expires_at TEXT NOT NULL
        )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS ix_sessions_user ON sessions(user_id)');

    /* Coverage. Everything is scoped by user_id -- there is no query in this
       file that reads a coverage row without one. */
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS cov_sessions (
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            sid        INTEGER NOT NULL,
            t0         INTEGER NOT NULL,
            t1         INTEGER,
            label      TEXT NOT NULL DEFAULT "",
            antenna    TEXT NOT NULL DEFAULT "",
            radio      TEXT NOT NULL DEFAULT "",
            client_updated INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (user_id, sid)
        )');
    /* Additive migration for databases created before session equipment tags
       and deterministic cross-device conflict resolution shipped. */
    $covSessionCols = array_column($pdo->query('PRAGMA table_info(cov_sessions)')->fetchAll(), 'name');
    if (!in_array('antenna', $covSessionCols, true))
        $pdo->exec('ALTER TABLE cov_sessions ADD COLUMN antenna TEXT NOT NULL DEFAULT ""');
    if (!in_array('radio', $covSessionCols, true))
        $pdo->exec('ALTER TABLE cov_sessions ADD COLUMN radio TEXT NOT NULL DEFAULT ""');
    if (!in_array('client_updated', $covSessionCols, true))
        $pdo->exec('ALTER TABLE cov_sessions ADD COLUMN client_updated INTEGER NOT NULL DEFAULT 0');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS cov_rows (
            user_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            rid      TEXT NOT NULL,
            sid      INTEGER NOT NULL,
            payload  TEXT NOT NULL,
            PRIMARY KEY (user_id, rid)
        )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS ix_rows_sid ON cov_rows(user_id, sid)');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS cov_track (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            sid     INTEGER NOT NULL,
            t       INTEGER NOT NULL,
            lat     REAL NOT NULL,
            lon     REAL NOT NULL,
            acc     INTEGER,
            PRIMARY KEY (user_id, sid, t)
        )');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS rate (
            bucket  TEXT NOT NULL,
            key     TEXT NOT NULL,
            at      TEXT NOT NULL
        )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS ix_rate ON rate(bucket, key, at)');

    return $pdo;
}

// ---------------------------------------------------------------------------
// Rate limiting
// ---------------------------------------------------------------------------

/** Count hits in the window and record this one. Returns the count INCLUDING it. */
function r_rate_hit(string $bucket, string $key, int $windowSeconds): int {
    $db = r_db();
    $cutoff = (new DateTimeImmutable("-{$windowSeconds} seconds", new DateTimeZone('UTC')))
        ->format('Y-m-d\TH:i:s\Z');
    $db->prepare('DELETE FROM rate WHERE at < ?')->execute([$cutoff]);
    $st = $db->prepare('SELECT COUNT(*) c FROM rate WHERE bucket = ? AND key = ? AND at >= ?');
    $st->execute([$bucket, $key, $cutoff]);
    $n = (int)($st->fetch()['c'] ?? 0);
    $db->prepare('INSERT INTO rate (bucket, key, at) VALUES (?, ?, ?)')
       ->execute([$bucket, $key, r_now_iso()]);
    return $n + 1;
}

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

/** Pull the Bearer token out of the request, accommodating Apache quirks. */
function r_bearer_token(): ?string {
    $sources = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
    ];
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) $sources[] = $v;
        }
    }
    foreach ($sources as $value) {
        if (is_string($value) && preg_match('/^\s*Bearer\s+(.+?)\s*$/i', $value, $m)) {
            return $m[1];
        }
    }
    return null;
}

function r_token_hash(string $token): string {
    return hash('sha256', RADIO_TOKEN_PEPPER . $token);
}

/** The authenticated user row, or a 401. */
function r_require_user(): array {
    $tok = r_bearer_token();
    if ($tok === null || $tok === '') r_error('Sign in to use this.', 401);
    $db = r_db();
    $st = $db->prepare('
        SELECT u.id, u.email, u.callsign, s.expires_at
        FROM sessions s JOIN users u ON u.id = s.user_id
        WHERE s.token_hash = ?');
    $st->execute([r_token_hash($tok)]);
    $row = $st->fetch();
    if (!$row) r_error('Session is not valid. Sign in again.', 401);
    if ($row['expires_at'] < r_now_iso()) {
        $db->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([r_token_hash($tok)]);
        r_error('Session expired. Sign in again.', 401);
    }
    $db->prepare('UPDATE sessions SET last_seen = ? WHERE token_hash = ?')
       ->execute([r_now_iso(), r_token_hash($tok)]);
    return $row;
}

function r_require_admin(): void {
    if (RADIO_ADMIN_TOKEN === 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET' || RADIO_ADMIN_TOKEN === '') {
        r_error('Admin token is not configured.', 500);
    }
    $tok = r_bearer_token();
    if ($tok === null || !hash_equals(RADIO_ADMIN_TOKEN, $tok)) r_error('Not authorised.', 401);
}

function r_issue_token(int $userId): array {
    $token = bin2hex(random_bytes(32));
    $expires = (new DateTimeImmutable('+' . RADIO_SESSION_DAYS . ' days', new DateTimeZone('UTC')))
        ->format('Y-m-d\TH:i:s\Z');
    r_db()->prepare('
        INSERT INTO sessions (token_hash, user_id, created_at, last_seen, expires_at)
        VALUES (?, ?, ?, ?, ?)')
        ->execute([r_token_hash($token), $userId, r_now_iso(), r_now_iso(), $expires]);
    return ['token' => $token, 'expires_at' => $expires];
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($base !== '' && $base !== '/' && str_starts_with($uri, $base)) {
    $uri = substr($uri, strlen($base));
}
$sub = trim($uri, '/');

$scheme = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$prefix = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($base ?: '');

// ---- GET / — discovery ----------------------------------------------------
if ($method === 'GET' && $sub === '') {
    r_send_json([
        'name'        => 'N7WGP radio API',
        'description' => 'Accounts, coverage-log storage and AI channel-list review for the VGC/Benshi radio programmer at n7wgp.com.',
        'version'     => '1.0.0',
        'registration'=> 'invite only',
        'endpoints'   => [
            'discovery'  => $prefix,
            'health'     => $prefix . '/health',
            'redeem'     => $prefix . '/auth/redeem',
            'login'      => $prefix . '/auth/login',
            'logout'     => $prefix . '/auth/logout',
            'me'         => $prefix . '/auth/me',
            'coverage'   => $prefix . '/coverage',
            'check_list' => $prefix . '/check-list',
        ],
        'auth' => [
            'scheme' => 'Bearer',
            'header' => 'Authorization: Bearer <token>',
            'public' => ['GET /api', 'GET /api/health', 'POST /api/auth/login', 'POST /api/auth/redeem'],
        ],
    ]);
}

// ---- GET /health ----------------------------------------------------------
if ($method === 'GET' && $sub === 'health') {
    $users = null; $ok = true;
    try { $users = (int)(r_db()->query('SELECT COUNT(*) c FROM users')->fetch()['c'] ?? 0); }
    catch (Throwable $e) { $ok = false; }
    r_send_json(['ok' => $ok, 'time' => r_now_iso(), 'db' => $ok ? 'up' : 'down', 'users' => $users],
        $ok ? 200 : 500);
}

// ---- POST /auth/redeem ----------------------------------------------------
if ($method === 'POST' && $sub === 'auth/redeem') {
    if (r_rate_hit('redeem', r_client_ip(), 900) > RADIO_LOGIN_ATTEMPTS) {
        r_error('Too many attempts. Wait a few minutes.', 429);
    }
    $b        = r_body();
    $code     = trim((string)($b['invite'] ?? ''));
    $email    = strtolower(trim((string)($b['email'] ?? '')));
    $password = (string)($b['password'] ?? '');
    $callsign = strtoupper(trim((string)($b['callsign'] ?? '')));

    if ($code === '')                                   r_error('An invite code is required.', 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))     r_error('That email address is not valid.', 400);
    if (strlen($password) < 10)                         r_error('Use a password of at least 10 characters.', 400);
    if (strlen($password) > 200)                        r_error('That password is too long.', 400);
    if (strlen($callsign) > 12)                         r_error('That callsign is too long.', 400);

    $db = r_db();
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT code, used_by FROM invites WHERE code = ?');
        $st->execute([$code]);
        $inv = $st->fetch();
        if (!$inv || $inv['used_by'] !== null) {
            $db->rollBack();
            r_error('That invite code is not valid or has already been used.', 403);
        }
        $st = $db->prepare('SELECT id FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetch()) {
            $db->rollBack();
            r_error('An account already exists for that email.', 409);
        }
        $db->prepare('INSERT INTO users (email, pass_hash, callsign, created_at, invite_code)
                      VALUES (?, ?, ?, ?, ?)')
           ->execute([$email, password_hash($password, PASSWORD_DEFAULT), $callsign, r_now_iso(), $code]);
        $uid = (int)$db->lastInsertId();
        $db->prepare('UPDATE invites SET used_by = ?, used_at = ? WHERE code = ?')
           ->execute([$uid, r_now_iso(), $code]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        r_error('Could not create the account.', 500);
    }
    $tok = r_issue_token($uid);
    r_send_json(['ok' => true, 'user' => ['email' => $email, 'callsign' => $callsign]] + $tok, 201);
}

// ---- POST /auth/login -----------------------------------------------------
if ($method === 'POST' && $sub === 'auth/login') {
    if (r_rate_hit('login', r_client_ip(), 900) > RADIO_LOGIN_ATTEMPTS) {
        r_error('Too many attempts. Wait a few minutes.', 429);
    }
    $b        = r_body();
    $email    = strtolower(trim((string)($b['email'] ?? '')));
    $password = (string)($b['password'] ?? '');

    $st = r_db()->prepare('SELECT id, email, pass_hash, callsign FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();

    /* Always run a verify, even with no such user, so response timing does not
       reveal which emails have accounts. The message is identical either way. */
    $hash = $u['pass_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin';
    $good = password_verify($password, $hash);
    if (!$u || !$good) r_error('That email and password do not match.', 401);

    $tok = r_issue_token((int)$u['id']);
    r_send_json(['ok' => true,
        'user' => ['email' => $u['email'], 'callsign' => $u['callsign']]] + $tok);
}

// ---- POST /auth/logout ----------------------------------------------------
if ($method === 'POST' && $sub === 'auth/logout') {
    $tok = r_bearer_token();
    if ($tok !== null && $tok !== '') {
        r_db()->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([r_token_hash($tok)]);
    }
    r_send_json(['ok' => true]);
}

// ---- GET /auth/me ---------------------------------------------------------
if ($method === 'GET' && $sub === 'auth/me') {
    $u = r_require_user();
    r_send_json(['ok' => true,
        'user' => ['email' => $u['email'], 'callsign' => $u['callsign']],
        'expires_at' => $u['expires_at']]);
}

// ---- GET /coverage --------------------------------------------------------
if ($method === 'GET' && $sub === 'coverage') {
    $u   = r_require_user();
    $uid = (int)$u['id'];
    $db  = r_db();

    $st = $db->prepare('SELECT sid, t0, t1, label, antenna, radio, client_updated
                        FROM cov_sessions WHERE user_id = ? ORDER BY t0');
    $st->execute([$uid]);
    $sessions = array_map(static fn(array $r): array => [
        'id'    => (int)$r['sid'],
        't0'    => (int)$r['t0'],
        't1'    => $r['t1'] === null ? null : (int)$r['t1'],
        'label' => (string)$r['label'],
        'antenna' => (string)$r['antenna'],
        'radio' => (string)$r['radio'],
        'updated' => (int)$r['client_updated'],
    ], $st->fetchAll());

    $st = $db->prepare('SELECT payload FROM cov_rows WHERE user_id = ?');
    $st->execute([$uid]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $decoded = json_decode((string)$r['payload'], true);
        if (is_array($decoded)) $rows[] = $decoded;
    }
    usort($rows, static fn(array $a, array $b): int => strcmp((string)($a['t'] ?? ''), (string)($b['t'] ?? '')));

    $st = $db->prepare('SELECT sid, t, lat, lon, acc FROM cov_track WHERE user_id = ? ORDER BY sid, t');
    $st->execute([$uid]);
    $track = array_map(static fn(array $r): array => [
        'sid' => (int)$r['sid'], 't' => (int)$r['t'],
        'lat' => (float)$r['lat'], 'lon' => (float)$r['lon'],
        'acc' => $r['acc'] === null ? null : (int)$r['acc'],
    ], $st->fetchAll());

    r_send_json(['ok' => true, 'sessions' => $sessions, 'rows' => $rows, 'track' => $track]);
}

// ---- POST /coverage -------------------------------------------------------
if ($method === 'POST' && $sub === 'coverage') {
    $u   = r_require_user();
    $uid = (int)$u['id'];
    $b   = r_body();
    $db  = r_db();

    $sessions = is_array($b['sessions'] ?? null) ? $b['sessions'] : [];
    $rows     = is_array($b['rows']     ?? null) ? $b['rows']     : [];
    $track    = is_array($b['track']    ?? null) ? $b['track']    : [];

    if (count($sessions) > 2000 || count($rows) > 20000 || count($track) > 40000) {
        r_error('That is more than one push can carry. Sync more often.', 413);
    }

    $nS = $nR = $nT = 0;
    $db->beginTransaction();
    try {
        $ins = $db->prepare('
            INSERT INTO cov_sessions (user_id, sid, t0, t1, label, antenna, radio, client_updated, updated_at)
            VALUES (:u, :sid, :t0, :t1, :label, :antenna, :radio, :client_up, :up)
            ON CONFLICT(user_id, sid) DO UPDATE SET
                t1 = excluded.t1, label = excluded.label, antenna = excluded.antenna,
                radio = excluded.radio, client_updated = excluded.client_updated,
                updated_at = excluded.updated_at
            WHERE excluded.client_updated >= cov_sessions.client_updated');
        foreach ($sessions as $s) {
            if (!is_array($s) || !isset($s['id'])) continue;
            $ins->execute([
                ':u' => $uid, ':sid' => (int)$s['id'], ':t0' => (int)($s['t0'] ?? 0),
                ':t1' => isset($s['t1']) && $s['t1'] !== null ? (int)$s['t1'] : null,
                ':label' => substr((string)($s['label'] ?? ''), 0, 60),
                ':antenna' => substr((string)($s['antenna'] ?? ''), 0, 60),
                ':radio' => substr((string)($s['radio'] ?? ''), 0, 80),
                ':client_up' => (int)($s['updated'] ?? $s['t1'] ?? $s['t0'] ?? 0),
                ':up' => r_now_iso(),
            ]);
            $nS++;
        }

        /* Rows are stored whole as JSON: the client owns their shape, and a
           schema change there must not need a migration here. The natural key
           makes a re-push idempotent rather than duplicating the log. */
        $insR = $db->prepare('
            INSERT INTO cov_rows (user_id, rid, sid, payload) VALUES (:u, :rid, :sid, :p)
            ON CONFLICT(user_id, rid) DO UPDATE SET payload = excluded.payload');
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $sid = (int)($r['sid'] ?? 0);
            $rid = $sid . '|' . (string)($r['t'] ?? '') . '|' . (string)($r['g'] ?? '')
                 . '|' . (string)($r['ch'] ?? '') . '|' . (string)($r['kind'] ?? '');
            $json = json_encode($r, JSON_UNESCAPED_SLASHES);
            if ($json === false || strlen($json) > 4000) continue;
            $insR->execute([':u' => $uid, ':rid' => $rid, ':sid' => $sid, ':p' => $json]);
            $nR++;
        }

        $insT = $db->prepare('
            INSERT INTO cov_track (user_id, sid, t, lat, lon, acc) VALUES (:u, :sid, :t, :la, :lo, :a)
            ON CONFLICT(user_id, sid, t) DO NOTHING');
        foreach ($track as $p) {
            if (!is_array($p) || !isset($p['lat'], $p['lon'])) continue;
            $insT->execute([
                ':u' => $uid, ':sid' => (int)($p['sid'] ?? 0), ':t' => (int)($p['t'] ?? 0),
                ':la' => (float)$p['lat'], ':lo' => (float)$p['lon'],
                ':a' => isset($p['acc']) && $p['acc'] !== null ? (int)$p['acc'] : null,
            ]);
            $nT++;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        r_error('Could not save the coverage log.', 500);
    }
    r_send_json(['ok' => true, 'stored' => ['sessions' => $nS, 'rows' => $nR, 'track' => $nT]]);
}

// ---- DELETE /coverage -----------------------------------------------------
if ($method === 'DELETE' && $sub === 'coverage') {
    $u   = r_require_user();
    $uid = (int)$u['id'];
    $db  = r_db();
    $sid = isset($_GET['session']) ? (int)$_GET['session'] : 0;

    if ($sid > 0) {
        $db->prepare('DELETE FROM cov_rows     WHERE user_id = ? AND sid = ?')->execute([$uid, $sid]);
        $db->prepare('DELETE FROM cov_track    WHERE user_id = ? AND sid = ?')->execute([$uid, $sid]);
        $db->prepare('DELETE FROM cov_sessions WHERE user_id = ? AND sid = ?')->execute([$uid, $sid]);
    } elseif (($_GET['all'] ?? '') === '1') {
        $db->prepare('DELETE FROM cov_rows     WHERE user_id = ?')->execute([$uid]);
        $db->prepare('DELETE FROM cov_track    WHERE user_id = ?')->execute([$uid]);
        $db->prepare('DELETE FROM cov_sessions WHERE user_id = ?')->execute([$uid]);
    } else {
        r_error('Pass ?session=<id> or ?all=1.', 400);
    }
    r_send_json(['ok' => true]);
}

// ---- POST /check-list -----------------------------------------------------
if ($method === 'POST' && $sub === 'check-list') {
    $u   = r_require_user();
    $uid = (int)$u['id'];

    if (r_rate_hit('check', (string)$uid, 86400) > RADIO_CHECKS_PER_DAY) {
        r_error('Daily limit for list checks reached. Try again tomorrow.', 429);
    }

    $b = r_body();
    $channels = is_array($b['channels'] ?? null) ? $b['channels'] : [];
    if (!$channels) r_error('Send a "channels" array to check.', 400);
    if (count($channels) > RADIO_CHECK_MAX_CHANNELS) {
        r_error('That list is longer than ' . RADIO_CHECK_MAX_CHANNELS . ' channels. Filter it down first.', 413);
    }

    /* Only the fields worth checking are forwarded -- no names of people, no
       positions, nothing about the account. */
    $lines = [];
    foreach ($channels as $i => $c) {
        if (!is_array($c)) continue;
        $lines[] = json_encode([
            'i'    => (int)$i,
            'name' => substr((string)($c['name'] ?? ''), 0, 16),
            'rx'   => isset($c['rx']) ? round((float)$c['rx'], 5) : null,
            'tx'   => isset($c['tx']) ? round((float)$c['tx'], 5) : null,
            'txt'  => (string)($c['txt'] ?? ''),
            'rxt'  => (string)($c['rxt'] ?? ''),
            'bw'   => (string)($c['bw'] ?? ''),
            'pwr'  => (string)($c['pwr'] ?? ''),
        ], JSON_UNESCAPED_SLASHES);
    }

    $system = <<<'SYS'
You are a ham radio engineer reviewing a channel list before it is written to a handheld transceiver.
You are given one JSON object per channel with: i (index), name, rx and tx frequencies in MHz, txt and rxt (CTCSS in Hz, "Dnnn" for DCS, or empty), bw ("wide"/"narrow"), pwr.

Check each channel for problems that would make it fail on the air or fail to program:
- rx or tx outside any amateur, GMRS/FRS, MURS or marine allocation, or outside 136-174 / 400-520 MHz entirely
- a repeater offset that does not match the standard plan for its band (2m: 600 kHz, 70cm: 5 MHz in the US; GMRS: 5 MHz), or an offset in the wrong direction for the sub-band
- a simplex channel that carries a tone it does not need, or a repeater input with no tone at all where one is conventional
- wide bandwidth on an allocation that requires narrow (GMRS 462.5625-462.7250 interstitials, MURS above 151.940, most 12.5 kHz plans)
- high power on a channel where it is not permitted (FRS, GMRS interstitials, 2m calling)
- a name longer than 10 characters, which this radio truncates
- duplicate rx/tx pairs within the list
- values that look like a data-entry slip: transposed digits, a tone of 88.5 where 100.0 is standard for that machine, tx and rx swapped

Report only real problems. Do not invent repeater callsigns or claim to know whether a specific machine exists. If a channel is fine, omit it.

Reply with STRICT JSON and nothing else, in this exact shape:
{"findings":[{"i":<channel index>,"severity":"error"|"warning"|"note","field":"rx"|"tx"|"tone"|"bandwidth"|"power"|"name"|"duplicate","message":"<one sentence, plain language>","suggest":"<the corrected value, or empty>"}]}
SYS;

    $user = "Channels to review:\n" . implode("\n", $lines);

    $payload = json_encode([
        'systemPrompt'      => $system,
        'userPrompt'        => $user,
        'preferredProvider' => RADIO_PROXY_PROVIDER,
    ], JSON_UNESCAPED_SLASHES);

    /* A model round-trip routinely runs past PHP's default 30s limit, and the
       default kills the request mid-flight with a fatal rather than an error
       the browser can act on. Ask for the headroom the curl timeout needs. */
    if (function_exists('set_time_limit')) @set_time_limit(120);

    $ch = curl_init(RADIO_PROXY_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    /* No curl_close(): it is a no-op since PHP 8.0 and deprecated in 8.5, and
       on a host with display_errors on the notice would land in the JSON. */

    if ($res === false)  r_error('Could not reach the model proxy: ' . $cerr, 502);
    if ($code < 200 || $code >= 300) {
        r_error('The model proxy returned ' . $code . '.', 502,
            ['detail' => substr((string)$res, 0, 400), 'provider' => RADIO_PROXY_PROVIDER]);
    }

    $outer  = json_decode((string)$res, true);
    $text   = is_array($outer) ? (string)($outer['text'] ?? '') : '';
    /* The worker reports which provider it actually used. It falls back
       silently when the requested one is not configured, so this is the only
       way to know the answer came from the model that was asked for. */
    $source = is_array($outer) ? (string)($outer['source'] ?? '') : '';
    if ($text === '') r_error('The model proxy returned no text.', 502,
        ['detail' => substr((string)$res, 0, 400)]);

    /* Models like to wrap JSON in prose or a code fence. Take the outermost
       braces rather than trusting the reply to be clean. */
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    $parsed = ($start !== false && $end !== false && $end > $start)
        ? json_decode(substr($text, $start, $end - $start + 1), true) : null;

    if (!is_array($parsed) || !is_array($parsed['findings'] ?? null)) {
        r_error('The model did not return usable JSON.', 502, ['raw' => substr($text, 0, 600)]);
    }

    $clean = [];
    foreach ($parsed['findings'] as $f) {
        if (!is_array($f)) continue;
        $sev = (string)($f['severity'] ?? 'note');
        $clean[] = [
            'i'        => (int)($f['i'] ?? -1),
            'severity' => in_array($sev, ['error', 'warning', 'note'], true) ? $sev : 'note',
            'field'    => substr((string)($f['field'] ?? ''), 0, 20),
            'message'  => substr((string)($f['message'] ?? ''), 0, 300),
            'suggest'  => substr((string)($f['suggest'] ?? ''), 0, 60),
        ];
    }
    r_send_json([
        'ok'        => true,
        'checked'   => count($lines),
        'findings'  => $clean,
        'requested' => RADIO_PROXY_PROVIDER,
        'source'    => $source,          // what actually answered
    ]);
}

// ---- POST /admin/invite ---------------------------------------------------
if ($method === 'POST' && $sub === 'admin/invite') {
    r_require_admin();
    $b     = r_body();
    $count = max(1, min(25, (int)($b['count'] ?? 1)));
    $note  = substr((string)($b['note'] ?? ''), 0, 120);

    $db  = r_db();
    $out = [];
    $st  = $db->prepare('INSERT INTO invites (code, note, created_at) VALUES (?, ?, ?)');
    for ($i = 0; $i < $count; $i++) {
        /* Ambiguity-free alphabet: no O/0, no I/1/l. These get read aloud. */
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($j = 0; $j < 12; $j++) {
            $code .= $abc[random_int(0, strlen($abc) - 1)];
            if ($j === 3 || $j === 7) $code .= '-';
        }
        $st->execute([$code, $note, r_now_iso()]);
        $out[] = $code;
    }
    r_send_json(['ok' => true, 'invites' => $out], 201);
}

// ---- GET /admin/invite — list what has been minted -------------------------
if ($method === 'GET' && $sub === 'admin/invite') {
    r_require_admin();
    $rows = r_db()->query('
        SELECT i.code, i.note, i.created_at, i.used_at, u.email AS used_by
        FROM invites i LEFT JOIN users u ON u.id = i.used_by
        ORDER BY i.created_at DESC')->fetchAll();
    r_send_json(['ok' => true, 'invites' => $rows]);
}

// ---- GET /admin/users -----------------------------------------------------
if ($method === 'GET' && $sub === 'admin/users') {
    r_require_admin();
    $rows = r_db()->query('
        SELECT u.id, u.email, u.callsign, u.created_at,
               (SELECT COUNT(*) FROM cov_rows     r WHERE r.user_id = u.id) AS receptions,
               (SELECT COUNT(*) FROM cov_sessions c WHERE c.user_id = u.id) AS sessions
        FROM users u ORDER BY u.created_at')->fetchAll();
    r_send_json(['ok' => true, 'users' => $rows]);
}

// ---- DELETE /admin/users?email=... ----------------------------------------
if ($method === 'DELETE' && $sub === 'admin/users') {
    r_require_admin();
    $email = strtolower(trim((string)($_GET['email'] ?? '')));
    if ($email === '') r_error('Pass ?email=<address>.', 400);

    $db = r_db();
    $st = $db->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u) r_error('No account with that email.', 404);
    $uid = (int)$u['id'];

    /* Delete the data explicitly rather than leaning on cascade -- this is the
       one destructive route in the file, and it should be obvious on the page
       exactly what it removes. */
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM cov_rows     WHERE user_id = ?')->execute([$uid]);
        $db->prepare('DELETE FROM cov_track    WHERE user_id = ?')->execute([$uid]);
        $db->prepare('DELETE FROM cov_sessions WHERE user_id = ?')->execute([$uid]);
        $db->prepare('DELETE FROM sessions     WHERE user_id = ?')->execute([$uid]);
        /* Free the invite so it can be handed out again. */
        $db->prepare('UPDATE invites SET used_by = NULL, used_at = NULL WHERE used_by = ?')
           ->execute([$uid]);
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        r_error('Could not delete that account.', 500);
    }
    r_send_json(['ok' => true, 'deleted' => $email]);
}

r_error('No such endpoint.', 404, ['path' => $sub]);
