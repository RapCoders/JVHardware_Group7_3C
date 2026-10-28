<?php
/*
 * Database connection + automatic setup.
 * First visit: creates the database, tables, sample products and default
 * accounts. Older databases are upgraded in place (no data is lost).
 */
require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Manila');
const SCHEMA_VERSION = 3;

function jv_connect($db = null)
{
    $dsn = 'mysql:host=' . DB_HOST . ($db ? ';dbname=' . $db : '') . ';charset=utf8mb4';
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function jv_fail($title, $help)
{
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<body style="font-family:Segoe UI,Arial,sans-serif;background:#f6f4ee;display:grid;place-items:center;min-height:100vh;margin:0">'
       . '<div style="max-width:480px;background:#fff;border-radius:14px;padding:32px;box-shadow:0 8px 30px #0001;border-top:6px solid #f5a800">'
       . '<h2 style="margin:0 0 10px">' . htmlspecialchars($title) . '</h2><p style="color:#555;line-height:1.6;margin:0">' . $help . '</p></div></body>';
    exit;
}

function jv_add_column($pdo, $table, $col, $ddl)
{
    $st = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col));
    if (!$st->fetch()) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
    }
}

function jv_schema_version($pdo)
{
    try {
        return (int)$pdo->query("SELECT v FROM settings WHERE k='schema_version'")->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function jv_install($pdo)
{
    // 1) tables + sample data (safe to repeat: CREATE IF NOT EXISTS / INSERT IGNORE)
    $sql = file_get_contents(dirname(__DIR__) . '/database.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (preg_split('/;\s*[\r\n]+/', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || preg_match('/^(CREATE DATABASE|USE)\b/i', $stmt)) continue;
        $pdo->exec($stmt);
    }
    // 2) upgrade databases created by the older version of this project
    jv_add_column($pdo, 'products', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
    jv_add_column($pdo, 'products', 'updated_at', 'DATETIME NULL');
    jv_add_column($pdo, 'stock_movements', 'user_id', 'INT UNSIGNED NULL');
    jv_add_column($pdo, 'stock_movements', 'balance_after', 'INT NULL');
    jv_add_column($pdo, 'sales', 'user_id', 'INT UNSIGNED NULL');
    jv_add_column($pdo, 'sales', 'status', "VARCHAR(10) NOT NULL DEFAULT 'completed'");
    jv_add_column($pdo, 'sales', 'void_reason', 'VARCHAR(255) NULL');
    jv_add_column($pdo, 'sales', 'voided_at', 'DATETIME NULL');
    $pdo->exec("ALTER TABLE stock_movements MODIFY movement_type ENUM('IN','OUT','SALE','VOID') NOT NULL");
    // 3) keep a single owner account; remove legacy staff logins.
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO users(username,full_name,password_hash,role,can_edit_prices) VALUES(?,?,?,?,?)');
        $ins->execute(['owner', 'Virginia Betuin', password_hash('admin123', PASSWORD_DEFAULT), 'owner', 1]);
    }
    $pdo->exec("DELETE FROM users WHERE role='staff'");
    $pdo->exec("ALTER TABLE users MODIFY role ENUM('owner') NOT NULL DEFAULT 'owner'");
    // 4) opening stock history for the sample products
    if ((int)$pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn() === 0) {
        $st = $pdo->prepare("INSERT INTO stock_movements(product_id,movement_type,quantity,note,balance_after,created_at) SELECT id,'IN',quantity,'Opening stock',quantity,? FROM products WHERE quantity>0");
        $st->execute([date('Y-m-d H:i:s')]);
    }
    $pdo->prepare("INSERT INTO settings(k,v) VALUES('schema_version',?) ON DUPLICATE KEY UPDATE v=VALUES(v)")->execute([SCHEMA_VERSION]);
}

try {
    $pdo = jv_connect(DB_NAME);
} catch (PDOException $e) {
    if ((int)($e->errorInfo[1] ?? 0) === 1049) { // database does not exist yet
        try {
            $root = jv_connect();
            $root->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo = jv_connect(DB_NAME);
        } catch (PDOException $e2) {
            jv_fail('Could not create the database', 'MySQL refused the request. Check the username and password in <code>includes/config.php</code>.');
        }
    } else {
        jv_fail('MySQL is not running', 'Open the <b>XAMPP Control Panel</b> and click <b>Start</b> beside <b>Apache</b> and <b>MySQL</b>, then refresh this page.');
    }
}

if (jv_schema_version($pdo) < SCHEMA_VERSION) {
    try {
        jv_install($pdo);
    } catch (Throwable $e) {
        jv_fail('Database setup failed', htmlspecialchars($e->getMessage()));
    }
}
