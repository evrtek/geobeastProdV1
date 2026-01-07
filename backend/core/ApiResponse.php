<?php
/**
 * API Response Handler
 * Provides consistent JSON responses for all API endpoints
 */

class ApiResponse {

    /**
     * Send success response
     */
    public static function success($data = null, $message = 'Success', $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json');

        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data
        ];

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send error response
     */
    public static function error($message = 'An error occurred', $code = 400, $errors = null) {
        http_response_code($code);
        header('Content-Type: application/json');

        $response = [
            'success' => false,
            'message' => $message
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send validation error response
     */
    public static function validationError($errors, $message = 'Validation failed') {
        self::error($message, 422, $errors);
    }

    /**
     * Send unauthorized response
     */
    public static function unauthorized($message = 'Unauthorized access') {
        self::error($message, 401);
    }

    /**
     * Send forbidden response
     */
    public static function forbidden($message = 'Access forbidden') {
        self::error($message, 403);
    }

    /**
     * Send not found response
     */
    public static function notFound($message = 'Resource not found') {
        self::error($message, 404);
    }

    /**
     * Send server error response
     */
    public static function serverError($message = 'Internal server error') {
        self::error($message, 500);
    }

    /**
     * Set CORS headers
     */
    public static function setCorsHeaders() {
        $allowedOrigins = explode(',', Config::get('CORS_ALLOWED_ORIGINS', '*'));
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (in_array($origin, $allowedOrigins) || in_array('*', $allowedOrigins)) {
            header("Access-Control-Allow-Origin: $origin");
        }

        header('Access-Control-Allow-Methods: ' . Config::get('CORS_ALLOWED_METHODS', 'GET, POST, PUT, DELETE, OPTIONS'));
        header('Access-Control-Allow-Headers: ' . Config::get('CORS_ALLOWED_HEADERS', 'Content-Type, Authorization, X-Requested-With'));
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');

        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    /**
     * Get request body as JSON
     */
    public static function getJsonBody() {
        $rawBody = file_get_contents('php://input');

        // If body is empty, fall back to form-encoded POST data
        if ($rawBody === false || trim($rawBody) === '') {
            if (!empty($_POST)) {
                return $_POST;
            }

            return [];
        }

        $data = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::error('Invalid JSON in request body', 400);
        }

        return $data ?? [];
    }

    /**
     * Get request method
     */
    public static function getMethod() {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    /**
     * Check if request method matches
     */
    public static function requireMethod($method) {
        if (self::getMethod() !== strtoupper($method)) {
            self::error('Method not allowed', 405);
        }
    }

    /**
     * Require authentication
     */
    public static function requireAuth() {
        $cookieData = Security::verifyAuthCookie();

        if ($cookieData === null) {
            self::unauthorized('Authentication required');
        }

        // Fetch full user data from database including user_id
        require_once __DIR__ . '/Database.php';
        $db = Database::getInstance();

        $user = $db->query(
            'SELECT u.user_id, u.check_code as user_code, u.username, u.given_name,
                    at.account_type_name
             FROM users u
             LEFT JOIN account_types at ON u.account_type_id = at.account_type_id
             WHERE u.check_code = ? AND u.active = TRUE',
            [$cookieData['user_code']]
        );

        if (empty($user)) {
            self::unauthorized('Invalid authentication');
        }

        return $user[0];
    }

    /**
     * Check if user is logged in (optional auth)
     */
    public static function getCurrentUser() {
        return Security::verifyAuthCookie();
    }

    /**
     * Require specific fields in request data
     */
    public static function requireFields($data, $requiredFields) {
        $missing = [];

        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $missing[] = $field;
                continue;
            }

            $value = $data[$field];

            // If the value is an array, ensure it's not empty
            if (is_array($value)) {
                if (count($value) === 0) {
                    $missing[] = $field;
                }
                continue;
            }

            // For scalar values (string, number, bool), check if empty
            if (is_scalar($value)) {
                // For strings, check if trimmed value is empty
                // For numbers and booleans, allow 0 and false as valid values
                if (is_string($value) && trim($value) === '') {
                    $missing[] = $field;
                }
            }
            // For objects or other types, just check if value exists (already passed isset check)
        }

        if (!empty($missing)) {
            self::validationError(
                ['missing_fields' => $missing],
                'Required fields are missing: ' . implode(', ', $missing)
            );
        }
    }

}
