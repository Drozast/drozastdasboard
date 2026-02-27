<?php
/**
 * Alert Controller
 * Manage alert thresholds and NTFY integration
 */

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Database.php';

class AlertController {

    // Cooldown in seconds (15 minutes)
    private const ALERT_COOLDOWN = 900;

    // NTFY server URL
    private const NTFY_URLS = [
        'http://frogio-ntfy:80',      // Docker container (frogio network)
        'https://ntfy.drozast.xyz'    // Public fallback
    ];

    /**
     * GET /alerts
     * List all alert configurations
     */
    public static function index(): void {
        Auth::require();

        $alerts = Database::fetchAll(
            "SELECT ac.*,
                    (SELECT sent_at FROM alert_history WHERE alert_config_id = ac.id ORDER BY sent_at DESC LIMIT 1) as last_triggered
             FROM alert_config ac
             ORDER BY metric, container_name"
        );

        self::json(['alerts' => $alerts]);
    }

    /**
     * PUT /alerts/:id
     * Update alert configuration
     */
    public static function update(int $id): void {
        Auth::require();

        $input = json_decode(file_get_contents('php://input'), true);

        $alert = Database::fetch("SELECT * FROM alert_config WHERE id = ?", [$id]);
        if (!$alert) {
            self::json(['error' => 'Alert not found'], 404);
            return;
        }

        $fields = [];
        $values = [];
        $allowed = ['threshold', 'ntfy_topic', 'is_active'];

        foreach ($allowed as $field) {
            if (isset($input[$field])) {
                $fields[] = "$field = ?";
                $values[] = $input[$field];
            }
        }

        if (empty($fields)) {
            self::json(['error' => 'No fields to update'], 400);
            return;
        }

        $values[] = $id;

        Database::query(
            "UPDATE alert_config SET " . implode(', ', $fields) . " WHERE id = ?",
            $values
        );

        Auth::logAction('update_alert', null, json_encode($input));

        self::json(['success' => true, 'message' => 'Alert updated']);
    }

    /**
     * POST /alerts/test
     * Test NTFY notification
     */
    public static function test(): void {
        Auth::require();

        $input = json_decode(file_get_contents('php://input'), true);
        $topic = $input['topic'] ?? 'drozast-alerts';
        $message = $input['message'] ?? 'Test notification from Dashboard';

        $success = self::sendNtfyNotification(
            $topic,
            'Dashboard Test',
            $message,
            'default'
        );

        if ($success) {
            self::json(['success' => true, 'message' => 'Notification sent']);
        } else {
            self::json(['success' => false, 'error' => 'Failed to send notification'], 500);
        }
    }

    /**
     * Check alerts and send NTFY notifications if needed
     * Called internally by stats endpoint
     */
    public static function checkAlerts(array $stats, array $containers = []): void {
        $alerts = Database::fetchAll(
            "SELECT * FROM alert_config WHERE is_active = 1"
        );

        foreach ($alerts as $alert) {
            $value = null;
            $metricLabel = '';

            switch ($alert['metric']) {
                case 'cpu':
                    $value = $stats['cpu']['usage'] ?? 0;
                    $metricLabel = 'CPU';
                    break;
                case 'ram':
                    $value = $stats['ram']['percent'] ?? 0;
                    $metricLabel = 'RAM';
                    break;
                case 'disk':
                    $value = $stats['disk']['root']['percent'] ?? 0;
                    $metricLabel = 'Disk';
                    break;
                case 'container_down':
                    // Check if specific container is down
                    if ($alert['container_name'] && !empty($containers)) {
                        $isRunning = false;
                        foreach ($containers as $c) {
                            if ($c['container_name'] === $alert['container_name']) {
                                $isRunning = ($c['status'] === 'running');
                                break;
                            }
                        }
                        if (!$isRunning) {
                            $value = 100; // Trigger alert
                            $metricLabel = 'Container Down';
                        }
                    }
                    break;
            }

            if ($value !== null && $value >= $alert['threshold']) {
                // Check cooldown
                if (!self::isOnCooldown($alert['id'])) {
                    $sent = self::sendNtfyNotification(
                        $alert['ntfy_topic'],
                        "⚠️ {$metricLabel} Alert",
                        $alert['metric'] === 'container_down'
                            ? "Container '{$alert['container_name']}' is DOWN!"
                            : "{$metricLabel} is at {$value}% (threshold: {$alert['threshold']}%)",
                        'high'
                    );

                    if ($sent) {
                        self::recordAlertSent($alert, $value);
                    }
                }
            }
        }
    }

    /**
     * Check critical containers status
     * Called by stats endpoint to detect container failures
     */
    public static function checkCriticalContainers(array $containers): void {
        // Get critical containers from DB
        $criticals = Database::fetchAll(
            "SELECT container_name, display_name FROM containers WHERE is_critical = 1"
        );

        foreach ($criticals as $critical) {
            $isRunning = false;
            foreach ($containers as $c) {
                if ($c['container_name'] === $critical['container_name']) {
                    $isRunning = ($c['status'] === 'running');
                    break;
                }
            }

            if (!$isRunning) {
                // Check cooldown using a special key for container alerts
                $alertKey = 'container_' . $critical['container_name'];
                $lastSent = Database::fetch(
                    "SELECT sent_at FROM alert_history WHERE metric = 'container_down' AND container_name = ? ORDER BY sent_at DESC LIMIT 1",
                    [$critical['container_name']]
                );

                $onCooldown = false;
                if ($lastSent) {
                    $lastTime = strtotime($lastSent['sent_at']);
                    $onCooldown = (time() - $lastTime) < self::ALERT_COOLDOWN;
                }

                if (!$onCooldown) {
                    $sent = self::sendNtfyNotification(
                        'drozast-alerts',
                        '🚨 Container Down!',
                        "Critical container '{$critical['display_name']}' ({$critical['container_name']}) is NOT running!",
                        'urgent'
                    );

                    if ($sent) {
                        Database::query(
                            "INSERT INTO alert_history (alert_config_id, metric, value, threshold, container_name) VALUES (0, 'container_down', 0, 0, ?)",
                            [$critical['container_name']]
                        );
                    }
                }
            }
        }
    }

    /**
     * Check if alert is on cooldown
     */
    private static function isOnCooldown(int $alertId): bool {
        $lastSent = Database::fetch(
            "SELECT sent_at FROM alert_history WHERE alert_config_id = ? ORDER BY sent_at DESC LIMIT 1",
            [$alertId]
        );

        if (!$lastSent) {
            return false;
        }

        $lastTime = strtotime($lastSent['sent_at']);
        return (time() - $lastTime) < self::ALERT_COOLDOWN;
    }

    /**
     * Record that an alert was sent
     */
    private static function recordAlertSent(array $alert, float $value): void {
        Database::query(
            "INSERT INTO alert_history (alert_config_id, metric, value, threshold, container_name) VALUES (?, ?, ?, ?, ?)",
            [$alert['id'], $alert['metric'], $value, $alert['threshold'], $alert['container_name']]
        );

        // Clean old history (keep last 7 days)
        Database::query(
            "DELETE FROM alert_history WHERE sent_at < datetime('now', '-7 days')"
        );
    }

    /**
     * Send NTFY notification
     */
    private static function sendNtfyNotification(
        string $topic,
        string $title,
        string $message,
        string $priority = 'default'
    ): bool {
        foreach (self::NTFY_URLS as $baseUrl) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "$baseUrl/$topic",
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $message,
                CURLOPT_HTTPHEADER => [
                    "Title: $title",
                    "Priority: $priority",
                    "Tags: server,alert,drozast",
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                return true;
            }
        }

        return false;
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
