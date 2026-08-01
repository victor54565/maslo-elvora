<?php
/**
 * Защищённый эндпоинт админ-панели «Дух Лес».
 *
 * Все изменяющие действия требуют:
 *   1) активной админ-сессии (проверка пароля через password_verify на сервере);
 *   2) валидного CSRF-токена в заголовке X-CSRF-Token.
 *
 * Действия: login, logout, session, list, save, delete, reorder, upload.
 */

require __DIR__ . '/lib.php';
$cfg = require __DIR__ . '/config.php';
boot_session();

// --- разбор входных данных: form-data ИЛИ JSON-тело ---------------------------
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$body   = [];
$isJson = false;

if ($action === '' || empty($_POST)) {
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body   = $decoded;
            $isJson = true;
            if ($action === '') {
                $action = $decoded['action'] ?? '';
            }
        }
    }
}

/** Достаёт значение из JSON-тела или из $_POST. */
$in = static function (string $key, $default = null) use ($isJson, $body) {
    if ($isJson) {
        return $body[$key] ?? $default;
    }
    return $_POST[$key] ?? $default;
};

// --- маршрутизация ------------------------------------------------------------
switch ($action) {

    case 'login':
        // Небольшая задержка тормозит перебор пароля.
        usleep(350000);
        $pw = (string) ($in('password', ''));
        if ($pw === '' || !password_verify($pw, $cfg['password_hash'])) {
            json_out(['ok' => false, 'error' => 'bad_password'], 401);
        }
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf']  = bin2hex(random_bytes(32));
        json_out(['ok' => true, 'csrf' => $_SESSION['csrf']]);
        break;

    case 'session':
        // Проверка статуса при загрузке админки.
        if (is_admin()) {
            if (empty($_SESSION['csrf'])) {
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
            }
            json_out(['ok' => true, 'authed' => true, 'csrf' => $_SESSION['csrf']]);
        }
        json_out(['ok' => true, 'authed' => false]);
        break;

    case 'logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        json_out(['ok' => true]);
        break;

    case 'list':
        require_admin();
        json_out(['ok' => true, 'videos' => load_videos($cfg)]);
        break;

    case 'save':
        require_admin();
        check_csrf();
        $vRaw = $in('video', []);
        if (is_string($vRaw)) {
            $vRaw = json_decode($vRaw, true);
        }
        if (!is_array($vRaw)) {
            json_out(['ok' => false, 'error' => 'bad_input'], 400);
        }
        $clean  = sanitize_video($vRaw);
        $videos = load_videos($cfg);

        if ($clean['id'] !== '') {
            $found = false;
            foreach ($videos as &$item) {
                if (($item['id'] ?? '') === $clean['id']) {
                    $item  = $clean;
                    $found = true;
                    break;
                }
            }
            unset($item);
            if (!$found) {
                $videos[] = $clean;
            }
        } else {
            $clean['id'] = 'v' . bin2hex(random_bytes(6));
            $videos[]    = $clean;
        }

        if (!save_videos($cfg, $videos)) {
            json_out(['ok' => false, 'error' => 'save_failed'], 500);
        }
        json_out(['ok' => true, 'videos' => $videos, 'saved' => $clean]);
        break;

    case 'delete':
        require_admin();
        check_csrf();
        $id = (string) $in('id', '');
        $videos = array_values(array_filter(
            load_videos($cfg),
            static fn($x) => ($x['id'] ?? '') !== $id
        ));
        if (!save_videos($cfg, $videos)) {
            json_out(['ok' => false, 'error' => 'save_failed'], 500);
        }
        json_out(['ok' => true, 'videos' => $videos]);
        break;

    case 'reorder':
        require_admin();
        check_csrf();
        $order = $in('order', []);
        if (is_string($order)) {
            $order = json_decode($order, true);
        }
        if (!is_array($order)) {
            json_out(['ok' => false, 'error' => 'bad_input'], 400);
        }
        $videos = load_videos($cfg);
        $map = [];
        foreach ($videos as $x) {
            $map[$x['id']] = $x;
        }
        $new = [];
        foreach ($order as $id) {
            if (is_string($id) && isset($map[$id])) {
                $new[] = $map[$id];
                unset($map[$id]);
            }
        }
        foreach ($map as $leftover) {  // всё, что не попало в порядок — в конец
            $new[] = $leftover;
        }
        if (!save_videos($cfg, $new)) {
            json_out(['ok' => false, 'error' => 'save_failed'], 500);
        }
        json_out(['ok' => true, 'videos' => $new]);
        break;

    case 'catalog_list':
        // Список ВСЕХ товаров, включая скрытые — только для админки.
        require_admin();
        json_out(['ok' => true, 'products' => load_catalog($cfg)]);
        break;

    case 'catalog_save':
        require_admin();
        check_csrf();
        $id = (string) $in('id', '');
        if ($id === '') {
            json_out(['ok' => false, 'error' => 'bad_input'], 400);
        }
        $patchRaw = $in('patch', []);
        if (is_string($patchRaw)) {
            $patchRaw = json_decode($patchRaw, true);
        }
        if (!is_array($patchRaw)) {
            json_out(['ok' => false, 'error' => 'bad_input'], 400);
        }
        $patch = sanitize_catalog_patch($patchRaw);

        $products = load_catalog($cfg);
        $found = false;
        foreach ($products as &$p) {
            if (($p['id'] ?? '') === $id) {
                $p = array_merge($p, $patch);
                $found = true;
                break;
            }
        }
        unset($p);
        if (!$found) {
            json_out(['ok' => false, 'error' => 'not_found'], 404);
        }
        if (!save_catalog($cfg, $products)) {
            json_out(['ok' => false, 'error' => 'save_failed'], 500);
        }
        json_out(['ok' => true, 'products' => $products]);
        break;

    case 'socials_list':
        require_admin();
        json_out(['ok' => true, 'socials' => load_socials($cfg)]);
        break;

    case 'socials_save':
        require_admin();
        check_csrf();
        $id = (string) $in('id', '');
        if ($id === '') {
            json_out(['ok' => false, 'error' => 'bad_input'], 400);
        }
        $patchRaw = $in('patch', []);
        if (is_string($patchRaw)) {
            $patchRaw = json_decode($patchRaw, true);
        }
        if (!is_array($patchRaw)) {
            json_out(['ok' => false, 'error' => 'bad_input'], 400);
        }
        $patch = sanitize_social_patch($patchRaw);

        $socials = load_socials($cfg);
        $found = false;
        foreach ($socials as &$s) {
            if (($s['id'] ?? '') === $id) {
                $s = array_merge($s, $patch);
                $found = true;
                break;
            }
        }
        unset($s);
        if (!$found) {
            json_out(['ok' => false, 'error' => 'not_found'], 404);
        }
        if (!save_socials($cfg, $socials)) {
            json_out(['ok' => false, 'error' => 'save_failed'], 500);
        }
        json_out(['ok' => true, 'socials' => $socials]);
        break;

    case 'upload':
        require_admin();
        check_csrf();
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            json_out(['ok' => false, 'error' => 'no_file'], 400);
        }
        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            json_out(['ok' => false, 'error' => 'upload_error', 'code' => $file['error']], 400);
        }
        $kind = ($_POST['kind'] ?? 'thumb') === 'video' ? 'video' : 'thumb';

        $allowed = $kind === 'video'
            ? ['video/mp4' => 'mp4', 'video/webm' => 'webm']
            : ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!isset($allowed[$mime])) {
            json_out(['ok' => false, 'error' => 'bad_type', 'mime' => $mime], 400);
        }
        $limit = $kind === 'video' ? $cfg['max_video_bytes'] : $cfg['max_thumb_bytes'];
        if (($file['size'] ?? 0) > $limit) {
            json_out(['ok' => false, 'error' => 'too_big', 'limit' => $limit], 400);
        }

        if (!is_dir($cfg['upload_dir'])) {
            @mkdir($cfg['upload_dir'], 0775, true);
        }
        $ext  = $allowed[$mime];
        $name = $kind . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $cfg['upload_dir'] . '/' . $name;

        // move_uploaded_file — только для настоящих загрузок (безопасность).
        $moved = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $dest)
            : rename($file['tmp_name'], $dest); // ветка для локального php -S тестирования

        if (!$moved) {
            json_out(['ok' => false, 'error' => 'move_failed'], 500);
        }
        json_out(['ok' => true, 'url' => $cfg['upload_url'] . '/' . $name]);
        break;

    default:
        json_out(['ok' => false, 'error' => 'unknown_action'], 400);
}
