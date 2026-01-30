<?php
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$energy_file = '/var/www/html/energy_log.json';

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

// Discos
$disk_root = (int)trim(shell_exec("df / | awk 'NR==2{print int(\$5)}'") ?? "0");
$disk_root_used = trim(shell_exec("df -h / | awk 'NR==2{print \$3}'") ?? "0");
$disk_root_total = trim(shell_exec("df -h / | awk 'NR==2{print \$2}'") ?? "0");
$disk_root_free = trim(shell_exec("df -h / | awk 'NR==2{print \$4}'") ?? "N/A");

$disk_data = (int)trim(shell_exec("df /mnt/nextcloud-data 2>/dev/null | awk 'NR==2{print int(\$5)}'") ?? "0");
$disk_data_used = trim(shell_exec("df -h /mnt/nextcloud-data 2>/dev/null | awk 'NR==2{print \$3}'") ?? "") ?: "0";
$disk_data_total = trim(shell_exec("df -h /mnt/nextcloud-data 2>/dev/null | awk 'NR==2{print \$2}'") ?? "") ?: "0";
$disk_data_free = trim(shell_exec("df -h /mnt/nextcloud-data 2>/dev/null | awk 'NR==2{print \$4}'") ?? "") ?: "N/A";

// I/O
$disk_io = trim(shell_exec("cat /host/proc/diskstats | grep ' sda ' | awk '{print \$6,\$10}'") ?? "0 0");
$io_parts = explode(' ', $disk_io);
$disk_read_gb = round((int)($io_parts[0] ?? 0) * 512 / 1024 / 1024 / 1024, 2);
$disk_write_gb = round((int)($io_parts[1] ?? 0) * 512 / 1024 / 1024 / 1024, 2);

// Red - usar interfaz wifi del servidor (wlp2s0 es la activa)
$net_interface = "wlp2s0";
$rx_bytes = (int)trim(shell_exec("cat /host/sys/class/net/$net_interface/statistics/rx_bytes 2>/dev/null") ?? "0");
$tx_bytes = (int)trim(shell_exec("cat /host/sys/class/net/$net_interface/statistics/tx_bytes 2>/dev/null") ?? "0");
$rx_gb = round($rx_bytes / 1024 / 1024 / 1024, 2);
$tx_gb = round($tx_bytes / 1024 / 1024 / 1024, 2);

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

// Procesos
$processes = (int)trim(shell_exec("ls /host/proc | grep -E '^[0-9]+$' | wc -l") ?? "0");

// Energia REAL con registro por periodos (dia, semana, mes, año)
$base_watts = 45;   // Consumo base servidor idle
$max_watts = 120;   // Consumo máximo (CPU 100%)
$current_watts = round($base_watts + (($max_watts - $base_watts) * $cpu / 100), 1);
$cost_per_kwh = 295; // CLP Chile (tarifa real según boleta: 54200/184=294)

$energy_data = file_exists($energy_file) ? json_decode(file_get_contents($energy_file), true) : [];
if (!is_array($energy_data)) {
    $energy_data = [
        'readings' => [],
        'last_update' => 0,
        'daily' => [],      // ['2026-01-10' => kwh]
        'total_kwh' => 0
    ];
}

$now = time();
$today = date('Y-m-d', $now);
$last_update = $energy_data['last_update'] ?? 0;
$time_diff = $last_update > 0 ? ($now - $last_update) : 0;

// Calcular kWh consumidos desde ultima lectura (sin límite de 1 hora)
$should_save = false;
if ($time_diff > 0) {
    // Usar consumo REAL actual (basado en CPU) para el cálculo
    // Para períodos largos sin lecturas, usar el promedio de las últimas lecturas si existen
    if ($time_diff > 3600 && !empty($energy_data['readings'])) {
        // Calcular promedio de las últimas lecturas disponibles
        $recent_readings = array_slice($energy_data['readings'], -60); // últimas 60 lecturas (1 hora)
        $watts_sum = array_sum(array_column($recent_readings, 'watts'));
        $watts_for_calc = $watts_sum / count($recent_readings);
    } else {
        // Usar el consumo actual medido
        $watts_for_calc = $current_watts;
    }
    $kwh_added = $watts_for_calc * ($time_diff / 3600) / 1000;

    // Acumular al total
    $energy_data['total_kwh'] = round(($energy_data['total_kwh'] ?? 0) + $kwh_added, 6);

    // Distribuir el consumo en los días que pasaron
    $start_day = date('Y-m-d', $last_update);
    $end_day = $today;

    if ($start_day === $end_day) {
        // Todo el consumo es del mismo día
        if (!isset($energy_data['daily'][$today])) {
            $energy_data['daily'][$today] = 0;
        }
        $energy_data['daily'][$today] = round($energy_data['daily'][$today] + $kwh_added, 6);
    } else {
        // Distribuir entre días
        $current_time = $last_update;
        while (date('Y-m-d', $current_time) <= $end_day) {
            $day = date('Y-m-d', $current_time);
            $day_end = strtotime($day . ' 23:59:59');
            $period_end = min($day_end, $now);
            $period_start = max(strtotime($day . ' 00:00:00'), $last_update);
            $period_seconds = $period_end - $period_start;

            if ($period_seconds > 0) {
                $day_kwh = $watts_for_calc * ($period_seconds / 3600) / 1000;
                if (!isset($energy_data['daily'][$day])) {
                    $energy_data['daily'][$day] = 0;
                }
                $energy_data['daily'][$day] = round($energy_data['daily'][$day] + $day_kwh, 6);
            }
            $current_time = strtotime($day . ' +1 day');
        }
    }

    // Limpiar días antiguos (mantener 365 días)
    $energy_data['daily'] = array_filter($energy_data['daily'], function($key) {
        return strtotime($key) > strtotime('-365 days');
    }, ARRAY_FILTER_USE_KEY);

    $energy_data['last_update'] = $now;
    $should_save = true;
}

// Agregar lectura cada minuto (para historial de 24h)
if ($time_diff >= 60 || $last_update == 0) {
    $energy_data['readings'][] = ['time' => $now, 'watts' => $current_watts, 'cpu' => $cpu];
    if (count($energy_data['readings']) > 1440) array_shift($energy_data['readings']); // 24h de lecturas
    $should_save = true;
}

// Guardar si hubo cambios
if ($should_save) {
    @file_put_contents($energy_file, json_encode($energy_data));
}

// Calcular consumo por períodos
$kwh_today = round($energy_data['daily'][$today] ?? 0, 3);

// Últimos 7 días
$kwh_week = 0;
for ($i = 0; $i < 7; $i++) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $kwh_week += $energy_data['daily'][$day] ?? 0;
}
$kwh_week = round($kwh_week, 3);

// Mes actual
$kwh_month = 0;
$current_month = date('Y-m');
foreach ($energy_data['daily'] as $day => $kwh) {
    if (substr($day, 0, 7) === $current_month) {
        $kwh_month += $kwh;
    }
}
$kwh_month = round($kwh_month, 3);

// Año actual
$kwh_year = 0;
$current_year = date('Y');
foreach ($energy_data['daily'] as $day => $kwh) {
    if (substr($day, 0, 4) === $current_year) {
        $kwh_year += $kwh;
    }
}
$kwh_year = round($kwh_year, 3);

$kwh_total = round($energy_data['total_kwh'], 3);

// Costos
$cost_today = round($kwh_today * $cost_per_kwh, 0);
$cost_week = round($kwh_week * $cost_per_kwh, 0);
$cost_month = round($kwh_month * $cost_per_kwh, 0);
$cost_year = round($kwh_year * $cost_per_kwh, 0);
$cost_total = round($kwh_total * $cost_per_kwh, 0);

// Conexiones
$net_connections = (int)trim(shell_exec("cat /host/proc/net/tcp /host/proc/net/tcp6 2>/dev/null | grep -v local_address | wc -l") ?? "0");

// Container Stats (CPU y RAM por contenedor)
$container_stats = [];
$stats_raw = shell_exec("curl -s --unix-socket /var/run/docker.sock 'http://localhost/containers/json' 2>/dev/null") ?? "[]";
$running_containers = json_decode($stats_raw, true) ?? [];

foreach ($running_containers as $container) {
    $name = ltrim($container['Names'][0] ?? 'unknown', '/');
    $id = substr($container['Id'], 0, 12);
    $status = $container['State'] ?? 'unknown';

    // Get individual container stats
    $stat_raw = shell_exec("curl -s --unix-socket /var/run/docker.sock 'http://localhost/containers/$id/stats?stream=false' 2>/dev/null");
    $stat = json_decode($stat_raw, true);

    $cpu_percent = 0;
    $mem_percent = 0;
    $mem_usage = 0;
    $mem_limit = 0;

    if ($stat) {
        // CPU calculation
        $cpu_delta = ($stat['cpu_stats']['cpu_usage']['total_usage'] ?? 0) - ($stat['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
        $system_delta = ($stat['cpu_stats']['system_cpu_usage'] ?? 0) - ($stat['precpu_stats']['system_cpu_usage'] ?? 0);
        $cpu_count = $stat['cpu_stats']['online_cpus'] ?? 1;

        if ($system_delta > 0 && $cpu_delta > 0) {
            $cpu_percent = round(($cpu_delta / $system_delta) * $cpu_count * 100, 2);
        }

        // Memory calculation
        $mem_usage = $stat['memory_stats']['usage'] ?? 0;
        $mem_limit = $stat['memory_stats']['limit'] ?? 1;
        $mem_percent = round(($mem_usage / $mem_limit) * 100, 2);
    }

    $container_stats[$name] = [
        'id' => $id,
        'status' => $status,
        'cpu_percent' => $cpu_percent,
        'mem_percent' => $mem_percent,
        'mem_usage_mb' => round($mem_usage / 1024 / 1024, 1),
        'mem_limit_mb' => round($mem_limit / 1024 / 1024, 1)
    ];
}

echo json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'cpu' => ['usage' => $cpu, 'temp' => $cpu_temp, 'cores' => $cpu_cores, 'load' => $load_avg],
    'ram' => ['used_mb' => $ram_used, 'total_mb' => $ram_total, 'percent' => $ram_percent],
    'swap' => ['used_mb' => $swap_used, 'total_mb' => $swap_total],
    'disk' => [
        'root' => ['percent' => $disk_root, 'used' => $disk_root_used, 'total' => $disk_root_total, 'free' => $disk_root_free],
        'data' => ['percent' => $disk_data, 'used' => $disk_data_used, 'total' => $disk_data_total, 'free' => $disk_data_free],
        'io' => ['read_gb' => $disk_read_gb, 'write_gb' => $disk_write_gb]
    ],
    'network' => ['interface' => $net_interface, 'rx_gb' => $rx_gb, 'tx_gb' => $tx_gb, 'connections' => $net_connections],
    'uptime' => ['days' => $uptime_days, 'hours' => $uptime_hours, 'minutes' => $uptime_mins, 'seconds' => $uptime_seconds],
    'docker' => ['running' => $containers_running, 'total' => $containers_total, 'images' => $docker_images],
    'processes' => $processes,
    'energy' => [
        'watts' => $current_watts,
        'today' => ['kwh' => $kwh_today, 'cost_clp' => $cost_today],
        'week' => ['kwh' => $kwh_week, 'cost_clp' => $cost_week],
        'month' => ['kwh' => $kwh_month, 'cost_clp' => $cost_month],
        'year' => ['kwh' => $kwh_year, 'cost_clp' => $cost_year],
        'total' => ['kwh' => $kwh_total, 'cost_clp' => $cost_total]
    ],
    'container_stats' => $container_stats
]);
