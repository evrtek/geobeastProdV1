<?php
/**
 * Admin Card Templates API
 * Manages card template CRUD operations for administrators
 */

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/ApiResponse.php';
require_once __DIR__ . '/../../core/Logger.php';
require_once __DIR__ . '/../../core/Security.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = ApiResponse::getMethod();
$path = $_GET['action'] ?? '';

Logger::logRequest('/api/admin/card-templates?action=' . $path, $method);

// Route to appropriate handler
switch ($path) {
    case 'list':
        handleListTemplates();
        break;
    case 'get':
        handleGetTemplate();
        break;
    case 'create':
        handleCreateTemplate();
        break;
    case 'update':
        handleUpdateTemplate();
        break;
    case 'delete':
        handleDeleteTemplate();
        break;
    case 'publish':
        handlePublishTemplate();
        break;
    case 'unpublish':
        handleUnpublishTemplate();
        break;
    case 'get-types':
        handleGetCardTypes();
        break;
    case 'get-status':
        handleGetCardStatus();
        break;
    default:
        ApiResponse::notFound('Endpoint not found');
}

/**
 * Check if user is admin
 */
function requireAdmin() {
    $user = ApiResponse::requireAuth();

    // Check if user is admin, sysadmin, or god
    if (!in_array($user['account_type_name'], ['admin', 'sysadmin', 'god'])) {
        ApiResponse::error('Unauthorized - Admin access required', 403);
    }

    return $user;
}

/**
 * List all card templates
 */
function handleListTemplates() {
    ApiResponse::requireMethod('GET');
    requireAdmin();

    try {
        $db = Database::getInstance();

        $search = $_GET['search'] ?? '';
        $typeId = isset($_GET['type_id']) ? (int)$_GET['type_id'] : null;
        $statusId = isset($_GET['status_id']) ? (int)$_GET['status_id'] : null;
        $published = isset($_GET['published']) ? (int)$_GET['published'] : null;

        $query = 'SELECT
            ct.*,
            ct_type.type_name,
            cs.status_name as rarity
        FROM card_templates ct
        JOIN card_types ct_type ON ct.card_type_id = ct_type.card_type_id
        JOIN card_status cs ON ct.status_id = cs.status_id
        WHERE 1=1';

        $params = [];

        if ($search) {
            $query .= ' AND (ct.card_name LIKE ? OR ct.description LIKE ?)';
            $searchParam = "%$search%";
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if ($typeId !== null) {
            $query .= ' AND ct.card_type_id = ?';
            $params[] = $typeId;
        }

        if ($statusId !== null) {
            $query .= ' AND ct.status_id = ?';
            $params[] = $statusId;
        }

        if ($published !== null) {
            $query .= ' AND ct.published = ?';
            $params[] = $published;
        }

        $query .= ' ORDER BY ct.card_template_id DESC';

        $templates = $db->query($query, $params);

        ApiResponse::success([
            'templates' => $templates,
            'count' => count($templates)
        ], 'Card templates retrieved');

    } catch (Exception $e) {
        Logger::error('List templates error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve card templates');
    }
}

/**
 * Get single card template
 */
function handleGetTemplate() {
    ApiResponse::requireMethod('GET');
    requireAdmin();

    if (!isset($_GET['id'])) {
        ApiResponse::validationError(['id' => 'Template ID is required']);
    }

    $templateId = (int)$_GET['id'];

    try {
        $db = Database::getInstance();

        $template = $db->query(
            'SELECT
                ct.*,
                ct_type.type_name,
                cs.status_name as rarity
            FROM card_templates ct
            JOIN card_types ct_type ON ct.card_type_id = ct_type.card_type_id
            JOIN card_status cs ON ct.status_id = cs.status_id
            WHERE ct.card_template_id = ?',
            [$templateId]
        );

        if (empty($template)) {
            ApiResponse::error('Template not found', 404);
        }

        ApiResponse::success($template[0], 'Template retrieved');

    } catch (Exception $e) {
        Logger::error('Get template error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve template');
    }
}

/**
 * Create new card template
 */
function handleCreateTemplate() {
    ApiResponse::requireMethod('POST');
    requireAdmin();

    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, [
        'card_name', 'description', 'status_id', 'speed_score', 'attack_score', 'defense_score',
        'character_image_path', 'attack_name', 'attack_description', 'science_fact',
        'card_type_id', 'card_number', 'card_total', 'header_color1', 'header_color2',
        'border_color1', 'border_color2', 'total_copies'
    ]);

    try {
        $db = Database::getInstance();

        // Validate scores
        if ($data['speed_score'] < 0 || $data['speed_score'] > 100 ||
            $data['attack_score'] < 0 || $data['attack_score'] > 100 ||
            $data['defense_score'] < 0 || $data['defense_score'] > 100) {
            ApiResponse::validationError(['scores' => 'Scores must be between 0 and 100']);
        }

        // Generate unique code
        $uniqueCode = md5(uniqid($data['card_name'], true));

        $templateId = $db->insert('card_templates', [
            'card_name' => Security::sanitizeInput($data['card_name']),
            'description' => Security::sanitizeInput($data['description']),
            'status_id' => (int)$data['status_id'],
            'speed_score' => (int)$data['speed_score'],
            'attack_score' => (int)$data['attack_score'],
            'defense_score' => (int)$data['defense_score'],
            'character_image_path' => Security::sanitizeInput($data['character_image_path']),
            'attack_name' => Security::sanitizeInput($data['attack_name']),
            'attack_description' => Security::sanitizeInput($data['attack_description']),
            'science_fact' => Security::sanitizeInput($data['science_fact']),
            'science_fact_image_path' => isset($data['science_fact_image_path']) ? Security::sanitizeInput($data['science_fact_image_path']) : null,
            'card_type_id' => (int)$data['card_type_id'],
            'card_number' => (int)$data['card_number'],
            'card_total' => (int)$data['card_total'],
            'unique_code' => $uniqueCode,
            'header_color1' => Security::sanitizeInput($data['header_color1']),
            'header_color2' => Security::sanitizeInput($data['header_color2']),
            'border_color1' => Security::sanitizeInput($data['border_color1']),
            'border_color2' => Security::sanitizeInput($data['border_color2']),
            'total_copies' => (int)$data['total_copies'],
            'published' => isset($data['published']) ? (int)$data['published'] : 0
        ]);

        ApiResponse::success([
            'card_template_id' => $templateId,
            'unique_code' => $uniqueCode
        ], 'Card template created successfully', 201);

    } catch (Exception $e) {
        Logger::error('Create template error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to create card template');
    }
}

/**
 * Update card template
 */
function handleUpdateTemplate() {
    ApiResponse::requireMethod('PUT');
    requireAdmin();

    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['card_template_id']);

    $templateId = (int)$data['card_template_id'];

    try {
        $db = Database::getInstance();

        // Check template exists
        $existing = $db->query(
            'SELECT card_template_id FROM card_templates WHERE card_template_id = ?',
            [$templateId]
        );

        if (empty($existing)) {
            ApiResponse::error('Template not found', 404);
        }

        // Build update array
        $updates = [];
        $fields = [
            'card_name', 'description', 'status_id', 'speed_score', 'attack_score', 'defense_score',
            'character_image_path', 'attack_name', 'attack_description', 'science_fact',
            'science_fact_image_path', 'card_type_id', 'card_number', 'card_total',
            'header_color1', 'header_color2', 'border_color1', 'border_color2', 'total_copies', 'published'
        ];

        foreach ($fields as $field) {
            if (isset($data[$field])) {
                if (in_array($field, ['status_id', 'speed_score', 'attack_score', 'defense_score', 'card_type_id', 'card_number', 'card_total', 'total_copies', 'published'])) {
                    $updates[$field] = (int)$data[$field];
                } else {
                    $updates[$field] = Security::sanitizeInput($data[$field]);
                }
            }
        }

        // Validate scores if provided
        if (isset($updates['speed_score']) && ($updates['speed_score'] < 0 || $updates['speed_score'] > 100) ||
            isset($updates['attack_score']) && ($updates['attack_score'] < 0 || $updates['attack_score'] > 100) ||
            isset($updates['defense_score']) && ($updates['defense_score'] < 0 || $updates['defense_score'] > 100)) {
            ApiResponse::validationError(['scores' => 'Scores must be between 0 and 100']);
        }

        if (!empty($updates)) {
            $db->update('card_templates', $updates, ['card_template_id' => $templateId]);
        }

        ApiResponse::success(['card_template_id' => $templateId], 'Card template updated successfully');

    } catch (Exception $e) {
        Logger::error('Update template error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to update card template');
    }
}

/**
 * Delete card template
 */
function handleDeleteTemplate() {
    ApiResponse::requireMethod('DELETE');
    requireAdmin();

    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['card_template_id']);

    $templateId = (int)$data['card_template_id'];

    try {
        $db = Database::getInstance();

        // Check if any published cards exist
        $publishedCards = $db->query(
            'SELECT COUNT(*) as count FROM published_cards WHERE card_template_id = ?',
            [$templateId]
        );

        if ($publishedCards[0]['count'] > 0) {
            ApiResponse::error('Cannot delete template with published cards', 400);
        }

        $db->delete('card_templates', ['card_template_id' => $templateId]);

        ApiResponse::success(null, 'Card template deleted successfully');

    } catch (Exception $e) {
        Logger::error('Delete template error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to delete card template');
    }
}

/**
 * Publish card template
 */
function handlePublishTemplate() {
    ApiResponse::requireMethod('POST');
    requireAdmin();

    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['card_template_id']);

    $templateId = (int)$data['card_template_id'];

    try {
        $db = Database::getInstance();

        $db->update('card_templates', ['published' => 1], ['card_template_id' => $templateId]);

        ApiResponse::success(null, 'Card template published successfully');

    } catch (Exception $e) {
        Logger::error('Publish template error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to publish card template');
    }
}

/**
 * Unpublish card template
 */
function handleUnpublishTemplate() {
    ApiResponse::requireMethod('POST');
    requireAdmin();

    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['card_template_id']);

    $templateId = (int)$data['card_template_id'];

    try {
        $db = Database::getInstance();

        $db->update('card_templates', ['published' => 0], ['card_template_id' => $templateId]);

        ApiResponse::success(null, 'Card template unpublished successfully');

    } catch (Exception $e) {
        Logger::error('Unpublish template error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to unpublish card template');
    }
}

/**
 * Get card types
 */
function handleGetCardTypes() {
    ApiResponse::requireMethod('GET');
    requireAdmin();

    try {
        $db = Database::getInstance();

        $types = $db->query('SELECT * FROM card_types ORDER BY type_name');

        ApiResponse::success($types, 'Card types retrieved');

    } catch (Exception $e) {
        Logger::error('Get card types error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve card types');
    }
}

/**
 * Get card status (rarities)
 */
function handleGetCardStatus() {
    ApiResponse::requireMethod('GET');
    requireAdmin();

    try {
        $db = Database::getInstance();

        $statuses = $db->query('SELECT * FROM card_status ORDER BY rarity_weight DESC');

        ApiResponse::success($statuses, 'Card statuses retrieved');

    } catch (Exception $e) {
        Logger::error('Get card status error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve card statuses');
    }
}
