<?php
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$energy_file = '/var/www/html/energy_log.json';
$rapl_cache_file = '/var/www/html/rapl_cache.json';

// CPU power real via RAPL (Intel)
$cpu_watts_real = null;
$rapl_path = '/host/sys/class/powercap/intel-rapl/intel-rapl:0/energy_uj';
$rapl_energy = @file_get_contents($rapl_path);
if ($rapl_energy !== false) {
    $rapl_energy = (float)trim($rapl_energy);
    $rapl_now = microtime(true);

    // Leer caché anterior
    $rapl_cache = @file_get_contents($rapl_cache_file);
    $rapl_prev = $rapl_cache ? json_decode($rapl_cache, true) : null;

    if ($rapl_prev && isset($rapl_prev['energy']) && isset($rapl_prev['time'])) {
        $energy_diff = $rapl_energy - $rapl_prev['energy'];
        $time_diff = $rapl_now - $rapl_prev['time'];

        // Handle counter overflow (max_energy_range_uj)
        if ($energy_diff < 0) {
            $max_energy = @file_get_contents('/host/sys/class/powercap/intel-rapl/intel-rapl:0/max_energy_range_uj');
            if ($max_energy) {
                $energy_diff = ((float)trim($max_energy) - $rapl_prev['energy']) + $rapl_energy;
            }
        }

        if ($time_diff > 0 && $time_diff < 60) { // máximo 60 segundos entre lecturas
            $cpu_watts_real = round($energy_diff / $time_diff / 1000000, 1); // uJ a W
        }
    }

    // Guardar lectura actual
    @file_put_contents($rapl_cache_file, json_encode(['energy' => $rapl_energy, 'time' => $rapl_now]));
}

// CPU
$cpu = (int)trim(shell_exec("grep 'cpu ' /host/proc/stat | awk '{usage=(\$2+\$4)*100/(\$2+\$4+\$5)} END {print int(usage)}'") ?? "0");
$cpu_temp = trim(shell_exec("cat /host/sys/class/thermal/thermal_zone0/temp 2>/dev/null") ?? "0");
$cpu_temp = $cpu_temp ? round($cpu_temp / 1000, 1) : 0;
$cpu_cores = (int)trim(shell_exec("nproc") ?? "1");
$load_avg = trim(shell_exec("cat /host/proc/loadavg | awk '{print \$1,\$2,\$3}'") ?? "0 0 0");

// CPU por núcleo - uso
$cpu_per_core = [];
$stat_lines = explode("\n", trim(shell_exec("grep '^cpu[0-9]' /host/proc/stat") ?? ""));
foreach ($stat_lines as $line) {
    if (preg_match('/^cpu(\d+)\s+(\d+)\s+\d+\s+(\d+)\s+(\d+)/', $line, $m)) {
        $core_num = (int)$m[1];
        $user = (int)$m[2];
        $sys = (int)$m[3];
        $idle = (int)$m[4];
        $total = $user + $sys + $idle;
        $usage = $total > 0 ? round(($user + $sys) * 100 / $total) : 0;
        $cpu_per_core[$core_num] = ['usage' => $usage];
    }
}

// CPU frecuencias por núcleo
$freq_output = trim(shell_exec("cat /host/sys/devices/system/cpu/cpu*/cpufreq/scaling_cur_freq 2>/dev/null") ?? "");
$freqs = explode("\n", $freq_output);
foreach ($freqs as $i => $freq) {
    if (isset($cpu_per_core[$i]) && is_numeric($freq)) {
        $cpu_per_core[$i]['freq_mhz'] = round((int)$freq / 1000);
    }
}

// CPU modelo
$cpu_model = trim(shell_exec("cat /host/proc/cpuinfo | grep 'model name' | head -1 | cut -d':' -f2") ?? "Unknown");

// Temperaturas de CPU por core (coretemp/k10temp)
$temps_output = trim(shell_exec("cat /host/sys/class/hwmon/hwmon*/temp*_input 2>/dev/null") ?? "");
$temps_labels = trim(shell_exec("cat /host/sys/class/hwmon/hwmon*/temp*_label 2>/dev/null") ?? "");
$temp_values = explode("\n", $temps_output);
$temp_labels_arr = explode("\n", $temps_labels);
$cpu_temps = [];
for ($i = 0; $i < count($temp_values); $i++) {
    $temp = is_numeric($temp_values[$i]) ? round((int)$temp_values[$i] / 1000, 1) : 0;
    $label = isset($temp_labels_arr[$i]) ? trim($temp_labels_arr[$i]) : "Temp $i";
    if ($temp > 0) {
        $cpu_temps[] = ['label' => $label, 'temp' => $temp];
    }
}

// GPU NVIDIA (si existe)
$gpu_info = null;
$nvidia_check = shell_exec("nvidia-smi --query-gpu=name,utilization.gpu,memory.used,memory.total,temperature.gpu,power.draw,fan.speed,clocks.gr,clocks.mem --format=csv,noheader,nounits 2>/dev/null");
if ($nvidia_check && trim($nvidia_check) !== '') {
    $gpu_data = str_getcsv(trim($nvidia_check));
    if (count($gpu_data) >= 9) {
        $gpu_info = [
            'name' => trim($gpu_data[0]),
            'usage' => (int)trim($gpu_data[1]),
            'mem_used_mb' => (int)trim($gpu_data[2]),
            'mem_total_mb' => (int)trim($gpu_data[3]),
            'temp' => (int)trim($gpu_data[4]),
            'power_w' => round((float)trim($gpu_data[5]), 1),
            'fan_percent' => (int)trim($gpu_data[6]),
            'clock_mhz' => (int)trim($gpu_data[7]),
            'mem_clock_mhz' => (int)trim($gpu_data[8])
        ];
    }
}

// GPU AMD (si existe) - RX 570, etc.
if (!$gpu_info) {
    // Buscar hwmon de AMD GPU
    $amd_hwmon = trim(shell_exec("ls -d /host/sys/class/drm/card0/device/hwmon/hwmon* 2>/dev/null | head -1") ?? "");
    $amd_device = "/host/sys/class/drm/card0/device";

    if ($amd_hwmon) {
        // Temperatura
        $temp_raw = trim(shell_exec("cat {$amd_hwmon}/temp1_input 2>/dev/null") ?? "0");
        $temp = $temp_raw ? round((int)$temp_raw / 1000) : 0;

        // Fan RPM
        $fan_rpm = (int)trim(shell_exec("cat {$amd_hwmon}/fan1_input 2>/dev/null") ?? "0");
        $fan_max = (int)trim(shell_exec("cat {$amd_hwmon}/fan1_max 2>/dev/null") ?? "4000");
        $fan_percent = $fan_max > 0 ? round($fan_rpm / $fan_max * 100) : 0;

        // VRAM usado
        $vram_total = 8192; // RX 570 8GB
        $vram_used_raw = trim(shell_exec("cat {$amd_device}/mem_info_vram_used 2>/dev/null") ?? "0");
        $vram_used = $vram_used_raw ? round((int)$vram_used_raw / 1024 / 1024) : 0;

        // Clock actual y máximo para calcular uso
        $clock_lines = shell_exec("cat {$amd_device}/pp_dpm_sclk 2>/dev/null") ?? "";
        $clock_mhz = 0;
        $clock_max = 1268; // RX 570 max boost
        if (preg_match('/(\d+):\s*(\d+)Mhz\s*\*/', $clock_lines, $m)) {
            $clock_mhz = (int)$m[2];
        }

        // Estimar uso basado en clock (300MHz idle, 1268MHz max)
        $clock_min = 300;
        $usage = $clock_mhz > $clock_min ? round(($clock_mhz - $clock_min) / ($clock_max - $clock_min) * 100) : 0;

        // Estimar potencia basado en uso (RX 570: 18W idle, 150W TDP)
        $power_w = round(18 + ($usage / 100 * (150 - 18)), 1);

        $gpu_info = [
            'name' => 'AMD RX 570 8GB',
            'usage' => $usage,
            'mem_used_mb' => $vram_used,
            'mem_total_mb' => $vram_total,
            'temp' => $temp,
            'power_w' => $power_w,
            'fan_percent' => $fan_percent,
            'fan_rpm' => $fan_rpm,
            'clock_mhz' => $clock_mhz,
            'mem_clock_mhz' => 0,
            'type' => 'AMD'
        ];
    }
}

// Motherboard / Sensores adicionales
$motherboard_info = [];
$mb_name = trim(shell_exec("cat /host/sys/class/dmi/id/board_name 2>/dev/null") ?? "");
$mb_vendor = trim(shell_exec("cat /host/sys/class/dmi/id/board_vendor 2>/dev/null") ?? "");
$motherboard_info['name'] = $mb_name ?: 'Unknown';
$motherboard_info['vendor'] = $mb_vendor ?: 'Unknown';

// Sensores de la placa (voltajes, temperaturas adicionales via lm-sensors)
$sensors_output = shell_exec("sensors -j 2>/dev/null");
if ($sensors_output) {
    $sensors_data = json_decode($sensors_output, true);
    $motherboard_info['sensors'] = $sensors_data ?: [];
} else {
    $motherboard_info['sensors'] = [];
}

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
// Consumo por componente - CPU
if ($cpu_watts_real !== null && $cpu_watts_real > 0 && $cpu_watts_real < 300) {
    // Usar lectura REAL de Intel RAPL
    $cpu_watts = $cpu_watts_real;
} else {
    // Fallback: estimar basado en TDP (Xeon E5-2696 v4 = 145W TDP)
    $cpu_base_watts = 35;    // Xeon idle
    $cpu_max_watts = 145;    // Xeon E5-2696 v4 TDP
    $cpu_watts = round($cpu_base_watts + (($cpu_max_watts - $cpu_base_watts) * $cpu / 100), 1);
}

// GPU watts - intentar obtener lectura REAL de lm-sensors primero
$gpu_watts = 0;
$gpu_watts_real = false;

// Buscar potencia real de AMD GPU en lm-sensors
if (isset($motherboard_info['sensors']['amdgpu-pci-0300']['PPT']['power1_input'])) {
    $gpu_watts = round($motherboard_info['sensors']['amdgpu-pci-0300']['PPT']['power1_input'], 1);
    $gpu_watts_real = true;
} elseif ($gpu_info && isset($gpu_info['power_w']) && $gpu_info['power_w'] > 0) {
    $gpu_watts = $gpu_info['power_w'];
} elseif ($gpu_info) {
    // Fallback: estimar por uso
    $gpu_idle = 18;
    $gpu_max = 150;
    $gpu_usage = $gpu_info['usage'] ?? 0;
    $gpu_watts = round($gpu_idle + (($gpu_max - $gpu_idle) * $gpu_usage / 100), 1);
} else {
    $gpu_watts = 5;
}

// Placa base, RAM, SSD, ventiladores (estimación realista)
$mb_watts = 15;          // Placa base + chipset (mini-ITX/mATX típico)
$ram_watts = round(($ram_total / 1024) / 8 * 3, 1); // ~3W por cada 8GB
$storage_watts = 5;      // NVMe (HDDs agregan ~5W cada uno)
$fans_watts = 4;         // 2-3 ventiladores eficientes
$other_watts = $mb_watts + $ram_watts + $storage_watts + $fans_watts;

// Total actual
$current_watts = round($cpu_watts + $gpu_watts + $other_watts, 1);
$cost_per_kwh = 295; // CLP Chile

// Desglose de consumo
$power_breakdown = [
    'cpu' => $cpu_watts,
    'cpu_real' => $cpu_watts_real !== null,  // true = RAPL, false = estimado
    'gpu' => $gpu_watts,
    'gpu_real' => $gpu_watts_real,  // true = lm-sensors, false = estimado
    'motherboard' => $mb_watts,
    'ram' => $ram_watts,
    'storage' => $storage_watts,
    'fans' => $fans_watts,
    'total' => $current_watts
];

$energy_data = file_exists($energy_file) ? json_decode(file_get_contents($energy_file), true) : [];
if (!is_array($energy_data) || !isset($energy_data['hourly'])) {
    $energy_data = [
        'readings' => [],
        'last_update' => 0,
        'daily' => [],           // ['2026-01-10' => kwh]
        'hourly' => [],          // ['2026-01-10-14' => kwh] (por hora del día)
        'hourly_avg' => [],      // [0-23 => avg watts] promedio por hora
        'component_daily' => [], // ['2026-01-10' => ['cpu' => kwh, 'gpu' => kwh, ...]]
        'total_kwh' => 0
    ];
}

$now = time();
$today = date('Y-m-d', $now);
$current_hour = date('Y-m-d-H', $now);
$hour_of_day = (int)date('G', $now); // 0-23
$last_update = $energy_data['last_update'] ?? 0;
$time_diff = $last_update > 0 ? ($now - $last_update) : 0;

// Calcular kWh consumidos desde ultima lectura
$should_save = false;
if ($time_diff > 0 && $time_diff < 7200) { // máximo 2 horas para evitar datos erróneos
    $kwh_added = $current_watts * ($time_diff / 3600) / 1000;

    // kWh por componente
    $cpu_kwh = $cpu_watts * ($time_diff / 3600) / 1000;
    $gpu_kwh = $gpu_watts * ($time_diff / 3600) / 1000;
    $other_kwh = $other_watts * ($time_diff / 3600) / 1000;

    // Acumular al total
    $energy_data['total_kwh'] = round(($energy_data['total_kwh'] ?? 0) + $kwh_added, 6);

    // Acumular al día actual
    if (!isset($energy_data['daily'][$today])) {
        $energy_data['daily'][$today] = 0;
    }
    $energy_data['daily'][$today] = round($energy_data['daily'][$today] + $kwh_added, 6);

    // Acumular por hora
    if (!isset($energy_data['hourly'][$current_hour])) {
        $energy_data['hourly'][$current_hour] = 0;
    }
    $energy_data['hourly'][$current_hour] = round($energy_data['hourly'][$current_hour] + $kwh_added, 6);

    // Acumular por componente del día
    if (!isset($energy_data['component_daily'][$today])) {
        $energy_data['component_daily'][$today] = ['cpu' => 0, 'gpu' => 0, 'other' => 0];
    }
    $energy_data['component_daily'][$today]['cpu'] = round($energy_data['component_daily'][$today]['cpu'] + $cpu_kwh, 6);
    $energy_data['component_daily'][$today]['gpu'] = round($energy_data['component_daily'][$today]['gpu'] + $gpu_kwh, 6);
    $energy_data['component_daily'][$today]['other'] = round($energy_data['component_daily'][$today]['other'] + $other_kwh, 6);

    // Limpiar datos antiguos
    $energy_data['daily'] = array_filter($energy_data['daily'], function($key) {
        return strtotime($key) > strtotime('-365 days');
    }, ARRAY_FILTER_USE_KEY);

    // Mantener solo 7 días de datos por hora
    $energy_data['hourly'] = array_filter($energy_data['hourly'], function($key) {
        return strtotime(substr($key, 0, 10)) > strtotime('-7 days');
    }, ARRAY_FILTER_USE_KEY);

    // Mantener 30 días de componentes
    $energy_data['component_daily'] = array_filter($energy_data['component_daily'], function($key) {
        return strtotime($key) > strtotime('-30 days');
    }, ARRAY_FILTER_USE_KEY);

    $should_save = true;
}

$energy_data['last_update'] = $now;

// Agregar lectura cada minuto (para historial de 24h y gráficos)
if ($time_diff >= 60 || $last_update == 0) {
    $energy_data['readings'][] = [
        'time' => $now,
        'watts' => $current_watts,
        'cpu' => $cpu,
        'cpu_w' => $cpu_watts,
        'gpu_w' => $gpu_watts
    ];
    // Mantener 24h de lecturas (1440 minutos)
    if (count($energy_data['readings']) > 1440) {
        array_shift($energy_data['readings']);
    }

    // Calcular promedio por hora del día (para patrones de uso)
    if (!isset($energy_data['hourly_avg'])) {
        $energy_data['hourly_avg'] = array_fill(0, 24, ['sum' => 0, 'count' => 0]);
    }
    $energy_data['hourly_avg'][$hour_of_day]['sum'] += $current_watts;
    $energy_data['hourly_avg'][$hour_of_day]['count']++;

    $should_save = true;
}

// Guardar si hubo cambios
if ($should_save) {
    @file_put_contents($energy_file, json_encode($energy_data));
}

// Calcular promedios por hora del día
$hourly_averages = [];
if (isset($energy_data['hourly_avg'])) {
    for ($h = 0; $h < 24; $h++) {
        $avg_data = $energy_data['hourly_avg'][$h] ?? ['sum' => 0, 'count' => 0];
        $hourly_averages[$h] = $avg_data['count'] > 0 ? round($avg_data['sum'] / $avg_data['count'], 1) : 0;
    }
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

// Consumo por componente del día actual
$today_components = $energy_data['component_daily'][$today] ?? ['cpu' => 0, 'gpu' => 0, 'other' => 0];

// Historial de las últimas 24 horas (para gráfico)
$last_24h_readings = array_slice($energy_data['readings'] ?? [], -60); // últimos 60 registros (1 hora si es cada minuto)

// Calcular consumo promedio por hora basado en historial
$avg_watts_24h = 0;
if (!empty($energy_data['readings'])) {
    $watts_sum = array_sum(array_column($energy_data['readings'], 'watts'));
    $avg_watts_24h = round($watts_sum / count($energy_data['readings']), 1);
}

// Estimar consumo mensual basado en promedio actual
$estimated_monthly_kwh = round(($avg_watts_24h * 24 * 30) / 1000, 2);
$estimated_monthly_cost = round($estimated_monthly_kwh * $cost_per_kwh, 0);

// Historial diario (últimos 7 días para gráfico)
$daily_history = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $daily_history[$day] = round($energy_data['daily'][$day] ?? 0, 3);
}

// Historial por hora del día actual
$today_hourly = [];
for ($h = 0; $h < 24; $h++) {
    $hour_key = $today . '-' . str_pad($h, 2, '0', STR_PAD_LEFT);
    $today_hourly[$h] = round($energy_data['hourly'][$hour_key] ?? 0, 4);
}

// Conexiones
$net_connections = (int)trim(shell_exec("cat /host/proc/net/tcp /host/proc/net/tcp6 2>/dev/null | grep -v local_address | wc -l") ?? "0");

// ==================== PORT SCANNER ====================
// Escanear puertos usados por Docker y sistema
$ports_data = [];
$port_conflicts = [];

// Obtener puertos de contenedores Docker
$docker_ports_raw = shell_exec("curl -s --unix-socket /var/run/docker.sock 'http://localhost/containers/json' 2>/dev/null") ?? "[]";
$docker_containers = json_decode($docker_ports_raw, true) ?? [];

foreach ($docker_containers as $container) {
    $container_name = ltrim($container['Names'][0] ?? 'unknown', '/');
    $container_ports = $container['Ports'] ?? [];

    foreach ($container_ports as $port_info) {
        $host_port = $port_info['PublicPort'] ?? null;
        $container_port = $port_info['PrivatePort'] ?? null;
        $protocol = $port_info['Type'] ?? 'tcp';
        $host_ip = $port_info['IP'] ?? '';

        // Solo procesar IPv4 (evitar duplicados por IPv6)
        if ($host_port && ($host_ip === '0.0.0.0' || $host_ip === '')) {
            $port_key = "{$host_port}/{$protocol}";

            // Detectar conflictos REALES (diferentes contenedores en el mismo puerto)
            if (isset($ports_data[$port_key]) && $ports_data[$port_key]['container'] !== $container_name) {
                $port_conflicts[] = [
                    'port' => $host_port,
                    'protocol' => $protocol,
                    'containers' => [$ports_data[$port_key]['container'], $container_name]
                ];
            }

            // Solo guardar si no existe (evitar sobrescribir)
            if (!isset($ports_data[$port_key])) {
                $ports_data[$port_key] = [
                    'port' => $host_port,
                    'container_port' => $container_port,
                    'protocol' => $protocol,
                    'container' => $container_name,
                    'type' => 'docker'
                ];
            }
        }
    }
}

// Agrupar puertos por rango
$port_ranges = [
    '2000-2999' => ['name' => 'SSH/Git', 'ports' => [], 'used' => 0],
    '3000-3999' => ['name' => 'Apps Web', 'ports' => [], 'used' => 0],
    '4000-4999' => ['name' => 'APIs', 'ports' => [], 'used' => 0],
    '5000-5999' => ['name' => 'Databases/Services', 'ports' => [], 'used' => 0],
    '6000-6999' => ['name' => 'Redis/Cache', 'ports' => [], 'used' => 0],
    '8000-8999' => ['name' => 'HTTP Services', 'ports' => [], 'used' => 0],
    '9000-9999' => ['name' => 'MinIO/Storage', 'ports' => [], 'used' => 0],
    '10000+' => ['name' => 'Otros', 'ports' => [], 'used' => 0]
];

foreach ($ports_data as $port_key => $port_info) {
    $port = $port_info['port'];
    $range_key = '10000+';

    if ($port >= 2000 && $port <= 2999) $range_key = '2000-2999';
    elseif ($port >= 3000 && $port <= 3999) $range_key = '3000-3999';
    elseif ($port >= 4000 && $port <= 4999) $range_key = '4000-4999';
    elseif ($port >= 5000 && $port <= 5999) $range_key = '5000-5999';
    elseif ($port >= 6000 && $port <= 6999) $range_key = '6000-6999';
    elseif ($port >= 8000 && $port <= 8999) $range_key = '8000-8999';
    elseif ($port >= 9000 && $port <= 9999) $range_key = '9000-9999';

    $port_ranges[$range_key]['ports'][] = $port_info;
    $port_ranges[$range_key]['used']++;
}

// Calcular porcentaje de uso por rango (asumiendo 100 puertos disponibles por rango)
foreach ($port_ranges as $key => &$range) {
    $range['percent'] = min(100, round($range['used'] / 100 * 100));
    // Ordenar puertos por número
    usort($range['ports'], function($a, $b) {
        return $a['port'] - $b['port'];
    });
}
unset($range);

// Función para sugerir siguiente puerto disponible en un rango
$suggest_port = function($range_start, $range_end) use ($ports_data) {
    for ($p = $range_start; $p <= $range_end; $p++) {
        if (!isset($ports_data["{$p}/tcp"])) {
            return $p;
        }
    }
    return null;
};

$suggested_ports = [
    'web' => $suggest_port(3000, 3999),
    'api' => $suggest_port(4000, 4999),
    'database' => $suggest_port(5432, 5499),
    'redis' => $suggest_port(6379, 6399),
    'http' => $suggest_port(8000, 8999),
    'minio' => $suggest_port(9000, 9099)
];

// ==================== END PORT SCANNER ====================

// Container Stats (CPU y RAM por contenedor) - Solo contenedores importantes
$container_stats = [];
// Incluir size=true para obtener tamaño del contenedor
$stats_raw = shell_exec("curl -s --max-time 5 --unix-socket /var/run/docker.sock 'http://localhost/containers/json?size=true' 2>/dev/null") ?? "[]";
$running_containers = json_decode($stats_raw, true) ?? [];

// Solo obtener stats de contenedores que se muestran en el dashboard
$important_containers = ['wolf-gaming', 'nextcloud', 'immich', 'jellyfin', 'audiobookshelf',
    'plex', 'vaultwarden', 'homeassistant', 'adguard', 'nginx-proxy-manager', 'portainer'];

foreach ($running_containers as $container) {
    $name = ltrim($container['Names'][0] ?? 'unknown', '/');
    $id = substr($container['Id'], 0, 12);
    $status = $container['State'] ?? 'unknown';
    $size_root = $container['SizeRootFs'] ?? 0;  // Tamaño total del contenedor
    $size_rw = $container['SizeRw'] ?? 0;        // Tamaño de la capa escribible

    // Solo obtener stats detalladas para contenedores importantes o Wolf
    $is_important = in_array($name, $important_containers) || stripos($name, 'wolf') !== false;

    $cpu_percent = 0;
    $mem_percent = 0;
    $mem_usage = 0;
    $mem_limit = 0;

    if ($is_important) {
        // Get individual container stats con timeout de 2 segundos
        $stat_raw = shell_exec("curl -s --max-time 2 --unix-socket /var/run/docker.sock 'http://localhost/containers/$id/stats?stream=false' 2>/dev/null");
        $stat = json_decode($stat_raw, true);

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
    }

    $container_stats[$name] = [
        'id' => $id,
        'status' => $status,
        'cpu_percent' => $cpu_percent,
        'mem_percent' => $mem_percent,
        'mem_usage_mb' => round($mem_usage / 1024 / 1024, 1),
        'mem_limit_mb' => round($mem_limit / 1024 / 1024, 1),
        'size_mb' => round($size_root / 1024 / 1024, 1),
        'size_rw_mb' => round($size_rw / 1024 / 1024, 1)
    ];
}

echo json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'cpu' => [
        'usage' => $cpu,
        'temp' => $cpu_temp,
        'cores' => $cpu_cores,
        'load' => $load_avg,
        'model' => $cpu_model,
        'per_core' => $cpu_per_core,
        'temps' => $cpu_temps
    ],
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
        'breakdown' => $power_breakdown,
        'today' => ['kwh' => $kwh_today, 'cost_clp' => $cost_today],
        'week' => ['kwh' => $kwh_week, 'cost_clp' => $cost_week],
        'month' => ['kwh' => $kwh_month, 'cost_clp' => $cost_month],
        'year' => ['kwh' => $kwh_year, 'cost_clp' => $cost_year],
        'total' => ['kwh' => $kwh_total, 'cost_clp' => $cost_total],
        'today_by_component' => $today_components,
        'avg_watts_24h' => $avg_watts_24h,
        'estimated_monthly' => ['kwh' => $estimated_monthly_kwh, 'cost_clp' => $estimated_monthly_cost],
        'daily_history' => $daily_history,
        'today_hourly' => $today_hourly,
        'hourly_pattern' => $hourly_averages,
        'cost_per_kwh' => $cost_per_kwh
    ],
    'gpu' => $gpu_info,
    'motherboard' => $motherboard_info,
    'container_stats' => $container_stats,
    'ports' => [
        'all' => array_values($ports_data),
        'by_range' => $port_ranges,
        'conflicts' => $port_conflicts,
        'total_used' => count($ports_data),
        'suggested' => $suggested_ports
    ]
]);
