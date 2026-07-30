<?php
/**
 * Публичный эндпоинт: отдаёт каталог масел для витрины сайта.
 * Доступен всем (только чтение). Скрытые администратором товары
 * (visible = false) в ответ не попадают — их не должно быть на сайте.
 */

require __DIR__ . '/lib.php';
$cfg = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$products = array_values(array_filter(
    load_catalog($cfg),
    static fn($p) => ($p['visible'] ?? true) !== false
));

json_out(['ok' => true, 'products' => $products]);
