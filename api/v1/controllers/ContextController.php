<?php
/**
 * Context Controller
 * Generate and serve server context for development
 */

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../services/ContextGenerator.php';

class ContextController {

    /**
     * GET /context
     * Get the generated server context
     */
    public static function show(): void {
        // Auth is optional for this endpoint - check if requested
        $requireAuth = isset($_GET['auth']) && $_GET['auth'] === 'true';
        if ($requireAuth) {
            Auth::require();
        }

        $format = $_GET['format'] ?? 'full';

        $context = ContextGenerator::generate();

        switch ($format) {
            case 'plain':
                header('Content-Type: text/plain; charset=utf-8');
                echo $context['plain'];
                break;

            case 'html':
                self::json([
                    'html' => $context['html'],
                    'generated_at' => $context['generated_at'],
                    'container_count' => $context['container_count'],
                    'category_count' => $context['category_count']
                ]);
                break;

            case 'download':
                header('Content-Type: text/plain; charset=utf-8');
                header('Content-Disposition: attachment; filename="SERVER_CONTEXT.md"');
                echo $context['plain'];
                break;

            default:
                self::json($context);
        }
    }

    /**
     * POST /context/regenerate
     * Regenerate and save the context to file
     */
    public static function regenerate(): void {
        Auth::require();

        $success = ContextGenerator::saveToFile();

        if ($success) {
            Auth::logAction('regenerate_context', null, 'Regenerated SERVER_CONTEXT.md');
            self::json([
                'success' => true,
                'message' => 'Context file regenerated successfully',
                'path' => '/data/SERVER_CONTEXT.md'
            ]);
        } else {
            self::json([
                'success' => false,
                'error' => 'Failed to save context file'
            ], 500);
        }
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
