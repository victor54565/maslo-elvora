<?php
/**
 * Публичный эндпоинт: отдаёт список видеоматериалов для витрины сайта.
 * Доступен всем (только чтение).
 */

require __DIR__ . '/lib.php';
$cfg = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

json_out(['ok' => true, 'videos' => load_videos($cfg)]);
