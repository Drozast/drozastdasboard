<?php
/**
 * Authentication Middleware
 * Supports both API Key and Session-based authentication
 */

require_once __DIR__ . '/../models/Database.php';

class Auth {
    private static ?array $currentUser = null;
    private static ?array $currentApiKey = null;

    /**
     * Check if request is authenticated (API Key or Session)
     * Returns true if authenticated, sends 401 and exits if not
     */
    public static function require(): bool {
        // Try API Key first
        $apiKey = self::getApiKeyFromRequest();
        if ($apiKey) {
            $keyData = self::validateApiKey($apiKey);
            if ($keyData) {
                self::$currentApiKey = $keyData;
                self::updateApiKeyLastUsed($keyData['id']);
                return true;
            }
        }

        // Try Session token
        $token = self::getTokenFromRequest();
        if ($token) {
            $session = self::validateSession($token);
            if ($session) {
                self::$currentUser = $session['user'];
                return true;
            }
        }

        self::sendUnauthorized();
        return false;
    }

    /**
     * Optional authentication check (doesn't exit on failure)
     */
    public static function check(): bool {
        $apiKey = self::getApiKeyFromRequest();
        if ($apiKey) {
            $keyData = self::validateApiKey($apiKey);
            if ($keyData) {
                self::$currentApiKey = $keyData;
                return true;
            }
        }

        $token = self::getTokenFromRequest();
        if ($token) {
            $session = self::validateSession($token);
            if ($session) {
                self::$currentUser = $session['user'];
                return true;
            }
        }

        return false;
    }

    /**
     * Get API Key from request headers
     */
    private static function getApiKeyFromRequest(): ?string {
        // Check X-API-Key header
        $headers = getallheaders();
        if (isset($headers['X-API-Key'])) {
            return $headers['X-API-Key'];
        }
        if (isset($headers['x-api-key'])) {
            return $headers['x-api-key'];
        }

        // Check query parameter as fallback
        if (isset($_GET['api_key'])) {
            return $_GET['api_key'];
        }

        return null;
    }

    /**
     * Get Bearer token from request
     */
    private static function getTokenFromRequest(): ?string {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        // Check cookie as fallback
        if (isset($_COOKIE['drozast_token'])) {
            return $_COOKIE['drozast_token'];
        }

        return null;
    }

    /**
     * Validate API Key
     */
    private static function validateApiKey(string $key): ?array {
        $hash = hash('sha256', $key);
        return Database::fetch(
            "SELECT * FROM api_keys WHERE key_hash = ? AND is_active = 1",
            [$hash]
        );
    }

    /**
     * Validate session token
     */
    private static function validateSession(string $token): ?array {
        $hash = hash('sha256', $token);
        $session = Database::fetch(
            "SELECT s.*, u.id as user_id, u.email
             FROM sessions s
             JOIN users u ON s.user_id = u.id
             WHERE s.token_hash = ? AND s.expires_at > datetime('now')",
            [$hash]
        );

        if ($session) {
            return [
                'session' => $session,
                'user' => [
                    'id' => $session['user_id'],
                    'email' => $session['email'],
                ]
            ];
        }

        return null;
    }

    /**
     * Update API key last used timestamp
     */
    private static function updateApiKeyLastUsed(int $id): void {
        Database::query(
            "UPDATE api_keys SET last_used = datetime('now') WHERE id = ?",
            [$id]
        );
    }

    /**
     * Login user and create session
     */
    public static function login(string $email, string $password): ?array {
        $user = Database::fetch(
            "SELECT * FROM users WHERE email = ?",
            [$email]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        // Generate session token
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

        Database::query(
            "INSERT INTO sessions (user_id, token_hash, expires_at, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?)",
            [
                $user['id'],
                $tokenHash,
                $expiresAt,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]
        );

        // Update last login
        Database::query(
            "UPDATE users SET last_login = datetime('now') WHERE id = ?",
            [$user['id']]
        );

        // Set cookie
        setcookie('drozast_token', $token, [
            'expires' => strtotime($expiresAt),
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
            ]
        ];
    }

    /**
     * Logout - invalidate session
     */
    public static function logout(): bool {
        $token = self::getTokenFromRequest();
        if ($token) {
            $hash = hash('sha256', $token);
            Database::query("DELETE FROM sessions WHERE token_hash = ?", [$hash]);
        }

        // Clear cookie
        setcookie('drozast_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);

        return true;
    }

    /**
     * Generate new API Key
     */
    public static function createApiKey(string $name): array {
        $key = 'drzst_' . bin2hex(random_bytes(24));
        $hash = hash('sha256', $key);

        Database::query(
            "INSERT INTO api_keys (key_hash, name) VALUES (?, ?)",
            [$hash, $name]
        );

        $id = Database::lastInsertId();

        // Log action
        self::logAction('create_api_key', null, json_encode(['name' => $name, 'key_id' => $id]));

        return [
            'id' => $id,
            'key' => $key,  // Only shown once!
            'name' => $name,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * List all API keys (without the actual keys)
     */
    public static function listApiKeys(): array {
        return Database::fetchAll(
            "SELECT id, name, created_at, last_used, is_active FROM api_keys ORDER BY created_at DESC"
        );
    }

    /**
     * Revoke API key
     */
    public static function revokeApiKey(int $id): bool {
        $result = Database::query(
            "UPDATE api_keys SET is_active = 0 WHERE id = ?",
            [$id]
        );

        self::logAction('revoke_api_key', null, json_encode(['key_id' => $id]));

        return $result->rowCount() > 0;
    }

    /**
     * Delete API key
     */
    public static function deleteApiKey(int $id): bool {
        $result = Database::query("DELETE FROM api_keys WHERE id = ?", [$id]);
        self::logAction('delete_api_key', null, json_encode(['key_id' => $id]));
        return $result->rowCount() > 0;
    }

    /**
     * Get current authenticated user
     */
    public static function user(): ?array {
        return self::$currentUser;
    }

    /**
     * Get current API key info
     */
    public static function apiKey(): ?array {
        return self::$currentApiKey;
    }

    /**
     * Log action to audit log
     */
    public static function logAction(string $action, ?string $containerName = null, ?string $details = null): void {
        Database::query(
            "INSERT INTO audit_log (user_id, api_key_id, action, container_name, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                self::$currentUser['id'] ?? null,
                self::$currentApiKey['id'] ?? null,
                $action,
                $containerName,
                $details,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]
        );
    }

    /**
     * Send 401 Unauthorized response
     */
    private static function sendUnauthorized(): void {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Unauthorized',
            'message' => 'Valid API key or session token required'
        ]);
        exit;
    }

    /**
     * Clean expired sessions
     */
    public static function cleanExpiredSessions(): int {
        $result = Database::query("DELETE FROM sessions WHERE expires_at < datetime('now')");
        return $result->rowCount();
    }
}
