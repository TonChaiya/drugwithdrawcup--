<?php

const APP_SESSION_IDLE = 7200;
const APP_SESSION_ABSOLUTE = 43200;

final class AppSessionStore implements SessionHandlerInterface, SessionIdInterface, SessionUpdateTimestampHandlerInterface
{
    private ?string $locked = null;
    private array $newIds = [];

    public function __construct(private PDO $pdo, private string $namespace) {}

    public function open(string $path, string $name): bool { return true; }

    private function lock(string $id): void
    {
        $key = hash('sha256', $this->namespace . ':' . $id);
        if ($this->locked === $key) return;
        $this->close();
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 5)');
        $stmt->execute([$key]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Session lock unavailable');
        }
        $this->locked = $key;
    }

    public function close(): bool
    {
        if ($this->locked !== null) {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([$this->locked]);
            $this->locked = null;
        }
        return true;
    }

    public function create_sid(): string
    {
        $id = bin2hex(random_bytes(32));
        $this->newIds[$id] = true;
        return $id;
    }

    private function lookup(string $id): string|false
    {
        $this->lock($id);
        $stmt = $this->pdo->prepare('SELECT payload FROM app_sessions WHERE id_hash = UNHEX(?) AND expires_at > ?');
        $stmt->execute([hash('sha256', $id), time()]);
        return $stmt->fetchColumn();
    }

    public function validateId(string $id): bool
    {
        if (!preg_match('/\A[0-9a-f]{64}\z/', $id)) return false;
        // Hold the lock from strict validation through read and write.
        if ($this->lookup($id) !== false) return true;
        $this->close();
        return false;
    }

    public function read(string $id): string|false
    {
        if (isset($this->newIds[$id])) {
            $this->lock($id);
            return '';
        }
        return $this->lookup($id);
    }

    public function write(string $id, string $data): bool
    {
        $this->lock($id);
        $now = time();
        $login = (int)($_SESSION['login_at'] ?? $now);
        $activity = (int)($_SESSION['last_activity_at'] ?? $now);
        $expiry = min($activity + APP_SESSION_IDLE, $login + APP_SESSION_ABSOLUTE);

        if (isset($this->newIds[$id])) {
            if ($expiry <= $now) return true;
            $stmt = $this->pdo->prepare('INSERT INTO app_sessions (id_hash, payload, expires_at) VALUES (UNHEX(?), ?, ?)');
            $stmt->execute([hash('sha256', $id), $data, $expiry]);
            unset($this->newIds[$id]);
        } else {
            // A late writer can never recreate a removed or expired session.
            $stmt = $this->pdo->prepare('UPDATE app_sessions SET payload = ?, expires_at = ? WHERE id_hash = UNHEX(?) AND expires_at > ?');
            $stmt->execute([$data, $expiry, hash('sha256', $id), $now]);
        }
        return true;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }

    public function destroy(string $id): bool
    {
        $this->lock($id);
        $stmt = $this->pdo->prepare('DELETE FROM app_sessions WHERE id_hash = UNHEX(?)');
        $stmt->execute([hash('sha256', $id)]);
        unset($this->newIds[$id]);
        return $this->close();
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->pdo->prepare('DELETE FROM app_sessions WHERE expires_at <= ? ORDER BY expires_at LIMIT 200');
        $stmt->execute([time()]);
        return $stmt->rowCount();
    }
}

function start_app_session(PDO $pdo): void
{
    try {
        if (session_status() !== PHP_SESSION_NONE) throw new RuntimeException('Session already started');
        $namespace = (string)$pdo->query('SELECT DATABASE(), (SELECT 1 FROM app_sessions LIMIT 1)')->fetchColumn();
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string)APP_SESSION_IDLE);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax',
        ]);
        if (!session_set_save_handler(new AppSessionStore($pdo, $namespace), true) || !session_start()) {
            throw new RuntimeException('Session initialization failed');
        }
    } catch (Throwable $e) {
        error_log('Application session initialization failed: ' . get_class($e) . ' [' . $e->getCode() . ']');
        http_response_code(503);
        if (defined('APP_SESSION_JSON')) {
            header('Content-Type: application/json; charset=utf-8');
            exit('{"status":"unavailable"}');
        }
        exit('ระบบเซสชันไม่พร้อมใช้งาน กรุณาลองใหม่อีกครั้ง');
    }
}
