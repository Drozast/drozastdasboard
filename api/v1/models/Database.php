<?php
/**
 * Database wrapper for SQLite
 * Drozast Dashboard - Container Management Platform
 */

class Database {
    private static ?PDO $instance = null;
    private static string $dbPath = __DIR__ . '/../../../data/dashboard.db';

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $dbDir = dirname(self::$dbPath);
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0755, true);
            }

            $isNew = !file_exists(self::$dbPath);

            self::$instance = new PDO(
                'sqlite:' . self::$dbPath,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            // Enable foreign keys
            self::$instance->exec('PRAGMA foreign_keys = ON');

            if ($isNew) {
                self::initializeSchema();
            }
        }

        return self::$instance;
    }

    private static function initializeSchema(): void {
        $db = self::$instance;

        // API Keys
        $db->exec("
            CREATE TABLE IF NOT EXISTS api_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key_hash TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_used DATETIME,
                is_active INTEGER DEFAULT 1
            )
        ");

        // Users
        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_login DATETIME
            )
        ");

        // Alert config
        $db->exec("
            CREATE TABLE IF NOT EXISTS alert_config (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                metric TEXT NOT NULL,
                threshold INTEGER NOT NULL,
                container_name TEXT,
                ntfy_topic TEXT DEFAULT 'drozast-alerts',
                is_active INTEGER DEFAULT 1
            )
        ");

        // Categories
        $db->exec("
            CREATE TABLE IF NOT EXISTS categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                icon TEXT DEFAULT '📁',
                sort_order INTEGER DEFAULT 0,
                is_visible INTEGER DEFAULT 1
            )
        ");

        // Containers metadata
        $db->exec("
            CREATE TABLE IF NOT EXISTS containers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                container_name TEXT NOT NULL UNIQUE,
                display_name TEXT NOT NULL,
                description TEXT,
                icon TEXT DEFAULT '📦',
                category_id INTEGER,
                subdomain TEXT,
                local_port INTEGER,
                external_url TEXT,
                is_visible INTEGER DEFAULT 1,
                is_critical INTEGER DEFAULT 0,
                sort_order INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
            )
        ");

        // Stats history
        $db->exec("
            CREATE TABLE IF NOT EXISTS stats_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                container_name TEXT,
                stat_type TEXT NOT NULL,
                value REAL NOT NULL,
                recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Energy readings
        $db->exec("
            CREATE TABLE IF NOT EXISTS energy_readings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                watts REAL NOT NULL,
                cpu_percent INTEGER,
                recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Energy daily
        $db->exec("
            CREATE TABLE IF NOT EXISTS energy_daily (
                date TEXT PRIMARY KEY,
                kwh REAL NOT NULL DEFAULT 0,
                cost_clp INTEGER DEFAULT 0
            )
        ");

        // Audit log
        $db->exec("
            CREATE TABLE IF NOT EXISTS audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                api_key_id INTEGER,
                action TEXT NOT NULL,
                container_name TEXT,
                details TEXT,
                ip_address TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Sessions
        $db->exec("
            CREATE TABLE IF NOT EXISTS sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                ip_address TEXT,
                user_agent TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");

        // Alert history (for cooldown tracking)
        $db->exec("
            CREATE TABLE IF NOT EXISTS alert_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                alert_config_id INTEGER NOT NULL,
                metric TEXT NOT NULL,
                value REAL NOT NULL,
                threshold INTEGER NOT NULL,
                container_name TEXT,
                sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (alert_config_id) REFERENCES alert_config(id) ON DELETE CASCADE
            )
        ");

        // Indexes
        $db->exec("CREATE INDEX IF NOT EXISTS idx_stats_container ON stats_history(container_name, stat_type)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_stats_recorded ON stats_history(recorded_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_energy_recorded ON energy_readings(recorded_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(token_hash)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_alert_history_sent ON alert_history(alert_config_id, sent_at)");

        // Seed default admin user (password: Enanitos123$)
        $defaultPassword = password_hash('Enanitos123$', PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT OR IGNORE INTO users (email, password_hash) VALUES (?, ?)");
        $stmt->execute(['drozast@gmail.com', $defaultPassword]);

        // Seed default categories
        $categories = [
            ['FROGIO - Gestion Municipal', '🏛️', 1],
            ['Proyectos Clientes', '💼', 2],
            ['Cloud & Storage', '☁️', 3],
            ['Media & Entertainment', '🎵', 4],
            ['DevOps & Tools', '🔧', 5],
            ['Network & DNS', '🌐', 6],
            ['Gaming', '🎮', 7],
            ['Maps', '🗺️', 8],
        ];

        $stmt = $db->prepare("INSERT OR IGNORE INTO categories (name, icon, sort_order) VALUES (?, ?, ?)");
        foreach ($categories as $cat) {
            $stmt->execute($cat);
        }

        // Seed default alerts
        $alerts = [
            ['cpu', 90, null, 'drozast-alerts'],
            ['ram', 95, null, 'drozast-alerts'],
            ['disk', 90, null, 'drozast-alerts'],
        ];

        $stmt = $db->prepare("INSERT OR IGNORE INTO alert_config (metric, threshold, container_name, ntfy_topic) VALUES (?, ?, ?, ?)");
        foreach ($alerts as $alert) {
            $stmt->execute($alert);
        }
    }

    /**
     * Helper for executing queries
     */
    public static function query(string $sql, array $params = []): \PDOStatement {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Helper for fetching single row
     */
    public static function fetch(string $sql, array $params = []): ?array {
        return self::query($sql, $params)->fetch() ?: null;
    }

    /**
     * Helper for fetching all rows
     */
    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Get last insert ID
     */
    public static function lastInsertId(): int {
        return (int) self::getInstance()->lastInsertId();
    }
}
