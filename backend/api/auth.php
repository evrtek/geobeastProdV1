<?php
/**
 * Authentication API Endpoints
 * Handles user registration, login, logout, and email verification
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/ApiResponse.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../core/EmailService.php';

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
    $parentUserCode = isset($data['parent_user_code']) ? Security::sanitizeInput($data['parent_user_code']) : null;
    $recaptchaToken = $data['recaptcha_token'] ?? null;

    // Validate inputs
    $errors = [];

    if (!Security::validateUsername($username)) {
        $errors['username'] = 'Username must be 3-50 characters and include at least 1 number';
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

    if ($age < 16 && !$parentUserCode) {
        $errors['parent_user_code'] = 'Child accounts require a parent user code';
    }

    // For child accounts, verify that the provided email matches the parent's email
    if ($accountType === 'child' && $parentUserCode) {
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $parentUserId = UserCodeHelper::getUserIdFromCode($parentUserCode);

        if ($parentUserId) {
            $db = Database::getInstance();
            $parentUser = $db->queryOne('SELECT email FROM users WHERE user_id = :user_id', [
                ':user_id' => $parentUserId
            ]);

            if ($parentUser && strtolower($parentUser['email']) !== strtolower($email)) {
                $errors['email'] = 'Email must match your parent\'s registered email address';
            }
        } else {
            $errors['parent_user_code'] = 'Invalid parent user code';
        }
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

    // Call stored procedure to register user
    try {
        $db = Database::getInstance();

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

        // Get user_id from user_code for legacy operations
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        // Create email verification token
        $verificationToken = Security::generateToken();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $db->execute(
            'INSERT INTO email_verification_tokens (user_id, token, expires_at) VALUES (?, ?, ?)',
            [$userId, $verificationToken, $expiresAt]
        );

        // Send verification email
        try {
            EmailService::sendVerificationEmail($email, $verificationToken);
        } catch (Exception $emailError) {
            Logger::error('Failed to send verification email: ' . $emailError->getMessage());
            // Don't fail registration if email fails
        }

        // Don't create auth cookie - user must verify email first
        // Security::createAuthCookie($userId, $username, $givenName, $userCode, $accountType);

        ApiResponse::success([
            'user_id' => $userId,
            'user_code' => $userCode,
            'username' => $username,
            'given_name' => $givenName,
            'email' => $email,
            'account_type' => $accountType,
            'account_type_name' => $accountType
        ], 'Registration successful! Please check your email to verify your account.', 201);

    } catch (Exception $e) {
        error_log('Registration error: ' . $e->getMessage());
        ApiResponse::serverError('Registration failed. Please try again.');
    }
}

/**
 * Handle user login
 */
function handleLogin() {
    ApiResponse::requireMethod('POST');

    $data = ApiResponse::getJsonBody();

    // Validate required fields
    ApiResponse::requireFields($data, ['username_or_email', 'password']);

    $usernameOrEmail = Security::sanitizeInput($data['username_or_email']);
    $password = $data['password'];

    // Rate limiting
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!Security::checkRateLimit('login_' . $clientIp, 10, 300)) {
        ApiResponse::error('Too many login attempts. Please try again later.', 429);
    }

    try {
        $db = Database::getInstance();

        // Get user details
        $result = $db->callProcedure('sp_user_login', [
            ':p_username_or_email' => $usernameOrEmail
        ], ['p_user_id', 'p_username', 'p_given_name', 'p_password_hash', 'p_active', 'p_confirmed', 'p_login_attempts', 'p_error_message']);

        $output = $result['output'];

        if (!empty($output['p_error_message'])) {
            ApiResponse::error($output['p_error_message'], 404);
        }

        $userId = $output['p_user_id'];
        $username = $output['p_username'];
        $givenName = $output['p_given_name'];
        $passwordHash = $output['p_password_hash'];
        $active = (bool)$output['p_active'];
        $confirmed = (bool)$output['p_confirmed'];
        $loginAttempts = (int)$output['p_login_attempts'];

        // Check if account is locked (more than 5 failed attempts)
        if ($loginAttempts >= 5) {
            ApiResponse::error('Account locked due to too many failed login attempts. Please contact support.', 423);
        }

        // Check if account is active
        if (!$active) {
            ApiResponse::error('Account is inactive. Please contact support.', 403);
        }

        // Check if email is confirmed
        if (!$confirmed) {
            ApiResponse::error('Please verify your email address before logging in. Check your inbox for the verification link.', 403);
        }

        // Verify password
        if (!Security::verifyPassword($password, $passwordHash)) {
            // Increment login attempts
            $db->callProcedure('sp_update_login_attempts', [
                ':p_user_id' => $userId,
                ':p_increment' => true
            ]);

            ApiResponse::error('Invalid credentials', 401);
        }

        // Reset login attempts and update online status
        $db->callProcedure('sp_update_login_attempts', [
            ':p_user_id' => $userId,
            ':p_increment' => false
        ]);

        // Get check code and account type
        $userDataResult = $db->query(
            'SELECT u.check_code, at.account_type_name
             FROM users u
             JOIN account_types at ON u.account_type_id = at.account_type_id
             WHERE u.user_id = ?',
            [$userId]
        );
        $checkCode = $userDataResult[0]['check_code'] ?? '';
        $accountTypeName = $userDataResult[0]['account_type_name'] ?? null;

        // Get full user profile including account_type_name
        $profileResult = $db->callProcedure('sp_get_user_profile', [
            ':p_user_id' => $userId
        ]);

        $profile = !empty($profileResult['results'][0][0]) ? $profileResult['results'][0][0] : null;

        // Create auth cookie with account_type_name
        Security::createAuthCookie($userId, $username, $givenName, $checkCode, $accountTypeName);

        if ($profile) {
            ApiResponse::success($profile, 'Login successful');
        } else {
            // Fallback to basic user data if profile fetch fails
            ApiResponse::success([
                'user_id' => $userId,
                'username' => $username,
                'given_name' => $givenName,
                'confirmed' => $confirmed
            ], 'Login successful');
        }

    } catch (Exception $e) {
        error_log('Login error: ' . $e->getMessage());
        ApiResponse::serverError('Login failed. Please try again.');
    }
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

        // Send password reset email
        try {
            EmailService::sendPasswordResetEmail($user['email'], $resetToken);
            Logger::info('Password reset email sent', ['user_id' => $user['user_id']]);
        } catch (Exception $emailError) {
            Logger::error('Failed to send password reset email: ' . $emailError->getMessage());
            // Don't fail the request if email fails
        }

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

        // Update password - regenerate check_code for security
        $newCheckCode = Security::generateToken();
        $db->execute(
            'UPDATE users SET password_hash = ?, check_code = ?, login_attempts = 0 WHERE user_id = ?',
            [$newPasswordHash, $newCheckCode, $tokenInfo['user_id']]
        );

        // Mark token as used
        $db->execute('UPDATE password_reset_tokens SET used = TRUE WHERE token = ?', [$token]);

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

        // Send verification email
        try {
            EmailService::sendVerificationEmail($user['email'], $verificationToken);
            Logger::info('Verification email resent', ['user_id' => $user['user_id']]);
        } catch (Exception $emailError) {
            Logger::error('Failed to resend verification email: ' . $emailError->getMessage());
            // Don't fail the request if email fails
        }

        ApiResponse::success(null, 'If an unverified account exists with this email, a verification link has been sent.');

    } catch (Exception $e) {
        Logger::error('Resend verification error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to resend verification email');
    }
}
