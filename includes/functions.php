<?php
/* Shared helpers: session, security, formatting, permissions, stock logic. */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('jvhw');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

/* ---------- output + formatting ---------- */
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n) { return '₱' . number_format((float)$n, 2); }
function money_short($n) {
    $n = (float)$n;
    if ($n >= 1000000) return '₱' . round($n / 1000000, 1) . 'M';
    if ($n >= 10000) return '₱' . round($n / 1000, 1) . 'k';
    return '₱' . number_format($n, 0);
}
function num($n) { return number_format((int)$n); }
function now() { return date('Y-m-d H:i:s'); }
function next_day($d) { return date('Y-m-d', strtotime($d . ' +1 day')); }
function fmt_dt($s) { return $s ? date('M j, Y · g:i A', strtotime($s)) : '—'; }
function fmt_d($s) { return $s ? date('M j, Y', strtotime($s)) : '—'; }
function icon($name, $cls = '') { return '<svg class="i ' . e($cls) . '" aria-hidden="true"><use href="#i-' . e($name) . '"/></svg>'; }
function page_url() { return basename($_SERVER['PHP_SELF']); }

/* ---------- database shortcuts ---------- */
function db_q($sql, $params = []) {
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st;
}
function lock_clause() {
    global $pdo;
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
}
function setting($key, $default = '') {
    global $pdo;
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach ($pdo->query('SELECT k,v FROM settings') as $r) $all[$r['k']] = $r['v'];
    }
    return isset($all[$key]) && $all[$key] !== '' ? $all[$key] : $default;
}

/* ---------- flash messages ---------- */
function flash($msg, $type = 'ok') { $_SESSION['flash'][] = ['m' => $msg, 't' => $type]; }
function redirect($url) { header('Location: ' . $url); exit; }

/* ---------- CSRF protection ---------- */
function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field() { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check() {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        flash('Your page expired. Please try again.', 'err');
        redirect(page_url());
    }
}

/* ---------- login + permissions ---------- */
function current_user() {
    global $pdo;
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $st = $pdo->prepare('SELECT * FROM users WHERE id=? AND is_active=1');
            $st->execute([$_SESSION['uid']]);
            $u = $st->fetch() ?: null;
        }
    }
    return $u;
}
function require_login() { if (!current_user()) redirect('login.php'); }
function is_owner() { $u = current_user(); return $u && $u['role'] === 'owner'; }
/* can('prices') = owner, or staff the owner authorised to manage products/prices */
function can($perm) {
    $u = current_user();
    if (!$u) return false;
    if ($u['role'] === 'owner') return true;
    return $perm === 'prices' && (int)$u['can_edit_prices'] === 1;
}
function require_owner() {
    if (!is_owner()) { flash('Only the store owner can open that.', 'err'); redirect('index.php'); }
}

/* ---------- inventory helpers ---------- */
function status_of($qty, $reorder) {
    if ((int)$qty <= 0) return ['out', 'Out of stock'];
    if ((int)$qty <= (int)$reorder) return ['low', 'Low stock'];
    return ['ok', 'In stock'];
}
function status_pill($qty, $reorder) {
    list($c, $l) = status_of($qty, $reorder);
    return '<span class="pill ' . $c . '">' . $l . '</span>';
}
function suggested_order($qty, $reorder) { return max(1, ((int)$reorder * 2) - (int)$qty); }

function next_sku() {
    $max = 0;
    foreach (db_q("SELECT sku FROM products WHERE sku LIKE 'JV-HW-%'")->fetchAll() as $r) {
        if (preg_match('/^JV-HW-(\d+)$/', $r['sku'], $m)) $max = max($max, (int)$m[1]);
    }
    return 'JV-HW-' . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}

/*
 * Change a product's stock by $delta (+ add / - remove) and write the history row.
 * Call inside a transaction. Throws RuntimeException if stock would go negative.
 */
function apply_stock($pid, $delta, $type, $note) {
    global $pdo;
    $st = $pdo->prepare('SELECT id,name,quantity FROM products WHERE id=?' . lock_clause());
    $st->execute([$pid]);
    $p = $st->fetch();
    if (!$p) throw new RuntimeException('Product not found.');
    $new = (int)$p['quantity'] + (int)$delta;
    if ($new < 0) throw new RuntimeException('Not enough stock for ' . $p['name'] . '. Available: ' . $p['quantity'] . '.');
    $pdo->prepare('UPDATE products SET quantity=?, updated_at=? WHERE id=?')->execute([$new, now(), $pid]);
    $u = current_user();
    $pdo->prepare('INSERT INTO stock_movements(product_id,movement_type,quantity,note,user_id,balance_after,created_at) VALUES(?,?,?,?,?,?,?)')
        ->execute([$pid, $type, abs((int)$delta), $note, $u ? $u['id'] : null, $new, now()]);
    return ['name' => $p['name'], 'old' => (int)$p['quantity'], 'new' => $new];
}

/* Simple bar chart (pure HTML/CSS). $rows = [['label'=>, 'value'=>, 'tip'=>], ...] */
function bar_chart($rows) {
    $max = 0;
    foreach ($rows as $r) $max = max($max, (float)$r['value']);
    $h = '<div class="bars" role="img" aria-label="Sales per day">';
    foreach ($rows as $r) {
        $pct = $max > 0 ? max(2, round($r['value'] / $max * 100)) : 2;
        $h .= '<div class="bar" title="' . e($r['tip']) . '"><div class="bar-val">' . ($r['value'] > 0 ? e(money_short($r['value'])) : '') . '</div>'
            . '<div class="bar-col"><span class="' . ($r['value'] > 0 ? '' : 'zero') . '" style="height:' . $pct . '%"></span></div>'
            . '<div class="bar-lab">' . e($r['label']) . '</div></div>';
    }
    return $h . '</div>';
}

/* Sidebar badge: number of products at or under their reorder level */
function low_stock_count() {
    return (int)db_q('SELECT COUNT(*) FROM products WHERE is_active=1 AND quantity<=reorder_level')->fetchColumn();
}
