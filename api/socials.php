<?php
/**
 * Публичный эндпоинт: отдаёт ссылки на соцсети для подвала сайта.
 * Доступен всем (только чтение). Скрытые администратором иконки
 * (visible = false) и записи с пустым адресом в ответ не попадают.
 */

require __DIR__ . '/lib.php';
$cfg = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$socials = array_values(array_filter(
    load_socials($cfg),
    static fn($s) => ($s['visible'] ?? true) !== false && trim((string) ($s['url'] ?? '')) !== ''
));

json_out(['ok' => true, 'socials' => $socials]);
