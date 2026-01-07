<?php
/**
 * Chat API Endpoints
 * Handles direct messaging between friends
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/ApiResponse.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../core/ChildActivityLogger.php';
require_once __DIR__ . '/../core/UserCodeHelper.php';

// Set CORS headers
ApiResponse::setCorsHeaders();

// Get request method and path
$method = ApiResponse::getMethod();
$path = $_GET['action'] ?? '';

// Log API request
Logger::logRequest('/api/chat?action=' . $path, $method);

// Route to appropriate handler
switch ($path) {
    case 'get-conversations':
        handleGetConversations();
        break;
    case 'get-messages':
        handleGetMessages();
        break;
    case 'send-message':
        handleSendMessage();
        break;
    case 'mark-as-read':
        handleMarkAsRead();
        break;
    case 'delete-message':
        handleDeleteMessage();
        break;
    case 'get-unread-count':
        handleGetUnreadCount();
        break;
    default:
        ApiResponse::notFound('Endpoint not found');
}

/**
 * Get user's conversations with friends
 */
function handleGetConversations() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

    try {
        $db = Database::getInstance();

        // Get all conversations with last message
        $conversations = $db->query(
            "SELECT
                CASE
                    WHEN m.sender_user_id = ? THEN m.recipient_user_id
                    ELSE m.sender_user_id
                END as friend_user_id,
                u.username as friend_username,
                u.avatar_id as friend_avatar_url,
                u.last_login as friend_last_login,
                m.message_text as last_message,
                m.sent_at as last_message_time,
                m.sender_user_id = ? as sent_by_me,
                (SELECT COUNT(*)
                 FROM messages m2
                 WHERE m2.recipient_user_id = ?
                 AND m2.sender_user_id = (CASE WHEN m.sender_user_id = ? THEN m.recipient_user_id ELSE m.sender_user_id END)
                 AND m2.is_read = FALSE) as unread_count
            FROM messages m
            JOIN users u ON u.user_id = (CASE WHEN m.sender_user_id = ? THEN m.recipient_user_id ELSE m.sender_user_id END)
            WHERE (m.sender_user_id = ? OR m.recipient_user_id = ?)
            AND m.message_id IN (
                SELECT MAX(message_id)
                FROM messages
                WHERE (sender_user_id = ? AND recipient_user_id = u.user_id)
                   OR (recipient_user_id = ? AND sender_user_id = u.user_id)
                GROUP BY LEAST(sender_user_id, recipient_user_id), GREATEST(sender_user_id, recipient_user_id)
            )
            ORDER BY m.sent_at DESC",
            [$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId]
        );

        ApiResponse::success([
            'conversations' => $conversations,
            'count' => count($conversations)
        ], 'Conversations retrieved');

    } catch (Exception $e) {
        Logger::error('Get conversations error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve conversations');
    }
}

/**
 * Get messages with a specific friend
 */
function handleGetMessages() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

    if (!isset($_GET['friend_user_code'])) {
        ApiResponse::validationError(['friend_user_code' => 'Friend user code is required']);
    }

    $friendUserCode = $_GET['friend_user_code'];
    $friendUserId = UserCodeHelper::getUserIdFromCode($friendUserCode);
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    try {
        $db = Database::getInstance();

        // Verify friendship exists
        $friendship = $db->query(
            'SELECT friendship_id FROM friendships
            WHERE ((requester_user_id = ? AND recipient_user_id = ?)
               OR (requester_user_id = ? AND recipient_user_id = ?))
            AND status = ?',
            [$userId, $friendUserId, $friendUserId, $userId, 'approved']
        );

        if (empty($friendship)) {
            ApiResponse::forbidden('You can only message friends');
        }

        // Get messages
        $messages = $db->query(
            "SELECT
                m.message_id,
                m.sender_user_id,
                m.recipient_user_id,
                sender.check_code as sender_user_code,
                recipient.check_code as recipient_user_code,
                recipient.check_code as receiver_user_code,
                m.message_text,
                m.message_text as message_content,
                m.is_read,
                m.sent_at,
                m.read_at,
                sender.username as sender_username,
                sender.avatar_id as sender_avatar_url
            FROM messages m
            JOIN users sender ON m.sender_user_id = sender.user_id
            JOIN users recipient ON m.recipient_user_id = recipient.user_id
            WHERE ((m.sender_user_id = ? AND m.recipient_user_id = ?)
               OR (m.sender_user_id = ? AND m.recipient_user_id = ?))
            ORDER BY m.sent_at DESC
            LIMIT ? OFFSET ?",
            [$userId, $friendUserId, $friendUserId, $userId, $limit, $offset]
        );

        // Mark messages from friend as read
        $db->execute(
            'UPDATE messages SET is_read = TRUE, read_at = NOW()
            WHERE recipient_user_id = ? AND sender_user_id = ? AND is_read = FALSE',
            [$userId, $friendUserId]
        );

        // Get total count
        $countResult = $db->query(
            'SELECT COUNT(*) as total FROM messages
            WHERE (sender_user_id = ? AND recipient_user_id = ?)
               OR (sender_user_id = ? AND recipient_user_id = ?)',
            [$userId, $friendUserId, $friendUserId, $userId]
        );
        $total = $countResult[0]['total'];

        ApiResponse::success([
            'messages' => array_reverse($messages), // Reverse to show oldest first
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset
        ], 'Messages retrieved');

    } catch (Exception $e) {
        Logger::error('Get messages error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve messages');
    }
}

/**
 * Send a message to a friend
 */
function handleSendMessage() {
    ApiResponse::requireMethod('POST');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['recipient_user_code', 'message_text']);

    $senderId = UserCodeHelper::getUserIdFromCode($userCode);
    $recipientUserCode = $data['recipient_user_code'];
    $recipientId = UserCodeHelper::getUserIdFromCode($recipientUserCode);
    $messageText = Security::sanitizeInput($data['message_text']);

    // Validate message length
    if (strlen($messageText) < 1) {
        ApiResponse::validationError(['message_text' => 'Message cannot be empty']);
    }

    if (strlen($messageText) > 1000) {
        ApiResponse::validationError(['message_text' => 'Message too long (max 1000 characters)']);
    }

    if ($senderId == $recipientId) {
        ApiResponse::error('Cannot send message to yourself', 400);
    }

    try {
        $db = Database::getInstance();

        // Verify friendship exists
        $friendship = $db->query(
            'SELECT friendship_id FROM friendships
            WHERE ((requester_user_id = ? AND recipient_user_id = ?)
               OR (requester_user_id = ? AND recipient_user_id = ?))
            AND status = ?',
            [$senderId, $recipientId, $recipientId, $senderId, 'approved']
        );

        if (empty($friendship)) {
            ApiResponse::forbidden('You can only message friends');
        }

        // Check if recipient has blocked sender
        $blockCheck = $db->query(
            'SELECT block_id FROM blocks WHERE blocker_user_id = ? AND blocked_user_id = ?',
            [$recipientId, $senderId]
        );

        if (!empty($blockCheck)) {
            ApiResponse::forbidden('Cannot send message to this user');
        }

        // Check if sender is child account
        $senderAccountType = $db->query(
            'SELECT at.account_type_name FROM users u
            JOIN account_types at ON u.account_type_id = at.account_type_id
            WHERE u.user_id = ?',
            [$senderId]
        );

        if ($senderAccountType[0]['account_type_name'] === 'child') {
            // Log child activity using ChildActivityLogger
            ChildActivityLogger::log($senderId, 'message_sent', [
                'recipient_id' => $recipientId,
                'message_length' => strlen($messageText)
            ]);
        }

        // Insert message
        $result = $db->execute(
            'INSERT INTO messages (sender_user_id, recipient_user_id, message_text, sent_at)
            VALUES (?, ?, ?, NOW())',
            [$senderId, $recipientId, $messageText]
        );

        $messageId = $result['last_insert_id'];

        // Get full message data
        $message = $db->query(
            'SELECT
                m.message_id,
                m.sender_user_id,
                m.recipient_user_id,
                sender.check_code as sender_user_code,
                recipient.check_code as recipient_user_code,
                recipient.check_code as receiver_user_code,
                m.message_text,
                m.message_text as message_content,
                m.is_read,
                m.sent_at,
                sender.username as sender_username,
                sender.avatar_id as sender_avatar_url
            FROM messages m
            JOIN users sender ON m.sender_user_id = sender.user_id
            JOIN users recipient ON m.recipient_user_id = recipient.user_id
            WHERE m.message_id = ?',
            [$messageId]
        );

        // Note: Chat notifications are handled via WebSocket real-time updates
        // Database notifications are not created for chat messages to avoid spam
        // Users will see messages in real-time via WebSocket connection

        // Broadcast message via WebSocket (if server is running)
        Logger::info('Broadcasting message to WebSocket: ' . json_encode($message[0]));
        broadcastMessage($message[0]);

        ApiResponse::success($message[0], 'Message sent successfully', 201);

    } catch (Exception $e) {
        Logger::error('Send message error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to send message');
    }
}

/**
 * Mark messages as read
 */
function handleMarkAsRead() {
    ApiResponse::requireMethod('POST');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['friend_user_code']);

    $userId = UserCodeHelper::getUserIdFromCode($userCode);
    $friendUserCode = $data['friend_user_code'];
    $friendUserId = UserCodeHelper::getUserIdFromCode($friendUserCode);

    try {
        $db = Database::getInstance();

        // Mark all messages from friend as read
        $db->execute(
            'UPDATE messages SET is_read = TRUE, read_at = NOW()
            WHERE recipient_user_id = ? AND sender_user_id = ? AND is_read = FALSE',
            [$userId, $friendUserId]
        );

        ApiResponse::success(null, 'Messages marked as read');

    } catch (Exception $e) {
        Logger::error('Mark messages as read error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to mark messages as read');
    }
}

/**
 * Delete a message
 */
function handleDeleteMessage() {
    ApiResponse::requireMethod('DELETE');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['message_id']);

    $messageId = (int)$data['message_id'];
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

    try {
        $db = Database::getInstance();

        // Verify message belongs to user (sender only can delete)
        $message = $db->query(
            'SELECT sender_user_id FROM messages WHERE message_id = ?',
            [$messageId]
        );

        if (empty($message)) {
            ApiResponse::notFound('Message not found');
        }

        if ($message[0]['sender_user_id'] != $userId) {
            ApiResponse::forbidden('You can only delete your own messages');
        }

        // Delete message
        $db->execute('DELETE FROM messages WHERE message_id = ?', [$messageId]);

        ApiResponse::success(null, 'Message deleted');

    } catch (Exception $e) {
        Logger::error('Delete message error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to delete message');
    }
}

/**
 * Get total unread message count
 */
function handleGetUnreadCount() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

    try {
        $db = Database::getInstance();

        $result = $db->query(
            'SELECT COUNT(*) as unread_count FROM messages
            WHERE recipient_user_id = ? AND is_read = FALSE',
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
 * Broadcast message to WebSocket server
 */
function broadcastMessage($message) {
    Logger::info('broadcastMessage called with message_id: ' . ($message['message_id'] ?? 'unknown'));

    // Send message to WebSocket server via HTTP notification endpoint
    // The WebSocket server should have an HTTP endpoint for receiving notifications

    $wsNotifyUrl = 'http://localhost:8444/notify'; // HTTP notification endpoint

    $payload = json_encode([
        'type' => 'chat_message',
        'message' => $message
    ]);

    // Use non-blocking HTTP request to avoid slowing down the API response
    $ch = curl_init($wsNotifyUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 500); // 500ms timeout - don't wait long
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 200); // 200ms connect timeout

    // Execute in non-blocking way (fire and forget)
    curl_exec($ch);
    curl_close($ch);

    // Also write to queue file as backup
    // Point to the WebSocket server's queue file in geobeasts-main
    $queueFile = 'C:/inetpub/work websites/geobeasts/geobeasts-main/backend/websocket/message_queue.json';
    $queueDir = dirname($queueFile);

    Logger::info('Queue file path: ' . $queueFile);
    Logger::info('Queue directory exists: ' . (is_dir($queueDir) ? 'yes' : 'no'));

    if (!is_dir($queueDir)) {
        mkdir($queueDir, 0755, true);
        Logger::info('Created queue directory');
    }

    $queue = [];
    if (file_exists($queueFile)) {
        $queue = json_decode(file_get_contents($queueFile), true) ?: [];
    }

    $queue[] = [
        'type' => 'chat_message',
        'message' => $message,
        'timestamp' => time()
    ];

    // Keep only last 100 messages in queue
    $queue = array_slice($queue, -100);

    $writeResult = file_put_contents($queueFile, json_encode($queue, JSON_PRETTY_PRINT));
    Logger::info('Wrote to queue file. Bytes written: ' . ($writeResult !== false ? $writeResult : 'FAILED'));
    Logger::info('Queue file now exists: ' . (file_exists($queueFile) ? 'yes' : 'no'));
}
