<?php
/**
 * UserCodeHelper - Utility functions for user_code migration
 * Provides helpers to convert between user_id (INT) and user_code (VARCHAR check_code)
 */

class UserCodeHelper {
    /**
     * Get user_code from user_id
     */
    public static function getUserCodeFromId($userId) {
        if (!$userId) {
            return null;
        }

        require_once __DIR__ . '/Database.php';
        $db = Database::getInstance();

        $result = $db->query(
            'SELECT check_code as user_code FROM users WHERE user_id = ?',
            [$userId]
        );

        return !empty($result) ? $result[0]['user_code'] : null;
    }

    /**
     * Get user_id from user_code (for legacy database operations)
     */
    public static function getUserIdFromCode($userCode) {
        if (!$userCode) {
            return null;
        }

        require_once __DIR__ . '/Database.php';
        $db = Database::getInstance();

        $result = $db->query(
            'SELECT user_id FROM users WHERE check_code = ?',
            [$userCode]
        );

        return !empty($result) ? (int)$result[0]['user_id'] : null;
    }

    /**
     * Get parent_user_code from parent_account_id
     */
    public static function getParentUserCode($parentAccountId) {
        if (!$parentAccountId) {
            return null;
        }

        return self::getUserCodeFromId($parentAccountId);
    }

    /**
     * Convert array of user_ids to user_codes
     */
    public static function convertIdsToCode($ids) {
        if (empty($ids) || !is_array($ids)) {
            return [];
        }

        require_once __DIR__ . '/Database.php';
        $db = Database::getInstance();

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $result = $db->query(
            "SELECT user_id, check_code as user_code FROM users WHERE user_id IN ($placeholders)",
            $ids
        );

        $map = [];
        foreach ($result as $row) {
            $map[$row['user_id']] = $row['user_code'];
        }

        return $map;
    }

    /**
     * Add user_code to user array (for API responses)
     * Converts user_id to user_code and adds it to the array
     */
    public static function enrichUserWithCode(&$user) {
        if (!is_array($user) || !isset($user['user_id'])) {
            return;
        }

        $user['user_code'] = self::getUserCodeFromId($user['user_id']);

        // Also add parent_user_code if parent_account_id exists
        if (isset($user['parent_account_id']) && $user['parent_account_id']) {
            $user['parent_user_code'] = self::getParentUserCode($user['parent_account_id']);
        }
    }

    /**
     * Enrich array of users with user_codes
     */
    public static function enrichUsersWithCodes(&$users) {
        if (!is_array($users)) {
            return;
        }

        foreach ($users as &$user) {
            self::enrichUserWithCode($user);
        }
    }
}
