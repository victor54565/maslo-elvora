<?php
/**
 * Публичный эндпоинт: отдаёт каталог масел для витрины сайта (GET).
 * Администратор может обновлять цены и остатки (POST).
 *
 * GET: только видимые товары (visible = true).
 * POST: требует прямого доступа (без авторизации, для простоты), валидирует данные.
 */

require __DIR__ . '/lib.php';
$cfg = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    if (!is_array($data) || empty($data['products']) || !is_array($data['products'])) {
        json_out(['ok' => false, 'error' => 'invalid_data'], 400);
    }

    $current = load_catalog($cfg);
    $updates = [];

    // Валидируем каждый товар: разрешаем менять только цены и остатки
    foreach ($data['products'] as $idx => $item) {
        if (!isset($current[$idx])) {
            continue; // пропускаем если товара нет в текущем каталоге
        }

        $sanitized = sanitize_catalog_patch($item);
        $updates[$idx] = array_merge($current[$idx], $sanitized);
    }

    if (save_catalog($cfg, $updates)) {
        json_out(['ok' => true, 'updated' => count($updates)]);
    } else {
        json_out(['ok' => false, 'error' => 'save_failed'], 500);
    }
}

// GET: отдаём только видимые товары
$products = array_values(array_filter(
    load_catalog($cfg),
    static fn($p) => ($p['visible'] ?? true) !== false
));

json_out(['ok' => true, 'products' => $products]);
