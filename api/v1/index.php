<?php
/**
 * API Router v1
 * Drozast Dashboard - Container Management Platform
 */

error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Autoload controllers
require_once __DIR__ . '/models/Database.php';
require_once __DIR__ . '/middleware/auth.php';
require_once __DIR__ . '/controllers/AuthController.php';

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove base path (adjust based on deployment)
$basePath = '/api/v1';
$path = preg_replace('#^' . preg_quote($basePath) . '#', '', $uri);
$path = $path ?: '/';

// Simple router
try {
    // Initialize database on first request
    Database::getInstance();

    // Route definitions
    switch (true) {
        // ============ AUTH ROUTES ============

        // POST /auth/login
        case $method === 'POST' && $path === '/auth/login':
            AuthController::login();
            break;

        // POST /auth/logout
        case $method === 'POST' && $path === '/auth/logout':
            AuthController::logout();
            break;

        // GET /auth/me
        case $method === 'GET' && $path === '/auth/me':
            AuthController::me();
            break;

        // GET /auth/check
        case $method === 'GET' && $path === '/auth/check':
            AuthController::check();
            break;

        // ============ API KEYS ROUTES ============

        // GET /api-keys
        case $method === 'GET' && $path === '/api-keys':
            AuthController::listApiKeys();
            break;

        // POST /api-keys
        case $method === 'POST' && $path === '/api-keys':
            AuthController::createApiKey();
            break;

        // DELETE /api-keys/:id
        case $method === 'DELETE' && preg_match('#^/api-keys/(\d+)$#', $path, $matches):
            AuthController::deleteApiKey((int)$matches[1]);
            break;

        // POST /api-keys/:id/revoke
        case $method === 'POST' && preg_match('#^/api-keys/(\d+)/revoke$#', $path, $matches):
            AuthController::revokeApiKey((int)$matches[1]);
            break;

        // ============ AUDIT LOG ============

        // GET /audit-log
        case $method === 'GET' && $path === '/audit-log':
            AuthController::auditLog();
            break;

        // ============ STATS ROUTES ============

        // GET /stats
        case $method === 'GET' && $path === '/stats':
            require_once __DIR__ . '/controllers/StatsController.php';
            StatsController::index();
            break;

        // GET /stats/history
        case $method === 'GET' && $path === '/stats/history':
            require_once __DIR__ . '/controllers/StatsController.php';
            StatsController::history();
            break;

        // ============ CONTAINERS ROUTES ============

        // GET /containers
        case $method === 'GET' && $path === '/containers':
            require_once __DIR__ . '/controllers/ContainerController.php';
            ContainerController::index();
            break;

        // POST /containers/discover
        case $method === 'POST' && $path === '/containers/discover':
            require_once __DIR__ . '/controllers/ContainerController.php';
            ContainerController::discover();
            break;

        // GET /containers/:name
        case $method === 'GET' && preg_match('#^/containers/([^/]+)$#', $path, $matches):
            require_once __DIR__ . '/controllers/ContainerController.php';
            ContainerController::show($matches[1]);
            break;

        // GET /containers/:name/logs
        case $method === 'GET' && preg_match('#^/containers/([^/]+)/logs$#', $path, $matches):
            require_once __DIR__ . '/controllers/ContainerController.php';
            ContainerController::logs($matches[1]);
            break;

        // POST /containers/:name/start
        case $method === 'POST' && preg_match('#^/containers/([^/]+)/start$#', $path, $matches):
            require_once __DIR__ . '/controllers/ContainerController.php';
            ContainerController::start($matches[1]);
            break;

        // POST /containers/:name/stop
        case $method === 'POST' && preg_match('#^/containers/([^/]+)/stop$#', $path, $matches):
            require_once __DIR__ . '/controllers/ContainerController.php';
            ContainerController::stop($matches[1]);
            break;

        // POST /containers/:name/restart
        case $method === 'POST' && preg_match('#^/containers/([^/]+)/restart$#', $path, $matches):
            require_once __DIR__ . '/controllers/ContainerController.php';
            ContainerController::restart($matches[1]);
            break;

        // ============ ADMIN CONTAINERS ROUTES ============

        // POST /admin/containers
        case $method === 'POST' && $path === '/admin/containers':
            require_once __DIR__ . '/controllers/ContainerController.php';
            ContainerController::create();
            break;

        // PUT /admin/containers/:id
        case $method === 'PUT' && preg_match('#^/admin/containers/(\d+)$#', $path, $matches):
            require_once __DIR__ . '/controllers/ContainerController.php';
            ContainerController::update((int)$matches[1]);
            break;

        // DELETE /admin/containers/:id
        case $method === 'DELETE' && preg_match('#^/admin/containers/(\d+)$#', $path, $matches):
            require_once __DIR__ . '/controllers/ContainerController.php';
            ContainerController::delete((int)$matches[1]);
            break;

        // ============ CATEGORIES ROUTES ============

        // GET /categories
        case $method === 'GET' && $path === '/categories':
            require_once __DIR__ . '/controllers/CategoryController.php';
            CategoryController::index();
            break;

        // POST /categories
        case $method === 'POST' && $path === '/categories':
            require_once __DIR__ . '/controllers/CategoryController.php';
            CategoryController::create();
            break;

        // PUT /categories/:id
        case $method === 'PUT' && preg_match('#^/categories/(\d+)$#', $path, $matches):
            require_once __DIR__ . '/controllers/CategoryController.php';
            CategoryController::update((int)$matches[1]);
            break;

        // DELETE /categories/:id
        case $method === 'DELETE' && preg_match('#^/categories/(\d+)$#', $path, $matches):
            require_once __DIR__ . '/controllers/CategoryController.php';
            CategoryController::delete((int)$matches[1]);
            break;

        // ============ CONTEXT ROUTES ============

        // GET /context
        case $method === 'GET' && $path === '/context':
            require_once __DIR__ . '/controllers/ContextController.php';
            ContextController::show();
            break;

        // POST /context/regenerate
        case $method === 'POST' && $path === '/context/regenerate':
            require_once __DIR__ . '/controllers/ContextController.php';
            ContextController::regenerate();
            break;

        // ============ ENERGY ROUTES ============

        // GET /energy
        case $method === 'GET' && $path === '/energy':
            require_once __DIR__ . '/controllers/StatsController.php';
            StatsController::energy();
            break;

        // ============ ALERTS ROUTES ============

        // GET /alerts
        case $method === 'GET' && $path === '/alerts':
            require_once __DIR__ . '/controllers/AlertController.php';
            AlertController::index();
            break;

        // PUT /alerts/:id
        case $method === 'PUT' && preg_match('#^/alerts/(\d+)$#', $path, $matches):
            require_once __DIR__ . '/controllers/AlertController.php';
            AlertController::update((int)$matches[1]);
            break;

        // POST /alerts/test
        case $method === 'POST' && $path === '/alerts/test':
            require_once __DIR__ . '/controllers/AlertController.php';
            AlertController::test();
            break;

        // ============ 404 ============
        default:
            http_response_code(404);
            echo json_encode([
                'error' => 'Not Found',
                'message' => "Route '$method $path' not found",
                'available_routes' => [
                    'POST /auth/login',
                    'POST /auth/logout',
                    'GET /auth/me',
                    'GET /auth/check',
                    'GET /api-keys',
                    'POST /api-keys',
                    'DELETE /api-keys/:id',
                    'GET /stats',
                    'GET /containers',
                    'POST /containers/discover',
                    'GET /containers/:name',
                    'GET /containers/:name/logs',
                    'POST /containers/:name/start',
                    'POST /containers/:name/stop',
                    'POST /containers/:name/restart',
                    'GET /categories',
                    'GET /context',
                ]
            ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => $e->getMessage()
    ]);
}
