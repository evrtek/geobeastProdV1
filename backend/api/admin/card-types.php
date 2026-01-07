<?php
/**
 * Admin Card Types API
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
        $types = $db->query('SELECT * FROM card_types ORDER BY type_name');

        ApiResponse::success($types, 'Card types retrieved');
    } catch (Exception $e) {
        Logger::error('List card types error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve card types');
    }
}

function handleCreate() {
    ApiResponse::requireMethod('POST');

    $data = ApiResponse::getJsonBody();
    ApiResponse::requireFields($data, ['type_name']);

    try {
        $db = Database::getInstance();

        $result = $db->execute('
            INSERT INTO card_types (type_name, type_description)
            VALUES (?, ?)
        ', [
            $data['type_name'],
            $data['type_description'] ?? null
        ]);

        Logger::info('Card type created', ['type_id' => $result['last_insert_id']]);
        ApiResponse::success(['card_type_id' => $result['last_insert_id']], 'Card type created', 201);
    } catch (Exception $e) {
        Logger::error('Create card type error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to create card type');
    }
}

function handleUpdate() {
    ApiResponse::requireMethod('PUT');

    $data = ApiResponse::getJsonBody();
    ApiResponse::requireFields($data, ['card_type_id', 'type_name']);

    try {
        $db = Database::getInstance();

        $db->execute('
            UPDATE card_types
            SET type_name = ?, type_description = ?
            WHERE card_type_id = ?
        ', [
            $data['type_name'],
            $data['type_description'] ?? null,
            $data['card_type_id']
        ]);

        Logger::info('Card type updated', ['type_id' => $data['card_type_id']]);
        ApiResponse::success(null, 'Card type updated');
    } catch (Exception $e) {
        Logger::error('Update card type error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to update card type');
    }
}

function handleDelete() {
    ApiResponse::requireMethod('DELETE');

    $data = ApiResponse::getJsonBody();
    ApiResponse::requireFields($data, ['card_type_id']);

    try {
        $db = Database::getInstance();

        $db->execute('DELETE FROM card_types WHERE card_type_id = ?', [$data['card_type_id']]);

        Logger::info('Card type deleted', ['type_id' => $data['card_type_id']]);
        ApiResponse::success(null, 'Card type deleted');
    } catch (Exception $e) {
        Logger::error('Delete card type error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to delete card type. It may be in use by existing cards.');
    }
}
