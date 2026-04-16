<?php
// ============================================================
//  daily focus — API backend
//  upload this file next to focus.html on your server
// ============================================================

// ---- config: fill these in before uploading ----------------

// your cPanel MySQL credentials (from the Database Wizard)
define('DB_HOST', 'localhost');
define('DB_NAME', 'rirxtzqyms_focus');
define('DB_USER', 'rirxtzqyms_ghorne');
define('DB_PASS', 'xyrpav-6somhu-tuhhYd');
define('APP_PASSWORD_HASH', '$2y$12$FX9sZwbQxRSnvLNQnuh8teo.SM0B7h2JvaIMH.CG/P4E90AIbt2PS');

// ---- session setup -----------------------------------------

define('SESSION_NAME',     'df_sess');
define('SESSION_LIFETIME', 60 * 60 * 24 * 30); // 30 days

session_name(SESSION_NAME);
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ---- CORS: allow same origin and beep subdomain ------------
$allowed_origins = [
    'https://garcehorne.com',
    'https://www.garcehorne.com',
    'https://beep.garcehorne.com',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && !in_array($origin, $allowed_origins, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}
if ($origin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

// -- handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---- route -------------------------------------------------

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

switch ($action) {
    case 'login':      handleLogin($body);    break;
    case 'logout':     handleLogout();        break;
    case 'check':      handleCheck();         break;
    case 'get_tasks':  requireAuth(); handleGetTasks($body);  break;
    case 'save_tasks': requireAuth(); handleSaveTasks($body); break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'unknown action']);
}

// ---- handlers ----------------------------------------------

function handleLogin(array $body): void {
    $pw = $body['password'] ?? '';
    if (APP_PASSWORD_HASH === 'YOUR_BCRYPT_HASH_HERE') {
        http_response_code(500);
        echo json_encode(['error' => 'app password not configured — see api.php setup instructions']);
        return;
    }
    if (password_verify($pw, APP_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'wrong password']);
    }
}

function handleLogout(): void {
    $_SESSION = [];
    session_destroy();
    echo json_encode(['ok' => true]);
}

function handleCheck(): void {
    echo json_encode(['ok' => !empty($_SESSION['auth'])]);
}

function handleGetTasks(array $body): void {
    $date = $body['date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid date']);
        return;
    }
    $db   = db();
    $stmt = $db->prepare('SELECT tasks_json FROM tasks WHERE date_key = ?');
    $stmt->execute([$date]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['tasks' => $row ? json_decode($row['tasks_json'], true) : []]);
}

function handleSaveTasks(array $body): void {
    $date  = $body['date']  ?? '';
    $tasks = $body['tasks'] ?? null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_array($tasks)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid request']);
        return;
    }
    $json = json_encode($tasks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $db   = db();
    $stmt = $db->prepare(
        'INSERT INTO tasks (date_key, tasks_json)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE tasks_json = VALUES(tasks_json), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$date, $json]);
    echo json_encode(['ok' => true]);
}

// ---- helpers -----------------------------------------------

function requireAuth(): void {
    if (empty($_SESSION['auth'])) {
        http_response_code(401);
        echo json_encode(['error' => 'not authenticated']);
        exit;
    }
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (DB_PASS === 'YOUR_DB_PASSWORD_HERE') {
            http_response_code(500);
            echo json_encode(['error' => 'database not configured — see api.php']);
            exit;
        }
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        // create the tasks table on first use — no manual SQL needed
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tasks (
                date_key   CHAR(10)     NOT NULL,
                tasks_json MEDIUMTEXT   NOT NULL,
                updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (date_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    return $pdo;
}
