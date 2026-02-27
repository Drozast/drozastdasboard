<?php
/**
 * Stats Controller
 * System stats and energy monitoring
 */

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Database.php';

class StatsController {

    /**
     * GET /stats
     * Get all system statistics
     */
    public static function index(): void {
        Auth::require();

        // CPU
        $cpu = (int)trim(shell_exec("grep 'cpu ' /host/proc/stat | awk '{usage=(\$2+\$4)*100/(\$2+\$4+\$5)} END {print int(usage)}'") ?? "0");
        $cpu_temp = trim(shell_exec("cat /host/sys/class/thermal/thermal_zone0/temp 2>/dev/null") ?? "0");
        $cpu_temp = $cpu_temp ? round($cpu_temp / 1000, 1) : 0;
        $cpu_cores = (int)trim(shell_exec("nproc") ?? "1");
        $load_avg = trim(shell_exec("cat /host/proc/loadavg | awk '{print \$1,\$2,\$3}'") ?? "0 0 0");

        // RAM
        $ram_total = round((int)trim(shell_exec("cat /host/proc/meminfo | grep MemTotal | awk '{print \$2}'") ?? "0") / 1024);
        $ram_available = round((int)trim(shell_exec("cat /host/proc/meminfo | grep MemAvailable | awk '{print \$2}'") ?? "0") / 1024);
        $ram_used = $ram_total - $ram_available;
        $ram_percent = $ram_total > 0 ? round($ram_used / $ram_total * 100) : 0;

        // Swap
        $swap_total = round((int)trim(shell_exec("cat /host/proc/meminfo | grep SwapTotal | awk '{print \$2}'") ?? "0") / 1024);
        $swap_free = (int)trim(shell_exec("cat /host/proc/meminfo | grep SwapFree | awk '{print \$2}'") ?? "0");
        $swap_used = round(($swap_total * 1024 - $swap_free) / 1024);

        // Disks
        $disk_root = (int)trim(shell_exec("df / | awk 'NR==2{print int(\$5)}'") ?? "0");
        $disk_root_used = trim(shell_exec("df -h / | awk 'NR==2{print \$3}'") ?? "0");
        $disk_root_total = trim(shell_exec("df -h / | awk 'NR==2{print \$2}'") ?? "0");
        $disk_root_free = trim(shell_exec("df -h / | awk 'NR==2{print \$4}'") ?? "N/A");

        $disk_data = (int)trim(shell_exec("df /mnt/nextcloud-data 2>/dev/null | awk 'NR==2{print int(\$5)}'") ?? "0");
        $disk_data_used = trim(shell_exec("df -h /mnt/nextcloud-data 2>/dev/null | awk 'NR==2{print \$3}'") ?? "") ?: "0";
        $disk_data_total = trim(shell_exec("df -h /mnt/nextcloud-data 2>/dev/null | awk 'NR==2{print \$2}'") ?? "") ?: "0";
        $disk_data_free = trim(shell_exec("df -h /mnt/nextcloud-data 2>/dev/null | awk 'NR==2{print \$4}'") ?? "") ?: "N/A";

        // Disk I/O
        $disk_io = trim(shell_exec("cat /host/proc/diskstats | grep ' sda ' | awk '{print \$6,\$10}'") ?? "0 0");
        $io_parts = explode(' ', $disk_io);
        $disk_read_gb = round((int)($io_parts[0] ?? 0) * 512 / 1024 / 1024 / 1024, 2);
        $disk_write_gb = round((int)($io_parts[1] ?? 0) * 512 / 1024 / 1024 / 1024, 2);

        // Network
        $net_interface = "wlp2s0";
        $rx_bytes = (int)trim(shell_exec("cat /host/sys/class/net/$net_interface/statistics/rx_bytes 2>/dev/null") ?? "0");
        $tx_bytes = (int)trim(shell_exec("cat /host/sys/class/net/$net_interface/statistics/tx_bytes 2>/dev/null") ?? "0");
        $rx_gb = round($rx_bytes / 1024 / 1024 / 1024, 2);
        $tx_gb = round($tx_bytes / 1024 / 1024 / 1024, 2);
        $connections = (int)trim(shell_exec("cat /host/proc/net/tcp /host/proc/net/tcp6 2>/dev/null | wc -l") ?? "0") - 2;

        // Uptime
        $uptime_raw = trim(shell_exec("cat /host/proc/uptime | awk '{print \$1}'") ?? "0");
        $uptime_seconds = (int)floor((float)$uptime_raw);
        $uptime_days = floor($uptime_seconds / 86400);
        $uptime_hours = floor(($uptime_seconds % 86400) / 3600);
        $uptime_mins = floor(($uptime_seconds % 3600) / 60);

        // Docker via socket
        $containers_json = shell_exec("curl -s --unix-socket /var/run/docker.sock http://localhost/containers/json 2>/dev/null") ?? "[]";
        $containers = json_decode($containers_json, true) ?? [];
        $containers_running = count($containers);

        $containers_all_json = shell_exec("curl -s --unix-socket /var/run/docker.sock 'http://localhost/containers/json?all=true' 2>/dev/null") ?? "[]";
        $containers_all = json_decode($containers_all_json, true) ?? [];
        $containers_total = count($containers_all);

        $images_json = shell_exec("curl -s --unix-socket /var/run/docker.sock http://localhost/images/json 2>/dev/null") ?? "[]";
        $images = json_decode($images_json, true) ?? [];
        $docker_images = count($images);

        // Processes
        $processes = (int)trim(shell_exec("ls /host/proc | grep -E '^[0-9]+$' | wc -l") ?? "0");

        // Container stats (parallel fetch)
        $container_stats = self::getContainerStats($containers);

        // Energy
        $energy = self::calculateEnergy($cpu);

        // Store stats in history
        self::recordStats($cpu, $ram_percent, $container_stats);

        // Check alerts and send notifications
        $statsForAlerts = [
            'cpu' => ['usage' => $cpu],
            'ram' => ['percent' => $ram_percent],
            'disk' => ['root' => ['percent' => $disk_root]],
        ];

        // Build container list for alerts
        $containerList = [];
        foreach ($containers as $c) {
            $name = ltrim($c['Names'][0] ?? '', '/');
            $containerList[] = [
                'container_name' => $name,
                'status' => 'running',
            ];
        }
        // Add stopped containers
        foreach ($containers_all as $c) {
            $name = ltrim($c['Names'][0] ?? '', '/');
            $state = strtolower($c['State'] ?? '');
            $found = false;
            foreach ($containerList as $cl) {
                if ($cl['container_name'] === $name) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $containerList[] = [
                    'container_name' => $name,
                    'status' => $state,
                ];
            }
        }

        require_once __DIR__ . '/AlertController.php';
        AlertController::checkAlerts($statsForAlerts, $containerList);
        AlertController::checkCriticalContainers($containerList);

        self::json([
            'timestamp' => date('Y-m-d H:i:s'),
            'cpu' => [
                'usage' => $cpu,
                'temp' => $cpu_temp,
                'cores' => $cpu_cores,
                'load' => $load_avg,
            ],
            'ram' => [
                'used_mb' => $ram_used,
                'total_mb' => $ram_total,
                'percent' => $ram_percent,
            ],
            'swap' => [
                'used_mb' => $swap_used,
                'total_mb' => $swap_total,
            ],
            'disk' => [
                'root' => [
                    'percent' => $disk_root,
                    'used' => $disk_root_used,
                    'total' => $disk_root_total,
                    'free' => $disk_root_free,
                ],
                'data' => [
                    'percent' => $disk_data,
                    'used' => $disk_data_used,
                    'total' => $disk_data_total,
                    'free' => $disk_data_free,
                ],
                'io' => [
                    'read_gb' => $disk_read_gb,
                    'write_gb' => $disk_write_gb,
                ],
            ],
            'network' => [
                'interface' => $net_interface,
                'rx_gb' => $rx_gb,
                'tx_gb' => $tx_gb,
                'connections' => $connections,
            ],
            'uptime' => [
                'days' => $uptime_days,
                'hours' => $uptime_hours,
                'minutes' => $uptime_mins,
                'seconds' => $uptime_seconds,
            ],
            'docker' => [
                'running' => $containers_running,
                'total' => $containers_total,
                'images' => $docker_images,
            ],
            'processes' => $processes,
            'energy' => $energy,
            'container_stats' => $container_stats,
        ]);
    }

    /**
     * GET /stats/history
     * Get historical stats
     */
    public static function history(): void {
        Auth::require();

        $type = $_GET['type'] ?? 'cpu';
        $container = $_GET['container'] ?? null;
        $hours = min((int)($_GET['hours'] ?? 24), 168); // Max 7 days

        $since = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        if ($container) {
            $stats = Database::fetchAll(
                "SELECT value, recorded_at FROM stats_history
                 WHERE container_name = ? AND stat_type = ? AND recorded_at > ?
                 ORDER BY recorded_at ASC",
                [$container, $type, $since]
            );
        } else {
            $stats = Database::fetchAll(
                "SELECT value, recorded_at FROM stats_history
                 WHERE container_name IS NULL AND stat_type = ? AND recorded_at > ?
                 ORDER BY recorded_at ASC",
                [$type, $since]
            );
        }

        self::json([
            'type' => $type,
            'container' => $container,
            'hours' => $hours,
            'data' => $stats,
        ]);
    }

    /**
     * GET /energy
     * Get energy consumption details
     */
    public static function energy(): void {
        Auth::require();

        $today = date('Y-m-d');
        $weekAgo = date('Y-m-d', strtotime('-7 days'));
        $monthAgo = date('Y-m-d', strtotime('-30 days'));
        $yearAgo = date('Y-m-d', strtotime('-365 days'));

        // Today's readings
        $todayReadings = Database::fetchAll(
            "SELECT watts, recorded_at FROM energy_readings WHERE date(recorded_at) = ? ORDER BY recorded_at",
            [$today]
        );

        // Daily summaries
        $dailyStats = Database::fetchAll(
            "SELECT date, kwh, cost_clp FROM energy_daily WHERE date >= ? ORDER BY date DESC",
            [$yearAgo]
        );

        // Calculate period totals
        $todayKwh = Database::fetch("SELECT kwh FROM energy_daily WHERE date = ?", [$today])['kwh'] ?? 0;
        $weekKwh = Database::fetch("SELECT SUM(kwh) as total FROM energy_daily WHERE date >= ?", [$weekAgo])['total'] ?? 0;
        $monthKwh = Database::fetch("SELECT SUM(kwh) as total FROM energy_daily WHERE date >= ?", [$monthAgo])['total'] ?? 0;
        $yearKwh = Database::fetch("SELECT SUM(kwh) as total FROM energy_daily WHERE date >= ?", [$yearAgo])['total'] ?? 0;
        $totalKwh = Database::fetch("SELECT SUM(kwh) as total FROM energy_daily")['total'] ?? 0;

        $costPerKwh = 295; // CLP

        self::json([
            'today' => [
                'kwh' => round($todayKwh, 3),
                'cost_clp' => round($todayKwh * $costPerKwh),
                'readings' => $todayReadings,
            ],
            'week' => [
                'kwh' => round($weekKwh, 3),
                'cost_clp' => round($weekKwh * $costPerKwh),
            ],
            'month' => [
                'kwh' => round($monthKwh, 3),
                'cost_clp' => round($monthKwh * $costPerKwh),
            ],
            'year' => [
                'kwh' => round($yearKwh, 3),
                'cost_clp' => round($yearKwh * $costPerKwh),
            ],
            'total' => [
                'kwh' => round($totalKwh, 3),
                'cost_clp' => round($totalKwh * $costPerKwh),
            ],
            'daily' => $dailyStats,
        ]);
    }

    /**
     * Get container stats via Docker socket (parallel)
     */
    private static function getContainerStats(array $containers): array {
        if (empty($containers)) {
            return [];
        }

        $mh = curl_multi_init();
        $handles = [];

        foreach ($containers as $c) {
            $name = ltrim($c['Names'][0] ?? '', '/');
            $id = $c['Id'];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock',
                CURLOPT_URL => "http://localhost/containers/$id/stats?stream=false&one-shot=true",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
            ]);

            $handles[$name] = ['handle' => $ch, 'id' => $id];
            curl_multi_add_handle($mh, $ch);
        }

        // Execute all requests in parallel
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        $stats = [];
        foreach ($handles as $name => $data) {
            $result = curl_multi_getcontent($data['handle']);
            curl_multi_remove_handle($mh, $data['handle']);
            curl_close($data['handle']);

            $json = json_decode($result, true);
            if ($json) {
                // Calculate CPU percentage
                $cpu_delta = ($json['cpu_stats']['cpu_usage']['total_usage'] ?? 0) -
                             ($json['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
                $system_delta = ($json['cpu_stats']['system_cpu_usage'] ?? 0) -
                                ($json['precpu_stats']['system_cpu_usage'] ?? 0);
                $num_cpus = $json['cpu_stats']['online_cpus'] ?? 1;
                $cpu_percent = ($system_delta > 0) ? round(($cpu_delta / $system_delta) * $num_cpus * 100, 2) : 0;

                // Memory
                $mem_usage = $json['memory_stats']['usage'] ?? 0;
                $mem_limit = $json['memory_stats']['limit'] ?? 1;
                $mem_percent = round(($mem_usage / $mem_limit) * 100, 2);
                $mem_usage_mb = round($mem_usage / 1024 / 1024, 2);
                $mem_limit_mb = round($mem_limit / 1024 / 1024, 2);

                $stats[$name] = [
                    'id' => substr($data['id'], 0, 12),
                    'status' => 'running',
                    'cpu_percent' => $cpu_percent,
                    'mem_percent' => $mem_percent,
                    'mem_usage_mb' => $mem_usage_mb,
                    'mem_limit_mb' => $mem_limit_mb,
                ];
            }
        }

        curl_multi_close($mh);
        return $stats;
    }

    /**
     * Calculate energy consumption
     */
    private static function calculateEnergy(int $cpu): array {
        $baseWatts = 45;
        $maxWatts = 120;
        $currentWatts = round($baseWatts + (($maxWatts - $baseWatts) * $cpu / 100), 1);
        $costPerKwh = 295;

        // Record current reading
        Database::query(
            "INSERT INTO energy_readings (watts, cpu_percent) VALUES (?, ?)",
            [$currentWatts, $cpu]
        );

        // Update daily total
        $today = date('Y-m-d');
        $lastReading = Database::fetch(
            "SELECT recorded_at FROM energy_readings WHERE date(recorded_at) = ? ORDER BY id DESC LIMIT 1 OFFSET 1",
            [$today]
        );

        if ($lastReading) {
            $lastTime = strtotime($lastReading['recorded_at']);
            $elapsed = time() - $lastTime;
            $kwhAdded = ($currentWatts * ($elapsed / 3600)) / 1000;

            Database::query(
                "INSERT INTO energy_daily (date, kwh, cost_clp) VALUES (?, ?, ?)
                 ON CONFLICT(date) DO UPDATE SET kwh = kwh + excluded.kwh, cost_clp = cost_clp + excluded.cost_clp",
                [$today, $kwhAdded, round($kwhAdded * $costPerKwh)]
            );
        }

        // Get period totals
        $todayKwh = Database::fetch("SELECT kwh FROM energy_daily WHERE date = ?", [$today])['kwh'] ?? 0;
        $weekKwh = Database::fetch("SELECT SUM(kwh) as t FROM energy_daily WHERE date >= ?", [date('Y-m-d', strtotime('-7 days'))])['t'] ?? 0;
        $monthKwh = Database::fetch("SELECT SUM(kwh) as t FROM energy_daily WHERE date >= ?", [date('Y-m-d', strtotime('-30 days'))])['t'] ?? 0;
        $yearKwh = Database::fetch("SELECT SUM(kwh) as t FROM energy_daily WHERE date >= ?", [date('Y-m-d', strtotime('-365 days'))])['t'] ?? 0;
        $totalKwh = Database::fetch("SELECT SUM(kwh) as t FROM energy_daily")['t'] ?? 0;

        // Cleanup old readings (keep 24h)
        Database::query("DELETE FROM energy_readings WHERE recorded_at < datetime('now', '-1 day')");

        return [
            'watts' => $currentWatts,
            'today' => ['kwh' => round($todayKwh, 3), 'cost_clp' => round($todayKwh * $costPerKwh)],
            'week' => ['kwh' => round($weekKwh, 3), 'cost_clp' => round($weekKwh * $costPerKwh)],
            'month' => ['kwh' => round($monthKwh, 3), 'cost_clp' => round($monthKwh * $costPerKwh)],
            'year' => ['kwh' => round($yearKwh, 3), 'cost_clp' => round($yearKwh * $costPerKwh)],
            'total' => ['kwh' => round($totalKwh, 3), 'cost_clp' => round($totalKwh * $costPerKwh)],
        ];
    }

    /**
     * Record stats to history
     */
    private static function recordStats(int $cpu, int $ram, array $containerStats): void {
        // System stats (every 5 minutes to save space)
        $lastSystemStat = Database::fetch(
            "SELECT recorded_at FROM stats_history WHERE container_name IS NULL ORDER BY id DESC LIMIT 1"
        );

        $shouldRecord = !$lastSystemStat ||
                        (time() - strtotime($lastSystemStat['recorded_at'])) >= 300;

        if ($shouldRecord) {
            Database::query(
                "INSERT INTO stats_history (container_name, stat_type, value) VALUES (NULL, 'cpu', ?)",
                [$cpu]
            );
            Database::query(
                "INSERT INTO stats_history (container_name, stat_type, value) VALUES (NULL, 'ram', ?)",
                [$ram]
            );
        }

        // Cleanup old history (keep 7 days)
        Database::query("DELETE FROM stats_history WHERE recorded_at < datetime('now', '-7 days')");
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
