<?php
/**
 * Notifications API Endpoints
 * Handles user notifications and real-time updates
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/ApiResponse.php';
require_once __DIR__ . '/../core/Logger.php';

// Set CORS headers
ApiResponse::setCorsHeaders();

// Get request method and path
$method = ApiResponse::getMethod();
$path = $_GET['action'] ?? '';

// Log API request
Logger::logRequest('/api/notifications?action=' . $path, $method);

// Route to appropriate handler
switch ($path) {
    case 'get-notifications':
        handleGetNotifications();
        break;
    case 'get-unread-count':
        handleGetUnreadCount();
        break;
    case 'mark-as-read':
        handleMarkAsRead();
        break;
    case 'mark-all-read':
        handleMarkAllRead();
        break;
    case 'delete-notification':
        handleDeleteNotification();
        break;
    case 'get-notification-settings':
        handleGetNotificationSettings();
        break;
    case 'update-notification-settings':
        handleUpdateNotificationSettings();
        break;
    default:
        ApiResponse::notFound('Endpoint not found');
}

/**
 * Get user's notifications
 */
function handleGetNotifications() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();
    $userId = $user['user_id'];

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] === 'true';

    try {
        $db = Database::getInstance();

        $whereClause = $unreadOnly ? 'AND n.is_read = FALSE' : '';

        $notifications = $db->query(
            "SELECT
                n.notification_id,
                n.notification_type,
                n.notification_color,
                n.title,
                n.message,
                n.related_user_id,
                n.related_entity_type,
                n.related_entity_id,
                n.read_status as is_read,
                n.created_at
            FROM notifications n
            WHERE n.user_id = ? $whereClause
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );

        // Enrich notifications with additional data
        foreach ($notifications as &$notification) {
            // If it's a marketplace offer notification, fetch offer details
            if ($notification['related_entity_type'] === 'marketplace_offer' && $notification['related_entity_id']) {
                $offerDetails = $db->query(
                    'SELECT
                        mo.offer_id,
                        mo.offer_price,
                        mo.offer_status,
                        mo.seller_confirmed,
                        mo.buyer_confirmed,
                        mo.created_at,
                        ml.listing_id,
                        ml.asking_price,
                        ml.seller_user_id,
                        ct.card_name,
                        ct.character_image_path
                    FROM marketplace_offers mo
                    JOIN marketplace_listings ml ON mo.listing_id = ml.listing_id
                    JOIN user_cards uc ON ml.user_card_id = uc.user_card_id
                    JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
                    JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
                    WHERE mo.offer_id = ?',
                    [$notification['related_entity_id']]
                );

                if (!empty($offerDetails)) {
                    $notification['offer_details'] = $offerDetails[0];
                    // Determine if user is seller or buyer
                    $notification['user_role'] = $offerDetails[0]['seller_user_id'] == $userId ? 'seller' : 'buyer';
                }
            }
        }

        // Get total count
        $countResult = $db->query(
            "SELECT COUNT(*) as total FROM notifications WHERE user_id = ? $whereClause",
            [$userId]
        );
        $total = $countResult[0]['total'];

        ApiResponse::success([
            'notifications' => $notifications,
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset
        ], 'Notifications retrieved');

    } catch (Exception $e) {
        Logger::error('Get notifications error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve notifications');
    }
}

/**
 * Get unread notification count
 */
function handleGetUnreadCount() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $result = $db->query(
            'SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = FALSE',
            [$userId]
        );

        ApiResponse::success([
            'unread_count' => (int)$result[0]['unread_count']
        ], 'Unread count retrieved');

    } catch (Exception $e) {
        Logger::error('Get unread count error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve unread count');
    }
}

/**
 * Mark notification as read
 */
function handleMarkAsRead() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['notification_id']);

    $notificationId = (int)$data['notification_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        // Verify notification belongs to user
        $notification = $db->query(
            'SELECT user_id FROM notifications WHERE notification_id = ?',
            [$notificationId]
        );

        if (empty($notification)) {
            ApiResponse::notFound('Notification not found');
        }

        if ($notification[0]['user_id'] != $userId) {
            ApiResponse::forbidden('Cannot access this notification');
        }

        // Mark as read
        $db->execute(
            'UPDATE notifications SET is_read = TRUE WHERE notification_id = ?',
            [$notificationId]
        );

        ApiResponse::success(null, 'Notification marked as read');

    } catch (Exception $e) {
        Logger::error('Mark as read error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to mark notification as read');
    }
}

/**
 * Mark all notifications as read
 */
function handleMarkAllRead() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $db->execute(
            'UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE',
            [$userId]
        );

        ApiResponse::success(null, 'All notifications marked as read');

    } catch (Exception $e) {
        Logger::error('Mark all read error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to mark all notifications as read');
    }
}

/**
 * Delete notification
 */
function handleDeleteNotification() {
    ApiResponse::requireMethod('DELETE');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['notification_id']);

    $notificationId = (int)$data['notification_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        // Verify notification belongs to user
        $notification = $db->query(
            'SELECT user_id FROM notifications WHERE notification_id = ?',
            [$notificationId]
        );

        if (empty($notification)) {
            ApiResponse::notFound('Notification not found');
        }

        if ($notification[0]['user_id'] != $userId) {
            ApiResponse::forbidden('Cannot delete this notification');
        }

        // Delete notification
        $db->execute('DELETE FROM notifications WHERE notification_id = ?', [$notificationId]);

        ApiResponse::success(null, 'Notification deleted');

    } catch (Exception $e) {
        Logger::error('Delete notification error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to delete notification');
    }
}

/**
 * Get notification settings
 */
function handleGetNotificationSettings() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $settings = $db->query(
            'SELECT
                enable_battle_notifications,
                enable_trade_notifications,
                enable_marketplace_notifications,
                enable_friend_notifications,
                enable_chat_notifications,
                enable_email_notifications
            FROM users
            WHERE user_id = ?',
            [$userId]
        );

        if (empty($settings)) {
            ApiResponse::notFound('User not found');
        }

        ApiResponse::success($settings[0], 'Notification settings retrieved');

    } catch (Exception $e) {
        Logger::error('Get notification settings error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve notification settings');
    }
}

/**
 * Update notification settings
 */
function handleUpdateNotificationSettings() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();
    $userId = $user['user_id'];

    $allowedFields = [
        'enable_battle_notifications',
        'enable_trade_notifications',
        'enable_marketplace_notifications',
        'enable_friend_notifications',
        'enable_chat_notifications',
        'enable_email_notifications'
    ];

    try {
        $db = Database::getInstance();

        $updates = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = (bool)$data[$field] ? 1 : 0;
            }
        }

        if (empty($updates)) {
            ApiResponse::validationError(['settings' => 'No valid settings provided']);
        }

        $params[] = $userId;

        $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE user_id = ?';
        $db->execute($sql, $params);

        ApiResponse::success(null, 'Notification settings updated');

    } catch (Exception $e) {
        Logger::error('Update notification settings error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to update notification settings');
    }
}
