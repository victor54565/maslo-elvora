<?php
/**
 * Общие помощники для API «Дух Лес»:
 * сессии, JSON-ответы, чтение/запись хранилища, авторизация, CSRF, санитизация.
 */

/** Запускает сессию с безопасными параметрами cookie. */
function boot_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Определяем HTTPS с учётом реверс-прокси (Plesk: nginx → Apache).
    // Без X-Forwarded-Proto после включения SSL кука не отдавалась бы и вход ломался.
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,      // cookie недоступен из JavaScript
        'samesite' => 'Lax',
        'secure'   => $https,    // по HTTPS cookie только по защищённому каналу
    ]);
    session_name('duxadm');
    session_start();
}

/** Отдаёт JSON и завершает выполнение. */
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Читает массив видео из JSON-файла. */
function load_videos(array $cfg): array
{
    $f = $cfg['data_file'];
    if (!is_file($f)) {
        return [];
    }
    $raw = file_get_contents($f);
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['videos']) || !is_array($data['videos'])) {
        return [];
    }
    return array_values($data['videos']);
}

/** Атомарно записывает массив видео в JSON-файл. */
function save_videos(array $cfg, array $videos): bool
{
    $f = $cfg['data_file'];
    $dir = dirname($f);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $json = json_encode(
        ['videos' => array_values($videos)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    return rename($tmp, $f);
}

/** Авторизован ли текущий посетитель как админ. */
function is_admin(): bool
{
    return !empty($_SESSION['admin']) && $_SESSION['admin'] === true;
}

/** Прерывает выполнение с 401, если нет админ-сессии. */
function require_admin(): void
{
    if (!is_admin()) {
        json_out(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}

/** Проверяет CSRF-токен из заголовка X-CSRF-Token. */
function check_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf']) || !is_string($sent) || !hash_equals($_SESSION['csrf'], $sent)) {
        json_out(['ok' => false, 'error' => 'bad_csrf'], 403);
    }
}

/** Обрезает строку до $max символов (UTF-8), работает и без расширения mbstring. */
function utf8_cut(string $s, int $max): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max, 'UTF-8');
    }
    if (strlen($s) <= $max) {          // байт не больше — значит и символов не больше
        return $s;
    }
    if (preg_match('/^.{0,' . $max . '}/us', $s, $m)) {  // режем по границам код-поинтов
        return $m[0];
    }
    return substr($s, 0, $max);
}

/** Определяет тип видео по ссылке. */
function detect_type(string $url): string
{
    if ($url === '') {
        return 'link';
    }
    if (preg_match('#(youtube\.com|youtu\.be)#i', $url)) {
        return 'youtube';
    }
    if (preg_match('#vimeo\.com#i', $url)) {
        return 'link';
    }
    if (preg_match('#\.mp4($|\?)#i', $url) || preg_match('#^uploads/.+\.(mp4|webm)$#i', $url)) {
        return 'mp4';
    }
    return 'link';
}

/** Пропускает только http/https-ссылки или локальные пути uploads/. */
function clean_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $url) || preg_match('#^uploads/[\w./-]+$#', $url)) {
        return $url;
    }
    return '';
}

/** Читает массив товаров каталога из JSON-файла. */
function load_catalog(array $cfg): array
{
    $f = $cfg['catalog_data_file'];
    if (!is_file($f)) {
        return [];
    }
    $raw = file_get_contents($f);
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['products']) || !is_array($data['products'])) {
        return [];
    }
    return array_values($data['products']);
}

/** Атомарно записывает массив товаров каталога в JSON-файл. */
function save_catalog(array $cfg, array $products): bool
{
    $f = $cfg['catalog_data_file'];
    $dir = dirname($f);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $json = json_encode(
        ['products' => array_values($products)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    return rename($tmp, $f);
}

/**
 * Приводит присланные из админки правки товара к безопасному виду.
 * Админка правит только доступность/остаток/цены — название, группу
 * и порядок трогать нельзя (эти поля заданы каталогом, а не формой).
 *
 * Остатки: { "100": N, "250": M, "500": K } — целые числа >= 0.
 */
function sanitize_catalog_patch(array $v): array
{
    $toInt = static function ($val) {
        return max(0, (int) $val);
    };

    $patch = [];
    foreach (['price100', 'price250', 'price500'] as $key) {
        if (array_key_exists($key, $v)) {
            $patch[$key] = $toInt($v[$key]);
        }
    }

    // Новая структура stock: массив { "100": N, "250": M, "500": K }
    if (array_key_exists('stock', $v) && is_array($v['stock'])) {
        $stock = [];
        foreach (['100', '250', '500'] as $vol) {
            if (array_key_exists($vol, $v['stock'])) {
                $stock[$vol] = $toInt($v['stock'][$vol]);
            }
        }
        if (!empty($stock)) {
            $patch['stock'] = $stock;
        }
    }

    if (array_key_exists('available', $v)) {
        $patch['available'] = (bool) $v['available'];
    }
    if (array_key_exists('visible', $v)) {
        $patch['visible'] = (bool) $v['visible'];
    }
    return $patch;
}

/* ─────────────────────────────────────────────
   Ссылки на соцсети в подвале сайта.
   Набор иконок фиксирован кодом (id → SVG в разметке), из админки
   правятся только адрес ссылки и показ/скрытие конкретной иконки.
───────────────────────────────────────────── */

/** Читает список соцсетей из JSON-файла. */
function load_socials(array $cfg): array
{
    $f = $cfg['socials_data_file'] ?? '';
    if ($f === '' || !is_file($f)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($f), true);
    if (!is_array($data) || empty($data['socials']) || !is_array($data['socials'])) {
        return [];
    }
    return array_values($data['socials']);
}

/** Атомарно записывает список соцсетей в JSON-файл. */
function save_socials(array $cfg, array $socials): bool
{
    $f = $cfg['socials_data_file'] ?? '';
    if ($f === '') {
        return false;
    }
    $dir = dirname($f);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $json = json_encode(
        ['socials' => array_values($socials)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    return rename($tmp, $f);
}

/**
 * Приводит правки соцсети к безопасному виду.
 * Разрешены только http(s)-ссылки: схемы вроде javascript: в href подвала
 * превратились бы в исполняемый код на странице у каждого посетителя.
 */
function sanitize_social_patch(array $v): array
{
    $patch = [];
    if (array_key_exists('url', $v)) {
        $url = trim((string) $v['url']);
        if ($url !== '') {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if ($scheme !== 'http' && $scheme !== 'https') {
                $url = '';
            }
        }
        $patch['url'] = mb_substr($url, 0, 500);
    }
    if (array_key_exists('visible', $v)) {
        $patch['visible'] = (bool) $v['visible'];
    }
    return $patch;
}

/** Приводит данные одного видео к безопасному виду. */
function sanitize_video(array $v): array
{
    $str = static function ($val, int $max): string {
        if (!is_string($val)) {
            return '';
        }
        return utf8_cut(trim(strip_tags($val)), $max);
    };

    $id = '';
    if (isset($v['id']) && is_string($v['id'])) {
        $id = preg_replace('/[^a-zA-Z0-9_]/', '', $v['id']);
    }

    $url   = clean_url(is_string($v['url'] ?? null) ? $v['url'] : '');
    $thumb = clean_url(is_string($v['thumb'] ?? null) ? $v['thumb'] : '');

    $type = $v['type'] ?? '';
    if (!in_array($type, ['youtube', 'link', 'mp4'], true)) {
        $type = detect_type($url);
    }

    $title = $str($v['title'] ?? '', 160);
    if ($title === '') {
        $title = 'Без названия';
    }

    return [
        'id'          => $id,
        'title'       => $title,
        'tag'         => $str($v['tag'] ?? '', 40),
        'description' => $str($v['description'] ?? '', 600),
        'duration'    => $str($v['duration'] ?? '', 12),
        'url'         => $url,
        'type'        => $type,
        'thumb'       => $thumb,
    ];
}
