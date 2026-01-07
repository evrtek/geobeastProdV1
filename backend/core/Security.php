<?php
/**
 * Security Utilities
 * Handles authentication, password hashing, input validation, and CSRF protection
 */

require_once __DIR__ . '/../config/config.php';

class Security {

    /**
     * Hash password using bcrypt
     */
    public static function hashPassword($password) {
        $pepper = Config::get('PASSWORD_PEPPER', '');
        $passwordWithPepper = $password . $pepper;

        return password_hash($passwordWithPepper, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Verify password against hash
     */
    public static function verifyPassword($password, $hash) {
        $pepper = Config::get('PASSWORD_PEPPER', '');
        $passwordWithPepper = $password . $pepper;

        return password_verify($passwordWithPepper, $hash);
    }

    /**
     * Validate password strength
     * Must be 10+ chars with upper, lower, numbers, and special characters
     */
    public static function validatePasswordStrength($password) {
        if (strlen($password) < 10) {
            return [
                'valid' => false,
                'message' => 'Password must be at least 10 characters long'
            ];
        }

        if (!preg_match('/[A-Z]/', $password)) {
            return [
                'valid' => false,
                'message' => 'Password must contain at least one uppercase letter'
            ];
        }

        if (!preg_match('/[a-z]/', $password)) {
            return [
                'valid' => false,
                'message' => 'Password must contain at least one lowercase letter'
            ];
        }

        if (!preg_match('/[0-9]/', $password)) {
            return [
                'valid' => false,
                'message' => 'Password must contain at least one number'
            ];
        }

        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return [
                'valid' => false,
                'message' => 'Password must contain at least one special character'
            ];
        }

        return ['valid' => true, 'message' => 'Password is strong'];
    }

    /**
     * Generate random token
     */
    public static function generateToken($length = 32) {
        return bin2hex(random_bytes($length));
    }

    /**
     * Generate check code for user (SHA256 hash)
     */
    public static function generateCheckCode($username, $passwordHash, $email) {
        $combined = $username . $passwordHash . $email;
        return substr(hash('sha256', $combined), 0, 20);
    }

    /**
     * Validate email format
     */
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate username (alphanumeric, underscore, hyphen, 3-50 chars, must contain at least 1 number)
     */
    public static function validateUsername($username) {
        // Check basic format: 3-50 chars, letters, numbers, underscore, hyphen
        $basicFormat = preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $username) === 1;
        // Check contains at least one number (for security)
        $hasNumber = preg_match('/\d/', $username) === 1;
        return $basicFormat && $hasNumber;
    }

    /**
     * Sanitize input to prevent XSS
     */
    public static function sanitizeInput($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitizeInput'], $input);
        }

        $input = trim($input);
        $input = stripslashes($input);
        $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');

        return $input;
    }

    /**
     * Validate against SQL injection patterns
     */
    public static function detectSqlInjection($input) {
        $patterns = [
            '/(\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|EXEC|EXECUTE|UNION|SCRIPT)\b)/i',
            '/(\/\*|\*\/|--|#|;|\'|\"|\||&|\$|`)/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create authentication cookie
     */
    public static function createAuthCookie($userCode, $username, $givenName, $accountTypeName = null) {
        $cookieData = [
            'user_code' => $userCode,
            'username' => $username,
            'given_name' => $givenName,
            'account_type_name' => $accountTypeName,
            'timestamp' => time()
        ];

        $cookieValue = base64_encode(json_encode($cookieData));
        $signature = hash_hmac('sha256', $cookieValue, Config::get('COOKIE_SECRET'));
        $signedCookie = $cookieValue . '.' . $signature;

        $cookieOptions = [
            'expires' => time() + (86400 * 30), // 30 days
            'path' => '/',
            'domain' => Config::get('COOKIE_DOMAIN', ''),
            'secure' => Config::get('COOKIE_SECURE', 'true') === 'true',
            'httponly' => Config::get('COOKIE_HTTPONLY', 'true') === 'true',
            'samesite' => Config::get('COOKIE_SAMESITE', 'Strict')
        ];

        setcookie('geobeasts_auth', $signedCookie, $cookieOptions);

        return true;
    }

    /**
     * Verify and read authentication cookie
     */
    public static function verifyAuthCookie() {
        if (!isset($_COOKIE['geobeasts_auth'])) {
            return null;
        }

        $signedCookie = $_COOKIE['geobeasts_auth'];
        $parts = explode('.', $signedCookie);

        if (count($parts) !== 2) {
            return null;
        }

        list($cookieValue, $signature) = $parts;
        $expectedSignature = hash_hmac('sha256', $cookieValue, Config::get('COOKIE_SECRET'));

        // Verify signature
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $cookieData = json_decode(base64_decode($cookieValue), true);

        if (!$cookieData || !isset($cookieData['user_code'])) {
            return null;
        }

        return $cookieData;
    }

    /**
     * Delete authentication cookie
     */
    public static function deleteAuthCookie() {
        $cookieOptions = [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => Config::get('COOKIE_DOMAIN', ''),
            'secure' => Config::get('COOKIE_SECURE', 'true') === 'true',
            'httponly' => Config::get('COOKIE_HTTPONLY', 'true') === 'true',
            'samesite' => Config::get('COOKIE_SAMESITE', 'Strict')
        ];

        setcookie('geobeasts_auth', '', $cookieOptions);
        return true;
    }

    /**
     * Verify reCAPTCHA token
     */
    public static function verifyRecaptcha($token) {
        $secretKey = Config::get('RECAPTCHA_SECRET_KEY');

        if (empty($secretKey) || empty($token)) {
            return false;
        }

        $url = 'https://www.google.com/recaptcha/api/siteverify';
        $data = [
            'secret' => $secretKey,
            'response' => $token
        ];

        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data)
            ]
        ];

        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        if ($result === false) {
            return false;
        }

        $responseData = json_decode($result, true);

        return isset($responseData['success']) && $responseData['success'] === true;
    }

    /**
     * Rate limiting check
     */
    public static function checkRateLimit($identifier, $maxRequests = 100, $period = 60) {
        if (Config::get('RATE_LIMIT_ENABLED', 'true') !== 'true') {
            return true;
        }

        $maxRequests = (int) Config::get('RATE_LIMIT_REQUESTS', $maxRequests);
        $period = (int) Config::get('RATE_LIMIT_PERIOD', $period);

        // This is a simple file-based rate limiter
        // In production, use Redis or Memcached
        $rateLimitDir = __DIR__ . '/../storage/rate_limits';
        if (!is_dir($rateLimitDir)) {
            mkdir($rateLimitDir, 0755, true);
        }

        $file = $rateLimitDir . '/' . md5($identifier) . '.json';
        $now = time();

        $data = [];
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true) ?: [];
        }

        // Clean old entries
        $data = array_filter($data, function($timestamp) use ($now, $period) {
            return ($now - $timestamp) < $period;
        });

        // Check limit
        if (count($data) >= $maxRequests) {
            return false;
        }

        // Add new entry
        $data[] = $now;
        file_put_contents($file, json_encode($data));

        return true;
    }

    /**
     * Generate CSRF token
     */
    public static function generateCsrfToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = self::generateToken();
        $_SESSION['csrf_token'] = $token;

        return $token;
    }

    /**
     * Verify CSRF token
     */
    public static function verifyCsrfToken($token) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Generate secure random code for friend requests, gift codes, etc.
     */
    public static function generateSecureCode($length = 8) {
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $code;
    }

    /**
     * Require authentication - returns user_id or sends 401 error
     */
    public static function requireAuth() {
        $authData = self::verifyAuthCookie();

        if (!$authData || !isset($authData['user_code'])) {
            require_once __DIR__ . '/ApiResponse.php';
            ApiResponse::unauthorized('Authentication required');
            exit;
        }

        // Verify user_code against database
        require_once __DIR__ . '/Database.php';
        $db = Database::getInstance();

        $user = $db->query(
            "SELECT check_code as user_code FROM users WHERE check_code = ? AND active = TRUE",
            [$authData['user_code']]
        );

        if (empty($user)) {
            require_once __DIR__ . '/ApiResponse.php';
            ApiResponse::unauthorized('Invalid authentication');
            exit;
        }

        return $authData['user_code'];
    }

    /**
     * Get current authenticated user code (returns null if not authenticated)
     */
    public static function getCurrentUserCode() {
        $authData = self::verifyAuthCookie();

        if (!$authData || !isset($authData['user_code'])) {
            return null;
        }

        return $authData['user_code'];
    }

    /**
     * Get current authenticated user data from cookie
     */
    public static function getCurrentUser() {
        require_once __DIR__ . '/Database.php';
        $db = Database::getInstance();

        $userCode = self::getCurrentUserCode();
        if (!$userCode) {
            return null;
        }

        $user = $db->query(
            "SELECT u.check_code as user_code, u.username, u.given_name, u.surname, u.email,
                    u.credits, u.dob, u.battle_color, u.avatar_id, u.online_status,
                    u.parent_account_id, at.account_type_id, at.account_type_name,
                    (SELECT check_code FROM users WHERE user_id = u.parent_account_id) as parent_user_code
             FROM users u
             JOIN account_types at ON u.account_type_id = at.account_type_id
             WHERE u.check_code = ? AND u.active = TRUE",
            [$userCode]
        );

        return !empty($user) ? $user[0] : null;
    }
}
