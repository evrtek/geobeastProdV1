<?php
/**
 * Admin Card Status/Rarity API
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
        $statuses = $db->query('SELECT * FROM card_status ORDER BY rarity_weight DESC');

        ApiResponse::success($statuses, 'Card statuses retrieved');
    } catch (Exception $e) {
        Logger::error('List card statuses error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve card statuses');
    }
}

function handleCreate() {
    ApiResponse::requireMethod('POST');

    $data = ApiResponse::getJsonBody();
    ApiResponse::requireFields($data, ['status_name', 'rarity_weight', 'max_copies']);

    try {
        $db = Database::getInstance();

        $result = $db->execute('
            INSERT INTO card_status (status_name, rarity_weight, max_copies, description)
            VALUES (?, ?, ?, ?)
        ', [
            $data['status_name'],
            $data['rarity_weight'],
            $data['max_copies'],
            $data['description'] ?? null
        ]);

        Logger::info('Card status created', ['status_id' => $result['last_insert_id']]);
        ApiResponse::success(['status_id' => $result['last_insert_id']], 'Card status created', 201);
    } catch (Exception $e) {
        Logger::error('Create card status error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to create card status');
    }
}

function handleUpdate() {
    ApiResponse::requireMethod('PUT');

    $data = ApiResponse::getJsonBody();
    ApiResponse::requireFields($data, ['status_id', 'status_name', 'rarity_weight', 'max_copies']);

    try {
        $db = Database::getInstance();

        $db->execute('
            UPDATE card_status
            SET status_name = ?, rarity_weight = ?, max_copies = ?, description = ?
            WHERE status_id = ?
        ', [
            $data['status_name'],
            $data['rarity_weight'],
            $data['max_copies'],
            $data['description'] ?? null,
            $data['status_id']
        ]);

        Logger::info('Card status updated', ['status_id' => $data['status_id']]);
        ApiResponse::success(null, 'Card status updated');
    } catch (Exception $e) {
        Logger::error('Update card status error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to update card status');
    }
}

function handleDelete() {
    ApiResponse::requireMethod('DELETE');

    $data = ApiResponse::getJsonBody();
    ApiResponse::requireFields($data, ['status_id']);

    try {
        $db = Database::getInstance();

        $db->execute('DELETE FROM card_status WHERE status_id = ?', [$data['status_id']]);

        Logger::info('Card status deleted', ['status_id' => $data['status_id']]);
        ApiResponse::success(null, 'Card status deleted');
    } catch (Exception $e) {
        Logger::error('Delete card status error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to delete card status. It may be in use by existing cards.');
    }
}
