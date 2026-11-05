<?php
// Manpower Planner server. Upload next to index.html. Needs PHP 7.4+ with the openssl extension.
const DATA_DIR = __DIR__ . '/planner-data';
const SU_USER = 'waaa';
const SU_START = '321564';
const ADMIN_START = '123456';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'planner') out(['err' => 'bad'], 400);

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0700, true);
    @file_put_contents(DATA_DIR . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    @file_put_contents(DATA_DIR . '/index.html', '');
}
if (!is_dir(DATA_DIR . '/sessions')) @mkdir(DATA_DIR . '/sessions', 0700, true);
if (!is_writable(DATA_DIR)) out(['err' => 'server', 'msg' => 'The server cannot write to the planner-data folder.'], 500);

ini_set('session.gc_maxlifetime', '2592000');
session_save_path(DATA_DIR . '/sessions');
session_name('plannersid');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

$in = json_decode(file_get_contents('php://input', false, null, 0, 2000000) ?: '[]', true);
if (!is_array($in)) $in = [];
$a = (string)($in['a'] ?? '');

function skey() {
    $p = DATA_DIR . '/secret.key';
    if (!is_file($p)) file_put_contents($p, bin2hex(random_bytes(32)));
    return hex2bin(trim(file_get_contents($p)));
}
function enc($p) {
    $iv = random_bytes(12);
    $c = openssl_encrypt((string)$p, 'aes-256-gcm', skey(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $c);
}
function dec($b) {
    $r = base64_decode((string)$b);
    $p = openssl_decrypt(substr($r, 28), 'aes-256-gcm', skey(), OPENSSL_RAW_DATA, substr($r, 0, 12), substr($r, 12, 16));
    return $p === false ? '' : $p;
}
function fresh() {
    return ['rev' => 1, 'theme' => 'midnight', 'people' => [], 'days' => [], 'pw' => [],
        'adm' => ['p' => enc(ADMIN_START)], 'su' => ['h' => password_hash(SU_START, PASSWORD_DEFAULT)]];
}
function st(callable $fn) {
    $f = fopen(DATA_DIR . '/state.json', 'c+');
    flock($f, LOCK_EX);
    $raw = stream_get_contents($f);
    $s = $raw ? json_decode($raw, true) : null;
    if (!is_array($s)) $s = fresh();
    $ch = false;
    $res = $fn($s, $ch);
    if ($ch || !$raw) { rewind($f); ftruncate($f, 0); fwrite($f, json_encode($s, JSON_UNESCAPED_UNICODE)); fflush($f); }
    flock($f, LOCK_UN);
    fclose($f);
    return $res;
}
function att(callable $fn) {
    $h = fopen(DATA_DIR . '/att.json', 'c+');
    flock($h, LOCK_EX);
    $d = json_decode(stream_get_contents($h) ?: '{}', true);
    if (!is_array($d)) $d = [];
    $r = $fn($d);
    rewind($h); ftruncate($h, 0); fwrite($h, json_encode($d)); fflush($h);
    flock($h, LOCK_UN); fclose($h);
    return $r;
}
function pub($s) {
    return ['rev' => $s['rev'], 'theme' => $s['theme'], 'people' => array_values($s['people']), 'days' => (object)$s['days']];
}
function cut($x, $n) {
    $x = preg_replace('/[\x00-\x1f]/u', '', trim((string)$x));
    if ($x === null) return '';
    return function_exists('mb_substr') ? mb_substr($x, 0, $n) : substr($x, 0, $n);
}
function vid($x) { return is_string($x) && preg_match('/^[a-z0-9]{1,16}$/', $x) ? $x : null; }

function clean($d) {
    if (!is_array($d) || !isset($d['people'], $d['days']) || !is_array($d['people']) || !is_array($d['days'])) return null;
    $people = []; $ids = [];
    foreach ($d['people'] as $p) {
        if (!is_array($p)) continue;
        $id = vid($p['id'] ?? null); $n = cut($p['name'] ?? '', 60); $r = $p['role'] ?? '';
        if (!$id || $n === '' || isset($ids[$id]) || !in_array($r, ['engineer', 'technician', 'cleaner'], true)) continue;
        $l = $p['lic'] ?? '';
        if ($r !== 'engineer' || !in_array($l, ['B1', 'B2', 'B1/B2'], true)) $l = '';
        $people[] = ['id' => $id, 'name' => $n, 'role' => $r, 'lic' => $l];
        $ids[$id] = 1;
    }
    $keys = array_map('strval', array_keys($d['days'])); sort($keys); $keys = array_slice($keys, -60);
    $days = [];
    foreach ($keys as $k) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $k) || !is_array($d['days'][$k])) continue;
        $day = $d['days'][$k]; $leave = [];
        foreach ((is_array($day['leave'] ?? null) ? $day['leave'] : []) as $x) if (is_string($x) && isset($ids[$x]) && !in_array($x, $leave, true)) $leave[] = $x;
        $ac = [];
        foreach ((is_array($day['aircraft'] ?? null) ? $day['aircraft'] : []) as $c) {
            if (!is_array($c) || count($ac) >= 200) continue;
            $id = vid($c['id'] ?? null); $reg = cut($c['reg'] ?? '', 12);
            if (!$id || $reg === '') continue;
            $crew = [];
            foreach ((is_array($c['crew'] ?? null) ? $c['crew'] : []) as $x) if (is_string($x) && isset($ids[$x]) && !in_array($x, $crew, true)) $crew[] = $x;
            $ac[] = ['id' => $id, 'reg' => $reg, 'kind' => (($c['kind'] ?? '') === 'base') ? 'base' : 'line', 'type' => cut($c['type'] ?? '', 40), 'mk' => cut($c['mk'] ?? '', 30), 'crew' => $crew];
        }
        $days[$k] = ['leave' => $leave, 'aircraft' => $ac];
    }
    $theme = in_array($d['theme'] ?? '', ['midnight', 'graphite', 'ocean', 'dune', 'daylight'], true) ? $d['theme'] : 'midnight';
    return ['people' => $people, 'days' => $days, 'theme' => $theme];
}
function creds(&$s) {
    $ids = [];
    foreach ($s['people'] as $p) $ids[$p['id']] = 1;
    foreach (array_keys($s['pw']) as $id) if (!isset($ids[$id])) unset($s['pw'][$id]);
    $used = [];
    foreach ($s['pw'] as $c) $used[$c['u']] = 1;
    foreach ($s['people'] as $p) {
        if (isset($s['pw'][$p['id']])) continue;
        $b = trim(preg_replace('/[^a-z0-9]+/', '.', strtolower($p['name'])), '.');
        if ($b === '') $b = 'user';
        $u = $b; $i = 2;
        while (isset($used[$u]) || $u === 'admin' || $u === SU_USER) $u = $b . $i++;
        $used[$u] = 1;
        $s['pw'][$p['id']] = ['u' => $u, 'p' => enc((string)random_int(100000, 999999))];
    }
}
function verify($s, $u, $p) {
    $u = strtolower(trim($u));
    if ($p === '') return null;
    if ($u === 'admin') return hash_equals(dec($s['adm']['p']), $p) ? ['role' => 'admin', 'u' => 'admin'] : null;
    if ($u === SU_USER) return password_verify($p, $s['su']['h']) ? ['role' => 'super', 'u' => SU_USER] : null;
    foreach ($s['pw'] as $id => $c) {
        if ($c['u'] === $u) return hash_equals(dec($c['p']), $p) ? ['role' => 'staff', 'u' => $u, 'pid' => (string)$id] : null;
    }
    return null;
}
function me() { $m = $_SESSION['m'] ?? null; return is_array($m) ? $m : null; }
function need($roles) { $m = me(); if (!$m || !in_array($m['role'], $roles, true)) out(['err' => 'auth'], 401); return $m; }

switch ($a) {
    case 'login':
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        if (att(function (&$d) use ($ip) { return ($d[$ip]['until'] ?? 0) > time(); })) out(['ok' => false, 'msg' => 'Too many attempts. Please wait a minute.']);
        $u = (string)($in['u'] ?? ''); $p = (string)($in['p'] ?? '');
        $r = st(function (&$s, &$ch) use ($u, $p) { $m = verify($s, $u, $p); return $m ? [$m, pub($s)] : null; });
        if (!$r) {
            att(function (&$d) use ($ip) {
                $e = $d[$ip] ?? ['n' => 0, 'until' => 0]; $e['n']++;
                if ($e['n'] >= 5) $e = ['n' => 0, 'until' => time() + 60];
                $d[$ip] = $e;
            });
            usleep(400000);
            out(['ok' => false, 'msg' => 'Wrong username or password.']);
        }
        att(function (&$d) use ($ip) { unset($d[$ip]); });
        session_regenerate_id(true);
        $_SESSION['m'] = $r[0];
        if (!empty($in['keep']) && $r[0]['role'] !== 'super') {
            setcookie(session_name(), session_id(), ['expires' => time() + 2592000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
        }
        out(['ok' => true, 'role' => $r[0]['role'], 'u' => $r[0]['u'], 'state' => $r[1]]);

    case 'me':
    case 'poll':
        $m = me();
        if (!$m) out(['err' => 'auth'], $a === 'me' ? 200 : 401);
        $rev = (int)($in['rev'] ?? -1);
        $r = st(function (&$s, &$ch) use ($m, $a, $rev) {
            if ($m['role'] === 'staff' && !isset($s['pw'][$m['pid']])) return null;
            if ($a === 'poll' && $rev === $s['rev']) return ['same' => true];
            return pub($s);
        });
        if (!$r) { $_SESSION = []; out(['err' => 'auth'], 401); }
        if ($a === 'me') out(['ok' => true, 'role' => $m['role'], 'u' => $m['u'], 'state' => $r]);
        out(isset($r['same']) ? ['ok' => true] : ['ok' => true, 'state' => $r]);

    case 'save':
        need(['admin', 'super']);
        $c = clean($in['state'] ?? null);
        if (!$c) out(['err' => 'bad'], 400);
        $rev = (int)($in['rev'] ?? -1);
        out(st(function (&$s, &$ch) use ($c, $rev) {
            if ($rev !== $s['rev']) return ['conflict' => true, 'state' => pub($s)];
            $s['people'] = $c['people']; $s['days'] = $c['days']; $s['theme'] = $c['theme'];
            creds($s); $s['rev']++; $ch = true;
            return ['ok' => true, 'rev' => $s['rev']];
        }));

    case 'accounts':
        $m = need(['admin', 'super']);
        $rows = st(function (&$s, &$ch) use ($m) {
            $rows = [['id' => 'admin', 'label' => 'Admin', 'u' => 'admin', 'p' => dec($s['adm']['p'])]];
            $o = ['engineer' => 0, 'technician' => 1, 'cleaner' => 2];
            $pp = $s['people'];
            usort($pp, function ($x, $y) use ($o) { return [$o[$x['role']], $x['name']] <=> [$o[$y['role']], $y['name']]; });
            foreach ($pp as $p) {
                $c = $s['pw'][$p['id']] ?? null;
                if ($c) $rows[] = ['id' => $p['id'], 'label' => $p['name'], 'u' => $c['u'], 'p' => dec($c['p'])];
            }
            if ($m['role'] === 'super') $rows[] = ['id' => 'super', 'label' => 'Superadmin', 'u' => SU_USER, 'p' => '', 'set' => true];
            return $rows;
        });
        out(['ok' => true, 'rows' => $rows]);

    case 'setpw':
        $m = need(['admin', 'super']);
        $id = (string)($in['id'] ?? ''); $v = (string)($in['v'] ?? '');
        if ($id === 'super' && $m['role'] !== 'super') out(['err' => 'auth'], 401);
        $min = $id === 'super' ? 8 : 6;
        if (strlen($v) < $min || strlen($v) > 64) out(['ok' => false, 'msg' => 'Password must be at least ' . $min . ' characters.']);
        $ok = st(function (&$s, &$ch) use ($id, $v) {
            if ($id === 'admin') $s['adm']['p'] = enc($v);
            elseif ($id === 'super') $s['su']['h'] = password_hash($v, PASSWORD_DEFAULT);
            elseif (isset($s['pw'][$id])) $s['pw'][$id]['p'] = enc($v);
            else return false;
            $ch = true; return true;
        });
        out(['ok' => $ok, 'msg' => $ok ? '' : 'Unknown account.']);

    case 'export':
        need(['super']);
        $d = st(function (&$s, &$ch) {
            $pw = [];
            foreach ($s['pw'] as $id => $c) $pw[$id] = ['u' => $c['u'], 'p' => dec($c['p'])];
            $o = pub($s); unset($o['rev']);
            $o['pw'] = (object)$pw; $o['adm'] = dec($s['adm']['p']);
            return $o;
        });
        out(['ok' => true, 'data' => $d]);

    case 'import':
        need(['super']);
        $d = $in['data'] ?? null;
        $c = clean($d);
        if (!$c) out(['ok' => false, 'msg' => 'That is not a valid backup.']);
        $r = st(function (&$s, &$ch) use ($c, $d) {
            $s['people'] = $c['people']; $s['days'] = $c['days']; $s['theme'] = $c['theme']; $s['pw'] = [];
            $seen = [];
            $bk = is_array($d['pw'] ?? null) ? $d['pw'] : [];
            foreach ($c['people'] as $p) {
                $x = $bk[$p['id']] ?? null;
                if (is_array($x) && is_string($x['u'] ?? null) && is_string($x['p'] ?? null)
                    && preg_match('/^[a-z0-9.]{1,40}$/', $x['u']) && !in_array($x['u'], ['admin', SU_USER], true)
                    && strlen($x['p']) >= 6 && strlen($x['p']) <= 64 && !isset($seen[$x['u']])) {
                    $s['pw'][$p['id']] = ['u' => $x['u'], 'p' => enc($x['p'])]; $seen[$x['u']] = 1;
                }
            }
            if (is_string($d['adm'] ?? null) && strlen($d['adm']) >= 6 && strlen($d['adm']) <= 64) $s['adm']['p'] = enc($d['adm']);
            creds($s); $s['rev']++; $ch = true;
            return pub($s);
        });
        out(['ok' => true, 'state' => $r]);

    case 'logout':
        $_SESSION = [];
        session_destroy();
        out(['ok' => true]);

    default:
        out(['err' => 'bad'], 400);
}
