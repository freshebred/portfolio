<?php
session_start();

$CONTENT_FILE = __DIR__ . '/content.json';
$BACKUP_FILE  = __DIR__ . '/content.backup.json';
$AUTH_FILE    = __DIR__ . '/.cms_auth.php';
$ASSETS_DIR   = __DIR__ . '/assets';

// Initialize default PIN ("2019") if auth file doesn't exist yet
if (!file_exists($AUTH_FILE)) {
    $defaultPin = '2019';
    $hash = password_hash($defaultPin, PASSWORD_DEFAULT);
    $authData = "<?php\nreturn " . var_export(['pin_hash' => $hash, 'pin_length' => 4], true) . ";\n";
    @file_put_contents($AUTH_FILE, $authData, LOCK_EX);
}

function getAuthConfig($authFile) {
    if (file_exists($authFile)) {
        $cfg = include $authFile;
        if (is_array($cfg) && isset($cfg['pin_hash'])) {
            return $cfg;
        }
    }
    return [
        'pin_hash' => password_hash('2019', PASSWORD_DEFAULT),
        'pin_length' => 4
    ];
}

$authConfig = getAuthConfig($AUTH_FILE);
$isAuthed = !empty($_SESSION['cms_authenticated']);

// Handle JSON API actions
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['action'];

    if ($action === 'status') {
        echo json_encode([
            'authenticated' => $isAuthed,
            'pinLength' => $authConfig['pin_length'] ?? 4
        ]);
        exit;
    }

    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];
        $pin = isset($body['pin']) ? trim((string)$body['pin']) : '';

        // Simple brute-force rate limit per session
        if (!isset($_SESSION['pin_attempts'])) {
            $_SESSION['pin_attempts'] = 0;
            $_SESSION['pin_lock_until'] = 0;
        }

        if (time() < $_SESSION['pin_lock_until']) {
            $wait = $_SESSION['pin_lock_until'] - time();
            http_response_code(429);
            echo json_encode([
                'ok' => false,
                'error' => "Too many attempts. Wait {$wait}s."
            ]);
            exit;
        }

        if (password_verify($pin, $authConfig['pin_hash'])) {
            $_SESSION['cms_authenticated'] = true;
            $_SESSION['pin_attempts'] = 0;
            echo json_encode(['ok' => true]);
        } else {
            $_SESSION['pin_attempts']++;
            if ($_SESSION['pin_attempts'] >= 5) {
                $_SESSION['pin_lock_until'] = time() + 15;
                $_SESSION['pin_attempts'] = 0;
            }
            http_response_code(401);
            echo json_encode([
                'ok' => false,
                'error' => 'Incorrect PIN'
            ]);
        }
        exit;
    }

    if ($action === 'logout') {
        $_SESSION['cms_authenticated'] = false;
        session_destroy();
        echo json_encode(['ok' => true]);
        exit;
    }

    // Protected routes require authentication
    if (!$isAuthed) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    if ($action === 'get_content') {
        if (!file_exists($CONTENT_FILE)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'content.json not found']);
            exit;
        }
        $json = file_get_contents($CONTENT_FILE);
        echo $json;
        exit;
    }

    if ($action === 'save_content' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['hero']) || !isset($decoded['projects'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid content payload']);
            exit;
        }

        if (file_exists($CONTENT_FILE)) {
            @copy($CONTENT_FILE, $BACKUP_FILE);
        }

        $formatted = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($CONTENT_FILE, $formatted . "\n", LOCK_EX) === false) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to write content.json. Check file permissions.']);
            exit;
        }

        echo json_encode(['ok' => true, 'savedAt' => date('H:i:s')]);
        exit;
    }

    if ($action === 'restore_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!file_exists($BACKUP_FILE)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'No backup file found yet.']);
            exit;
        }
        @copy($BACKUP_FILE, $CONTENT_FILE);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'upload_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Upload failed or no file selected.']);
            exit;
        }

        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'ico'];
        if (!in_array($ext, $allowed, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unsupported image format.']);
            exit;
        }

        if (!is_dir($ASSETS_DIR)) {
            @mkdir($ASSETS_DIR, 0775, true);
        }

        $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
        $safeBase = trim($safeBase, '_') ?: 'image';
        $targetName = $safeBase . '_' . substr(md5(uniqid('', true)), 0, 6) . '.' . $ext;
        $targetPath = $ASSETS_DIR . '/' . $targetName;

        if (!@move_uploaded_file($file['tmp_name'], $targetPath)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not move uploaded file to assets/']);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'url' => 'assets/' . $targetName
        ]);
        exit;
    }

    if ($action === 'change_pin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: [];
        $currentPin = isset($body['currentPin']) ? trim((string)$body['currentPin']) : '';
        $newPin = isset($body['newPin']) ? trim((string)$body['newPin']) : '';

        if (!password_verify($currentPin, $authConfig['pin_hash'])) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Current PIN is incorrect.']);
            exit;
        }

        if (!preg_match('/^\d{4,8}$/', $newPin)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'New PIN must be 4 to 8 digits (0-9).']);
            exit;
        }

        $newHash = password_hash($newPin, PASSWORD_DEFAULT);
        $newConfig = [
            'pin_hash' => $newHash,
            'pin_length' => strlen($newPin)
        ];
        $phpCode = "<?php\nreturn " . var_export($newConfig, true) . ";\n";
        if (@file_put_contents($AUTH_FILE, $phpCode, LOCK_EX) === false) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to save new PIN.']);
            exit;
        }

        echo json_encode(['ok' => true, 'pinLength' => strlen($newPin)]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

$pinLength = (int)($authConfig['pin_length'] ?? 4);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Portfolio Studio — CMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bowlby+One+SC&family=Outfit:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&family=VT323&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-base: #0a0a0a;
            --surface-1: #121212;
            --surface-2: #1a1a1a;
            --surface-3: #242424;
            --surface-bright: #ffffff;
            --text-primary: #ffffff;
            --text-secondary: #a1a1aa;
            --text-muted: #686870;
            --border-subtle: rgba(255, 255, 255, 0.1);
            --border-strong: rgba(255, 255, 255, 0.24);
            --font-display: 'Bowlby One SC', sans-serif;
            --font-sans: 'Outfit', sans-serif;
            --font-mono: 'VT323', monospace;
            /* Android Material 3 Expressive Spring Easings */
            --m3-spring-fast: cubic-bezier(0.34, 1.65, 0.64, 1);
            --m3-spring-Morph: cubic-bezier(0.22, 1.35, 0.36, 1);
            --m3-emphasized: cubic-bezier(0.2, 0, 0, 1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        html, body {
            background: var(--bg-base);
            color: var(--text-primary);
            font-family: var(--font-sans);
            min-height: 100dvh;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        /* Subtle Editorial Grain Overlay */
        .grain-overlay {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 9999;
            opacity: 0.045;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.8' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)'/%3E%3C/svg%3E");
        }

        /* =====================================================
           FULL-SCREEN M3 EXPRESSIVE B&W EDITORIAL LOCK SCREEN
           ===================================================== */
        #lock-screen {
            position: fixed;
            inset: 0;
            z-index: 5000;
            background: var(--bg-base);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
            padding: clamp(20px, 4vh, 44px) 24px;
            height: 100dvh;
            user-select: none;
            transition: clip-path 0.75s cubic-bezier(0.7, 0, 0.2, 1), opacity 0.5s ease, transform 0.65s var(--m3-emphasized);
            clip-path: circle(150% at 50% 50%);
        }

        #lock-screen.unlocked {
            clip-path: circle(0% at 50% 50%);
            opacity: 0;
            pointer-events: none;
        }

        /* Editorial Top Bar */
        .lock-topbar {
            width: 100%;
            max-width: 1200px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            font-size: 0.78rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--text-secondary);
        }

        .lock-back-link {
            color: var(--text-secondary);
            text-decoration: none;
            padding: 6px 14px;
            border-radius: 999px;
            border: 1px solid transparent;
            transition: all 0.25s ease;
        }

        .lock-back-link:hover {
            color: #fff;
            border-color: var(--border-subtle);
            background: var(--surface-1);
        }

        /* Lock Main Container */
        .lock-body {
            width: 100%;
            max-width: 420px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: clamp(22px, 3.6vh, 36px);
            margin: auto 0;
        }

        /* Android M3 Expressive Morphing Shape PIN Indicators */
        .pin-dots-stage {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            min-height: 44px;
            padding: 8px 24px;
            border-radius: 999px;
            background: var(--surface-1);
            border: 1px solid var(--border-subtle);
            transition: transform 0.3s var(--m3-spring-fast), border-color 0.3s ease;
        }

        .pin-dots-stage.shake {
            animation: m3Shake 0.45s cubic-bezier(0.36, 0.07, 0.19, 0.97) both;
            border-color: #fff;
        }

        @keyframes m3Shake {
            0%, 100% { transform: translateX(0); }
            15% { transform: translateX(-14px) scaleX(1.04); }
            30% { transform: translateX(12px) scaleX(0.96); }
            45% { transform: translateX(-9px); }
            60% { transform: translateX(7px); }
            75% { transform: translateX(-4px); }
        }

        .pin-slot {
            width: 22px;
            height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .pin-shape {
            width: 10px;
            height: 10px;
            background: transparent;
            border: 2px solid rgba(255, 255, 255, 0.28);
            border-radius: 50%;
            transition:
                width 0.32s var(--m3-spring-fast),
                height 0.32s var(--m3-spring-fast),
                border-radius 0.32s var(--m3-spring-Morph),
                transform 0.32s var(--m3-spring-fast),
                background-color 0.2s ease,
                border-color 0.2s ease,
                clip-path 0.32s var(--m3-spring-Morph);
        }

        /* Expressive Morphing Shapes when filled (Android 14/15/16 Lock Screen style) */
        .pin-slot.filled .pin-shape {
            width: 20px;
            height: 20px;
            background: #ffffff;
            border-color: #ffffff;
        }

        /* Shape 0: Expressive Squircle Rotate */
        .pin-slot.filled[data-shape="0"] .pin-shape {
            border-radius: 6px;
            transform: rotate(45deg) scale(0.92);
        }
        /* Shape 1: Pill / Capsule */
        .pin-slot.filled[data-shape="1"] .pin-shape {
            width: 24px;
            height: 16px;
            border-radius: 999px;
            transform: rotate(-15deg);
        }
        /* Shape 2: 4-Point Expressive Star / Cookie */
        .pin-slot.filled[data-shape="2"] .pin-shape {
            border-radius: 2px;
            clip-path: polygon(50% 0%, 68% 32%, 100% 50%, 68% 68%, 50% 100%, 32% 68%, 0% 50%, 32% 32%);
            transform: scale(1.18);
        }
        /* Shape 3: Octagon / Gem */
        .pin-slot.filled[data-shape="3"] .pin-shape {
            border-radius: 4px;
            clip-path: polygon(30% 0%, 70% 0%, 100% 30%, 100% 70%, 70% 100%, 30% 100%, 0% 70%, 0% 30%);
            transform: rotate(15deg) scale(1.05);
        }
        /* Shape 4: Bold Circle */
        .pin-slot.filled[data-shape="4"] .pin-shape {
            border-radius: 50%;
            transform: scale(1.05);
        }
        /* Shape 5: Hexagon */
        .pin-slot.filled[data-shape="5"] .pin-shape {
            clip-path: polygon(25% 0%, 75% 0%, 100% 50%, 75% 100%, 25% 100%, 0% 50%);
            transform: rotate(30deg) scale(1.1);
        }

        .pin-slot.pop .pin-shape {
            transform: scale(1.38) rotate(12deg) !important;
        }

        /* Material 3 Expressive Numpad Grid */
        .m3-numpad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: clamp(10px, 1.8vh, 16px);
            width: 100%;
            max-width: 330px;
        }

        .m3-key {
            position: relative;
            overflow: hidden;
            height: clamp(64px, 9.5vh, 78px);
            border-radius: 999px;
            background: var(--surface-2);
            border: 1px solid var(--border-subtle);
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            outline: none;
            transition:
                border-radius 0.28s var(--m3-spring-Morph),
                transform 0.22s var(--m3-spring-fast),
                background-color 0.2s ease,
                color 0.2s ease,
                border-color 0.2s ease;
        }

        .m3-key:hover {
            background: var(--surface-3);
            border-color: var(--border-strong);
        }

        /* Iconic Material 3 Expressive Pill-to-Squircle Morph on Press */
        .m3-key:active,
        .m3-key.pressed {
            border-radius: 20px;
            transform: scale(0.93);
            background: #ffffff;
            color: #000000;
            border-color: #ffffff;
        }

        .m3-key-num {
            font-family: var(--font-sans);
            font-size: clamp(1.55rem, 3vh, 1.9rem);
            font-weight: 500;
            line-height: 1.05;
        }

        .m3-key-letters {
            font-size: 0.62rem;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            opacity: 0.45;
            margin-top: 1px;
        }

        .m3-key:active .m3-key-letters,
        .m3-key.pressed .m3-key-letters {
            opacity: 0.75;
        }

        /* Action Keys (Backspace & Enter) */
        .m3-key.action-secondary {
            background: transparent;
            border-color: transparent;
        }

        .m3-key.action-secondary:hover {
            background: var(--surface-2);
            border-color: var(--border-subtle);
        }

        .m3-key.action-secondary:active,
        .m3-key.action-secondary.pressed {
            background: var(--surface-3);
            color: #fff;
            border-radius: 20px;
        }

        .m3-key.action-primary {
            background: #ffffff;
            color: #000000;
            border-color: #ffffff;
        }

        .m3-key.action-primary:hover {
            background: #e6e6e6;
        }

        .m3-key.action-primary:active,
        .m3-key.action-primary.pressed {
            border-radius: 18px;
            transform: scale(0.91);
            background: #cfcfcf;
        }

        /* Ripple animation inside keys */
        .m3-ripple {
            position: absolute;
            border-radius: 50%;
            transform: scale(0);
            background: rgba(255, 255, 255, 0.25);
            pointer-events: none;
            animation: rippleAnim 0.55s ease-out forwards;
        }

        @keyframes rippleAnim {
            to {
                transform: scale(3.2);
                opacity: 0;
            }
        }

        /* =====================================================
           CMS STUDIO WORKSPACE (B&W MATERIAL EXPRESSIVE)
           ===================================================== */
        #cms-app {
            display: flex;
            min-height: 100dvh;
            padding-bottom: 100px;
        }

        /* Desktop Sidebar Navigation */
        .cms-sidebar {
            width: 270px;
            background: var(--surface-1);
            border-right: 1px solid var(--border-subtle);
            padding: 28px 18px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: sticky;
            top: 0;
            height: 100dvh;
            flex-shrink: 0;
        }

        .cms-brand {
            padding: 0 12px 24px;
            border-bottom: 1px solid var(--border-subtle);
            margin-bottom: 20px;
        }

        .cms-brand-title {
            font-family: var(--font-display);
            font-size: 1.35rem;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            line-height: 1.1;
        }

        .cms-brand-sub {
            font-family: var(--font-mono);
            font-size: 1rem;
            color: var(--text-secondary);
            margin-top: 4px;
        }

        .cms-nav-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            list-style: none;
        }

        .cms-nav-btn {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 18px;
            border-radius: 999px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--text-secondary);
            font-family: var(--font-sans);
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            text-align: left;
            transition:
                background-color 0.25s ease,
                color 0.25s ease,
                border-radius 0.3s var(--m3-spring-Morph),
                transform 0.2s var(--m3-spring-fast);
        }

        .cms-nav-btn:hover {
            background: var(--surface-2);
            color: #fff;
        }

        .cms-nav-btn:active {
            border-radius: 16px;
            transform: scale(0.97);
        }

        .cms-nav-btn.active {
            background: #ffffff;
            color: #000000;
            font-weight: 600;
        }

        .cms-sidebar-footer {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding-top: 18px;
            border-top: 1px solid var(--border-subtle);
        }

        /* Main Content Area */
        .cms-main {
            flex: 1;
            max-width: 1040px;
            margin: 0 auto;
            padding: clamp(24px, 4vw, 48px) clamp(16px, 4vw, 44px);
            width: 100%;
        }

        .cms-section-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-subtle);
        }

        .cms-section-title {
            font-family: var(--font-display);
            font-size: clamp(1.6rem, 3.5vw, 2.4rem);
            text-transform: uppercase;
            line-height: 1.05;
        }

        .cms-section-desc {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin-top: 6px;
        }

        /* Expressive Cards & Surfaces */
        .m3-card {
            background: var(--surface-1);
            border: 1px solid var(--border-subtle);
            border-radius: 28px;
            padding: clamp(20px, 3vw, 30px);
            margin-bottom: 22px;
            transition: border-color 0.25s ease, transform 0.25s ease;
        }

        .m3-card:hover {
            border-color: rgba(255, 255, 255, 0.18);
        }

        .m3-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .m3-card-title {
            font-size: 1.15rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .m3-badge {
            font-family: var(--font-mono);
            font-size: 0.95rem;
            padding: 3px 10px;
            border-radius: 999px;
            background: var(--surface-3);
            color: #fff;
            border: 1px solid var(--border-subtle);
        }

        /* Form Controls */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-label {
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-secondary);
        }

        .m3-input,
        .m3-textarea,
        .m3-select {
            width: 100%;
            background: var(--surface-2);
            border: 1px solid var(--border-subtle);
            border-radius: 16px;
            padding: 13px 16px;
            color: #fff;
            font-family: var(--font-sans);
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s ease, background-color 0.2s ease, border-radius 0.25s var(--m3-spring-Morph);
        }

        .m3-input:focus,
        .m3-textarea:focus,
        .m3-select:focus {
            border-color: #ffffff;
            background: var(--surface-3);
            border-radius: 12px;
        }

        .m3-textarea {
            min-height: 96px;
            resize: vertical;
            line-height: 1.5;
        }

        /* Image Input + Upload Row */
        .img-field-row {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .img-preview-thumb {
            width: 64px;
            height: 64px;
            border-radius: 14px;
            background: var(--surface-3);
            border: 1px solid var(--border-subtle);
            object-fit: cover;
            flex-shrink: 0;
        }

        .img-field-controls {
            flex: 1;
            min-width: 200px;
            display: flex;
            gap: 8px;
        }

        /* Buttons */
        .m3-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 20px;
            border-radius: 999px;
            border: 1px solid var(--border-subtle);
            background: var(--surface-2);
            color: #fff;
            font-family: var(--font-sans);
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            transition:
                border-radius 0.25s var(--m3-spring-Morph),
                transform 0.2s var(--m3-spring-fast),
                background-color 0.2s ease,
                color 0.2s ease;
        }

        .m3-btn:hover {
            background: var(--surface-3);
            border-color: var(--border-strong);
        }

        .m3-btn:active {
            border-radius: 14px;
            transform: scale(0.95);
        }

        .m3-btn.primary {
            background: #ffffff;
            color: #000000;
            border-color: #ffffff;
        }

        .m3-btn.primary:hover {
            background: #e4e4e7;
        }

        .m3-btn.danger {
            color: #fca5a5;
            border-color: rgba(252, 165, 165, 0.25);
        }

        .m3-btn.danger:hover {
            background: rgba(239, 68, 68, 0.15);
            border-color: rgba(252, 165, 165, 0.5);
        }

        .m3-btn.icon-only {
            padding: 9px 12px;
        }

        /* Material 3 Expressive Toggle Switch */
        .m3-switch-wrap {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            user-select: none;
        }

        .m3-switch {
            width: 52px;
            height: 30px;
            border-radius: 999px;
            background: var(--surface-3);
            border: 2px solid var(--border-strong);
            position: relative;
            transition: background-color 0.25s ease, border-color 0.25s ease;
        }

        .m3-switch-thumb {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: var(--text-secondary);
            position: absolute;
            top: 50%;
            left: 5px;
            transform: translateY(-50%);
            transition:
                left 0.28s var(--m3-spring-fast),
                width 0.25s var(--m3-spring-fast),
                height 0.25s var(--m3-spring-fast),
                background-color 0.2s ease;
        }

        .m3-switch.on {
            background: #ffffff;
            border-color: #ffffff;
        }

        .m3-switch.on .m3-switch-thumb {
            width: 22px;
            height: 22px;
            left: 23px;
            background: #000000;
        }

        /* Interactive Tech Stack & Skill Chips */
        .chips-pool {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .m3-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            background: var(--surface-2);
            border: 1px solid var(--border-subtle);
            color: var(--text-secondary);
            font-size: 0.84rem;
            font-weight: 500;
            cursor: pointer;
            user-select: none;
            transition:
                border-radius 0.25s var(--m3-spring-Morph),
                background-color 0.2s ease,
                color 0.2s ease,
                transform 0.2s var(--m3-spring-fast);
        }

        .m3-chip img {
            width: 16px;
            height: 16px;
            object-fit: contain;
        }

        .m3-chip.selected {
            background: #ffffff;
            color: #000000;
            border-color: #ffffff;
            border-radius: 12px;
            font-weight: 600;
        }

        .m3-chip:active {
            transform: scale(0.93);
        }

        /* Floating Save Bar */
        .floating-save-bar {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(140%);
            z-index: 4000;
            background: #ffffff;
            color: #000000;
            padding: 10px 12px 10px 22px;
            border-radius: 999px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.8), 0 0 0 1px rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: transform 0.4s var(--m3-spring-fast);
        }

        .floating-save-bar.visible {
            transform: translateX(-50%) translateY(0);
        }

        .save-bar-text {
            font-size: 0.9rem;
            font-weight: 600;
        }

        .save-now-btn {
            background: #000000;
            color: #ffffff;
            border: none;
            padding: 10px 22px;
            border-radius: 999px;
            font-family: var(--font-sans);
            font-weight: 600;
            font-size: 0.88rem;
            cursor: pointer;
            transition: transform 0.2s var(--m3-spring-fast), border-radius 0.25s ease;
        }

        .save-now-btn:active {
            transform: scale(0.94);
            border-radius: 14px;
        }

        /* Toast Notification */
        #toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 6000;
            background: #ffffff;
            color: #000000;
            padding: 12px 22px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.9rem;
            box-shadow: 0 14px 36px rgba(0, 0, 0, 0.6);
            transform: translateY(-150%);
            transition: transform 0.35s var(--m3-spring-fast);
        }

        #toast.show {
            transform: translateY(0);
        }

        /* Mobile Bottom Bar (Material 3 Expressive Navigation Bar) */
        .cms-mobile-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 3500;
            background: rgba(18, 18, 18, 0.92);
            backdrop-filter: blur(16px);
            border-top: 1px solid var(--border-subtle);
            padding: 8px 10px calc(8px + env(safe-area-inset-bottom));
            justify-content: space-around;
            gap: 4px;
        }

        .mobile-nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            background: transparent;
            border: none;
            color: var(--text-secondary);
            font-family: var(--font-sans);
            font-size: 0.72rem;
            font-weight: 500;
            cursor: pointer;
            padding: 4px 0;
        }

        .mobile-nav-pill {
            padding: 4px 16px;
            border-radius: 999px;
            font-size: 0.95rem;
            transition: background-color 0.25s var(--m3-spring-fast), color 0.2s ease, transform 0.2s var(--m3-spring-fast);
        }

        .mobile-nav-item.active {
            color: #ffffff;
            font-weight: 600;
        }

        .mobile-nav-item.active .mobile-nav-pill {
            background: #ffffff;
            color: #000000;
        }

        @media (max-width: 860px) {
            .cms-sidebar {
                display: none;
            }
            .cms-mobile-nav {
                display: flex;
            }
            #cms-app {
                padding-bottom: 140px;
            }
            .floating-save-bar {
                bottom: 82px;
                width: calc(100% - 32px);
                max-width: 420px;
                justify-content: space-between;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-height: 680px) {
            .m3-key {
                height: 54px;
            }
            .lock-body {
                gap: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="grain-overlay"></div>
    <div id="toast">Saved!</div>

    <!-- ==========================================================
         1. FULL-SCREEN MATERIAL 3 EXPRESSIVE B&W LOCK SCREEN
         ========================================================== -->
    <section id="lock-screen" class="<?php echo $isAuthed ? 'unlocked' : ''; ?>">
        <div class="lock-topbar">
            <a href="index.html" class="lock-back-link">← Back to Portfolio</a>
        </div>

        <div class="lock-body">
            <!-- Morphing PIN Dots -->
            <div class="pin-dots-stage" id="pin-dots-stage" aria-label="PIN progress"></div>

            <!-- Material 3 Expressive Numpad -->
            <div class="m3-numpad" id="m3-numpad">
                <button type="button" class="m3-key" data-digit="1">
                    <span class="m3-key-num">1</span>
                    <span class="m3-key-letters">&nbsp;</span>
                </button>
                <button type="button" class="m3-key" data-digit="2">
                    <span class="m3-key-num">2</span>
                    <span class="m3-key-letters">ABC</span>
                </button>
                <button type="button" class="m3-key" data-digit="3">
                    <span class="m3-key-num">3</span>
                    <span class="m3-key-letters">DEF</span>
                </button>
                <button type="button" class="m3-key" data-digit="4">
                    <span class="m3-key-num">4</span>
                    <span class="m3-key-letters">GHI</span>
                </button>
                <button type="button" class="m3-key" data-digit="5">
                    <span class="m3-key-num">5</span>
                    <span class="m3-key-letters">JKL</span>
                </button>
                <button type="button" class="m3-key" data-digit="6">
                    <span class="m3-key-num">6</span>
                    <span class="m3-key-letters">MNO</span>
                </button>
                <button type="button" class="m3-key" data-digit="7">
                    <span class="m3-key-num">7</span>
                    <span class="m3-key-letters">PQRS</span>
                </button>
                <button type="button" class="m3-key" data-digit="8">
                    <span class="m3-key-num">8</span>
                    <span class="m3-key-letters">TUV</span>
                </button>
                <button type="button" class="m3-key" data-digit="9">
                    <span class="m3-key-num">9</span>
                    <span class="m3-key-letters">WXYZ</span>
                </button>
                <button type="button" class="m3-key action-secondary" id="key-backspace" aria-label="Backspace">
                    <span class="m3-key-num">⌫</span>
                </button>
                <button type="button" class="m3-key" data-digit="0">
                    <span class="m3-key-num">0</span>
                    <span class="m3-key-letters">+</span>
                </button>
                <button type="button" class="m3-key action-primary" id="key-submit" aria-label="Unlock">
                    <span class="m3-key-num">→</span>
                </button>
            </div>
        </div>
    </section>

    <!-- ==========================================================
         2. CMS STUDIO WORKSPACE
         ========================================================== -->
    <div id="cms-app">
        <aside class="cms-sidebar">
            <div>
                <div class="cms-brand">
                    <div class="cms-brand-title">Pnam Studio</div>
                    <div class="cms-brand-sub">B&W Expressive CMS</div>
                </div>
                <ul class="cms-nav-list" id="desktop-nav">
                    <li><button class="cms-nav-btn active" data-tab="hero"><span>01</span> Hero & Bio</button></li>
                    <li><button class="cms-nav-btn" data-tab="projects"><span>02</span> Projects</button></li>
                    <li><button class="cms-nav-btn" data-tab="about"><span>03</span> About & Timeline</button></li>
                    <li><button class="cms-nav-btn" data-tab="contact"><span>04</span> Contact & Footer</button></li>
                    <li><button class="cms-nav-btn" data-tab="security"><span>05</span> PIN & Backup</button></li>
                </ul>
            </div>

            <div class="cms-sidebar-footer">
                <button class="m3-btn primary" onclick="saveContent()">Save Changes</button>
                <a href="index.html" target="_blank" class="m3-btn">View Live Site ↗</a>
                <button class="m3-btn" onclick="lockStudio()">Lock Studio</button>
            </div>
        </aside>

        <main class="cms-main" id="cms-main-container">
            <!-- Dynamically rendered active tab -->
        </main>
    </div>

    <!-- Mobile Bottom Navigation -->
    <nav class="cms-mobile-nav" id="mobile-nav">
        <button class="mobile-nav-item active" data-tab="hero">
            <span class="mobile-nav-pill">01</span>
            <span>Hero</span>
        </button>
        <button class="mobile-nav-item" data-tab="projects">
            <span class="mobile-nav-pill">02</span>
            <span>Projects</span>
        </button>
        <button class="mobile-nav-item" data-tab="about">
            <span class="mobile-nav-pill">03</span>
            <span>About</span>
        </button>
        <button class="mobile-nav-item" data-tab="contact">
            <span class="mobile-nav-pill">04</span>
            <span>Contact</span>
        </button>
        <button class="mobile-nav-item" data-tab="security">
            <span class="mobile-nav-pill">⚙</span>
            <span>PIN</span>
        </button>
    </nav>

    <!-- Floating Unsaved Changes Pill -->
    <div class="floating-save-bar" id="floating-save-bar">
        <span class="save-bar-text">Unsaved changes</span>
        <button class="save-now-btn" onclick="saveContent()">Save Now</button>
    </div>

    <!-- Hidden File Input for Image Uploads -->
    <input type="file" id="hidden-file-input" accept="image/*" style="display:none">

    <script>
        let expectedPinLength = <?php echo $pinLength; ?>;
        let isAuthenticated = <?php echo $isAuthed ? 'true' : 'false'; ?>;
        let currentPin = '';
        let isVerifyingPin = false;
        let cmsData = null;
        let activeTab = 'hero';
        let isDirty = false;
        let pendingUploadCallback = null;

        // 1. Render Morphing PIN Slots
        function renderPinSlots() {
            const stage = document.getElementById('pin-dots-stage');
            stage.innerHTML = '';
            const count = Math.max(expectedPinLength, currentPin.length, 4);
            for (let i = 0; i < count; i++) {
                const slot = document.createElement('div');
                const filled = i < currentPin.length;
                slot.className = 'pin-slot' + (filled ? ' filled' : '');
                slot.dataset.shape = String(i % 6);
                const shape = document.createElement('div');
                shape.className = 'pin-shape';
                slot.appendChild(shape);
                stage.appendChild(slot);
            }
        }
        renderPinSlots();

        function updatePinUI(popIndex = -1) {
            const slots = document.querySelectorAll('#pin-dots-stage .pin-slot');
            if (slots.length !== Math.max(expectedPinLength, currentPin.length, 4)) {
                renderPinSlots();
            }
            const updatedSlots = document.querySelectorAll('#pin-dots-stage .pin-slot');
            updatedSlots.forEach((slot, idx) => {
                if (idx < currentPin.length) {
                    slot.classList.add('filled');
                } else {
                    slot.classList.remove('filled', 'pop');
                }
            });
            if (popIndex >= 0 && updatedSlots[popIndex]) {
                const targetSlot = updatedSlots[popIndex];
                targetSlot.classList.add('pop');
                setTimeout(() => targetSlot.classList.remove('pop'), 180);
            }
        }

        function spawnRipple(btn, clientX, clientY) {
            const rect = btn.getBoundingClientRect();
            const ripple = document.createElement('span');
            ripple.className = 'm3-ripple';
            const size = Math.max(rect.width, rect.height);
            ripple.style.width = ripple.style.height = `${size}px`;
            const x = (clientX || (rect.left + rect.width / 2)) - rect.left - size / 2;
            const y = (clientY || (rect.top + rect.height / 2)) - rect.top - size / 2;
            ripple.style.left = `${x}px`;
            ripple.style.top = `${y}px`;
            btn.appendChild(ripple);
            setTimeout(() => ripple.remove(), 550);
        }

        function pressDigit(digit) {
            if (isVerifyingPin || currentPin.length >= 8) return;

            currentPin += digit;
            updatePinUI(currentPin.length - 1);

            if (currentPin.length === expectedPinLength) {
                setTimeout(() => submitPin(), 140);
            }
        }

        function backspacePin() {
            if (isVerifyingPin || currentPin.length === 0) return;
            currentPin = currentPin.slice(0, -1);
            updatePinUI();
        }

        async function submitPin() {
            if (isVerifyingPin || currentPin.length === 0) return;
            isVerifyingPin = true;
            const stage = document.getElementById('pin-dots-stage');

            try {
                const res = await fetch('cms.php?action=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ pin: currentPin })
                });
                const data = await res.json();
                if (res.ok && data.ok) {
                    isAuthenticated = true;
                    document.getElementById('lock-screen').classList.add('unlocked');
                    currentPin = '';
                    updatePinUI();
                    loadCmsContent();
                } else {
                    stage.classList.remove('shake');
                    void stage.offsetWidth;
                    stage.classList.add('shake');
                    if (res.status === 429 && data.error) {
                        showToast(data.error);
                    }
                    setTimeout(() => {
                        currentPin = '';
                        updatePinUI();
                    }, 320);
                }
            } catch (e) {
                showToast('Connection error');
            } finally {
                isVerifyingPin = false;
            }
        }

        // Bind Numpad Clicks & Physical Keyboard
        document.querySelectorAll('#m3-numpad .m3-key[data-digit]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                spawnRipple(btn, e.clientX, e.clientY);
                pressDigit(btn.dataset.digit);
            });
        });

        document.getElementById('key-backspace').addEventListener('click', (e) => {
            spawnRipple(e.currentTarget, e.clientX, e.clientY);
            backspacePin();
        });

        document.getElementById('key-submit').addEventListener('click', (e) => {
            spawnRipple(e.currentTarget, e.clientX, e.clientY);
            submitPin();
        });

        window.addEventListener('keydown', (e) => {
            if (isAuthenticated) {
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
                    e.preventDefault();
                    saveContent();
                }
                return;
            }
            if (e.key >= '0' && e.key <= '9') {
                const btn = document.querySelector(`.m3-key[data-digit="${e.key}"]`);
                if (btn) {
                    btn.classList.add('pressed');
                    spawnRipple(btn);
                    setTimeout(() => btn.classList.remove('pressed'), 160);
                }
                pressDigit(e.key);
            } else if (e.key === 'Backspace') {
                const btn = document.getElementById('key-backspace');
                btn.classList.add('pressed');
                setTimeout(() => btn.classList.remove('pressed'), 160);
                backspacePin();
            } else if (e.key === 'Enter') {
                const btn = document.getElementById('key-submit');
                btn.classList.add('pressed');
                setTimeout(() => btn.classList.remove('pressed'), 160);
                submitPin();
            }
        });

        // 3. CMS Data Loading & Saving
        async function loadCmsContent() {
            try {
                const res = await fetch('cms.php?action=get_content', { cache: 'no-store' });
                if (res.status === 403) {
                    isAuthenticated = false;
                    document.getElementById('lock-screen').classList.remove('unlocked');
                    return;
                }
                cmsData = await res.json();
                isDirty = false;
                updateSaveBar();
                renderActiveTab();
            } catch (err) {
                showToast('Failed to load content.json');
            }
        }

        function markDirty() {
            isDirty = true;
            updateSaveBar();
        }

        function updateSaveBar() {
            const bar = document.getElementById('floating-save-bar');
            if (isDirty) {
                bar.classList.add('visible');
            } else {
                bar.classList.remove('visible');
            }
        }

        async function saveContent() {
            if (!cmsData) return;
            try {
                const res = await fetch('cms.php?action=save_content', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(cmsData)
                });
                const result = await res.json();
                if (res.ok && result.ok) {
                    isDirty = false;
                    updateSaveBar();
                    showToast(`Saved at ${result.savedAt}`);
                } else {
                    showToast(result.error || 'Save failed');
                }
            } catch (err) {
                showToast('Network error while saving');
            }
        }

        async function lockStudio() {
            await fetch('cms.php?action=logout');
            isAuthenticated = false;
            currentPin = '';
            updatePinUI();
            document.getElementById('lock-screen').classList.remove('unlocked');
        }

        function showToast(msg) {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.classList.add('show');
            clearTimeout(window._toastTimer);
            window._toastTimer = setTimeout(() => t.classList.remove('show'), 2600);
        }

        // Image Upload Helper
        const fileInput = document.getElementById('hidden-file-input');
        fileInput.addEventListener('change', async () => {
            if (!fileInput.files || !fileInput.files[0]) return;
            const formData = new FormData();
            formData.append('file', fileInput.files[0]);
            showToast('Uploading image...');
            try {
                const res = await fetch('cms.php?action=upload_image', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (res.ok && data.ok && pendingUploadCallback) {
                    pendingUploadCallback(data.url);
                    markDirty();
                    renderActiveTab();
                    showToast('Image uploaded!');
                } else {
                    showToast(data.error || 'Upload error');
                }
            } catch (e) {
                showToast('Upload failed');
            } finally {
                fileInput.value = '';
                pendingUploadCallback = null;
            }
        });

        function triggerImageUpload(callback) {
            pendingUploadCallback = callback;
            fileInput.click();
        }

        // Navigation Tabs
        function switchTab(tab) {
            activeTab = tab;
            document.querySelectorAll('#desktop-nav .cms-nav-btn, #mobile-nav .mobile-nav-item').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.tab === tab);
            });
            renderActiveTab();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        document.querySelectorAll('#desktop-nav .cms-nav-btn, #mobile-nav .mobile-nav-item').forEach(btn => {
            btn.addEventListener('click', () => switchTab(btn.dataset.tab));
        });

        function escapeHtml(str) {
            return String(str ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        // 4. Render Active Tab
        function renderActiveTab() {
            const container = document.getElementById('cms-main-container');
            if (!cmsData) {
                container.innerHTML = `<div class="m3-card">Loading content...</div>`;
                return;
            }

            if (activeTab === 'hero') renderHeroTab(container);
            else if (activeTab === 'projects') renderProjectsTab(container);
            else if (activeTab === 'about') renderAboutTab(container);
            else if (activeTab === 'contact') renderContactTab(container);
            else if (activeTab === 'security') renderSecurityTab(container);
        }

        // TAB 1: HERO & NAVBAR
        function renderHeroTab(container) {
            const h = cmsData.hero || {};
            container.innerHTML = `
                <div class="cms-section-header">
                    <div>
                        <h2 class="cms-section-title">01 // Hero & Navigation</h2>
                        <p class="cms-section-desc">Edit your first impression, bio text, profile photo, and navbar section labels.</p>
                    </div>
                    <button class="m3-btn primary" onclick="saveContent()">Save Changes</button>
                </div>

                <div class="m3-card">
                    <div class="m3-card-header">
                        <div class="m3-card-title">Hero Introduction</div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group full">
                            <label class="form-label">Greeting Headline</label>
                            <input type="text" class="m3-input" value="${escapeHtml(h.greeting)}" oninput="cmsData.hero.greeting = this.value; markDirty()">
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Bio Paragraph</label>
                            <textarea class="m3-textarea" oninput="cmsData.hero.bio = this.value; markDirty()">${escapeHtml(h.bio)}</textarea>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Profile Image URL or Upload</label>
                            <div class="img-field-row">
                                <img src="${escapeHtml(h.image)}" class="img-preview-thumb" onerror="this.style.opacity=0.2">
                                <div class="img-field-controls">
                                    <input type="text" class="m3-input" value="${escapeHtml(h.image)}" oninput="cmsData.hero.image = this.value; this.closest('.img-field-row').querySelector('img').src = this.value; markDirty()">
                                    <button type="button" class="m3-btn" onclick="triggerImageUpload(url => { cmsData.hero.image = url; })">Upload</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="m3-card">
                    <div class="m3-card-header">
                        <div class="m3-card-title">Navbar Pill Titles</div>
                    </div>
                    <div class="form-grid">
                        ${(cmsData.navbar || []).map((item, i) => `
                            <div class="form-group">
                                <label class="form-label">Section #${i + 1} (${escapeHtml(item.id)})</label>
                                <input type="text" class="m3-input" value="${escapeHtml(item.sectionTitle)}" oninput="cmsData.navbar[${i}].sectionTitle = this.value; markDirty()">
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        }

        // TAB 2: PROJECTS
        function renderProjectsTab(container) {
            const projects = cmsData.projects || [];
            const techIcons = (cmsData.assets && cmsData.assets.techIcons) ? cmsData.assets.techIcons : {};
            const allTechKeys = Object.keys(techIcons);

            container.innerHTML = `
                <div class="cms-section-header">
                    <div>
                        <h2 class="cms-section-title">02 // Full-Screen Projects</h2>
                        <p class="cms-section-desc">Manage project cards, reorder them, toggle maintenance status, and pick tech stack icons.</p>
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button class="m3-btn" onclick="addProject()">+ Add Project</button>
                        <button class="m3-btn primary" onclick="saveContent()">Save Changes</button>
                    </div>
                </div>

                ${projects.map((proj, idx) => {
                    const num = String(idx + 1).padStart(2, '0');
                    const selectedStack = Array.isArray(proj.techStack) ? proj.techStack : [];
                    return `
                    <div class="m3-card">
                        <div class="m3-card-header">
                            <div class="m3-card-title">
                                <span class="m3-badge">${num}</span>
                                <span>${escapeHtml(proj.title || 'Untitled Project')}</span>
                            </div>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <button type="button" class="m3-btn icon-only" title="Move Up" onclick="moveProject(${idx}, -1)" ${idx === 0 ? 'disabled style="opacity:0.3"' : ''}>↑</button>
                                <button type="button" class="m3-btn icon-only" title="Move Down" onclick="moveProject(${idx}, 1)" ${idx === projects.length - 1 ? 'disabled style="opacity:0.3"' : ''}>↓</button>
                                <button type="button" class="m3-btn danger icon-only" title="Delete Project" onclick="deleteProject(${idx})">✕</button>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Project Title</label>
                                <input type="text" class="m3-input" value="${escapeHtml(proj.title)}" oninput="cmsData.projects[${idx}].title = this.value; markDirty()">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Title Typography Style</label>
                                <select class="m3-select" onchange="cmsData.projects[${idx}].fontStyle = this.value; markDirty()">
                                    <option value="stretched" ${proj.fontStyle !== '8-bit' ? 'selected' : ''}>Stretched Display (Bowlby One SC)</option>
                                    <option value="8-bit" ${proj.fontStyle === '8-bit' ? 'selected' : ''}>8-Bit Pixel (VT323)</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Live Project Link (URL)</label>
                                <input type="text" class="m3-input" value="${escapeHtml(proj.link)}" oninput="cmsData.projects[${idx}].link = this.value; markDirty()">
                            </div>

                            <div class="form-group" style="justify-content:center;">
                                <label class="form-label">Maintenance Status</label>
                                <div class="m3-switch-wrap" onclick="toggleProjectMaintained(${idx})">
                                    <div class="m3-switch ${proj.maintained ? 'on' : ''}">
                                        <div class="m3-switch-thumb"></div>
                                    </div>
                                    <span>${proj.maintained ? 'Actively Maintained' : 'No Longer Maintained'}</span>
                                </div>
                            </div>

                            <div class="form-group full">
                                <label class="form-label">Description</label>
                                <textarea class="m3-textarea" oninput="cmsData.projects[${idx}].description = this.value; markDirty()">${escapeHtml(proj.description)}</textarea>
                            </div>

                            <div class="form-group full">
                                <label class="form-label">Project Screenshot URL or Upload</label>
                                <div class="img-field-row">
                                    <img src="${escapeHtml(proj.image)}" class="img-preview-thumb" onerror="this.style.opacity=0.2">
                                    <div class="img-field-controls">
                                        <input type="text" class="m3-input" value="${escapeHtml(proj.image)}" oninput="cmsData.projects[${idx}].image = this.value; this.closest('.img-field-row').querySelector('img').src = this.value; markDirty()">
                                        <button type="button" class="m3-btn" onclick="triggerImageUpload(url => { cmsData.projects[${idx}].image = url; })">Upload</button>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Client Feedback Quote (Optional)</label>
                                <input type="text" class="m3-input" placeholder="Leave empty to hide quote" value="${escapeHtml(proj.customer_feedback || '')}" oninput="updateProjectFeedback(${idx}, 'quote', this.value)">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Client Name / Role (Optional)</label>
                                <input type="text" class="m3-input" placeholder="e.g. Castel - InfinityBot's creator" value="${escapeHtml(proj.customer_name || '')}" oninput="updateProjectFeedback(${idx}, 'name', this.value)">
                            </div>

                            <div class="form-group full">
                                <label class="form-label">Tech Stack (Tap chips to toggle)</label>
                                <div class="chips-pool">
                                    ${allTechKeys.map(tech => {
                                        const isSelected = selectedStack.includes(tech);
                                        return `
                                            <div class="m3-chip ${isSelected ? 'selected' : ''}" onclick="toggleProjectTech(${idx}, '${escapeHtml(tech)}')">
                                                <img src="${escapeHtml(techIcons[tech])}" alt="">
                                                <span>${escapeHtml(tech)}</span>
                                            </div>
                                        `;
                                    }).join('')}
                                </div>
                            </div>
                        </div>
                    </div>
                    `;
                }).join('')}

                <div class="m3-card">
                    <div class="m3-card-header">
                        <div class="m3-card-title">Tech Stack Icon Library</div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">New Tech Key (e.g. typescript)</label>
                            <input type="text" id="new-tech-key" class="m3-input" placeholder="typescript">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Icon Image URL</label>
                            <div style="display:flex; gap:8px;">
                                <input type="text" id="new-tech-url" class="m3-input" placeholder="https://...">
                                <button type="button" class="m3-btn primary" onclick="addTechIcon()">+ Add Icon</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        function toggleProjectMaintained(idx) {
            cmsData.projects[idx].maintained = !cmsData.projects[idx].maintained;
            markDirty();
            renderActiveTab();
        }

        function updateProjectFeedback(idx, type, val) {
            if (type === 'quote') {
                if (val.trim()) cmsData.projects[idx].customer_feedback = val;
                else delete cmsData.projects[idx].customer_feedback;
            } else {
                if (val.trim()) cmsData.projects[idx].customer_name = val;
                else delete cmsData.projects[idx].customer_name;
            }
            markDirty();
        }

        function toggleProjectTech(idx, techKey) {
            if (!Array.isArray(cmsData.projects[idx].techStack)) {
                cmsData.projects[idx].techStack = [];
            }
            const arr = cmsData.projects[idx].techStack;
            const pos = arr.indexOf(techKey);
            if (pos >= 0) arr.splice(pos, 1);
            else arr.push(techKey);
            markDirty();
            renderActiveTab();
        }

        function addTechIcon() {
            const k = document.getElementById('new-tech-key').value.trim().toLowerCase();
            const u = document.getElementById('new-tech-url').value.trim();
            if (!k || !u) return showToast('Enter both tech name and icon URL');
            if (!cmsData.assets) cmsData.assets = { techIcons: {} };
            if (!cmsData.assets.techIcons) cmsData.assets.techIcons = {};
            cmsData.assets.techIcons[k] = u;
            markDirty();
            renderActiveTab();
            showToast(`Added ${k} icon!`);
        }

        function moveProject(idx, dir) {
            const target = idx + dir;
            if (target < 0 || target >= cmsData.projects.length) return;
            const temp = cmsData.projects[idx];
            cmsData.projects[idx] = cmsData.projects[target];
            cmsData.projects[target] = temp;
            markDirty();
            renderActiveTab();
        }

        function addProject() {
            cmsData.projects.unshift({
                title: "New Project",
                fontStyle: "stretched",
                description: "Describe your project here.",
                image: "https://portfolio.hgphnm.com/assets/portfolio.png",
                link: "https://",
                techStack: ["html", "css", "javascript"],
                maintained: true
            });
            markDirty();
            renderActiveTab();
            showToast('Added new project at top');
        }

        function deleteProject(idx) {
            if (!confirm(`Delete "${cmsData.projects[idx].title}"?`)) return;
            cmsData.projects.splice(idx, 1);
            markDirty();
            renderActiveTab();
        }

        // TAB 3: ABOUT & TIMELINE
        function renderAboutTab(container) {
            const about = cmsData.about || { skills: [], timeline: [] };
            container.innerHTML = `
                <div class="cms-section-header">
                    <div>
                        <h2 class="cms-section-title">03 // About & Timeline</h2>
                        <p class="cms-section-desc">Update your skills pills and interactive journey milestones.</p>
                    </div>
                    <button class="m3-btn primary" onclick="saveContent()">Save Changes</button>
                </div>

                <div class="m3-card">
                    <div class="m3-card-header">
                        <div class="m3-card-title">About Header & Skills</div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Section Heading</label>
                            <input type="text" class="m3-input" value="${escapeHtml(about.heading)}" oninput="cmsData.about.heading = this.value; markDirty()">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Add a Skill Pill</label>
                            <div style="display:flex; gap:8px;">
                                <input type="text" id="new-skill-input" class="m3-input" placeholder="e.g. TypeScript" onkeydown="if(event.key==='Enter'){event.preventDefault();addSkill();}">
                                <button type="button" class="m3-btn primary" onclick="addSkill()">+ Add</button>
                            </div>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Subtitle / Description</label>
                            <textarea class="m3-textarea" oninput="cmsData.about.description = this.value; markDirty()">${escapeHtml(about.description)}</textarea>
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Current Skills (Tap to remove)</label>
                            <div class="chips-pool">
                                ${(about.skills || []).map((skill, sIdx) => `
                                    <div class="m3-chip selected" onclick="removeSkill(${sIdx})" title="Tap to remove">
                                        <span>${escapeHtml(skill)}</span>
                                        <span style="opacity:0.5;">✕</span>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="cms-section-header" style="margin-top:36px;">
                    <div>
                        <h3 class="cms-section-title" style="font-size:1.5rem;">Journey Milestones</h3>
                        <p class="cms-section-desc">Use <code>dynamic</code> as the date and <code>{{YEARS_SINCE_START}}</code> in the description for automatic year counting.</p>
                    </div>
                    <button type="button" class="m3-btn" onclick="addTimelineItem()">+ Add Milestone</button>
                </div>

                ${(about.timeline || []).map((item, idx) => `
                    <div class="m3-card">
                        <div class="m3-card-header">
                            <div class="m3-card-title">
                                <span class="m3-badge">#${idx + 1}</span>
                                <span>${escapeHtml(item.title)}</span>
                            </div>
                            <div style="display:flex; gap:8px;">
                                <button type="button" class="m3-btn icon-only" onclick="moveTimeline(${idx}, -1)" ${idx === 0 ? 'disabled style="opacity:0.3"' : ''}>↑</button>
                                <button type="button" class="m3-btn icon-only" onclick="moveTimeline(${idx}, 1)" ${idx === about.timeline.length - 1 ? 'disabled style="opacity:0.3"' : ''}>↓</button>
                                <button type="button" class="m3-btn danger icon-only" onclick="deleteTimeline(${idx})">✕</button>
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Date Label (or "dynamic")</label>
                                <input type="text" class="m3-input" value="${escapeHtml(item.date)}" oninput="cmsData.about.timeline[${idx}].date = this.value; markDirty()">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Milestone Title</label>
                                <input type="text" class="m3-input" value="${escapeHtml(item.title)}" oninput="cmsData.about.timeline[${idx}].title = this.value; markDirty()">
                            </div>
                            <div class="form-group full">
                                <label class="form-label">Description</label>
                                <textarea class="m3-textarea" oninput="cmsData.about.timeline[${idx}].description = this.value; markDirty()">${escapeHtml(item.description)}</textarea>
                            </div>
                            <div class="form-group full">
                                <label class="form-label">Milestone Photo</label>
                                <div class="img-field-row">
                                    <img src="${escapeHtml(item.image)}" class="img-preview-thumb" onerror="this.style.opacity=0.2">
                                    <div class="img-field-controls">
                                        <input type="text" class="m3-input" value="${escapeHtml(item.image)}" oninput="cmsData.about.timeline[${idx}].image = this.value; this.closest('.img-field-row').querySelector('img').src = this.value; markDirty()">
                                        <button type="button" class="m3-btn" onclick="triggerImageUpload(url => { cmsData.about.timeline[${idx}].image = url; })">Upload</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `).join('')}
            `;
        }

        function addSkill() {
            const input = document.getElementById('new-skill-input');
            const val = input.value.trim();
            if (!val) return;
            cmsData.about.skills.push(val);
            markDirty();
            renderActiveTab();
        }

        function removeSkill(idx) {
            cmsData.about.skills.splice(idx, 1);
            markDirty();
            renderActiveTab();
        }

        function moveTimeline(idx, dir) {
            const arr = cmsData.about.timeline;
            const target = idx + dir;
            if (target < 0 || target >= arr.length) return;
            const tmp = arr[idx];
            arr[idx] = arr[target];
            arr[target] = tmp;
            markDirty();
            renderActiveTab();
        }

        function addTimelineItem() {
            cmsData.about.timeline.push({
                date: "Month Year",
                title: "New Milestone",
                description: "What happened on this step of your journey?",
                image: "https://portfolio.hgphnm.com/assets/journey1.jpg"
            });
            markDirty();
            renderActiveTab();
        }

        function deleteTimeline(idx) {
            if (!confirm('Remove this timeline milestone?')) return;
            cmsData.about.timeline.splice(idx, 1);
            markDirty();
            renderActiveTab();
        }

        // TAB 4: CONTACT & FOOTER
        function renderContactTab(container) {
            const contact = cmsData.contact || {};
            const footer = cmsData.footer || { platforms: [], emails: [] };

            container.innerHTML = `
                <div class="cms-section-header">
                    <div>
                        <h2 class="cms-section-title">04 // Contact & Footer</h2>
                        <p class="cms-section-desc">Customize your Contact photo, social popup platforms, and email addresses.</p>
                    </div>
                    <button class="m3-btn primary" onclick="saveContent()">Save Changes</button>
                </div>

                <div class="m3-card">
                    <div class="m3-card-header">
                        <div class="m3-card-title">Contact Section Image & Footer Identity</div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Footer Display Mark</label>
                            <input type="text" class="m3-input" value="${escapeHtml(footer.mark)}" oninput="cmsData.footer.mark = this.value; syncFooterColumnMark(this.value); markDirty()">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Footer Handle (@Freshebred target)</label>
                            <input type="text" class="m3-input" value="${escapeHtml(footer.handle)}" oninput="cmsData.footer.handle = this.value; syncFooterColumnHandle(this.value); markDirty()">
                        </div>
                        <div class="form-group full">
                            <label class="form-label">Contact Left Split Photo</label>
                            <div class="img-field-row">
                                <img src="${escapeHtml(contact.image)}" class="img-preview-thumb" onerror="this.style.opacity=0.2">
                                <div class="img-field-controls">
                                    <input type="text" class="m3-input" value="${escapeHtml(contact.image)}" oninput="cmsData.contact.image = this.value; this.closest('.img-field-row').querySelector('img').src = this.value; markDirty()">
                                    <button type="button" class="m3-btn" onclick="triggerImageUpload(url => { cmsData.contact.image = url; })">Upload</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="m3-card">
                    <div class="m3-card-header">
                        <div class="m3-card-title">Social Popup Platforms</div>
                        <button type="button" class="m3-btn" onclick="addPlatform()">+ Add Platform</button>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:14px;">
                        ${(footer.platforms || []).map((p, idx) => `
                            <div class="form-grid" style="align-items:center; padding-bottom:14px; border-bottom:1px solid var(--border-subtle);">
                                <div class="form-group">
                                    <label class="form-label">Platform Name</label>
                                    <input type="text" class="m3-input" value="${escapeHtml(p.name)}" oninput="cmsData.footer.platforms[${idx}].name = this.value; markDirty()">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Profile URL</label>
                                    <input type="text" class="m3-input" value="${escapeHtml(p.url)}" oninput="cmsData.footer.platforms[${idx}].url = this.value; markDirty()">
                                </div>
                                <div class="form-group full">
                                    <label class="form-label">Icon URL</label>
                                    <div style="display:flex; gap:10px; align-items:center;">
                                        <img src="${escapeHtml(p.icon)}" style="width:28px;height:28px;object-fit:contain;">
                                        <input type="text" class="m3-input" value="${escapeHtml(p.icon)}" oninput="cmsData.footer.platforms[${idx}].icon = this.value; this.previousElementSibling.src = this.value; markDirty()">
                                        <button type="button" class="m3-btn danger icon-only" onclick="cmsData.footer.platforms.splice(${idx},1); markDirty(); renderActiveTab();">✕</button>
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>

                <div class="m3-card">
                    <div class="m3-card-header">
                        <div class="m3-card-title">Email Addresses (Popup List)</div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group full">
                            <div style="display:flex; gap:8px;">
                                <input type="email" id="new-email-input" class="m3-input" placeholder="you@domain.com" onkeydown="if(event.key==='Enter'){event.preventDefault();addEmail();}">
                                <button type="button" class="m3-btn primary" onclick="addEmail()">+ Add Email</button>
                            </div>
                        </div>
                        <div class="form-group full">
                            <div class="chips-pool">
                                ${(footer.emails || []).map((em, eIdx) => `
                                    <div class="m3-chip selected" onclick="cmsData.footer.emails.splice(${eIdx},1); markDirty(); renderActiveTab();" title="Tap to remove">
                                        <span>${escapeHtml(em)}</span>
                                        <span style="opacity:0.5;">✕</span>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        function syncFooterColumnMark(val) {
            try {
                if (cmsData.footer.columns["0"][0][2]) {
                    cmsData.footer.columns["0"][0][2].content = val;
                }
            } catch (e) {}
        }

        function syncFooterColumnHandle(val) {
            try {
                if (cmsData.footer.columns["0"][1][2]) {
                    cmsData.footer.columns["0"][1][2].content = val;
                }
            } catch (e) {}
        }

        function addPlatform() {
            cmsData.footer.platforms.push({
                name: "New Platform",
                url: "https://",
                icon: "https://upload.wikimedia.org/wikipedia/commons/c/c2/GitHub_Invertocat_Logo.svg"
            });
            markDirty();
            renderActiveTab();
        }

        function addEmail() {
            const input = document.getElementById('new-email-input');
            const val = input.value.trim();
            if (!val) return;
            cmsData.footer.emails.push(val);
            markDirty();
            renderActiveTab();
        }

        // TAB 5: SECURITY & BACKUP
        function renderSecurityTab(container) {
            container.innerHTML = `
                <div class="cms-section-header">
                    <div>
                        <h2 class="cms-section-title">05 // PIN & Data Backup</h2>
                        <p class="cms-section-desc">Update your lock-screen PIN (4–8 digits) or restore your previous content snapshot.</p>
                    </div>
                </div>

                <div class="m3-card">
                    <div class="m3-card-header">
                        <div class="m3-card-title">Change Studio PIN</div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Current PIN</label>
                            <input type="password" inputmode="numeric" id="sec-current-pin" class="m3-input" placeholder="••••">
                        </div>
                        <div class="form-group">
                            <label class="form-label">New PIN (4 to 8 digits)</label>
                            <input type="password" inputmode="numeric" id="sec-new-pin" class="m3-input" placeholder="••••">
                        </div>
                        <div class="form-group full">
                            <button type="button" class="m3-btn primary" style="align-self:flex-start;" onclick="updatePinCode()">Update PIN</button>
                        </div>
                    </div>
                </div>

                <div class="m3-card">
                    <div class="m3-card-header">
                        <div class="m3-card-title">Backup & Recovery</div>
                    </div>
                    <p style="color:var(--text-secondary); font-size:0.92rem; margin-bottom:18px;">
                        Every time you press Save, an automatic backup is stored in <code>content.backup.json</code>.
                    </p>
                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        <a href="content.json" download="content.json" class="m3-btn">Download content.json</a>
                        <button type="button" class="m3-btn danger" onclick="restoreBackup()">Restore Last Backup</button>
                    </div>
                </div>
            `;
        }

        async function updatePinCode() {
            const currentPinVal = document.getElementById('sec-current-pin').value.trim();
            const newPinVal = document.getElementById('sec-new-pin').value.trim();
            if (!currentPinVal || !newPinVal) return showToast('Fill in both PIN fields');

            try {
                const res = await fetch('cms.php?action=change_pin', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ currentPin: currentPinVal, newPin: newPinVal })
                });
                const data = await res.json();
                if (res.ok && data.ok) {
                    expectedPinLength = data.pinLength;
                    renderPinSlots();
                    document.getElementById('sec-current-pin').value = '';
                    document.getElementById('sec-new-pin').value = '';
                    showToast('Studio PIN updated!');
                } else {
                    showToast(data.error || 'Could not update PIN');
                }
            } catch (e) {
                showToast('Error updating PIN');
            }
        }

        async function restoreBackup() {
            if (!confirm('Restore content.json from the last automatic backup?')) return;
            const res = await fetch('cms.php?action=restore_backup', { method: 'POST' });
            const data = await res.json();
            if (res.ok && data.ok) {
                await loadCmsContent();
                showToast('Restored from backup!');
            } else {
                showToast(data.error || 'Restore failed');
            }
        }

        if (isAuthenticated) {
            loadCmsContent();
        }
    </script>
</body>
</html>
