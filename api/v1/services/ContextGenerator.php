<?php
/**
 * Context Generator Service
 * Generates SERVER_CONTEXT.md dynamically from database
 */

require_once __DIR__ . '/../models/Database.php';

class ContextGenerator {

    /**
     * Generate the full context document
     * Returns both plain text and HTML formatted versions
     */
    public static function generate(): array {
        $containers = self::getContainersGrouped();
        $categories = self::getCategories();

        // Build plain text version
        $plain = self::buildPlainText($containers, $categories);

        // Build HTML formatted version (for dashboard display)
        $html = self::buildHtmlFormatted($containers, $categories);

        return [
            'plain' => $plain,
            'html' => $html,
            'generated_at' => date('Y-m-d H:i:s'),
            'container_count' => array_sum(array_map('count', $containers)),
            'category_count' => count($categories)
        ];
    }

    /**
     * Get containers grouped by category
     */
    private static function getContainersGrouped(): array {
        $containers = Database::fetchAll("
            SELECT c.*, cat.name as category_name, cat.icon as category_icon
            FROM containers c
            LEFT JOIN categories cat ON c.category_id = cat.id
            WHERE c.is_visible = 1
            ORDER BY cat.sort_order, c.sort_order, c.display_name
        ");

        $grouped = [];
        foreach ($containers as $container) {
            $catName = $container['category_name'] ?? 'Sin Categoria';
            if (!isset($grouped[$catName])) {
                $grouped[$catName] = [];
            }
            $grouped[$catName][] = $container;
        }
        return $grouped;
    }

    /**
     * Get all categories
     */
    private static function getCategories(): array {
        return Database::fetchAll("SELECT * FROM categories ORDER BY sort_order");
    }

    /**
     * Build plain text context (for copying/markdown)
     */
    private static function buildPlainText(array $containers, array $categories): string {
        $ctx = "";

        // Header
        $ctx .= "# ═══════════════════════════════════════════════════════════════════════════════\n";
        $ctx .= "# DROZAST SERVER - CONTEXTO PARA DESARROLLO EN EQUIPO\n";
        $ctx .= "# ═══════════════════════════════════════════════════════════════════════════════\n\n";

        // SSH Access
        $ctx .= "# ACCESO SSH (VIA CLOUDFLARE - ACCESO REMOTO DESDE CUALQUIER UBICACION)\n";
        $ctx .= "SSH_HOST=\"ssh.drozast.xyz\"\n";
        $ctx .= "SSH_USER=\"drozast\"\n";
        $ctx .= "SSH_PASS=\"123\"\n\n";

        $ctx .= "# REQUISITO: Instalar cloudflared\n";
        $ctx .= "# macOS:   brew install cloudflared\n";
        $ctx .= "# Linux:   https://github.com/cloudflare/cloudflared/releases\n";
        $ctx .= "# Windows: https://github.com/cloudflare/cloudflared/releases\n\n";

        $ctx .= "# COMANDO DE CONEXION SSH:\n";
        $ctx .= "ssh -o ProxyCommand=\"cloudflared access ssh --hostname ssh.drozast.xyz\" drozast@ssh.drozast.xyz\n\n";

        $ctx .= "# CON SSHPASS (automatizado):\n";
        $ctx .= "sshpass -p '123' ssh -o ProxyCommand=\"cloudflared access ssh --hostname ssh.drozast.xyz\" -o StrictHostKeyChecking=no drozast@ssh.drozast.xyz\n\n";

        // Cloudflare Tunnel
        $ctx .= "# ─────────────────────────────────────────────────────────────────────────────────\n";
        $ctx .= "# CLOUDFLARE TUNNEL\n";
        $ctx .= "# ─────────────────────────────────────────────────────────────────────────────────\n";
        $ctx .= "TUNNEL_ID=\"804e80c5-881c-4ad0-963a-42549223c4f6\"\n";
        $ctx .= "TUNNEL_CONFIG=\"/home/drozast/.cloudflared/config.yml\"\n\n";

        // Storage
        $ctx .= "# ─────────────────────────────────────────────────────────────────────────────────\n";
        $ctx .= "# ALMACENAMIENTO\n";
        $ctx .= "# ─────────────────────────────────────────────────────────────────────────────────\n";
        $ctx .= "# SSD OS (480GB)\n";
        $ctx .= "DISK_OS=\"/dev/sda\"\n";
        $ctx .= "DISK_OS_SIZE=\"447GB (437GB asignados via LVM - expandido)\"\n";
        $ctx .= "DISK_OS_MOUNT=\"/\"\n";
        $ctx .= "DISK_OS_LVM=\"ubuntu--vg-ubuntu--lv\"\n\n";

        $ctx .= "# SSD Cache (120GB)\n";
        $ctx .= "DISK_CACHE=\"/dev/sdb\"\n";
        $ctx .= "DISK_CACHE_SIZE=\"112GB\"\n";
        $ctx .= "DISK_CACHE_TYPE=\"bcache (cache para RAID)\"\n\n";

        $ctx .= "# RAID 1 (4TB x2 = 3.6TB util)\n";
        $ctx .= "DISK_RAID_1=\"/dev/sdc\"\n";
        $ctx .= "DISK_RAID_2=\"/dev/sdd\"\n";
        $ctx .= "DISK_RAID_DEVICE=\"/dev/md0\"\n";
        $ctx .= "DISK_RAID_BCACHE=\"/dev/bcache0\"\n";
        $ctx .= "DISK_RAID_MOUNT=\"/mnt/nextcloud-data\"\n";
        $ctx .= "DISK_RAID_SIZE=\"3.6TB\"\n\n";

        // MinIO
        $ctx .= "# ─────────────────────────────────────────────────────────────────────────────────\n";
        $ctx .= "# MINIO (S3 Storage)\n";
        $ctx .= "# ─────────────────────────────────────────────────────────────────────────────────\n";
        $ctx .= "# MinIO Principal\n";
        $ctx .= "MINIO_USER=\"admin\"\n";
        $ctx .= "MINIO_PASS=\"Y5/iqYWfm7MjHx8V1pHNRNgnJe7F7Mz1SJ3n9LK5+Lk=\"\n";
        $ctx .= "MINIO_PUBLIC=\"https://minio.drozast.xyz\"\n";
        $ctx .= "MINIO_RECLAMAYA=\"https://storage.reclamaya.cl\"\n\n";

        $ctx .= "# MinIO Frogio\n";
        $ctx .= "FROGIO_MINIO_USER=\"frogio_admin\"\n";
        $ctx .= "FROGIO_MINIO_PASS=\"frogio_secret_key_2024\"\n";
        $ctx .= "FROGIO_MINIO_PUBLIC=\"https://minio-frogio.drozast.xyz\"\n\n";

        // Databases
        $ctx .= "# ─────────────────────────────────────────────────────────────────────────────────\n";
        $ctx .= "# BASES DE DATOS\n";
        $ctx .= "# ─────────────────────────────────────────────────────────────────────────────────\n";
        $ctx .= "# PostgreSQL disponibles (verificar puertos con docker ps)\n";
        $ctx .= "POSTGRES_BARBERBOOK=\"barberbook-postgres\"\n";
        $ctx .= "POSTGRES_RECLAMAYA=\"reclamaya-postgres\"\n";
        $ctx .= "POSTGRES_WMS=\"wms-postgres\"\n";
        $ctx .= "POSTGRES_NEXTCLOUD=\"nextcloud-db\"\n\n";
        $ctx .= "# Redis\n";
        $ctx .= "REDIS_BARBERBOOK=\"barberbook-redis\"\n\n";

        // Dynamic Services by Category
        $ctx .= "# ─────────────────────────────────────────────────────────────────────────────────\n";
        $ctx .= "# SERVICIOS PUBLICOS (VIA CLOUDFLARE)\n";
        $ctx .= "# ─────────────────────────────────────────────────────────────────────────────────\n";

        foreach ($containers as $categoryName => $categoryContainers) {
            $ctx .= "\n# {$categoryName}\n";
            foreach ($categoryContainers as $c) {
                if (!empty($c['external_url'])) {
                    $subdomain = $c['subdomain'] ?? parse_url($c['external_url'], PHP_URL_HOST);
                    $desc = $c['display_name'];
                    if (!empty($c['description'])) {
                        $desc .= " - " . $c['description'];
                    }
                    $ctx .= str_pad($subdomain, 30) . " -> " . $desc . "\n";
                }
            }
        }

        // Maps section (static for now)
        $ctx .= "\n# ─────────────────────────────────────────────────────────────────────────────────\n";
        $ctx .= "# MAPAS SELF-HOSTED (Alternativa a Google Maps - Solo Chile)\n";
        $ctx .= "# ─────────────────────────────────────────────────────────────────────────────────\n";
        $ctx .= "# Stack: TileServer GL + Nominatim + OSRM\n";
        $ctx .= "# Datos: OpenStreetMap Chile (~300MB PBF, ~500MB mbtiles)\n";
        $ctx .= "MAPS_URL=\"https://maps.drozast.xyz\"\n";
        $ctx .= "MAPS_STYLE=\"https://maps.drozast.xyz/styles/osm-bright/style.json\"\n";
        $ctx .= "GEO_URL=\"https://geo.drozast.xyz\"\n";
        $ctx .= "ROUTING_URL=\"https://routing.drozast.xyz\"\n\n";

        // API Info
        $ctx .= "# ─────────────────────────────────────────────────────────────────────────────────\n";
        $ctx .= "# DASHBOARD API\n";
        $ctx .= "# ─────────────────────────────────────────────────────────────────────────────────\n";
        $ctx .= "# Base URL: https://stats.drozast.xyz/api/v1\n";
        $ctx .= "# Auth: Header X-API-Key o Session Cookie\n";
        $ctx .= "# Endpoints:\n";
        $ctx .= "#   GET  /stats           - System stats\n";
        $ctx .= "#   GET  /containers      - List all containers\n";
        $ctx .= "#   POST /containers/:name/start|stop|restart\n";
        $ctx .= "#   GET  /containers/:name/logs?lines=100\n";
        $ctx .= "#   GET  /context         - This context document\n\n";

        // Footer
        $ctx .= "# ═══════════════════════════════════════════════════════════════════════════════\n";
        $ctx .= "# Generado automaticamente: " . date('Y-m-d H:i:s') . "\n";
        $ctx .= "# Total: " . array_sum(array_map('count', $containers)) . " contenedores en " . count($categories) . " categorias\n";
        $ctx .= "# ═══════════════════════════════════════════════════════════════════════════════\n";

        return $ctx;
    }

    /**
     * Build HTML formatted context (for dashboard display)
     */
    private static function buildHtmlFormatted(array $containers, array $categories): string {
        $html = "";

        // Helper function to wrap text
        $label = fn($t) => "<span class=\"section-label\">{$t}</span>";
        $comment = fn($t) => "<span class=\"comment\">{$t}</span>";
        $key = fn($t) => "<span class=\"key\">{$t}</span>";
        $value = fn($t) => "<span class=\"value\">{$t}</span>";

        // Header
        $html .= $label("# ═══════════════════════════════════════════════════════════════════════════════") . "\n";
        $html .= $label("# DROZAST SERVER - CONTEXTO PARA DESARROLLO EN EQUIPO") . "\n";
        $html .= $label("# ═══════════════════════════════════════════════════════════════════════════════") . "\n\n";

        // SSH Access
        $html .= $comment("# ACCESO SSH (VIA CLOUDFLARE - ACCESO REMOTO DESDE CUALQUIER UBICACION)") . "\n";
        $html .= $key("SSH_HOST") . "=" . $value("\"ssh.drozast.xyz\"") . "\n";
        $html .= $key("SSH_USER") . "=" . $value("\"drozast\"") . "\n";
        $html .= $key("SSH_PASS") . "=" . $value("\"123\"") . "\n\n";

        $html .= $comment("# REQUISITO: Instalar cloudflared") . "\n";
        $html .= $comment("# macOS:   brew install cloudflared") . "\n";
        $html .= $comment("# Linux:   https://github.com/cloudflare/cloudflared/releases") . "\n";
        $html .= $comment("# Windows: https://github.com/cloudflare/cloudflared/releases") . "\n\n";

        $html .= $comment("# COMANDO DE CONEXION SSH:") . "\n";
        $html .= $key("ssh -o ProxyCommand=\"cloudflared access ssh --hostname ssh.drozast.xyz\" drozast@ssh.drozast.xyz") . "\n\n";

        $html .= $comment("# CON SSHPASS (automatizado):") . "\n";
        $html .= $key("sshpass -p '123' ssh -o ProxyCommand=\"cloudflared access ssh --hostname ssh.drozast.xyz\" -o StrictHostKeyChecking=no drozast@ssh.drozast.xyz") . "\n\n";

        // Cloudflare Tunnel
        $html .= $label("# ─────────────────────────────────────────────────────────────────────────────────") . "\n";
        $html .= $label("# CLOUDFLARE TUNNEL") . "\n";
        $html .= $label("# ─────────────────────────────────────────────────────────────────────────────────") . "\n";
        $html .= $key("TUNNEL_ID") . "=" . $value("\"804e80c5-881c-4ad0-963a-42549223c4f6\"") . "\n";
        $html .= $key("TUNNEL_CONFIG") . "=" . $value("\"/home/drozast/.cloudflared/config.yml\"") . "\n\n";

        // Storage
        $html .= $label("# ─────────────────────────────────────────────────────────────────────────────────") . "\n";
        $html .= $label("# ALMACENAMIENTO") . "\n";
        $html .= $label("# ─────────────────────────────────────────────────────────────────────────────────") . "\n";
        $html .= $comment("# SSD OS (480GB)") . "\n";
        $html .= $key("DISK_OS") . "=" . $value("\"/dev/sda\"") . "\n";
        $html .= $key("DISK_OS_SIZE") . "=" . $value("\"447GB (437GB asignados via LVM - expandido)\"") . "\n";
        $html .= $key("DISK_OS_MOUNT") . "=" . $value("\"/\"") . "\n";
        $html .= $key("DISK_OS_LVM") . "=" . $value("\"ubuntu--vg-ubuntu--lv\"") . "\n\n";

        $html .= $comment("# SSD Cache (120GB)") . "\n";
        $html .= $key("DISK_CACHE") . "=" . $value("\"/dev/sdb\"") . "\n";
        $html .= $key("DISK_CACHE_SIZE") . "=" . $value("\"112GB\"") . "\n";
        $html .= $key("DISK_CACHE_TYPE") . "=" . $value("\"bcache (cache para RAID)\"") . "\n\n";

        $html .= $comment("# RAID 1 (4TB x2 = 3.6TB util)") . "\n";
        $html .= $key("DISK_RAID_1") . "=" . $value("\"/dev/sdc\"") . "\n";
        $html .= $key("DISK_RAID_2") . "=" . $value("\"/dev/sdd\"") . "\n";
        $html .= $key("DISK_RAID_DEVICE") . "=" . $value("\"/dev/md0\"") . "\n";
        $html .= $key("DISK_RAID_BCACHE") . "=" . $value("\"/dev/bcache0\"") . "\n";
        $html .= $key("DISK_RAID_MOUNT") . "=" . $value("\"/mnt/nextcloud-data\"") . "\n";
        $html .= $key("DISK_RAID_SIZE") . "=" . $value("\"3.6TB\"") . "\n\n";

        // MinIO
        $html .= $label("# ─────────────────────────────────────────────────────────────────────────────────") . "\n";
        $html .= $label("# MINIO (S3 Storage)") . "\n";
        $html .= $label("# ─────────────────────────────────────────────────────────────────────────────────") . "\n";
        $html .= $comment("# MinIO Principal") . "\n";
        $html .= $key("MINIO_USER") . "=" . $value("\"admin\"") . "\n";
        $html .= $key("MINIO_PASS") . "=" . $value("\"Y5/iqYWfm7MjHx8V1pHNRNgnJe7F7Mz1SJ3n9LK5+Lk=\"") . "\n";
        $html .= $key("MINIO_PUBLIC") . "=" . $value("\"https://minio.drozast.xyz\"") . "\n";
        $html .= $key("MINIO_RECLAMAYA") . "=" . $value("\"https://storage.reclamaya.cl\"") . "\n\n";

        $html .= $comment("# MinIO Frogio") . "\n";
        $html .= $key("FROGIO_MINIO_USER") . "=" . $value("\"frogio_admin\"") . "\n";
        $html .= $key("FROGIO_MINIO_PASS") . "=" . $value("\"frogio_secret_key_2024\"") . "\n";
        $html .= $key("FROGIO_MINIO_PUBLIC") . "=" . $value("\"https://minio-frogio.drozast.xyz\"") . "\n\n";

        // Databases
        $html .= $label("# ─────────────────────────────────────────────────────────────────────────────────") . "\n";
        $html .= $label("# BASES DE DATOS") . "\n";
        $html .= $label("# ─────────────────────────────────────────────────────────────────────────────────") . "\n";
        $html .= $comment("# PostgreSQL disponibles (verificar puertos con docker ps)") . "\n";
        $html .= $key("POSTGRES_BARBERBOOK") . "=" . $value("\"barberbook-postgres\"") . "\n";
        $html .= $key("POSTGRES_RECLAMAYA") . "=" . $value("\"reclamaya-postgres\"") . "\n";
        $html .= $key("POSTGRES_WMS") . "=" . $value("\"wms-postgres\"") . "\n";
        $html .= $key("POSTGRES_NEXTCLOUD") . "=" . $value("\"nextcloud-db\"") . "\n\n";
        $html .= $comment("# Redis") . "\n";
        $html .= $key("REDIS_BARBERBOOK") . "=" . $value("\"barberbook-redis\"") . "\n\n";

        // Dynamic Services by Category
        $html .= $label("# ─────────────────────────────────────────────────────────────────────────────────") . "\n";
        $html .= $label("# SERVICIOS PUBLICOS (VIA CLOUDFLARE)") . "\n";
        $html .= $label("# ─────────────────────────────────────────────────────────────────────────────────") . "\n";

        foreach ($containers as $categoryName => $categoryContainers) {
            $html .= "\n" . $comment("# {$categoryName}") . "\n";
            foreach ($categoryContainers as $c) {
                if (!empty($c['external_url'])) {
                    $subdomain = $c['subdomain'] ?? parse_url($c['external_url'], PHP_URL_HOST);
                    $desc = $c['display_name'];
                    if (!empty($c['description'])) {
                        $desc .= " - " . $c['description'];
                    }
                    $html .= $key(str_pad($subdomain, 30)) . " -> " . $value($desc) . "\n";
                }
            }
        }

        // Maps section
        $html .= "\n" . $label("# ─────────────────────────────────────────────────────────────────────────────────") . "\n";
        $html .= $label("# MAPAS SELF-HOSTED (Alternativa a Google Maps - Solo Chile)") . "\n";
        $html .= $label("# ─────────────────────────────────────────────────────────────────────────────────") . "\n";
        $html .= $comment("# Stack: TileServer GL + Nominatim + OSRM") . "\n";
        $html .= $comment("# Datos: OpenStreetMap Chile (~300MB PBF, ~500MB mbtiles)") . "\n";
        $html .= $key("MAPS_URL") . "=" . $value("\"https://maps.drozast.xyz\"") . "\n";
        $html .= $key("MAPS_STYLE") . "=" . $value("\"https://maps.drozast.xyz/styles/osm-bright/style.json\"") . "\n";
        $html .= $key("GEO_URL") . "=" . $value("\"https://geo.drozast.xyz\"") . "\n";
        $html .= $key("ROUTING_URL") . "=" . $value("\"https://routing.drozast.xyz\"") . "\n\n";

        // API Info
        $html .= $label("# ─────────────────────────────────────────────────────────────────────────────────") . "\n";
        $html .= $label("# DASHBOARD API") . "\n";
        $html .= $label("# ─────────────────────────────────────────────────────────────────────────────────") . "\n";
        $html .= $comment("# Base URL: https://stats.drozast.xyz/api/v1") . "\n";
        $html .= $comment("# Auth: Header X-API-Key o Session Cookie") . "\n";
        $html .= $comment("# Endpoints:") . "\n";
        $html .= $comment("#   GET  /stats           - System stats") . "\n";
        $html .= $comment("#   GET  /containers      - List all containers") . "\n";
        $html .= $comment("#   POST /containers/:name/start|stop|restart") . "\n";
        $html .= $comment("#   GET  /containers/:name/logs?lines=100") . "\n";
        $html .= $comment("#   GET  /context         - This context document") . "\n\n";

        // Footer
        $html .= $label("# ═══════════════════════════════════════════════════════════════════════════════") . "\n";
        $html .= $comment("# Generado automaticamente: " . date('Y-m-d H:i:s')) . "\n";
        $html .= $comment("# Total: " . array_sum(array_map('count', $containers)) . " contenedores en " . count($categories) . " categorias") . "\n";
        $html .= $label("# ═══════════════════════════════════════════════════════════════════════════════") . "\n";

        return $html;
    }

    /**
     * Save context to file
     */
    public static function saveToFile(string $path = null): bool {
        if ($path === null) {
            $path = __DIR__ . '/../../../data/SERVER_CONTEXT.md';
        }

        $context = self::generate();
        return file_put_contents($path, $context['plain']) !== false;
    }
}
