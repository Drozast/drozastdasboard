<?php
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// CPU
$cpu = (int)trim(shell_exec("top -bn1 | grep 'Cpu(s)' | awk '{print 100 - \$8}' | cut -d. -f1") ?? "0");
$cpu_temp = trim(shell_exec("cat /host/sys/class/thermal/thermal_zone0/temp 2>/dev/null") ?? "0");
$cpu_temp = $cpu_temp ? round($cpu_temp / 1000, 1) : 0;
$cpu_cores = (int)trim(shell_exec("nproc") ?? "1");
$load_avg = trim(shell_exec("cat /host/proc/loadavg | awk '{print \$1,\$2,\$3}'") ?? "0 0 0");

// RAM
$mem = trim(shell_exec("cat /host/proc/meminfo | grep -E 'MemTotal|MemFree|MemAvailable|Cached|Buffers' | awk '{print \$2}'") ?? "");
$mem_lines = explode("\n", $mem);
$ram_total = round((int)($mem_lines[0] ?? 0) / 1024);
$ram_free = round((int)($mem_lines[1] ?? 0) / 1024);
$ram_available = round((int)($mem_lines[2] ?? 0) / 1024);
$ram_used = $ram_total - $ram_available;
$ram_percent = $ram_total > 0 ? round($ram_used / $ram_total * 100) : 0;

// Swap
$swap_total = (int)trim(shell_exec("cat /host/proc/meminfo | grep SwapTotal | awk '{print \$2}'") ?? "0");
$swap_free = (int)trim(shell_exec("cat /host/proc/meminfo | grep SwapFree | awk '{print \$2}'") ?? "0");
$swap_total = round($swap_total / 1024);
$swap_used = round(($swap_total * 1024 - $swap_free) / 1024);

// Discos
$disk_root = (int)trim(shell_exec("df -h / | awk 'NR==2{print \$5}' | tr -d '%'") ?? "0");
$disk_root_used = trim(shell_exec("df -h / | awk 'NR==2{print \$3}'") ?? "0");
$disk_root_total = trim(shell_exec("df -h / | awk 'NR==2{print \$2}'") ?? "0");
$disk_root_free = trim(shell_exec("df -h / | awk 'NR==2{print \$4}'") ?? "N/A");

$disk_data_raw = trim(shell_exec("df -h /mnt/nextcloud-data 2>/dev/null | awk 'NR==2{print \$5}' | tr -d '%'") ?? "");
$disk_data = $disk_data_raw !== "" ? (int)$disk_data_raw : 0;
$disk_data_used = trim(shell_exec("df -h /mnt/nextcloud-data 2>/dev/null | awk 'NR==2{print \$3}'") ?? "") ?: "0";
$disk_data_total = trim(shell_exec("df -h /mnt/nextcloud-data 2>/dev/null | awk 'NR==2{print \$2}'") ?? "") ?: "0";
$disk_data_free = trim(shell_exec("df -h /mnt/nextcloud-data 2>/dev/null | awk 'NR==2{print \$4}'") ?? "") ?: "N/A";

// Disk I/O
$disk_io = trim(shell_exec("cat /host/proc/diskstats | grep -E 'sda |nvme0n1 |sdb ' | head -1 | awk '{print \$6,\$10}'") ?? "0 0");
$io_parts = explode(' ', $disk_io);
$disk_read_gb = round((int)($io_parts[0] ?? 0) * 512 / 1024 / 1024 / 1024, 2);
$disk_write_gb = round((int)($io_parts[1] ?? 0) * 512 / 1024 / 1024 / 1024, 2);

// Red
$net_interface = trim(shell_exec("cat /host/proc/net/route | awk 'NR==2{print \$1}'") ?? "eth0");
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

// Docker
$containers_running = (int)trim(shell_exec("docker ps -q 2>/dev/null | wc -l") ?? "0");
$containers_total = (int)trim(shell_exec("docker ps -aq 2>/dev/null | wc -l") ?? "0");
$docker_images = (int)trim(shell_exec("docker images -q 2>/dev/null | wc -l") ?? "0");

// Procesos
$processes = (int)trim(shell_exec("ls /host/proc | grep -E '^[0-9]+$' | wc -l") ?? "0");

// Consumo energético estimado
$base_watts = 50;
$max_watts = 150;
$current_watts = round($base_watts + (($max_watts - $base_watts) * $cpu / 100), 1);
$kwh_consumed = round(($base_watts + $current_watts) / 2 * $uptime_seconds / 3600 / 1000, 3);
$cost_per_kwh = 300; // CLP por kWh (Chile)
$energy_cost = round($kwh_consumed * $cost_per_kwh, 0);

// Conexiones
$net_connections = (int)trim(shell_exec("cat /host/proc/net/tcp /host/proc/net/tcp6 2>/dev/null | wc -l") ?? "0");

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
    'energy' => ['watts' => $current_watts, 'kwh' => $kwh_consumed, 'cost_clp' => $energy_cost]
]);
