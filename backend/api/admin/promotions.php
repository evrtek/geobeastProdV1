<?php
/**
 * Admin Promotions API
 */

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/ApiResponse.php';
require_once __DIR__ . '/../../core/Logger.php';

ApiResponse::setCorsHeaders();

$method = ApiResponse::getMethod();
$action = $_GET['action'] ?? '';

$user = ApiResponse::requireAuth();
if (!in_array($user['account_type_name'] ?? '', ['admin', 'sysadmin', 'god'])) {
    ApiResponse::forbidden('Admin access required');
}

switch ($action) {
    case 'list':
        handleList();
        break;
    case 'create':
        handleCreate();
        break;
    case 'update':
        handleUpdate();
        break;
    case 'delete':
        handleDelete();
        break;
    default:
        ApiResponse::notFound('Endpoint not found');
}

function handleList() {
    ApiResponse::requireMethod('GET');

    try {
        $db = Database::getInstance();

        // Create promotions table if it doesn't exist
        $db->execute("
            CREATE TABLE IF NOT EXISTS promotions (
                promotion_id INT AUTO_INCREMENT PRIMARY KEY,
                promotion_name VARCHAR(255) NOT NULL,
                description TEXT,
                discount_percentage DECIMAL(5,2) NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        $promotions = $db->query('SELECT * FROM promotions ORDER BY created_at DESC');

        ApiResponse::success($promotions, 'Promotions retrieved');
    } catch (Exception $e) {
        Logger::error('List promotions error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve promotions');
    }
}

function handleCreate() {
    ApiResponse::requireMethod('POST');

    $data = ApiResponse::getJsonBody();
    ApiResponse::requireFields($data, ['promotion_name', 'discount_percentage', 'start_date', 'end_date']);

    try {
        $db = Database::getInstance();

        $result = $db->execute('
            INSERT INTO promotions (promotion_name, description, discount_percentage, start_date, end_date, active)
            VALUES (?, ?, ?, ?, ?, ?)
        ', [
            $data['promotion_name'],
            $data['description'] ?? null,
            $data['discount_percentage'],
            $data['start_date'],
            $data['end_date'],
            $data['active'] ?? true
        ]);

        Logger::info('Promotion created', ['promo_id' => $result['last_insert_id']]);
        ApiResponse::success(['promotion_id' => $result['last_insert_id']], 'Promotion created', 201);
    } catch (Exception $e) {
        Logger::error('Create promotion error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to create promotion');
    }
}

function handleUpdate() {
    ApiResponse::requireMethod('PUT');

    $data = ApiResponse::getJsonBody();
    ApiResponse::requireFields($data, ['promotion_id', 'promotion_name', 'discount_percentage', 'start_date', 'end_date']);

    try {
        $db = Database::getInstance();

        $db->execute('
            UPDATE promotions
            SET promotion_name = ?, description = ?, discount_percentage = ?,
                start_date = ?, end_date = ?, active = ?
            WHERE promotion_id = ?
        ', [
            $data['promotion_name'],
            $data['description'] ?? null,
            $data['discount_percentage'],
            $data['start_date'],
            $data['end_date'],
            $data['active'] ?? true,
            $data['promotion_id']
        ]);

        Logger::info('Promotion updated', ['promo_id' => $data['promotion_id']]);
        ApiResponse::success(null, 'Promotion updated');
    } catch (Exception $e) {
        Logger::error('Update promotion error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to update promotion');
    }
}

function handleDelete() {
    ApiResponse::requireMethod('DELETE');

    $data = ApiResponse::getJsonBody();
    ApiResponse::requireFields($data, ['promotion_id']);

    try {
        $db = Database::getInstance();

        $db->execute('DELETE FROM promotions WHERE promotion_id = ?', [$data['promotion_id']]);

        Logger::info('Promotion deleted', ['promo_id' => $data['promotion_id']]);
        ApiResponse::success(null, 'Promotion deleted');
    } catch (Exception $e) {
        Logger::error('Delete promotion error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to delete promotion');
    }
}
