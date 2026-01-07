<?php
/**
 * Admin Users API
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
    case 'toggle-active':
        handleToggleActive();
        break;
    case 'update-account-type':
        handleUpdateAccountType();
        break;
    default:
        ApiResponse::notFound('Endpoint not found');
}

function handleList() {
    ApiResponse::requireMethod('GET');

    try {
        $db = Database::getInstance();

        $where = [];
        $params = [];

        if (!empty($_GET['search'])) {
            $where[] = "(username LIKE ? OR email LIKE ? OR given_name LIKE ? OR surname LIKE ?)";
            $searchTerm = '%' . $_GET['search'] . '%';
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }

        if (!empty($_GET['account_type'])) {
            $where[] = "at.account_type_name = ?";
            $params[] = $_GET['account_type'];
        }

        if (isset($_GET['active'])) {
            $where[] = "u.active = ?";
            $params[] = $_GET['active'] === '1' ? 1 : 0;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $users = $db->query("
            SELECT
                u.user_id, u.username, u.given_name, u.surname, u.email,
                at.account_type_name, u.active, u.confirmed, u.credits,
                u.created_at, u.last_activity
            FROM users u
            JOIN account_types at ON u.account_type_id = at.account_type_id
            $whereClause
            ORDER BY u.created_at DESC
            LIMIT 100
        ", $params);

        ApiResponse::success(['users' => $users], 'Users retrieved');
    } catch (Exception $e) {
        Logger::error('List users error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve users');
    }
}

function handleToggleActive() {
    ApiResponse::requireMethod('POST');

    $data = ApiResponse::getJsonBody();
    ApiResponse::requireFields($data, ['user_id']);

    try {
        $db = Database::getInstance();

        $db->execute('UPDATE users SET active = NOT active WHERE user_id = ?', [$data['user_id']]);

        Logger::info('User active status toggled', ['user_id' => $data['user_id']]);
        ApiResponse::success(null, 'User status updated');
    } catch (Exception $e) {
        Logger::error('Toggle user active error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to update user status');
    }
}

function handleUpdateAccountType() {
    ApiResponse::requireMethod('POST');

    $data = ApiResponse::getJsonBody();
    ApiResponse::requireFields($data, ['user_id', 'account_type_name']);

    try {
        $db = Database::getInstance();

        // Get account type ID
        $accountType = $db->query('SELECT account_type_id FROM account_types WHERE account_type_name = ?', [$data['account_type_name']]);

        if (empty($accountType)) {
            ApiResponse::error('Invalid account type', 400);
        }

        $db->execute('UPDATE users SET account_type_id = ? WHERE user_id = ?', [
            $accountType[0]['account_type_id'],
            $data['user_id']
        ]);

        Logger::info('User account type updated', ['user_id' => $data['user_id'], 'new_type' => $data['account_type_name']]);
        ApiResponse::success(null, 'Account type updated');
    } catch (Exception $e) {
        Logger::error('Update account type error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to update account type');
    }
}
