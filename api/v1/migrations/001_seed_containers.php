<?php
/**
 * Migration: Seed initial containers from existing dashboard
 * Run this once to populate the database with existing services
 */

require_once __DIR__ . '/../models/Database.php';

echo "Starting container migration...\n";

// Initialize database (creates schema if needed)
$db = Database::getInstance();

// Define existing containers from the dashboard
$containers = [
    // FROGIO - Gestion Municipal
    ['frogio-backend', 'Frogio API', 'Backend API for Frogio municipal management', '🔧', 1, 'api-frogio.drozast.xyz', 3110, 'https://api-frogio.drozast.xyz'],
    ['frogio-web-admin', 'Frogio Admin', 'Admin panel for Frogio', '⚙️', 1, 'admin-frogio.drozast.xyz', 3025, 'https://admin-frogio.drozast.xyz'],
    ['frogio-minio', 'Frogio MinIO', 'Object storage for Frogio', '📦', 1, null, 9000, null],
    ['frogio-postgres', 'Frogio PostgreSQL', 'Database for Frogio', '🐘', 1, null, 5432, null],
    ['frogio-redis', 'Frogio Redis', 'Cache for Frogio', '🔴', 1, null, 6379, null],

    // Proyectos Clientes
    ['casainfante-frontend', 'Casa Infante', 'Frontend for Casa Infante project', '🏠', 2, 'casainfante.drozast.xyz', 3020, 'https://casainfante.drozast.xyz'],
    ['natik-travel', 'Natik Travel', 'Travel agency website', '✈️', 2, 'natiktravel.drozast.xyz', 3100, 'https://natiktravel.drozast.xyz'],
    ['miseenplace-restaurant', 'Mise en Place', 'Restaurant management system', '🍽️', 2, 'miseenplace.drozast.xyz', 3030, 'https://miseenplace.drozast.xyz'],
    ['barberbook-frontend', 'BarberBook', 'Barbershop booking system', '💈', 2, 'barberbook.drozast.xyz', 3040, 'https://barberbook.drozast.xyz'],
    ['reclamaya-web', 'Reclama Ya', 'Legal claims platform', '⚖️', 2, 'reclamaya.drozast.xyz', 3050, 'https://reclamaya.drozast.xyz'],

    // Cloud & Storage
    ['nextcloud', 'Nextcloud', 'Personal cloud storage', '☁️', 3, 'cloud.drozast.xyz', 10081, 'https://cloud.drozast.xyz'],
    ['nextcloud-db', 'Nextcloud DB', 'PostgreSQL for Nextcloud', '🐘', 3, null, 5433, null],
    ['minio', 'MinIO', 'S3-compatible object storage', '📦', 3, 'minio.drozast.xyz', 9001, 'https://minio.drozast.xyz'],
    ['vaultwarden', 'Vaultwarden', 'Password manager', '🔐', 3, 'vault.drozast.xyz', 8080, 'https://vault.drozast.xyz'],

    // Media & Entertainment
    ['navidrome', 'Navidrome', 'Music streaming server', '🎵', 4, 'music.drozast.xyz', 4533, 'https://music.drozast.xyz'],
    ['kavita', 'Kavita', 'Book/manga reader', '📚', 4, 'books.drozast.xyz', 5000, 'https://books.drozast.xyz'],
    ['metube', 'MeTube', 'YouTube downloader', '📺', 4, 'videos.drozast.xyz', 8081, 'https://videos.drozast.xyz'],

    // DevOps & Tools
    ['gitea', 'Gitea', 'Git repository hosting', '🦊', 5, 'git.drozast.xyz', 3000, 'https://git.drozast.xyz'],
    ['portainer', 'Portainer', 'Docker management UI', '🐳', 5, 'portainer.drozast.xyz', 9443, 'https://portainer.drozast.xyz'],
    ['n8n', 'n8n', 'Workflow automation', '🔄', 5, 'n8n.drozast.xyz', 5678, 'https://n8n.drozast.xyz'],
    ['mailpit', 'Mailpit', 'Email testing tool', '📧', 5, 'mail.drozast.xyz', 8025, 'https://mail.drozast.xyz'],
    ['server-stats', 'Server Stats', 'Dashboard stats API', '📊', 5, 'stats.drozast.xyz', 8095, 'https://stats.drozast.xyz'],
    ['stirling-pdf', 'Stirling PDF', 'PDF tools', '📄', 5, 'pdf.drozast.xyz', 8082, 'https://pdf.drozast.xyz'],

    // Network & DNS
    ['pihole', 'Pi-hole', 'DNS ad blocker', '🛡️', 6, 'pihole.drozast.xyz', 8083, 'https://pihole.drozast.xyz'],
    ['cloudflared', 'Cloudflared', 'Cloudflare tunnel', '🌐', 6, null, null, null],

    // Gaming
    ['wolf-gaming', 'Wolf Gaming', 'Cloud gaming server', '🎮', 7, 'gaming.drozast.xyz', 47989, 'https://gaming.drozast.xyz'],

    // Maps
    ['maps-tileserver', 'TileServer GL', 'Map tile server', '🗺️', 8, 'maps.drozast.xyz', 8082, 'https://maps.drozast.xyz'],
    ['maps-nominatim', 'Nominatim', 'Geocoding service', '📍', 8, 'geo.drozast.xyz', 8081, 'https://geo.drozast.xyz'],
    ['maps-osrm', 'OSRM', 'Routing engine', '🛣️', 8, 'routing.drozast.xyz', 5001, 'https://routing.drozast.xyz'],

    // Notifications & Links
    ['ntfy', 'NTFY', 'Push notification server', '🔔', 6, 'ntfy.drozast.xyz', 2586, 'https://ntfy.drozast.xyz'],
    ['shlink', 'Shlink', 'URL shortener', '🔗', 5, 'link.drozast.xyz', 8080, 'https://link.drozast.xyz'],
    ['shlink-web', 'Shlink Web', 'Shlink admin panel', '🔗', 5, 'links.drozast.xyz', 8084, 'https://links.drozast.xyz'],
];

$stmt = $db->prepare(
    "INSERT OR IGNORE INTO containers
     (container_name, display_name, description, icon, category_id, subdomain, local_port, external_url, is_visible, is_critical, sort_order)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?)"
);

$count = 0;
foreach ($containers as $index => $c) {
    $stmt->execute([
        $c[0], // container_name
        $c[1], // display_name
        $c[2], // description
        $c[3], // icon
        $c[4], // category_id
        $c[5], // subdomain
        $c[6], // local_port
        $c[7], // external_url
        $index + 1, // sort_order
    ]);
    $count++;
}

echo "Migrated $count containers.\n";

// Verify
$totalContainers = Database::fetch("SELECT COUNT(*) as count FROM containers")['count'];
$totalCategories = Database::fetch("SELECT COUNT(*) as count FROM categories")['count'];
$totalUsers = Database::fetch("SELECT COUNT(*) as count FROM users")['count'];

echo "\nDatabase status:\n";
echo "- Containers: $totalContainers\n";
echo "- Categories: $totalCategories\n";
echo "- Users: $totalUsers\n";
echo "\nMigration complete!\n";
