<?php
/**
 * User Management API Endpoints
 * Handles user profiles, parent/child accounts, settings, and parental controls
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
Logger::logRequest('/api/users?action=' . $path, $method);

// Route to appropriate handler
switch ($path) {
    case 'profile':
        handleGetProfile();
        break;
    case 'update-profile':
        handleUpdateProfile();
        break;
    case 'change-password':
        handleChangePassword();
        break;
    case 'update-settings':
        handleUpdateSettings();
        break;
    case 'get-children':
        handleGetChildren();
        break;
    case 'get-child-activity':
        handleGetChildActivity();
        break;
    case 'update-parent-controls':
        handleUpdateParentControls();
        break;
    case 'get-parent-controls':
        handleGetParentControls();
        break;
    case 'create-child-account':
        handleCreateChildAccount();
        break;
    case 'get-stamps':
        handleGetStamps();
        break;
    case 'get-my-stamps':
        handleGetMyStamps();
        break;
    case 'set-active-stamp':
        handleSetActiveStamp();
        break;
    case 'generate-custom-stamp':
        handleGenerateCustomStamp();
        break;
    case 'get-avatars':
        handleGetAvatars();
        break;
    case 'set-avatar':
        handleSetAvatar();
        break;
    case 'user-stats':
        handleGetUserStats();
        break;
    case 'get-friend-code':
        handleGetFriendCode();
        break;
    case 'regenerate-friend-code':
        handleRegenerateFriendCode();
        break;
    case 'lock-account':
        handleLockAccount();
        break;
    case 'deactivate-account':
        handleDeactivateAccount();
        break;
    default:
        ApiResponse::notFound('Endpoint not found');
}

/**
 * Get user profile
 */
function handleGetProfile() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();

    try {
        $db = Database::getInstance();

        $result = $db->callProcedure('sp_get_user_profile', [
            ':p_user_code' => $userCode
        ]);

        if (empty($result['results'][0])) {
            ApiResponse::notFound('User profile not found');
        }

        $profile = $result['results'][0][0];

        // Get active stamp (need user_id for legacy table)
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $stampResult = $db->query(
            'SELECT s.stamp_id, s.stamp_name, s.stamp_image_path, s.color
            FROM user_stamps us
            JOIN stamps s ON us.stamp_id = s.stamp_id
            WHERE us.user_id = ? AND us.is_active = TRUE
            LIMIT 1',
            [$userId]
        );

        $profile['active_stamp'] = !empty($stampResult) ? $stampResult[0] : null;

        ApiResponse::success($profile, 'Profile retrieved');

    } catch (Exception $e) {
        error_log('Get profile error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve profile');
    }
}

/**
 * Update user profile
 */
function handleUpdateProfile() {
    ApiResponse::requireMethod('PUT');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    $allowedFields = ['given_name', 'surname', 'battle_color'];
    $updates = [];
    $params = [];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $value = Security::sanitizeInput($data[$field]);

            // Validate battle_color format
            if ($field === 'battle_color' && !preg_match('/^#[0-9A-F]{6}$/i', $value)) {
                ApiResponse::validationError(['battle_color' => 'Invalid color format. Use #RRGGBB']);
            }

            $updates[] = "$field = ?";
            $params[] = $value;
        }
    }

    if (empty($updates)) {
        ApiResponse::validationError(['fields' => 'No valid fields to update']);
    }

    try {
        $db = Database::getInstance();

        $params[] = $userCode;
        $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE check_code = ?';

        $db->execute($sql, $params);

        ApiResponse::success(null, 'Profile updated successfully');

    } catch (Exception $e) {
        error_log('Update profile error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to update profile');
    }
}

/**
 * Change password
 */
function handleChangePassword() {
    ApiResponse::requireMethod('POST');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['current_password', 'new_password']);

    $currentPassword = $data['current_password'];
    $newPassword = $data['new_password'];

    try {
        $db = Database::getInstance();

        // Verify current password
        $userResult = $db->query(
            'SELECT password_hash FROM users WHERE check_code = ?',
            [$userCode]
        );

        if (empty($userResult)) {
            ApiResponse::error('User not found', 404);
        }

        if (!Security::verifyPassword($currentPassword, $userResult[0]['password_hash'])) {
            ApiResponse::error('Current password is incorrect', 401);
        }

        // Validate new password strength
        $validation = Security::validatePasswordStrength($newPassword);
        if (!$validation['valid']) {
            ApiResponse::validationError(['new_password' => $validation['message']]);
        }

        // Hash new password
        $newPasswordHash = Security::hashPassword($newPassword);

        // Update password
        $db->execute(
            'UPDATE users SET password_hash = ? WHERE check_code = ?',
            [$newPasswordHash, $userCode]
        );

        ApiResponse::success(null, 'Password changed successfully');

    } catch (Exception $e) {
        error_log('Change password error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to change password');
    }
}

/**
 * Update user settings
 */
function handleUpdateSettings() {
    ApiResponse::requireMethod('PUT');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    // Settings that can be updated
    $settings = [];

    if (isset($data['battle_color'])) {
        $battleColor = Security::sanitizeInput($data['battle_color']);
        if (!preg_match('/^#[0-9A-F]{6}$/i', $battleColor)) {
            ApiResponse::validationError(['battle_color' => 'Invalid color format']);
        }
        $settings['battle_color'] = $battleColor;
    }

    if (empty($settings)) {
        ApiResponse::error('No settings to update', 400);
    }

    try {
        $db = Database::getInstance();

        foreach ($settings as $key => $value) {
            $db->execute(
                "UPDATE users SET $key = ? WHERE check_code = ?",
                [$value, $userCode]
            );
        }

        ApiResponse::success($settings, 'Settings updated');

    } catch (Exception $e) {
        error_log('Update settings error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to update settings');
    }
}

/**
 * Get parent's child accounts
 */
function handleGetChildren() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();

    try {
        $db = Database::getInstance();

        // Need user_id for legacy table lookup
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $children = $db->query(
            'SELECT
                u.check_code as user_code,
                u.user_id,
                u.username,
                u.given_name,
                u.surname,
                u.email,
                u.dob,
                u.active,
                u.created_at,
                pc.allow_mode2_battles,
                pc.allow_mode3_battles,
                pc.require_friend_approval,
                pc.require_marketplace_approval
            FROM users u
            LEFT JOIN parent_controls pc ON u.user_id = pc.child_user_id
            WHERE u.parent_account_id = ?
            ORDER BY u.created_at DESC',
            [$userId]
        );

        ApiResponse::success(['children' => $children], 'Child accounts retrieved');

    } catch (Exception $e) {
        error_log('Get children error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve child accounts');
    }
}

/**
 * Get child activity log
 */
function handleGetChildActivity() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();

    // Accept both child_user_code (new) and child_user_id (legacy) for backwards compatibility
    if (!isset($_GET['child_user_code']) && !isset($_GET['child_user_id'])) {
        ApiResponse::validationError(['child_user_code' => 'Child user code is required']);
    }

    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    try {
        $db = Database::getInstance();

        // Need user_id for legacy table lookup
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        // Get child user_id from either user_code or user_id parameter
        if (isset($_GET['child_user_code'])) {
            $childUserCode = Security::sanitizeInput($_GET['child_user_code']);
            $childUserId = UserCodeHelper::getUserIdFromCode($childUserCode);
        } else {
            $childUserId = (int)$_GET['child_user_id'];
        }

        // Verify this is user's child
        $childCheck = $db->query(
            'SELECT parent_account_id FROM users WHERE user_id = ?',
            [$childUserId]
        );

        if (empty($childCheck) || $childCheck[0]['parent_account_id'] != $userId) {
            ApiResponse::forbidden('Not authorized to view this child\'s activity');
        }

        $activities = $db->query(
            'SELECT
                cal.activity_id,
                cal.activity_type,
                cal.activity_description,
                cal.activity_datetime,
                u.username as related_username
            FROM child_activity_log cal
            LEFT JOIN users u ON cal.related_user_id = u.user_id
            WHERE cal.child_user_id = ?
            ORDER BY cal.activity_datetime DESC
            LIMIT ? OFFSET ?',
            [$childUserId, $limit, $offset]
        );

        $totalCount = $db->query(
            'SELECT COUNT(*) as total FROM child_activity_log WHERE child_user_id = ?',
            [$childUserId]
        )[0]['total'];

        ApiResponse::success([
            'activities' => $activities,
            'pagination' => [
                'total' => (int)$totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $totalCount
            ]
        ], 'Child activity retrieved');

    } catch (Exception $e) {
        error_log('Get child activity error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve child activity');
    }
}

/**
 * Update parent controls for child account
 */
function handleUpdateParentControls() {
    ApiResponse::requireMethod('PUT');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    // Accept both child_user_code (new) and child_user_id (legacy)
    if (!isset($data['child_user_code']) && !isset($data['child_user_id'])) {
        ApiResponse::requireFields($data, ['child_user_code']);
    }

    try {
        $db = Database::getInstance();

        // Need user_id for legacy table
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        // Get child user_id from either user_code or user_id parameter
        if (isset($data['child_user_code'])) {
            $childUserCode = Security::sanitizeInput($data['child_user_code']);
            $childUserId = UserCodeHelper::getUserIdFromCode($childUserCode);
        } else {
            $childUserId = (int)$data['child_user_id'];
        }

        // Verify this is user's child
        $childCheck = $db->query(
            'SELECT parent_account_id FROM users WHERE user_id = ?',
            [$childUserId]
        );

        if (empty($childCheck) || $childCheck[0]['parent_account_id'] != $userId) {
            ApiResponse::forbidden('Not authorized to manage this child account');
        }

        // Update controls
        $updates = [];
        $params = [];

        if (isset($data['allow_mode2_battles'])) {
            $updates[] = 'allow_mode2_battles = ?';
            $params[] = (bool)$data['allow_mode2_battles'];
        }

        if (isset($data['allow_mode3_battles'])) {
            $updates[] = 'allow_mode3_battles = ?';
            $params[] = (bool)$data['allow_mode3_battles'];
        }

        if (isset($data['require_friend_approval'])) {
            $updates[] = 'require_friend_approval = ?';
            $params[] = (bool)$data['require_friend_approval'];
        }

        if (isset($data['require_marketplace_approval'])) {
            $updates[] = 'require_marketplace_approval = ?';
            $params[] = (bool)$data['require_marketplace_approval'];
        }

        if (empty($updates)) {
            ApiResponse::error('No controls to update', 400);
        }

        $params[] = $childUserId;
        $params[] = $userId;

        $sql = 'UPDATE parent_controls SET ' . implode(', ', $updates) .
               ' WHERE child_user_id = ? AND parent_user_id = ?';

        $db->execute($sql, $params);

        ApiResponse::success(null, 'Parent controls updated');

    } catch (Exception $e) {
        error_log('Update parent controls error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to update parent controls');
    }
}

/**
 * Get parent controls for child account
 */
function handleGetParentControls() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();

    // Accept both child_user_code (new) and child_user_id (legacy)
    if (!isset($_GET['child_user_code']) && !isset($_GET['child_user_id'])) {
        ApiResponse::validationError(['child_user_code' => 'Child user code is required']);
    }

    try {
        $db = Database::getInstance();

        // Need user_id for legacy table
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        // Get child user_id from either user_code or user_id parameter
        if (isset($_GET['child_user_code'])) {
            $childUserCode = Security::sanitizeInput($_GET['child_user_code']);
            $childUserId = UserCodeHelper::getUserIdFromCode($childUserCode);
        } else {
            $childUserId = (int)$_GET['child_user_id'];
        }

        // Verify this is user's child
        $childCheck = $db->query(
            'SELECT parent_account_id FROM users WHERE user_id = ?',
            [$childUserId]
        );

        if (empty($childCheck) || $childCheck[0]['parent_account_id'] != $userId) {
            ApiResponse::forbidden('Not authorized to view this child\'s controls');
        }

        $controls = $db->query(
            'SELECT * FROM parent_controls WHERE child_user_id = ? AND parent_user_id = ?',
            [$childUserId, $userId]
        );

        if (empty($controls)) {
            ApiResponse::notFound('Parent controls not found');
        }

        ApiResponse::success($controls[0], 'Parent controls retrieved');

    } catch (Exception $e) {
        error_log('Get parent controls error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve parent controls');
    }
}

/**
 * Create child account
 */
function handleCreateChildAccount() {
    ApiResponse::requireMethod('POST');

    $parentUserCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['username', 'given_name', 'surname', 'email', 'password', 'dob']);

    $username = Security::sanitizeInput($data['username']);
    $givenName = Security::sanitizeInput($data['given_name']);
    $surname = Security::sanitizeInput($data['surname']);
    $email = Security::sanitizeInput($data['email']);
    $password = $data['password'];
    $dob = Security::sanitizeInput($data['dob']);

    try {
        $db = Database::getInstance();

        // Validate inputs
        if (!Security::validateUsername($username)) {
            ApiResponse::validationError(['username' => 'Invalid username format']);
        }

        if (!Security::validateEmail($email)) {
            ApiResponse::validationError(['email' => 'Invalid email format']);
        }

        $passwordValidation = Security::validatePasswordStrength($password);
        if (!$passwordValidation['valid']) {
            ApiResponse::validationError(['password' => $passwordValidation['message']]);
        }

        // Hash password
        $passwordHash = Security::hashPassword($password);

        // Register child account
        $result = $db->callProcedure('sp_register_user', [
            ':p_username' => $username,
            ':p_given_name' => $givenName,
            ':p_surname' => $surname,
            ':p_email' => $email,
            ':p_password_hash' => $passwordHash,
            ':p_dob' => $dob,
            ':p_account_type_name' => 'child',
            ':p_parent_user_code' => $parentUserCode
        ], ['p_user_code', 'p_error_message']);

        $output = $result['output'];

        if (!empty($output['p_error_message'])) {
            ApiResponse::error($output['p_error_message'], 400);
        }

        $childUserCode = $output['p_user_code'];

        ApiResponse::success([
            'user_code' => $childUserCode,
            'username' => $username
        ], 'Child account created successfully', 201);

    } catch (Exception $e) {
        error_log('Create child account error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to create child account');
    }
}

/**
 * Get available stamps
 */
function handleGetStamps() {
    ApiResponse::requireMethod('GET');

    ApiResponse::requireAuth();

    try {
        $db = Database::getInstance();

        $stamps = $db->query(
            'SELECT stamp_id, stamp_name, stamp_image_path, is_premium, color
            FROM stamps
            ORDER BY is_premium ASC, stamp_name ASC'
        );

        ApiResponse::success(['stamps' => $stamps], 'Stamps retrieved');

    } catch (Exception $e) {
        error_log('Get stamps error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve stamps');
    }
}

/**
 * Set active stamp
 */
function handleSetActiveStamp() {
    ApiResponse::requireMethod('POST');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['stamp_id']);

    $stampId = (int)$data['stamp_id'];

    try {
        $db = Database::getInstance();

        // Need user_id for legacy table
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        // Verify stamp exists
        $stampCheck = $db->query('SELECT stamp_id FROM stamps WHERE stamp_id = ?', [$stampId]);

        if (empty($stampCheck)) {
            ApiResponse::notFound('Stamp not found');
        }

        $db->beginTransaction();

        try {
            // Deactivate current stamp
            $db->execute(
                'UPDATE user_stamps SET is_active = FALSE WHERE user_id = ?',
                [$userId]
            );

            // Check if user already has this stamp
            $userStampCheck = $db->query(
                'SELECT user_stamp_id FROM user_stamps WHERE user_id = ? AND stamp_id = ?',
                [$userId, $stampId]
            );

            if (empty($userStampCheck)) {
                // Add stamp to user
                $db->execute(
                    'INSERT INTO user_stamps (user_id, stamp_id, is_active) VALUES (?, ?, TRUE)',
                    [$userId, $stampId]
                );
            } else {
                // Activate existing stamp
                $db->execute(
                    'UPDATE user_stamps SET is_active = TRUE WHERE user_id = ? AND stamp_id = ?',
                    [$userId, $stampId]
                );
            }

            $db->commit();

            ApiResponse::success(null, 'Active stamp updated');

        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }

    } catch (Exception $e) {
        error_log('Set active stamp error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to set active stamp');
    }
}

/**
 * Get available avatars
 */
function handleGetAvatars() {
    ApiResponse::requireMethod('GET');

    ApiResponse::requireAuth();

    try {
        $db = Database::getInstance();

        $avatars = $db->query(
            'SELECT avatar_id, avatar_name, avatar_image_path FROM avatars ORDER BY avatar_name'
        );

        ApiResponse::success(['avatars' => $avatars], 'Avatars retrieved');

    } catch (Exception $e) {
        error_log('Get avatars error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve avatars');
    }
}

/**
 * Set user avatar
 */
function handleSetAvatar() {
    ApiResponse::requireMethod('POST');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['avatar_id']);

    $avatarId = (int)$data['avatar_id'];

    try {
        $db = Database::getInstance();

        // Verify avatar exists
        $avatarCheck = $db->query('SELECT avatar_id FROM avatars WHERE avatar_id = ?', [$avatarId]);

        if (empty($avatarCheck)) {
            ApiResponse::notFound('Avatar not found');
        }

        // Update user avatar
        $db->execute(
            'UPDATE users SET avatar_id = ? WHERE check_code = ?',
            [$avatarId, $userCode]
        );

        ApiResponse::success(null, 'Avatar updated');

    } catch (Exception $e) {
        error_log('Set avatar error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to set avatar');
    }
}

/**
 * Get user statistics
 */
function handleGetUserStats() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();

    try {
        $db = Database::getInstance();

        // Need user_id for legacy tables
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        // Get battle stats
        $battleStats = $db->query(
            'SELECT * FROM user_battle_stats WHERE user_id = ?',
            [$userId]
        );

        $stats = !empty($battleStats) ? $battleStats[0] : [
            'total_battles' => 0,
            'total_wins' => 0,
            'total_losses' => 0,
            'mode1_battles' => 0,
            'mode2_battles' => 0,
            'mode3_battles' => 0
        ];

        // Get card stats
        $cardStats = $db->query(
            'SELECT
                COUNT(*) as total_cards,
                SUM(times_battled) as total_card_battles,
                SUM(wins) as total_card_wins,
                SUM(losses) as total_card_losses
            FROM user_cards
            WHERE user_id = ?',
            [$userId]
        );

        $stats['card_stats'] = !empty($cardStats) ? $cardStats[0] : [
            'total_cards' => 0,
            'total_card_battles' => 0,
            'total_card_wins' => 0,
            'total_card_losses' => 0
        ];

        // Get trade stats
        $tradeStats = $db->query(
            'SELECT
                COUNT(*) as total_trades,
                SUM(CASE WHEN trade_status = ? THEN 1 ELSE 0 END) as completed_trades
            FROM trades
            WHERE initiator_user_id = ? OR recipient_user_id = ?',
            ['completed', $userId, $userId]
        );

        $stats['trade_stats'] = !empty($tradeStats) ? $tradeStats[0] : [
            'total_trades' => 0,
            'completed_trades' => 0
        ];

        // Calculate win rate
        $stats['win_rate'] = $stats['total_battles'] > 0
            ? round(($stats['total_wins'] / $stats['total_battles']) * 100, 2)
            : 0;

        ApiResponse::success($stats, 'User statistics retrieved');

    } catch (Exception $e) {
        Logger::error('Get user stats error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve user statistics');
    }
}

/**
 * Get user's owned stamps
 */
function handleGetMyStamps() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();

    try {
        $db = Database::getInstance();

        // Need user_id for legacy table
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $stamps = $db->query(
            'SELECT
                s.stamp_id,
                s.stamp_name,
                s.stamp_image_path,
                s.is_premium,
                s.color,
                us.is_active,
                us.acquired_at
            FROM user_stamps us
            JOIN stamps s ON us.stamp_id = s.stamp_id
            WHERE us.user_id = ?
            ORDER BY us.is_active DESC, s.stamp_name ASC',
            [$userId]
        );

        ApiResponse::success(['stamps' => $stamps], 'User stamps retrieved');

    } catch (Exception $e) {
        Logger::error('Get my stamps error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve user stamps');
    }
}

/**
 * Generate custom stamp using AI (placeholder)
 */
function handleGenerateCustomStamp() {
    ApiResponse::requireMethod('POST');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['prompt']);

    $prompt = Security::sanitizeInput($data['prompt']);

    // Validate prompt length
    if (strlen($prompt) < 3 || strlen($prompt) > 200) {
        ApiResponse::validationError(['prompt' => 'Prompt must be between 3 and 200 characters']);
    }

    try {
        $db = Database::getInstance();

        // Need user_id for legacy table
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        // Check user credits (5 credits for custom stamp)
        $creditCheck = $db->query('SELECT credits FROM users WHERE check_code = ?', [$userCode]);
        $credits = (float)$creditCheck[0]['credits'];

        if ($credits < 5) {
            ApiResponse::error('Insufficient credits. Custom stamps cost 5 credits.', 402);
        }

        // TODO: Integrate with OpenAI DALL-E for actual stamp generation
        // For now, return a placeholder response

        // Deduct credits
        $db->execute(
            'UPDATE users SET credits = credits - 5 WHERE check_code = ?',
            [$userCode]
        );

        // Log transaction
        $db->execute(
            'INSERT INTO credit_transactions (user_id, transaction_type, amount, balance_before, balance_after, description)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, 'stamp_generation', -5, $credits, $credits - 5, 'Custom stamp generation: ' . substr($prompt, 0, 50)]
        );

        Logger::info('Custom stamp generation requested', ['user_id' => $userId, 'prompt' => $prompt]);

        // Placeholder response - in production this would return the generated stamp
        ApiResponse::success([
            'status' => 'pending',
            'message' => 'Stamp generation initiated. This feature will be available soon.',
            'credits_remaining' => $credits - 5
        ], 'Stamp generation request submitted');

    } catch (Exception $e) {
        Logger::error('Generate custom stamp error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to generate custom stamp');
    }
}

/**
 * Get user's friend code
 */
function handleGetFriendCode() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();

    try {
        $db = Database::getInstance();

        // Need user_id for legacy table
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        // Get existing friend code
        $codeResult = $db->query(
            'SELECT friend_code, code_expires_at FROM user_friend_codes WHERE user_id = ? AND code_expires_at > NOW()',
            [$userId]
        );

        if (!empty($codeResult)) {
            ApiResponse::success([
                'friend_code' => $codeResult[0]['friend_code'],
                'expires_at' => $codeResult[0]['code_expires_at']
            ], 'Friend code retrieved');
        }

        // Generate new code
        $newCode = Security::generateSecureCode(8);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $db->execute(
            'INSERT INTO user_friend_codes (user_id, friend_code, code_expires_at)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE friend_code = ?, code_expires_at = ?',
            [$userId, $newCode, $expiresAt, $newCode, $expiresAt]
        );

        ApiResponse::success([
            'friend_code' => $newCode,
            'expires_at' => $expiresAt
        ], 'Friend code generated');

    } catch (Exception $e) {
        Logger::error('Get friend code error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to get friend code');
    }
}

/**
 * Regenerate friend code
 */
function handleRegenerateFriendCode() {
    ApiResponse::requireMethod('POST');

    $userCode = Security::requireAuth();

    try {
        $db = Database::getInstance();

        // Need user_id for legacy table
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        // Generate new code
        $newCode = Security::generateSecureCode(8);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $db->execute(
            'INSERT INTO user_friend_codes (user_id, friend_code, code_expires_at)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE friend_code = ?, code_expires_at = ?',
            [$userId, $newCode, $expiresAt, $newCode, $expiresAt]
        );

        Logger::info('Friend code regenerated', ['user_id' => $userId]);

        ApiResponse::success([
            'friend_code' => $newCode,
            'expires_at' => $expiresAt
        ], 'Friend code regenerated');

    } catch (Exception $e) {
        Logger::error('Regenerate friend code error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to regenerate friend code');
    }
}

/**
 * Lock own account (temporary self-lock)
 */
function handleLockAccount() {
    ApiResponse::requireMethod('POST');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['password', 'lock_duration_hours']);

    $password = $data['password'];
    $lockDurationHours = (int)$data['lock_duration_hours'];

    // Validate lock duration (1 hour to 720 hours / 30 days)
    if ($lockDurationHours < 1 || $lockDurationHours > 720) {
        ApiResponse::validationError(['lock_duration_hours' => 'Lock duration must be between 1 and 720 hours']);
    }

    try {
        $db = Database::getInstance();

        // Need user_id for logging
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        // Verify password
        $userResult = $db->query('SELECT password_hash FROM users WHERE check_code = ?', [$userCode]);

        if (!Security::verifyPassword($password, $userResult[0]['password_hash'])) {
            ApiResponse::error('Incorrect password', 401);
        }

        // Lock the account
        $lockedUntil = date('Y-m-d H:i:s', strtotime("+$lockDurationHours hours"));

        $db->execute(
            'UPDATE users SET locked_until = ?, active = FALSE WHERE check_code = ?',
            [$lockedUntil, $userCode]
        );

        // Delete auth cookie
        Security::deleteAuthCookie();

        Logger::info('Account self-locked', ['user_id' => $userId, 'locked_until' => $lockedUntil]);

        ApiResponse::success([
            'locked_until' => $lockedUntil
        ], 'Account has been locked');

    } catch (Exception $e) {
        Logger::error('Lock account error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to lock account');
    }
}

/**
 * Deactivate account (permanent)
 */
function handleDeactivateAccount() {
    ApiResponse::requireMethod('POST');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['password', 'confirmation']);

    $password = $data['password'];
    $confirmation = $data['confirmation'];

    // Require explicit confirmation
    if ($confirmation !== 'DEACTIVATE MY ACCOUNT') {
        ApiResponse::validationError(['confirmation' => 'Please type "DEACTIVATE MY ACCOUNT" to confirm']);
    }

    try {
        $db = Database::getInstance();

        // Need user_id for legacy tables
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        // Verify password
        $userResult = $db->query('SELECT password_hash, account_type_id FROM users WHERE check_code = ?', [$userCode]);

        if (!Security::verifyPassword($password, $userResult[0]['password_hash'])) {
            ApiResponse::error('Incorrect password', 401);
        }

        // Check if this is a parent account with active children
        $childCheck = $db->query(
            'SELECT COUNT(*) as count FROM users WHERE parent_account_id = ? AND active = TRUE',
            [$userId]
        );

        if ((int)$childCheck[0]['count'] > 0) {
            ApiResponse::error('Cannot deactivate account with active child accounts. Please deactivate child accounts first.', 400);
        }

        // Deactivate the account
        $db->execute(
            'UPDATE users SET active = FALSE, deactivated_at = NOW() WHERE check_code = ?',
            [$userCode]
        );

        // Remove any marketplace listings
        $db->execute(
            'UPDATE marketplace_listings SET listing_status = ? WHERE seller_user_id = ? AND listing_status = ?',
            ['cancelled', $userId, 'active']
        );

        // Cancel any pending trades
        $db->execute(
            'UPDATE trades SET trade_status = ? WHERE (initiator_user_id = ? OR recipient_user_id = ?) AND trade_status IN (?, ?, ?)',
            ['cancelled', $userId, $userId, 'proposed', 'counter_offered', 'accepted']
        );

        // Delete auth cookie
        Security::deleteAuthCookie();

        Logger::logSecurity('Account deactivated', ['user_id' => $userId]);

        ApiResponse::success(null, 'Account has been deactivated. We are sorry to see you go.');

    } catch (Exception $e) {
        Logger::error('Deactivate account error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to deactivate account');
    }
}
