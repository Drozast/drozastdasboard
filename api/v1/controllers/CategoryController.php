<?php
/**
 * Category Controller
 * Manage service categories
 */

require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../models/Database.php';

class CategoryController {

    /**
     * GET /categories
     * List all categories
     */
    public static function index(): void {
        Auth::require();

        $categories = Database::fetchAll(
            "SELECT c.*, COUNT(cont.id) as container_count
             FROM categories c
             LEFT JOIN containers cont ON c.id = cont.category_id AND cont.is_visible = 1
             GROUP BY c.id
             ORDER BY c.sort_order, c.name"
        );

        self::json(['categories' => $categories]);
    }

    /**
     * POST /categories
     * Create new category
     */
    public static function create(): void {
        Auth::require();

        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['name'])) {
            self::json(['error' => 'Category name required'], 400);
            return;
        }

        $maxOrder = Database::fetch("SELECT MAX(sort_order) as max FROM categories")['max'] ?? 0;

        Database::query(
            "INSERT INTO categories (name, icon, sort_order, is_visible) VALUES (?, ?, ?, ?)",
            [
                $input['name'],
                $input['icon'] ?? '📁',
                $input['sort_order'] ?? ($maxOrder + 1),
                $input['is_visible'] ?? 1,
            ]
        );

        $id = Database::lastInsertId();
        Auth::logAction('create_category', null, json_encode(['name' => $input['name']]));

        self::json([
            'success' => true,
            'id' => $id,
            'message' => 'Category created',
        ], 201);
    }

    /**
     * PUT /categories/:id
     * Update category
     */
    public static function update(int $id): void {
        Auth::require();

        $input = json_decode(file_get_contents('php://input'), true);

        $category = Database::fetch("SELECT * FROM categories WHERE id = ?", [$id]);
        if (!$category) {
            self::json(['error' => 'Category not found'], 404);
            return;
        }

        $fields = [];
        $values = [];
        $allowed = ['name', 'icon', 'sort_order', 'is_visible'];

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

        $values[] = $id;

        Database::query(
            "UPDATE categories SET " . implode(', ', $fields) . " WHERE id = ?",
            $values
        );

        Auth::logAction('update_category', null, json_encode($input));

        self::json(['success' => true, 'message' => 'Category updated']);
    }

    /**
     * DELETE /categories/:id
     * Delete category (sets containers to uncategorized)
     */
    public static function delete(int $id): void {
        Auth::require();

        $category = Database::fetch("SELECT * FROM categories WHERE id = ?", [$id]);
        if (!$category) {
            self::json(['error' => 'Category not found'], 404);
            return;
        }

        // Set containers to uncategorized
        Database::query("UPDATE containers SET category_id = NULL WHERE category_id = ?", [$id]);

        // Delete category
        Database::query("DELETE FROM categories WHERE id = ?", [$id]);

        Auth::logAction('delete_category', null, json_encode(['name' => $category['name']]));

        self::json(['success' => true, 'message' => 'Category deleted']);
    }

    /**
     * POST /categories/reorder
     * Reorder categories
     */
    public static function reorder(): void {
        Auth::require();

        $input = json_decode(file_get_contents('php://input'), true);
        $order = $input['order'] ?? [];

        if (empty($order)) {
            self::json(['error' => 'Order array required'], 400);
            return;
        }

        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            foreach ($order as $index => $id) {
                Database::query(
                    "UPDATE categories SET sort_order = ? WHERE id = ?",
                    [$index, $id]
                );
            }
            $db->commit();
            self::json(['success' => true, 'message' => 'Categories reordered']);
        } catch (Exception $e) {
            $db->rollBack();
            self::json(['error' => 'Failed to reorder'], 500);
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
