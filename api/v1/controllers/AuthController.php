<?php
/**
 * Authentication Controller
 * Handles login, logout, and API key management
 */

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Database.php';

class AuthController {

    /**
     * POST /auth/login
     * Login with email and password
     */
    public static function login(): void {
        $input = json_decode(file_get_contents('php://input'), true);

        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';

        if (empty($email) || empty($password)) {
            self::json(['error' => 'Email and password required'], 400);
            return;
        }

        $result = Auth::login($email, $password);

        if ($result) {
            Auth::logAction('login', null, json_encode(['email' => $email]));
            self::json([
                'success' => true,
                'token' => $result['token'],
                'expires_at' => $result['expires_at'],
                'user' => $result['user']
            ]);
        } else {
            self::json(['error' => 'Invalid credentials'], 401);
        }
    }

    /**
     * POST /auth/logout
     * Logout current session
     */
    public static function logout(): void {
        Auth::logout();
        self::json(['success' => true, 'message' => 'Logged out successfully']);
    }

    /**
     * GET /auth/me
     * Get current user info
     */
    public static function me(): void {
        Auth::require();

        $user = Auth::user();
        $apiKey = Auth::apiKey();

        if ($user) {
            self::json([
                'authenticated' => true,
                'type' => 'user',
                'user' => $user
            ]);
        } elseif ($apiKey) {
            self::json([
                'authenticated' => true,
                'type' => 'api_key',
                'api_key' => [
                    'id' => $apiKey['id'],
                    'name' => $apiKey['name']
                ]
            ]);
        }
    }

    /**
     * GET /api-keys
     * List all API keys
     */
    public static function listApiKeys(): void {
        Auth::require();

        $keys = Auth::listApiKeys();
        self::json(['api_keys' => $keys]);
    }

    /**
     * POST /api-keys
     * Create new API key
     */
    public static function createApiKey(): void {
        Auth::require();

        $input = json_decode(file_get_contents('php://input'), true);
        $name = $input['name'] ?? '';

        if (empty($name)) {
            self::json(['error' => 'API key name required'], 400);
            return;
        }

        $result = Auth::createApiKey($name);

        self::json([
            'success' => true,
            'api_key' => $result,
            'message' => 'Save this key securely - it will only be shown once!'
        ], 201);
    }

    /**
     * DELETE /api-keys/:id
     * Revoke/delete API key
     */
    public static function deleteApiKey(int $id): void {
        Auth::require();

        $success = Auth::deleteApiKey($id);

        if ($success) {
            self::json(['success' => true, 'message' => 'API key deleted']);
        } else {
            self::json(['error' => 'API key not found'], 404);
        }
    }

    /**
     * POST /api-keys/:id/revoke
     * Revoke (disable) API key without deleting
     */
    public static function revokeApiKey(int $id): void {
        Auth::require();

        $success = Auth::revokeApiKey($id);

        if ($success) {
            self::json(['success' => true, 'message' => 'API key revoked']);
        } else {
            self::json(['error' => 'API key not found'], 404);
        }
    }

    /**
     * GET /auth/check
     * Check if current request is authenticated (doesn't require auth)
     */
    public static function check(): void {
        $isAuthenticated = Auth::check();

        if ($isAuthenticated) {
            $user = Auth::user();
            $apiKey = Auth::apiKey();

            self::json([
                'authenticated' => true,
                'type' => $user ? 'user' : 'api_key',
                'user' => $user,
                'api_key' => $apiKey ? ['id' => $apiKey['id'], 'name' => $apiKey['name']] : null
            ]);
        } else {
            self::json(['authenticated' => false]);
        }
    }

    /**
     * GET /audit-log
     * Get audit log entries
     */
    public static function auditLog(): void {
        Auth::require();

        $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

        $logs = Database::fetchAll(
            "SELECT al.*, u.email as user_email, ak.name as api_key_name
             FROM audit_log al
             LEFT JOIN users u ON al.user_id = u.id
             LEFT JOIN api_keys ak ON al.api_key_id = ak.id
             ORDER BY al.created_at DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        $total = Database::fetch("SELECT COUNT(*) as count FROM audit_log")['count'];

        self::json([
            'logs' => $logs,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }

    /**
     * Helper to send JSON response
     */
    private static function json(array $data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
