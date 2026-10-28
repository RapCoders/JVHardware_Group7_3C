<?php
/* CSV downloads (open in Excel). */
require __DIR__ . '/includes/bootstrap.php';
require_login();

$type = in_array($_GET['type'] ?? '', ['sales', 'inventory', 'prices'], true) ? $_GET['type'] : 'sales';
$from = valid_iso_date($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-01');
$to = valid_iso_date($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-d');
if ($from > $to) { $swap = $from; $from = $to; $to = $swap; }

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="jv-' . ($type === 'sales' ? "sales-$from-to-$to" : $type . '-' . date('Y-m-d')) . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // so Excel shows the characters correctly

if ($type === 'inventory') {
    fputcsv($out, array_map('csv_safe_cell', ['sku', 'name', 'category', 'unit', 'quantity', 'price', 'reorder_level', 'stock_value', 'status']));
    foreach (db_q('SELECT * FROM products WHERE is_active=1 ORDER BY category, name')->fetchAll() as $p) {
        fputcsv($out, array_map('csv_safe_cell', [$p['sku'], $p['name'], $p['category'], $p['unit'], $p['quantity'], $p['price'], $p['reorder_level'], round($p['quantity'] * $p['price'], 2), status_of($p['quantity'], $p['reorder_level'])[1]]));
    }
} elseif ($type === 'prices') {
    fputcsv($out, array_map('csv_safe_cell', ['date', 'sku', 'product', 'old_price', 'new_price', 'changed_by']));
    foreach (db_q('SELECT ph.*, p.name, p.sku, u.full_name AS by_user FROM price_history ph JOIN products p ON p.id=ph.product_id LEFT JOIN users u ON u.id=ph.user_id ORDER BY ph.id DESC')->fetchAll() as $c) {
        fputcsv($out, array_map('csv_safe_cell', [$c['changed_at'], $c['sku'], $c['name'], $c['old_price'], $c['new_price'], $c['by_user']]));
    }
} else {
    fputcsv($out, array_map('csv_safe_cell', ['receipt_no', 'date', 'customer', 'payment', 'status', 'product', 'sku', 'quantity', 'unit_price', 'line_total', 'sale_total']));
    $rows = db_q('SELECT s.receipt_no, s.sold_at, s.customer_name, s.payment_method, s.status, s.subtotal, si.product_name, si.sku, si.quantity, si.unit_price, si.line_total
        FROM sales s LEFT JOIN sale_items si ON si.sale_id=s.id WHERE s.sold_at>=? AND s.sold_at<? ORDER BY s.sold_at DESC, si.id', [$from . ' 00:00:00', next_day($to) . ' 00:00:00'])->fetchAll();
    foreach ($rows as $r) fputcsv($out, array_map('csv_safe_cell', [$r['receipt_no'], $r['sold_at'], $r['customer_name'] ?: 'Walk-in customer', $r['payment_method'], $r['status'], $r['product_name'], $r['sku'], $r['quantity'], $r['unit_price'], $r['line_total'], $r['subtotal']]));
}
fclose($out);
