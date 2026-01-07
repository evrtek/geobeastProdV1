<?php
/**
 * Authentication API Endpoints
 * Handles user registration, login, logout, and email verification
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
Logger::logRequest('/api/auth?action=' . $path, $method);

// Route to appropriate handler
switch ($path) {
    case 'register':
        handleRegister();
        break;
    case 'login':
        handleLogin();
        break;
    case 'logout':
        handleLogout();
        break;
    case 'verify-email':
        handleVerifyEmail();
        break;
    case 'check-auth':
        handleCheckAuth();
        break;
    case 'forgot-password':
        handleForgotPassword();
        break;
    case 'reset-password':
        handleResetPassword();
        break;
    case 'resend-verification':
        handleResendVerification();
        break;
    default:
        ApiResponse::notFound('Endpoint not found');
}

/**
 * Handle user registration
 */
function handleRegister() {
    ApiResponse::requireMethod('POST');

    $data = ApiResponse::getJsonBody();

    // Validate required fields
    $required = ['username', 'given_name', 'surname', 'email', 'password', 'dob', 'account_type'];
    ApiResponse::requireFields($data, $required);

    // Sanitize inputs
    $username = Security::sanitizeInput($data['username']);
    $givenName = Security::sanitizeInput($data['given_name']);
    $surname = Security::sanitizeInput($data['surname']);
    $email = Security::sanitizeInput($data['email']);
    $password = $data['password']; // Don't sanitize password
    $dob = Security::sanitizeInput($data['dob']);
    $accountType = Security::sanitizeInput($data['account_type']);
    $parentAccountId = isset($data['parent_account_id']) ? (int)$data['parent_account_id'] : null;
    $recaptchaToken = $data['recaptcha_token'] ?? null;

    // Validate inputs
    $errors = [];

    if (!Security::validateUsername($username)) {
        $errors['username'] = 'Username must be 3-50 characters (letters, numbers, underscore, hyphen only)';
    }

    if (!Security::validateEmail($email)) {
        $errors['email'] = 'Invalid email format';
    }

    $passwordValidation = Security::validatePasswordStrength($password);
    if (!$passwordValidation['valid']) {
        $errors['password'] = $passwordValidation['message'];
    }

    // Validate DOB format
    $dobTimestamp = strtotime($dob);
    if (!$dobTimestamp) {
        $errors['dob'] = 'Invalid date of birth format (use YYYY-MM-DD)';
    }

    // Check if user is under 16
    $age = (time() - $dobTimestamp) / (365.25 * 24 * 60 * 60);
    if ($age < 16 && $accountType !== 'child') {
        $errors['account_type'] = 'Users under 16 must register as child accounts';
    }

    if ($age < 16 && !$parentAccountId) {
        $errors['parent_account_id'] = 'Child accounts require a parent account ID';
    }

    // Verify reCAPTCHA
    if ($recaptchaToken && !Security::verifyRecaptcha($recaptchaToken)) {
        $errors['recaptcha'] = 'reCAPTCHA verification failed';
    }

    if (!empty($errors)) {
        ApiResponse::validationError($errors);
    }

    // Hash password
    $passwordHash = Security::hashPassword($password);

    // Convert parent_account_id to parent_user_code if provided
        $parentUserCode = null;
        if ($parentAccountId) {
            require_once __DIR__ . '/../core/UserCodeHelper.php';
            $parentUserCode = UserCodeHelper::getUserCodeFromId($parentAccountId);
            if (!$parentUserCode) {
                ApiResponse::error('Parent account not found', 400);
            }
        }

        $result = $db->callProcedure('sp_register_user', [
            ':p_username' => $username,
            ':p_given_name' => $givenName,
            ':p_surname' => $surname,
            ':p_email' => $email,
            ':p_password_hash' => $passwordHash,
            ':p_dob' => $dob,
            ':p_account_type_name' => $accountType,
            ':p_parent_user_code' => $parentUserCode
        ], ['p_user_code', 'p_error_message']);

        $output = $result['output'];

        if (!empty($output['p_error_message'])) {
            ApiResponse::error($output['p_error_message'], 400);
        }

        $userCode = $output['p_user_code'];

        // Create email verification token (need user_id for legacy table)
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $verificationToken = Security::generateToken();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $db->execute(
            'INSERT INTO email_verification_tokens (user_id, token, expires_at) VALUES (?, ?, ?)',
            [$userId, $verificationToken, $expiresAt]
        );

        // TODO: Send verification email
        // sendVerificationEmail($email, $verificationToken);

        // Create auth cookie
        Security::createAuthCookie($userCode, $username, $givenName, $accountType);

        ApiResponse::success([
            'user_code' => $userCode,
            'username' => $username,
            'given_name' => $givenName,
            'email' => $email,
            'account_type' => $accountType,
            'account_type_name' => $accountType
        ], 'Registration successful! Please check your email to verify your account.', 201);
    }
/**
 * Handle user login
 */
function handleLogin() {
       // Get user details
        $result = $db->callProcedure('sp_user_login', [
            ':p_username_or_email' => $usernameOrEmail
        ], ['p_user_code', 'p_username', 'p_given_name', 'p_password_hash', 'p_active', 'p_confirmed', 'p_login_attempts', 'p_error_message']);

        $output = $result['output'];

        if (!empty($output['p_error_message'])) {
            ApiResponse::error($output['p_error_message'], 404);
        }

        $userCode = $output['p_user_code'];
        $username = $output['p_username'];
        $givenName = $output['p_given_name'];
        $passwordHash = $output['p_password_hash'];
        $active = (bool)$output['p_active'];
        $confirmed = (bool)$output['p_confirmed'];
        $loginAttempts = (int)$output['p_login_attempts'];

        // Check if account is locked
        if ($loginAttempts >= 5) {
            ApiResponse::error('Account locked due to too many failed login attempts. Please contact support.', 423);
        }

        // Check if account is active
        if (!$active) {
            ApiResponse::error('Account is inactive. Please contact support.', 403);
        }

        // Verify password
        if (!Security::verifyPassword($password, $passwordHash)) {
            // Increment login attempts
            $db->callProcedure('sp_update_login_attempts', [
                ':p_user_code' => $userCode,
                ':p_login_attempts' => $loginAttempts + 1
            ]);

            ApiResponse::error('Invalid credentials', 401);
        }

        // Reset login attempts and update online status
        $db->callProcedure('sp_update_login_attempts', [
            ':p_user_code' => $userCode,
            ':p_login_attempts' => 0
        ]);

        // Get full user profile including account_type
        $profileResult = $db->callProcedure('sp_get_user_profile', [
            ':p_user_code' => $userCode
        ]);

        $profile = !empty($profileResult['results'][0][0]) ? $profileResult['results'][0][0] : null;

        if (!$profile) {
            ApiResponse::error('Failed to load user profile', 500);
        }

        $accountTypeName = $profile['account_type_name'] ?? null;

        // Create auth cookie
        Security::createAuthCookie($userCode, $username, $givenName, $accountTypeName);

        ApiResponse::success($profile, 'Login successful');    
}

/**
 * Handle user logout
 */
function handleLogout() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::getCurrentUser();

    if ($user) {
        try {
            $db = Database::getInstance();

            // Update user status to offline
            $db->callProcedure('sp_update_online_status', [
                ':p_user_code' => $user['user_code'],
                ':p_status' => 'offline'
            ]);
        } catch (Exception $e) {
            error_log('Logout status update error: ' . $e->getMessage());
        }
    }

    // Delete auth cookie
    Security::deleteAuthCookie();

    ApiResponse::success(null, 'Logout successful');
}

/**
 * Handle email verification
 */
function handleVerifyEmail() {
    ApiResponse::requireMethod('POST');

    $data = ApiResponse::getJsonBody();
    ApiResponse::requireFields($data, ['token']);

    $token = Security::sanitizeInput($data['token']);

    try {
        $db = Database::getInstance();

        // Get token details
        $tokenData = $db->query(
            'SELECT user_id, expires_at, used FROM email_verification_tokens WHERE token = ?',
            [$token]
        );

        if (empty($tokenData)) {
            ApiResponse::error('Invalid verification token', 400);
        }

        $tokenInfo = $tokenData[0];

        if ($tokenInfo['used']) {
            ApiResponse::error('Verification token already used', 400);
        }

        if (strtotime($tokenInfo['expires_at']) < time()) {
            ApiResponse::error('Verification token expired', 400);
        }

        // Mark user as confirmed
        $db->execute('UPDATE users SET confirmed = TRUE WHERE user_id = ?', [$tokenInfo['user_id']]);

        // Mark token as used
        $db->execute('UPDATE email_verification_tokens SET used = TRUE WHERE token = ?', [$token]);

        ApiResponse::success(null, 'Email verified successfully');

    } catch (Exception $e) {
        error_log('Email verification error: ' . $e->getMessage());
        ApiResponse::serverError('Email verification failed');
    }
}

/**
 * Check if user is authenticated
 */
function handleCheckAuth() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::getCurrentUser();

    if ($user) {
        try {
            $db = Database::getInstance();

            // Get full user profile
            $result = $db->callProcedure('sp_get_user_profile', [
                ':p_user_code' => $user['user_code']
            ]);

            if (!empty($result['results'][0])) {
                $profile = $result['results'][0][0];
                ApiResponse::success($profile, 'Authenticated');
            } else {
                ApiResponse::unauthorized();
            }
        } catch (Exception $e) {
            Logger::error('Check auth error: ' . $e->getMessage());
            ApiResponse::unauthorized();
        }
    } else {
        ApiResponse::unauthorized();
    }
}

/**
 * Handle forgot password request
 */
function handleForgotPassword() {
    ApiResponse::requireMethod('POST');

    $data = ApiResponse::getJsonBody();
    ApiResponse::requireFields($data, ['email']);

    $email = Security::sanitizeInput($data['email']);

    // Rate limiting
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!Security::checkRateLimit('forgot_password_' . $clientIp, 3, 3600)) {
        ApiResponse::error('Too many password reset requests. Please try again later.', 429);
    }

    try {
        $db = Database::getInstance();

        // Check if email exists
        $userResult = $db->query(
            'SELECT user_id, username, email FROM users WHERE email = ? AND active = TRUE',
            [$email]
        );

        // Always return success to prevent email enumeration
        if (empty($userResult)) {
            Logger::info('Password reset requested for non-existent email', ['email' => $email]);
            ApiResponse::success(null, 'If an account exists with this email, a password reset link has been sent.');
        }

        $user = $userResult[0];

        // Generate reset token
        $resetToken = Security::generateToken();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // Invalidate any existing tokens
        $db->execute(
            'UPDATE password_reset_tokens SET used = TRUE WHERE user_id = ? AND used = FALSE',
            [$user['user_id']]
        );

        // Create new token
        $db->execute(
            'INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)',
            [$user['user_id'], $resetToken, $expiresAt]
        );

        // TODO: Send password reset email
        // EmailService::sendPasswordResetEmail($user['email'], $resetToken);

        Logger::info('Password reset token generated', ['user_id' => $user['user_id']]);

        ApiResponse::success(null, 'If an account exists with this email, a password reset link has been sent.');

    } catch (Exception $e) {
        Logger::error('Forgot password error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to process password reset request');
    }
}

/**
 * Handle password reset
 */
function handleResetPassword() {
    ApiResponse::requireMethod('POST');

    $data = ApiResponse::getJsonBody();
    ApiResponse::requireFields($data, ['token', 'new_password']);

    $token = Security::sanitizeInput($data['token']);
    $newPassword = $data['new_password'];

    // Validate new password
    $passwordValidation = Security::validatePasswordStrength($newPassword);
    if (!$passwordValidation['valid']) {
        ApiResponse::validationError(['password' => $passwordValidation['message']]);
    }

    try {
        $db = Database::getInstance();

        // Get token details
        $tokenData = $db->query(
            'SELECT user_id, expires_at, used FROM password_reset_tokens WHERE token = ?',
            [$token]
        );

        if (empty($tokenData)) {
            Logger::logSecurity('Invalid password reset token attempted', ['token' => substr($token, 0, 10) . '...']);
            ApiResponse::error('Invalid or expired reset token', 400);
        }

        $tokenInfo = $tokenData[0];

        if ($tokenInfo['used']) {
            ApiResponse::error('Reset token has already been used', 400);
        }

        if (strtotime($tokenInfo['expires_at']) < time()) {
            ApiResponse::error('Reset token has expired', 400);
        }

        // Hash new password
        $newPasswordHash = Security::hashPassword($newPassword);

        // Update password and regenerate check code
        $db->callProcedure('sp_change_password', [
            ':p_user_id' => $tokenInfo['user_id'],
            ':p_new_password_hash' => $newPasswordHash
        ], ['p_error_message']);

        // Mark token as used
        $db->execute('UPDATE password_reset_tokens SET used = TRUE WHERE token = ?', [$token]);

        // Reset login attempts
        $db->execute('UPDATE users SET login_attempts = 0 WHERE user_id = ?', [$tokenInfo['user_id']]);

        Logger::info('Password reset successful', ['user_id' => $tokenInfo['user_id']]);

        ApiResponse::success(null, 'Password has been reset successfully. Please log in with your new password.');

    } catch (Exception $e) {
        Logger::error('Reset password error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to reset password');
    }
}

/**
 * Handle resend verification email
 */
function handleResendVerification() {
    ApiResponse::requireMethod('POST');

    $data = ApiResponse::getJsonBody();
    ApiResponse::requireFields($data, ['email']);

    $email = Security::sanitizeInput($data['email']);

    // Rate limiting
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!Security::checkRateLimit('resend_verification_' . $clientIp, 3, 3600)) {
        ApiResponse::error('Too many verification requests. Please try again later.', 429);
    }

    try {
        $db = Database::getInstance();

        // Find user
        $userResult = $db->query(
            'SELECT user_id, username, email, confirmed FROM users WHERE email = ? AND active = TRUE',
            [$email]
        );

        if (empty($userResult)) {
            // Don't reveal if email exists
            ApiResponse::success(null, 'If an unverified account exists with this email, a verification link has been sent.');
        }

        $user = $userResult[0];

        if ($user['confirmed']) {
            ApiResponse::error('This email is already verified', 400);
        }

        // Invalidate existing tokens
        $db->execute(
            'UPDATE email_verification_tokens SET used = TRUE WHERE user_id = ? AND used = FALSE',
            [$user['user_id']]
        );

        // Generate new verification token
        $verificationToken = Security::generateToken();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $db->execute(
            'INSERT INTO email_verification_tokens (user_id, token, expires_at) VALUES (?, ?, ?)',
            [$user['user_id'], $verificationToken, $expiresAt]
        );

        // TODO: Send verification email
        // EmailService::sendVerificationEmail($user['email'], $verificationToken);

        Logger::info('Verification email resent', ['user_id' => $user['user_id']]);

        ApiResponse::success(null, 'If an unverified account exists with this email, a verification link has been sent.');

    } catch (Exception $e) {
        Logger::error('Resend verification error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to resend verification email');
    }
}
