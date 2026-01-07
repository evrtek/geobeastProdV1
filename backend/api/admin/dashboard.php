<?php
/**
 * Admin Dashboard API
 */

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/ApiResponse.php';
require_once __DIR__ . '/../../core/Logger.php';

ApiResponse::setCorsHeaders();

$method = ApiResponse::getMethod();
$action = $_GET['action'] ?? '';

// Require admin authentication
$user = ApiResponse::requireAuth();
if (!in_array($user['account_type_name'] ?? '', ['admin', 'sysadmin', 'god'])) {
    ApiResponse::forbidden('Admin access required');
}

switch ($action) {
    case 'stats':
        handleGetStats();
        break;
    default:
        ApiResponse::notFound('Endpoint not found');
}

function handleGetStats() {
    ApiResponse::requireMethod('GET');

    try {
        $db = Database::getInstance();

        // Get card template stats
        $templateStats = $db->query('
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN published = 1 THEN 1 ELSE 0 END) as published,
                SUM(CASE WHEN published = 0 THEN 1 ELSE 0 END) as drafts
            FROM card_templates
        ')[0];

        // Get card types count
        $typeCount = $db->query('SELECT COUNT(*) as total FROM card_types')[0]['total'];

        // Get card status count
        $statusCount = $db->query('SELECT COUNT(*) as total FROM card_status')[0]['total'];

        // Get user stats
        $userStats = $db->query('
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active
            FROM users
        ')[0];

        // Get promotions count (if table exists)
        try {
            $promoCount = $db->query('SELECT COUNT(*) as total FROM promotions WHERE active = 1')[0]['total'];
        } catch (Exception $e) {
            $promoCount = 0;
        }

        ApiResponse::success([
            'total_card_templates' => (int)$templateStats['total'],
            'published_templates' => (int)$templateStats['published'],
            'draft_templates' => (int)$templateStats['drafts'],
            'total_card_types' => (int)$typeCount,
            'total_card_statuses' => (int)$statusCount,
            'total_users' => (int)$userStats['total'],
            'active_users' => (int)$userStats['active'],
            'total_promotions' => (int)$promoCount
        ], 'Dashboard stats retrieved');

    } catch (Exception $e) {
        Logger::error('Get dashboard stats error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve dashboard stats');
    }
}
