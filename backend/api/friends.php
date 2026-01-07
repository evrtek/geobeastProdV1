<?php
/**
 * Friend System API Endpoints
 * Handles friend codes, friend requests, friend list management
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/ApiResponse.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../core/ChildActivityLogger.php';

// Set CORS headers
ApiResponse::setCorsHeaders();

// Get request method and path
$method = ApiResponse::getMethod();
$path = $_GET['action'] ?? '';

// Log API request
Logger::logRequest('/api/friends?action=' . $path, $method);

// Route to appropriate handler
switch ($path) {
    case 'get-friend-code':
        handleGetFriendCode();
        break;
    case 'regenerate-friend-code':
        handleRegenerateFriendCode();
        break;
    case 'send-friend-request':
        handleSendFriendRequest();
        break;
    case 'get-friend-requests':
        handleGetFriendRequests();
        break;
    case 'respond-to-request':
        handleRespondToRequest();
        break;
    case 'get-friends':
        handleGetFriends();
        break;
    case 'remove-friend':
        handleRemoveFriend();
        break;
    case 'block-user':
        handleBlockUser();
        break;
    case 'unblock-user':
        handleUnblockUser();
        break;
    case 'get-blocked-users':
        handleGetBlockedUsers();
        break;
    case 'search-friends':
        handleSearchFriends();
        break;
    case 'search-users':
        handleSearchUsers();
        break;
    default:
        ApiResponse::notFound('Endpoint not found');
}

/**
 * Get user's friend code
 */
function handleGetFriendCode() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();
    $userId = $user['userCode'];

    try {
        $db = Database::getInstance();

        $result = $db->query(
            'SELECT friend_code, friend_code_generated_at FROM users WHERE check_code = ?',
            [$userId]
        );

        if (empty($result)) {
            ApiResponse::error('User not found', 404);
        }

        $userData = $result[0];

        ApiResponse::success([
            'friend_code' => $userData['friend_code'],
            'generated_at' => $userData['friend_code_generated_at']
        ], 'Friend code retrieved');

    } catch (Exception $e) {
        Logger::error('Get friend code error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve friend code');
    }
}

/**
 * Regenerate user's friend code
 */
function handleRegenerateFriendCode() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        // Generate new unique friend code
        $newFriendCode = generateUniqueFriendCode($db);

        $db->execute(
            'UPDATE users SET friend_code = ?, friend_code_generated_at = NOW() WHERE user_id = ?',
            [$newFriendCode, $userId]
        );

        ApiResponse::success([
            'friend_code' => $newFriendCode,
            'generated_at' => date('Y-m-d H:i:s')
        ], 'Friend code regenerated successfully');

    } catch (Exception $e) {
        Logger::error('Regenerate friend code error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to regenerate friend code');
    }
}

/**
 * Send friend request using friend code or user ID
 */
function handleSendFriendRequest() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    $requesterId = $user['user_id'];

    try {
        $db = Database::getInstance();

        // Find recipient by friend code or user code
        if (isset($data['friend_code'])) {
            $friendCode = Security::sanitizeInput($data['friend_code']);
            $recipientResult = $db->query(
                'SELECT user_id, username, account_type_id FROM users WHERE friend_code = ? AND active = TRUE',
                [$friendCode]
            );
        } elseif (isset($data['friend_user_code'])) {
            $friendUserCode = Security::sanitizeInput($data['friend_user_code']);
            $recipientResult = $db->query(
                'SELECT user_id, username, account_type_id FROM users WHERE check_code = ? AND active = TRUE',
                [$friendUserCode]
            );
        } else {
            ApiResponse::validationError(['friend_code' => 'Friend code or user code is required']);
        }

        if (empty($recipientResult)) {
            ApiResponse::error('User not found', 404);
        }

        $recipient = $recipientResult[0];
        $recipientId = $recipient['user_id'];

        // Can't send request to self
        if ($recipientId == $requesterId) {
            ApiResponse::error('Cannot send friend request to yourself', 400);
        }

        // Check for blocked status
        $blockCheck = $db->query(
            'SELECT block_id FROM blocks
            WHERE (blocker_user_id = ? AND blocked_user_id = ?)
               OR (blocker_user_id = ? AND blocked_user_id = ?)',
            [$requesterId, $recipientId, $recipientId, $requesterId]
        );

        if (!empty($blockCheck)) {
            ApiResponse::error('Cannot send friend request', 403);
        }

        // Check for existing friendship
        $friendshipCheck = $db->query(
            'SELECT friendship_id, status FROM friendships
            WHERE (requester_user_id = ? AND recipient_user_id = ?)
               OR (requester_user_id = ? AND recipient_user_id = ?)',
            [$requesterId, $recipientId, $recipientId, $requesterId]
        );

        if (!empty($friendshipCheck)) {
            $friendship = $friendshipCheck[0];
            if ($friendship['status'] === 'approved') {
                ApiResponse::error('You are already friends with this user', 400);
            } elseif ($friendship['status'] === 'pending') {
                ApiResponse::error('Friend request already pending', 400);
            } elseif ($friendship['status'] === 'declined') {
                ApiResponse::error('Friend request was previously declined', 400);
            }
        }

        // Check if recipient is child account (requires parent approval)
        $childAccountTypeId = $db->query(
            "SELECT account_type_id FROM account_types WHERE account_type_name = 'child'"
        )[0]['account_type_id'];

        $requiresParentApproval = ($recipient['account_type_id'] == $childAccountTypeId);

        // Create friend request
        $result = $db->execute(
            'INSERT INTO friendships (requester_user_id, recipient_user_id, status, requested_at)
            VALUES (?, ?, ?, NOW())',
            [$requesterId, $recipientId, 'pending']
        );

        $friendshipId = $result['last_insert_id'];

        // Create notification for recipient (or their parent)
        $notificationUserId = $recipientId;
        $notificationMessage = "You have a new friend request from {$user['username']}";

        if ($requiresParentApproval) {
            $parentId = $db->query(
                'SELECT parent_account_id FROM users WHERE user_id = ?',
                [$recipientId]
            )[0]['parent_account_id'];

            if ($parentId) {
                $notificationUserId = $parentId;
                $notificationMessage = "Friend request for your child {$recipient['username']} from {$user['username']}";
            }
        }

        $db->execute(
            'INSERT INTO notifications (user_id, notification_type, message, related_entity_type, related_entity_id, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())',
            [$notificationUserId, 'friend_request', $notificationMessage, 'friendship', $friendshipId]
        );

        // Log child activity if sender is a child account
        $senderAccountType = $db->query(
            "SELECT at.account_type_name FROM users u
            JOIN account_types at ON u.account_type_id = at.account_type_id
            WHERE u.user_id = ?",
            [$requesterId]
        );

        if (!empty($senderAccountType) && $senderAccountType[0]['account_type_name'] === 'child') {
            ChildActivityLogger::log($requesterId, 'friend_request_sent', [
                'recipient_id' => $recipientId,
                'recipient_username' => $recipient['username'],
                'friendship_id' => $friendshipId
            ]);
        }

        ApiResponse::success([
            'friendship_id' => $friendshipId,
            'recipient_username' => $recipient['username'],
            'status' => 'pending',
            'requires_parent_approval' => $requiresParentApproval
        ], 'Friend request sent successfully', 201);

    } catch (Exception $e) {
        Logger::error('Send friend request error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to send friend request');
    }
}

/**
 * Get pending friend requests
 */
function handleGetFriendRequests() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();
    $userId = $user['user_id'];

    $type = $_GET['type'] ?? 'received'; // 'received' or 'sent'

    try {
        $db = Database::getInstance();

        if ($type === 'received') {
            // Get requests where user is recipient
            $requests = $db->query(
                'SELECT
                    f.friendship_id,
                    f.status,
                    f.requested_at,
                    u.user_id,
                    u.username,
                    u.avatar_id as avatar_url
                FROM friendships f
                JOIN users u ON f.requester_user_id = u.user_id
                WHERE f.recipient_user_id = ? AND f.status = ?
                ORDER BY f.requested_at DESC',
                [$userId, 'pending']
            );
        } else {
            // Get requests where user is requester
            $requests = $db->query(
                'SELECT
                    f.friendship_id,
                    f.status,
                    f.requested_at,
                    u.user_id,
                    u.username,
                    u.avatar_id as avatar_url
                FROM friendships f
                JOIN users u ON f.recipient_user_id = u.user_id
                WHERE f.requester_user_id = ? AND f.status = ?
                ORDER BY f.requested_at DESC',
                [$userId, 'pending']
            );
        }

        ApiResponse::success([
            'requests' => $requests,
            'count' => count($requests)
        ], 'Friend requests retrieved');

    } catch (Exception $e) {
        Logger::error('Get friend requests error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve friend requests');
    }
}

/**
 * Respond to friend request (approve or decline)
 */
function handleRespondToRequest() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['friendship_id', 'response']);

    $friendshipId = (int)$data['friendship_id'];
    $response = Security::sanitizeInput($data['response']); // 'approve' or 'decline'
    $userId = $user['user_id'];

    if (!in_array($response, ['approve', 'decline'])) {
        ApiResponse::validationError(['response' => 'Response must be approve or decline']);
    }

    try {
        $db = Database::getInstance();

        // Get friendship details
        $friendship = $db->query(
            'SELECT
                f.friendship_id,
                f.requester_user_id,
                f.recipient_user_id,
                f.status,
                u1.username as requester_username,
                u2.username as recipient_username,
                u2.account_type_id as recipient_account_type_id,
                u2.parent_account_id
            FROM friendships f
            JOIN users u1 ON f.requester_user_id = u1.user_id
            JOIN users u2 ON f.recipient_user_id = u2.user_id
            WHERE f.friendship_id = ?',
            [$friendshipId]
        );

        if (empty($friendship)) {
            ApiResponse::notFound('Friend request not found');
        }

        $friendship = $friendship[0];

        // Verify user can respond (must be recipient or parent of child recipient)
        $canRespond = false;
        if ($friendship['recipient_user_id'] == $userId) {
            $canRespond = true;
        } elseif ($friendship['parent_account_id'] == $userId) {
            // Parent responding for child
            $canRespond = true;
        }

        if (!$canRespond) {
            ApiResponse::forbidden('You cannot respond to this friend request');
        }

        if ($friendship['status'] !== 'pending') {
            ApiResponse::error('Friend request already processed', 400);
        }

        // Update friendship status
        $newStatus = $response === 'approve' ? 'approved' : 'declined';
        $db->execute(
            'UPDATE friendships SET status = ?, responded_at = NOW() WHERE friendship_id = ?',
            [$newStatus, $friendshipId]
        );

        // Create notification for requester
        $notificationMessage = $response === 'approve'
            ? "{$friendship['recipient_username']} accepted your friend request"
            : "{$friendship['recipient_username']} declined your friend request";

        $db->execute(
            'INSERT INTO notifications (user_id, notification_type, message, related_entity_type, related_entity_id, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())',
            [
                $friendship['requester_user_id'],
                'friend_request',
                $notificationMessage,
                'friendship',
                $friendshipId
            ]
        );

        // Log child activity if responder is a child account
        $responderAccountType = $db->query(
            "SELECT at.account_type_name FROM users u
            JOIN account_types at ON u.account_type_id = at.account_type_id
            WHERE u.user_id = ?",
            [$userId]
        );

        if (!empty($responderAccountType) && $responderAccountType[0]['account_type_name'] === 'child') {
            ChildActivityLogger::log($userId, 'friend_request_' . $response . 'd', [
                'requester_id' => $friendship['requester_user_id'],
                'requester_username' => $friendship['requester_username'],
                'friendship_id' => $friendshipId
            ]);
        }

        $message = $response === 'approve'
            ? 'Friend request approved'
            : 'Friend request declined';

        ApiResponse::success([
            'friendship_id' => $friendshipId,
            'status' => $newStatus
        ], $message);

    } catch (Exception $e) {
        Logger::error('Respond to friend request error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to respond to friend request');
    }
}

/**
 * Get user's friends list
 */
function handleGetFriends() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();

    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($user['user_code']);
    //$userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $friends = $db->query(
            'SELECT
                f.friendship_id,
                f.responded_at as friends_since,
                CASE
                    WHEN f.requester_user_id = ? THEN u2.user_id
                    ELSE u1.user_id
                END as friend_user_id,
                CASE
                    WHEN f.requester_user_id = ? THEN u2.check_code
                    ELSE u1.check_code
                END as friend_user_code,
                CASE
                    WHEN f.requester_user_id = ? THEN u2.username
                    ELSE u1.username
                END as friend_username,
                CASE
                    WHEN f.requester_user_id = ? THEN u2.avatar_id
                    ELSE u1.avatar_id
                END as friend_avatar_url,
                CASE
                    WHEN f.requester_user_id = ? THEN u2.last_activity
                    ELSE u1.last_activity
                END as last_active
            FROM friendships f
            JOIN users u1 ON f.requester_user_id = u1.user_id
            JOIN users u2 ON f.recipient_user_id = u2.user_id
            WHERE (f.requester_user_id = ? OR f.recipient_user_id = ?)
            AND f.status = ?
            ORDER BY last_active DESC',
            [$userId, $userId, $userId, $userId, $userId, $userId, $userId, 'approved']
        );

        ApiResponse::success([
            'friends' => $friends,
            'count' => count($friends)
        ], 'Friends list retrieved');

    } catch (Exception $e) {
        Logger::error('Get friends error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve friends');
    }
}

/**
 * Remove a friend
 */
function handleRemoveFriend() {
    ApiResponse::requireMethod('DELETE');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['friendship_id']);

    $friendshipId = (int)$data['friendship_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        // Verify friendship exists and user is part of it
        $friendship = $db->query(
            'SELECT requester_user_id, recipient_user_id, status
            FROM friendships
            WHERE friendship_id = ?',
            [$friendshipId]
        );

        if (empty($friendship)) {
            ApiResponse::notFound('Friendship not found');
        }

        $friendship = $friendship[0];

        if ($friendship['requester_user_id'] != $userId && $friendship['recipient_user_id'] != $userId) {
            ApiResponse::forbidden('You cannot remove this friendship');
        }

        // Delete friendship
        $db->execute('DELETE FROM friendships WHERE friendship_id = ?', [$friendshipId]);

        ApiResponse::success(null, 'Friend removed successfully');

    } catch (Exception $e) {
        Logger::error('Remove friend error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to remove friend');
    }
}

/**
 * Block a user
 */
function handleBlockUser() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['blocked_user_id']);

    $blockedUserId = (int)$data['blocked_user_id'];
    $blockerId = $user['user_id'];

    if ($blockedUserId == $blockerId) {
        ApiResponse::error('Cannot block yourself', 400);
    }

    try {
        $db = Database::getInstance();

        // Verify blocked user exists
        $blockedUser = $db->query(
            'SELECT user_id, username FROM users WHERE user_id = ? AND active = TRUE',
            [$blockedUserId]
        );

        if (empty($blockedUser)) {
            ApiResponse::error('User not found', 404);
        }

        // Check if already blocked
        $existingBlock = $db->query(
            'SELECT block_id FROM blocks WHERE blocker_user_id = ? AND blocked_user_id = ?',
            [$blockerId, $blockedUserId]
        );

        if (!empty($existingBlock)) {
            ApiResponse::error('User already blocked', 400);
        }

        // Create block
        $result = $db->execute(
            'INSERT INTO blocks (blocker_user_id, blocked_user_id, blocked_at) VALUES (?, ?, NOW())',
            [$blockerId, $blockedUserId]
        );

        $blockId = $result['last_insert_id'];

        // Remove any existing friendship
        $db->execute(
            'DELETE FROM friendships
            WHERE (requester_user_id = ? AND recipient_user_id = ?)
               OR (requester_user_id = ? AND recipient_user_id = ?)',
            [$blockerId, $blockedUserId, $blockedUserId, $blockerId]
        );

        ApiResponse::success([
            'block_id' => $blockId,
            'blocked_username' => $blockedUser[0]['username']
        ], 'User blocked successfully', 201);

    } catch (Exception $e) {
        Logger::error('Block user error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to block user');
    }
}

/**
 * Unblock a user
 */
function handleUnblockUser() {
    ApiResponse::requireMethod('DELETE');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['blocked_user_id']);

    $blockedUserId = (int)$data['blocked_user_id'];
    $blockerId = $user['user_id'];

    try {
        $db = Database::getInstance();

        // Delete block
        $result = $db->execute(
            'DELETE FROM blocks WHERE blocker_user_id = ? AND blocked_user_id = ?',
            [$blockerId, $blockedUserId]
        );

        if ($result === 0) {
            ApiResponse::notFound('Block not found');
        }

        ApiResponse::success(null, 'User unblocked successfully');

    } catch (Exception $e) {
        Logger::error('Unblock user error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to unblock user');
    }
}

/**
 * Get blocked users list
 */
function handleGetBlockedUsers() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $blockedUsers = $db->query(
            'SELECT
                b.block_id,
                b.blocked_at,
                u.user_id,
                u.username,
                u.avatar_id as avatar_url
            FROM blocks b
            JOIN users u ON b.blocked_user_id = u.user_id
            WHERE b.blocker_user_id = ?
            ORDER BY b.blocked_at DESC',
            [$userId]
        );

        ApiResponse::success([
            'blocked_users' => $blockedUsers,
            'count' => count($blockedUsers)
        ], 'Blocked users retrieved');

    } catch (Exception $e) {
        Logger::error('Get blocked users error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve blocked users');
    }
}

/**
 * Search friends by username
 */
function handleSearchFriends() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();
    $userId = $user['user_id'];

    if (!isset($_GET['query'])) {
        ApiResponse::validationError(['query' => 'Search query is required']);
    }

    $query = Security::sanitizeInput($_GET['query']);

    if (strlen($query) < 2) {
        ApiResponse::validationError(['query' => 'Query must be at least 2 characters']);
    }

    try {
        $db = Database::getInstance();

        // Search within user's friends
        $friends = $db->query(
            'SELECT
                CASE
                    WHEN f.requester_user_id = ? THEN u2.user_id
                    ELSE u1.user_id
                END as friend_user_id,
                CASE
                    WHEN f.requester_user_id = ? THEN u2.username
                    ELSE u1.username
                END as friend_username,
                CASE
                    WHEN f.requester_user_id = ? THEN u2.avatar_id
                    ELSE u1.avatar_id
                END as friend_avatar_url
            FROM friendships f
            JOIN users u1 ON f.requester_user_id = u1.user_id
            JOIN users u2 ON f.recipient_user_id = u2.user_id
            WHERE (f.requester_user_id = ? OR f.recipient_user_id = ?)
            AND f.status = ?
            AND (u1.username LIKE ? OR u2.username LIKE ?)
            LIMIT 20',
            [
                $userId, $userId, $userId, $userId, $userId, 'approved',
                "%$query%", "%$query%"
            ]
        );

        ApiResponse::success([
            'friends' => $friends,
            'count' => count($friends),
            'query' => $query
        ], 'Search results retrieved');

    } catch (Exception $e) {
        Logger::error('Search friends error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to search friends');
    }
}

/**
 * Search all users by username
 */
function handleSearchUsers() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();
    $userId = $user['user_id'];

    if (!isset($_GET['query'])) {
        ApiResponse::validationError(['query' => 'Search query is required']);
    }

    $query = Security::sanitizeInput($_GET['query']);

    if (strlen($query) < 2) {
        ApiResponse::validationError(['query' => 'Query must be at least 2 characters']);
    }

    try {
        $db = Database::getInstance();

        // Search by friend code (if query looks like a friend code) or exact username
        // This prevents strangers from discovering users randomly
        $isFriendCode = (strlen($query) > 10 && strpos($query, '-') !== false);

        if ($isFriendCode) {
            // Search by friend code
            $users = $db->query(
                'SELECT
                    u.user_id,
                    u.check_code as user_code,
                    u.username,
                    u.avatar_id as avatar_url,
                    CASE
                        WHEN f.friendship_id IS NOT NULL AND f.status = ? THEN true
                        ELSE false
                    END as is_friend,
                    CASE
                        WHEN f.friendship_id IS NOT NULL AND f.status = ? THEN true
                        ELSE false
                    END as has_pending_request
                FROM users u
                LEFT JOIN friendships f ON (
                    (f.requester_user_id = ? AND f.recipient_user_id = u.user_id)
                    OR (f.requester_user_id = u.user_id AND f.recipient_user_id = ?)
                )
                LEFT JOIN blocks b ON (
                    (b.blocker_user_id = ? AND b.blocked_user_id = u.user_id)
                    OR (b.blocker_user_id = u.user_id AND b.blocked_user_id = ?)
                )
                WHERE u.active = TRUE
                AND u.user_id != ?
                AND b.block_id IS NULL
                AND u.friend_code = ?
                LIMIT 1',
                [
                    'approved', 'pending',
                    $userId, $userId,
                    $userId, $userId,
                    $userId,
                    $query
                ]
            );
        } else {
            // Search by exact username only (more secure)
            $users = $db->query(
                'SELECT
                    u.user_id,
                    u.check_code as user_code,
                    u.username,
                    u.avatar_id as avatar_url,
                    CASE
                        WHEN f.friendship_id IS NOT NULL AND f.status = ? THEN true
                        ELSE false
                    END as is_friend,
                    CASE
                        WHEN f.friendship_id IS NOT NULL AND f.status = ? THEN true
                        ELSE false
                    END as has_pending_request
                FROM users u
                LEFT JOIN friendships f ON (
                    (f.requester_user_id = ? AND f.recipient_user_id = u.user_id)
                    OR (f.requester_user_id = u.user_id AND f.recipient_user_id = ?)
                )
                LEFT JOIN blocks b ON (
                    (b.blocker_user_id = ? AND b.blocked_user_id = u.user_id)
                    OR (b.blocker_user_id = u.user_id AND b.blocked_user_id = ?)
                )
                WHERE u.active = TRUE
                AND u.user_id != ?
                AND b.block_id IS NULL
                AND u.username = ?
                LIMIT 5',
                [
                    'approved', 'pending',
                    $userId, $userId,
                    $userId, $userId,
                    $userId,
                    $query
                ]
            );
        }

        ApiResponse::success([
            'users' => $users,
            'count' => count($users),
            'query' => $query
        ], 'Search results retrieved');

    } catch (Exception $e) {
        Logger::error('Search users error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to search users');
    }
}

/**
 * Generate unique friend code
 */
function generateUniqueFriendCode($db) {
    $maxAttempts = 10;
    $attempts = 0;

    while ($attempts < $maxAttempts) {
        // Generate 8-character alphanumeric code
        $code = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 8));

        // Check if unique
        $existing = $db->query('SELECT user_id FROM users WHERE friend_code = ?', [$code]);

        if (empty($existing)) {
            return $code;
        }

        $attempts++;
    }

    throw new Exception('Failed to generate unique friend code');
}
