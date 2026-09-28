<?php
require_once __DIR__ . '/db.php';

function start_app_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $config = require __DIR__ . '/../config.php';
        session_name($config['session_name']);
        session_start();
    }
}

function csrf_token(): string {
    start_app_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function valid_csrf_token(?string $token): bool {
    start_app_session();
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function current_user(): ?array {
    start_app_session();
    return $_SESSION['user'] ?? null;
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        header('Location: /login.php');
        exit;
    }
    return $user;
}

function require_admin(): array {
    $user = require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        render_error('Admins only.');
        exit;
    }
    return $user;
}

function require_member(): array {
    $user = require_login();
    if ($user['role'] !== 'member') {
        header('Location: /admin/index.php');
        exit;
    }
    return $user;
}

function find_user_by_phone(string $phone): ?array {
    $stmt = db()->prepare('SELECT * FROM users WHERE phone = ?');
    $stmt->execute([$phone]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_user_by_id(int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function create_user(string $name, string $phone, ?string $email, ?string $address, string $password, string $role): int {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare('INSERT INTO users (name, phone, email, address, password_hash, role) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $phone, $email, $address, $hash, $role]);
    return (int) db()->lastInsertId();
}

// A member with chit subscriptions cannot be removed: their subscriptions,
// auctions, and installment records are part of the scheme's financial history.
function delete_member(int $id): string {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'member' FOR UPDATE");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            $pdo->rollBack();
            return 'not_found';
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM subscriptions WHERE user_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            $pdo->rollBack();
            return 'enrolled';
        }

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'member'");
        $stmt->execute([$id]);
        $pdo->commit();
        return 'deleted';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function list_members(): array {
    return db()->query("SELECT * FROM users WHERE role = 'member' ORDER BY name")->fetchAll();
}

function render_error(string $message): void {
    $title = 'Error';
    include __DIR__ . '/../error.php';
}

// Small helper to escape output consistently.
function h($value): string {
    if ($value === null) return '';
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money($value): string {
    return number_format((float) $value, 2);
}
