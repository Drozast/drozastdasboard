<?php
/**
 * Docker Service
 * Handles all Docker socket communication
 */

class DockerService {
    private static string $socketPath = '/var/run/docker.sock';

    /**
     * List containers
     */
    public static function listContainers(bool $all = false): array {
        $url = $all ? 'containers/json?all=true' : 'containers/json';
        $response = self::request('GET', $url);
        return $response ?? [];
    }

    /**
     * Inspect container
     */
    public static function inspectContainer(string $nameOrId): ?array {
        $response = self::request('GET', "containers/$nameOrId/json");
        return $response;
    }

    /**
     * Get container stats
     */
    public static function getContainerStats(string $id): ?array {
        $response = self::request('GET', "containers/$id/stats?stream=false&one-shot=true", 3);

        if (!$response) {
            return null;
        }

        // Calculate CPU percentage
        $cpuDelta = ($response['cpu_stats']['cpu_usage']['total_usage'] ?? 0) -
                    ($response['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
        $systemDelta = ($response['cpu_stats']['system_cpu_usage'] ?? 0) -
                       ($response['precpu_stats']['system_cpu_usage'] ?? 0);
        $numCpus = $response['cpu_stats']['online_cpus'] ?? 1;
        $cpuPercent = ($systemDelta > 0) ? round(($cpuDelta / $systemDelta) * $numCpus * 100, 2) : 0;

        // Memory
        $memUsage = $response['memory_stats']['usage'] ?? 0;
        $memLimit = $response['memory_stats']['limit'] ?? 1;
        $memPercent = round(($memUsage / $memLimit) * 100, 2);
        $memUsageMb = round($memUsage / 1024 / 1024, 2);
        $memLimitMb = round($memLimit / 1024 / 1024, 2);

        // Network
        $networks = $response['networks'] ?? [];
        $rxBytes = 0;
        $txBytes = 0;
        foreach ($networks as $net) {
            $rxBytes += $net['rx_bytes'] ?? 0;
            $txBytes += $net['tx_bytes'] ?? 0;
        }

        return [
            'cpu_percent' => $cpuPercent,
            'mem_percent' => $memPercent,
            'mem_usage_mb' => $memUsageMb,
            'mem_limit_mb' => $memLimitMb,
            'network_rx_mb' => round($rxBytes / 1024 / 1024, 2),
            'network_tx_mb' => round($txBytes / 1024 / 1024, 2),
        ];
    }

    /**
     * Get container logs
     */
    public static function getContainerLogs(string $nameOrId, int $lines = 100, string $since = '1h'): array {
        // Convert since to timestamp
        $sinceTime = 0;
        if (preg_match('/^(\d+)([hmd])$/', $since, $matches)) {
            $value = (int)$matches[1];
            $unit = $matches[2];
            $multiplier = ['h' => 3600, 'm' => 60, 'd' => 86400][$unit];
            $sinceTime = time() - ($value * $multiplier);
        }

        $url = "containers/$nameOrId/logs?stdout=true&stderr=true&tail=$lines";
        if ($sinceTime > 0) {
            $url .= "&since=$sinceTime";
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_UNIX_SOCKET_PATH => self::$socketPath,
            CURLOPT_URL => "http://localhost/$url",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return [];
        }

        // Docker logs have 8-byte header per line
        $lines = [];
        $pos = 0;
        while ($pos < strlen($response)) {
            if ($pos + 8 > strlen($response)) break;

            // Read header
            $header = substr($response, $pos, 8);
            $streamType = ord($header[0]); // 1 = stdout, 2 = stderr
            $size = unpack('N', substr($header, 4, 4))[1];

            $pos += 8;
            if ($pos + $size > strlen($response)) break;

            $line = substr($response, $pos, $size);
            $lines[] = [
                'stream' => $streamType === 1 ? 'stdout' : 'stderr',
                'text' => trim($line),
            ];

            $pos += $size;
        }

        return $lines;
    }

    /**
     * Start container
     */
    public static function startContainer(string $nameOrId): bool {
        $response = self::request('POST', "containers/$nameOrId/start", 10, true);
        return $response !== false;
    }

    /**
     * Stop container
     */
    public static function stopContainer(string $nameOrId): bool {
        $response = self::request('POST', "containers/$nameOrId/stop?t=10", 15, true);
        return $response !== false;
    }

    /**
     * Restart container
     */
    public static function restartContainer(string $nameOrId): bool {
        $response = self::request('POST', "containers/$nameOrId/restart?t=10", 20, true);
        return $response !== false;
    }

    /**
     * Kill container
     */
    public static function killContainer(string $nameOrId): bool {
        $response = self::request('POST', "containers/$nameOrId/kill", 5, true);
        return $response !== false;
    }

    /**
     * Remove container
     */
    public static function removeContainer(string $nameOrId, bool $force = false): bool {
        $url = "containers/$nameOrId" . ($force ? '?force=true' : '');
        $response = self::request('DELETE', $url, 10, true);
        return $response !== false;
    }

    /**
     * List images
     */
    public static function listImages(): array {
        $response = self::request('GET', 'images/json');
        return $response ?? [];
    }

    /**
     * Get Docker system info
     */
    public static function systemInfo(): ?array {
        return self::request('GET', 'info');
    }

    /**
     * Make request to Docker socket
     */
    private static function request(string $method, string $endpoint, int $timeout = 5, bool $rawResponse = false) {
        $ch = curl_init();

        $options = [
            CURLOPT_UNIX_SOCKET_PATH => self::$socketPath,
            CURLOPT_URL => "http://localhost/$endpoint",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = '';
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // For actions that return no content (204)
        if ($rawResponse) {
            return $httpCode >= 200 && $httpCode < 300;
        }

        if ($response === false || $httpCode >= 400) {
            return null;
        }

        return json_decode($response, true);
    }
}
