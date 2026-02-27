<?php
/**
 * Container Controller
 * Docker container management and metadata
 */

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Database.php';
require_once __DIR__ . '/../services/DockerService.php';

class ContainerController {

    /**
     * GET /containers
     * List all containers with metadata and stats
     */
    public static function index(): void {
        Auth::require();

        // Get containers from database with category info
        $dbContainers = Database::fetchAll(
            "SELECT c.*, cat.name as category_name, cat.icon as category_icon
             FROM containers c
             LEFT JOIN categories cat ON c.category_id = cat.id
             WHERE c.is_visible = 1
             ORDER BY cat.sort_order, c.sort_order, c.display_name"
        );

        // Get running containers from Docker
        $dockerContainers = DockerService::listContainers(true);
        $runningNames = [];
        foreach ($dockerContainers as $dc) {
            $name = ltrim($dc['Names'][0] ?? '', '/');
            $runningNames[$name] = $dc;
        }

        // Merge data
        $result = [];
        foreach ($dbContainers as $container) {
            $name = $container['container_name'];
            $isRunning = isset($runningNames[$name]);

            $stats = null;
            if ($isRunning) {
                $stats = DockerService::getContainerStats($runningNames[$name]['Id']);
            }

            $result[] = [
                'id' => $container['id'],
                'container_name' => $name,
                'display_name' => $container['display_name'],
                'description' => $container['description'],
                'icon' => $container['icon'],
                'category' => [
                    'id' => $container['category_id'],
                    'name' => $container['category_name'],
                    'icon' => $container['category_icon'],
                ],
                'subdomain' => $container['subdomain'],
                'local_port' => $container['local_port'],
                'external_url' => $container['external_url'],
                'is_critical' => (bool)$container['is_critical'],
                'status' => $isRunning ? 'running' : 'stopped',
                'stats' => $stats,
            ];
        }

        self::json(['containers' => $result]);
    }

    /**
     * POST /containers/discover
     * Discover Docker containers not in database
     */
    public static function discover(): void {
        Auth::require();

        $dockerContainers = DockerService::listContainers(true);
        $dbContainers = Database::fetchAll("SELECT container_name FROM containers");
        $existingNames = array_column($dbContainers, 'container_name');

        $discovered = [];
        foreach ($dockerContainers as $dc) {
            $name = ltrim($dc['Names'][0] ?? '', '/');
            if (!in_array($name, $existingNames)) {
                // Try to extract info from container
                $image = $dc['Image'] ?? '';
                $ports = $dc['Ports'] ?? [];
                $publicPort = null;
                foreach ($ports as $port) {
                    if (isset($port['PublicPort'])) {
                        $publicPort = $port['PublicPort'];
                        break;
                    }
                }

                $discovered[] = [
                    'container_name' => $name,
                    'suggested_display_name' => ucwords(str_replace(['-', '_'], ' ', $name)),
                    'image' => $image,
                    'status' => $dc['State'] ?? 'unknown',
                    'local_port' => $publicPort,
                ];
            }
        }

        self::json([
            'discovered' => $discovered,
            'existing_count' => count($existingNames),
            'message' => count($discovered) . ' new containers found',
        ]);
    }

    /**
     * GET /containers/:name
     * Get single container details
     */
    public static function show(string $name): void {
        Auth::require();

        $container = Database::fetch(
            "SELECT c.*, cat.name as category_name
             FROM containers c
             LEFT JOIN categories cat ON c.category_id = cat.id
             WHERE c.container_name = ?",
            [$name]
        );

        if (!$container) {
            self::json(['error' => 'Container not found'], 404);
            return;
        }

        // Get Docker info
        $dockerInfo = DockerService::inspectContainer($name);
        $stats = null;
        if ($dockerInfo && ($dockerInfo['State']['Running'] ?? false)) {
            $stats = DockerService::getContainerStats($dockerInfo['Id']);
        }

        self::json([
            'container' => array_merge($container, [
                'docker' => $dockerInfo,
                'stats' => $stats,
            ])
        ]);
    }

    /**
     * GET /containers/:name/logs
     * Get container logs
     */
    public static function logs(string $name): void {
        Auth::require();

        $lines = isset($_GET['lines']) ? min((int)$_GET['lines'], 1000) : 100;
        $since = $_GET['since'] ?? '1h';

        $logs = DockerService::getContainerLogs($name, $lines, $since);

        Auth::logAction('view_logs', $name);

        self::json([
            'container' => $name,
            'lines' => $lines,
            'logs' => $logs,
        ]);
    }

    /**
     * POST /containers/:name/start
     */
    public static function start(string $name): void {
        Auth::require();

        $result = DockerService::startContainer($name);
        Auth::logAction('start', $name);

        self::json([
            'success' => $result,
            'message' => $result ? "Container $name started" : "Failed to start $name",
        ]);
    }

    /**
     * POST /containers/:name/stop
     */
    public static function stop(string $name): void {
        Auth::require();

        $result = DockerService::stopContainer($name);
        Auth::logAction('stop', $name);

        self::json([
            'success' => $result,
            'message' => $result ? "Container $name stopped" : "Failed to stop $name",
        ]);
    }

    /**
     * POST /containers/:name/restart
     */
    public static function restart(string $name): void {
        Auth::require();

        $result = DockerService::restartContainer($name);
        Auth::logAction('restart', $name);

        self::json([
            'success' => $result,
            'message' => $result ? "Container $name restarted" : "Failed to restart $name",
        ]);
    }

    /**
     * POST /admin/containers
     * Create container metadata
     */
    public static function create(): void {
        Auth::require();

        $input = json_decode(file_get_contents('php://input'), true);

        $required = ['container_name', 'display_name'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                self::json(['error' => "$field is required"], 400);
                return;
            }
        }

        // Check if exists
        $existing = Database::fetch(
            "SELECT id FROM containers WHERE container_name = ?",
            [$input['container_name']]
        );
        if ($existing) {
            self::json(['error' => 'Container already exists'], 409);
            return;
        }

        Database::query(
            "INSERT INTO containers (container_name, display_name, description, icon, category_id,
             subdomain, local_port, external_url, is_visible, is_critical, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $input['container_name'],
                $input['display_name'],
                $input['description'] ?? null,
                $input['icon'] ?? '📦',
                $input['category_id'] ?? null,
                $input['subdomain'] ?? null,
                $input['local_port'] ?? null,
                $input['external_url'] ?? null,
                $input['is_visible'] ?? 1,
                $input['is_critical'] ?? 0,
                $input['sort_order'] ?? 0,
            ]
        );

        $id = Database::lastInsertId();
        Auth::logAction('create_container', $input['container_name']);

        self::json([
            'success' => true,
            'id' => $id,
            'message' => 'Container created',
        ], 201);
    }

    /**
     * PUT /admin/containers/:id
     * Update container metadata
     */
    public static function update(int $id): void {
        Auth::require();

        $input = json_decode(file_get_contents('php://input'), true);

        $container = Database::fetch("SELECT * FROM containers WHERE id = ?", [$id]);
        if (!$container) {
            self::json(['error' => 'Container not found'], 404);
            return;
        }

        $fields = [];
        $values = [];
        $allowed = ['display_name', 'description', 'icon', 'category_id', 'subdomain',
                    'local_port', 'external_url', 'is_visible', 'is_critical', 'sort_order'];

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

        $fields[] = "updated_at = datetime('now')";
        $values[] = $id;

        Database::query(
            "UPDATE containers SET " . implode(', ', $fields) . " WHERE id = ?",
            $values
        );

        Auth::logAction('update_container', $container['container_name'], json_encode($input));

        self::json(['success' => true, 'message' => 'Container updated']);
    }

    /**
     * DELETE /admin/containers/:id
     * Delete container metadata
     */
    public static function delete(int $id): void {
        Auth::require();

        $container = Database::fetch("SELECT * FROM containers WHERE id = ?", [$id]);
        if (!$container) {
            self::json(['error' => 'Container not found'], 404);
            return;
        }

        Database::query("DELETE FROM containers WHERE id = ?", [$id]);
        Auth::logAction('delete_container', $container['container_name']);

        self::json(['success' => true, 'message' => 'Container deleted']);
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
