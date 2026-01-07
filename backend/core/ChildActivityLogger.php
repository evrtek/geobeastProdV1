<?php
/**
 * Child Activity Logger
 * Logs activities for child accounts for parent monitoring
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Logger.php';

class ChildActivityLogger {

    const TYPE_BATTLE = 'battle';
    const TYPE_TRADE = 'trade';
    const TYPE_MARKETPLACE_SALE = 'marketplace_sale';
    const TYPE_MARKETPLACE_PURCHASE = 'marketplace_purchase';
    const TYPE_FRIEND_REQUEST = 'friend_request';
    const TYPE_CHAT = 'chat';
    const TYPE_CARD_PURCHASE = 'card_purchase';
    const TYPE_CREDIT_TRANSFER = 'credit_transfer';

    /**
     * Log an activity for a child account
     */
    public static function log($childUserId, $activityType, $description, $relatedUserId = null) {
        try {
            $db = Database::getInstance();

            // Check if user is a child account
            $userCheck = $db->query(
                'SELECT u.user_id, at.account_type_name
                 FROM users u
                 JOIN account_types at ON u.account_type_id = at.account_type_id
                 WHERE u.user_id = ?',
                [$childUserId]
            );

            if (empty($userCheck) || $userCheck[0]['account_type_name'] !== 'child') {
                return false; // Not a child account, no logging needed
            }

            // Log the activity
            $db->execute(
                'INSERT INTO child_activity_log (child_user_id, activity_type, activity_description, related_user_id)
                 VALUES (?, ?, ?, ?)',
                [$childUserId, $activityType, $description, $relatedUserId]
            );

            Logger::debug('Child activity logged', [
                'child_user_id' => $childUserId,
                'activity_type' => $activityType
            ]);

            return true;

        } catch (Exception $e) {
            Logger::error('Failed to log child activity: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Log a battle activity
     */
    public static function logBattle($childUserId, $opponentUserId, $battleMode, $result) {
        $description = "Played a $battleMode battle. Result: $result";
        return self::log($childUserId, self::TYPE_BATTLE, $description, $opponentUserId);
    }

    /**
     * Log a trade activity
     */
    public static function logTrade($childUserId, $tradePartnerUserId, $cardsGiven, $cardsReceived, $creditsGiven, $creditsReceived) {
        $description = "Completed a trade: gave $cardsGiven cards";
        if ($creditsGiven > 0) {
            $description .= " and $creditsGiven credits";
        }
        $description .= ", received $cardsReceived cards";
        if ($creditsReceived > 0) {
            $description .= " and $creditsReceived credits";
        }
        return self::log($childUserId, self::TYPE_TRADE, $description, $tradePartnerUserId);
    }

    /**
     * Log a marketplace sale
     */
    public static function logMarketplaceSale($childUserId, $buyerUserId, $cardName, $salePrice) {
        $description = "Sold '$cardName' on marketplace for $salePrice credits";
        return self::log($childUserId, self::TYPE_MARKETPLACE_SALE, $description, $buyerUserId);
    }

    /**
     * Log a marketplace purchase
     */
    public static function logMarketplacePurchase($childUserId, $sellerUserId, $cardName, $purchasePrice) {
        $description = "Purchased '$cardName' from marketplace for $purchasePrice credits";
        return self::log($childUserId, self::TYPE_MARKETPLACE_PURCHASE, $description, $sellerUserId);
    }

    /**
     * Log a friend request activity
     */
    public static function logFriendRequest($childUserId, $friendUserId, $action, $context = null) {
        $actions = [
            'sent' => 'Sent a friend request',
            'accepted' => 'Accepted a friend request',
            'declined' => 'Declined a friend request',
            'received' => 'Received a friend request'
        ];
        $description = $actions[$action] ?? "Friend request action: $action";
        if ($context) {
            $description .= " (Context: $context)";
        }
        return self::log($childUserId, self::TYPE_FRIEND_REQUEST, $description, $friendUserId);
    }

    /**
     * Log a chat activity (summarized, not individual messages)
     */
    public static function logChatActivity($childUserId, $chatPartnerId, $messageCount) {
        $description = "Exchanged $messageCount messages in chat";
        return self::log($childUserId, self::TYPE_CHAT, $description, $chatPartnerId);
    }

    /**
     * Log a card purchase
     */
    public static function logCardPurchase($childUserId, $packSize, $creditsSpent) {
        $description = "Purchased a $packSize-card pack for $creditsSpent credits";
        return self::log($childUserId, self::TYPE_CARD_PURCHASE, $description);
    }

    /**
     * Log a credit transfer
     */
    public static function logCreditTransfer($childUserId, $otherUserId, $amount, $direction) {
        if ($direction === 'sent') {
            $description = "Sent $amount credits";
        } else {
            $description = "Received $amount credits";
        }
        return self::log($childUserId, self::TYPE_CREDIT_TRANSFER, $description, $otherUserId);
    }

    /**
     * Get activity summary for notification to parent
     */
    public static function getDailySummary($childUserId) {
        try {
            $db = Database::getInstance();

            $summary = $db->query(
                'SELECT
                    activity_type,
                    COUNT(*) as count,
                    MAX(activity_datetime) as last_activity
                 FROM child_activity_log
                 WHERE child_user_id = ?
                   AND activity_datetime >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 GROUP BY activity_type',
                [$childUserId]
            );

            return $summary;

        } catch (Exception $e) {
            Logger::error('Failed to get daily activity summary: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if activity requires parent approval
     */
    public static function requiresApproval($childUserId, $activityType) {
        try {
            $db = Database::getInstance();

            $controls = $db->query(
                'SELECT * FROM parent_controls WHERE child_user_id = ?',
                [$childUserId]
            );

            if (empty($controls)) {
                return false;
            }

            $pc = $controls[0];

            switch ($activityType) {
                case self::TYPE_FRIEND_REQUEST:
                    return (bool)$pc['require_friend_approval'];

                case self::TYPE_MARKETPLACE_SALE:
                case self::TYPE_MARKETPLACE_PURCHASE:
                    return (bool)$pc['require_marketplace_approval'];

                default:
                    return false;
            }

        } catch (Exception $e) {
            Logger::error('Failed to check approval requirement: ' . $e->getMessage());
            return true; // Default to requiring approval on error
        }
    }
}
