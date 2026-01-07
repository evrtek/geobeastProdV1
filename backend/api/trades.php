<?php
/**
 * Trading System API Endpoints
 * Handles peer-to-peer card and credit trading between friends
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
Logger::logRequest('/api/trades?action=' . $path, $method);

// Route to appropriate handler
switch ($path) {
    case 'create-trade':
        handleCreateTrade();
        break;
    case 'get-trades':
    case 'get-active-trades': // Frontend alias
        handleGetTrades();
        break;
    case 'get-trade-details':
        handleGetTradeDetails();
        break;
    case 'add-card-to-trade': // Frontend alias (singular)
    case 'add-cards-to-trade':
        handleAddCardsToTrade();
        break;
    case 'add-credits-to-trade':
        handleAddCreditsToTrade();
        break;
    case 'remove-card-from-trade':
        handleRemoveCardFromTrade();
        break;
    case 'make-offer':
        handleMakeOffer();
        break;
    case 'respond-to-trade':
        handleRespondToTrade();
        break;
    case 'accept-trade': // Frontend alias for accepting trades
        handleAcceptTrade();
        break;
    case 'reject-trade': // Unaccept a trade (remove your acceptance)
        handleRejectTrade();
        break;
    case 'confirm-trade':
        handleConfirmTrade();
        break;
    case 'cancel-trade':
        handleCancelTrade();
        break;
    case 'approve-trade': // Parent approves a trade
        handleApproveTrade();
        break;
    case 'trade-history':
    case 'get-trade-history': // Frontend alias
        handleTradeHistory();
        break;
    case 'add-comment':
        handleAddComment();
        break;
    case 'get-comments':
        handleGetComments();
        break;
    default:
        ApiResponse::notFound('Endpoint not found');
}

/**
 * Create a new trade with a friend
 */
function handleCreateTrade() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    // Accept either recipient_user_code (frontend) or recipient_username (legacy)
    $recipientUserCode = $data['recipient_user_code'] ?? null;
    $recipientUsername = $data['recipient_username'] ?? null;

    if (!$recipientUserCode && !$recipientUsername) {
        ApiResponse::validationError(['recipient' => 'Recipient user code or username required']);
    }

    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        // Get recipient user by user_code or username
        if ($recipientUserCode) {
            require_once __DIR__ . '/../core/UserCodeHelper.php';
            $recipientId = UserCodeHelper::getUserIdFromCode($recipientUserCode);

            if (!$recipientId) {
                ApiResponse::error('Invalid recipient user code', 400);
            }

            // Get recipient username for trade name generation
            $recipientResult = $db->query(
                'SELECT user_id, username FROM users WHERE user_id = ? AND active = TRUE',
                [$recipientId]
            );

            if (empty($recipientResult)) {
                ApiResponse::error('Recipient user not found', 404);
            }

            $recipientUsername = $recipientResult[0]['username'];
        } else {
            // Legacy: lookup by username
            $recipientResult = $db->query(
                'SELECT user_id, username FROM users WHERE username = ? AND active = TRUE',
                [$recipientUsername]
            );

            if (empty($recipientResult)) {
                ApiResponse::error('User not found', 404);
            }

            $recipientId = $recipientResult[0]['user_id'];
        }

        // Auto-generate trade name
        $tradeName = 'Trade with ' . $recipientUsername;

        // Cannot trade with self
        if ($recipientId == $userId) {
            ApiResponse::error('Cannot create trade with yourself', 400);
        }

        // Verify friendship
        $friendshipCheck = $db->query(
            'SELECT friendship_id FROM friendships
            WHERE ((requester_user_id = ? AND recipient_user_id = ?)
               OR (requester_user_id = ? AND recipient_user_id = ?))
            AND status = ?',
            [$userId, $recipientId, $recipientId, $userId, 'approved']
        );

        if (empty($friendshipCheck)) {
            ApiResponse::error('You can only trade with approved friends', 403);
        }

        // Create trade
        $result = $db->execute(
            'INSERT INTO trades (trade_name, initiator_user_id, recipient_user_id, trade_status)
            VALUES (?, ?, ?, ?)',
            [$tradeName, $userId, $recipientId, 'proposed']
        );

        $tradeId = $result['last_insert_id'];

        // Create notification for recipient
        $db->execute(
            'INSERT INTO notifications (user_id, notification_type, notification_color, title, message, related_user_id, related_entity_type, related_entity_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $recipientId,
                'trade',
                '#C0C0C0',
                'New Trade Proposal',
                $user['username'] . ' wants to trade with you',
                $userId,
                'trade',
                $tradeId
            ]
        );

        // Log child activity if initiator is a child account
        $initiatorAccountType = $db->query(
            "SELECT at.account_type_name FROM users u
            JOIN account_types at ON u.account_type_id = at.account_type_id
            WHERE u.user_id = ?",
            [$userId]
        );

        if (!empty($initiatorAccountType) && $initiatorAccountType[0]['account_type_name'] === 'child') {
            ChildActivityLogger::log($userId, 'trade_created', [
                'trade_id' => $tradeId,
                'recipient_id' => $recipientId,
                'recipient_username' => $recipientUsername
            ]);
        }

        ApiResponse::success([
            'trade_id' => $tradeId,
            'trade_name' => $tradeName,
            'recipient' => $recipientUsername,
            'status' => 'proposed'
        ], 'Trade created successfully', 201);

    } catch (Exception $e) {
        Logger::error('Create trade error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to create trade');
    }
}

/**
 * Get user's active trades
 */
function handleGetTrades() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();
    $userId = $user['user_id'];

    $status = $_GET['status'] ?? null;

    try {
        $db = Database::getInstance();

        $query = 'SELECT
            t.trade_id,
            t.trade_name,
            t.trade_status,
            t.initiator_credits,
            t.recipient_credits,
            t.initiator_confirmed,
            t.recipient_confirmed,
            t.created_at,
            t.updated_at,
            t.completed_at,
            u_initiator.user_id as initiator_id,
            u_initiator.username as initiator_username,
            u_initiator.check_code as initiator_user_code,
            u_recipient.user_id as recipient_id,
            u_recipient.username as recipient_username,
            u_recipient.check_code as recipient_user_code
        FROM trades t
        JOIN users u_initiator ON t.initiator_user_id = u_initiator.user_id
        JOIN users u_recipient ON t.recipient_user_id = u_recipient.user_id
        WHERE (t.initiator_user_id = ? OR t.recipient_user_id = ?)';

        $params = [$userId, $userId];

        if ($status) {
            $query .= ' AND t.trade_status = ?';
            $params[] = $status;
        }

        $query .= ' ORDER BY t.updated_at DESC';

        $trades = $db->query($query, $params);

        // Fetch cards for each trade
        foreach ($trades as &$trade) {
            $trade['user_role'] = ($trade['initiator_id'] == $userId) ? 'initiator' : 'recipient';

            // Get trade cards
            $cards = $db->query(
                'SELECT
                    tc.trade_card_id,
                    tc.offered_by_user_id,
                    uc.user_card_id,
                    ct.card_name,
                    ct.character_image_path,
                    ct.speed_score,
                    ct.attack_score,
                    ct.defense_score,
                    cs.status_name,
                    ctype.type_name,
                    u.username as offered_by_username
                FROM trade_cards tc
                JOIN user_cards uc ON tc.user_card_id = uc.user_card_id
                JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
                JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
                JOIN card_status cs ON ct.status_id = cs.status_id
                JOIN card_types ctype ON ct.card_type_id = ctype.card_type_id
                JOIN users u ON tc.offered_by_user_id = u.user_id
                WHERE tc.trade_id = ?',
                [$trade['trade_id']]
            );

            // Separate cards by owner
            $initiatorCards = [];
            $recipientCards = [];

            foreach ($cards as $card) {
                if ($card['offered_by_user_id'] == $trade['initiator_id']) {
                    $initiatorCards[] = $card;
                } else {
                    $recipientCards[] = $card;
                }
            }

            $trade['initiator_cards'] = $initiatorCards;
            $trade['recipient_cards'] = $recipientCards;
        }

        ApiResponse::success(['trades' => $trades], 'Trades retrieved');

    } catch (Exception $e) {
        Logger::error('Get trades error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve trades');
    }
}

/**
 * Get detailed trade information
 */
function handleGetTradeDetails() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();

    if (!isset($_GET['trade_id'])) {
        ApiResponse::validationError(['trade_id' => 'Trade ID is required']);
    }

    $tradeId = (int)$_GET['trade_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        // Get trade details
        $trade = $db->query(
            'SELECT
                t.*,
                u_initiator.username as initiator_username,
                u_recipient.username as recipient_username
            FROM trades t
            JOIN users u_initiator ON t.initiator_user_id = u_initiator.user_id
            JOIN users u_recipient ON t.recipient_user_id = u_recipient.user_id
            WHERE t.trade_id = ?',
            [$tradeId]
        );

        if (empty($trade)) {
            ApiResponse::notFound('Trade not found');
        }

        $trade = $trade[0];

        // Verify access
        if ($trade['initiator_user_id'] != $userId && $trade['recipient_user_id'] != $userId) {
            ApiResponse::forbidden('You do not have access to this trade');
        }

        // Get trade cards
        $cards = $db->query(
            'SELECT
                tc.trade_card_id,
                tc.offered_by_user_id,
                uc.user_card_id,
                ct.card_name,
                ct.character_image_path,
                ct.speed_score,
                ct.attack_score,
                ct.defense_score,
                cs.status_name,
                ctype.type_name,
                u.username as offered_by_username
            FROM trade_cards tc
            JOIN user_cards uc ON tc.user_card_id = uc.user_card_id
            JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
            JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
            JOIN card_status cs ON ct.status_id = cs.status_id
            JOIN card_types ctype ON ct.card_type_id = ctype.card_type_id
            JOIN users u ON tc.offered_by_user_id = u.user_id
            WHERE tc.trade_id = ?',
            [$tradeId]
        );

        // Separate cards by owner
        $initiatorCards = [];
        $recipientCards = [];

        foreach ($cards as $card) {
            if ($card['offered_by_user_id'] == $trade['initiator_user_id']) {
                $initiatorCards[] = $card;
            } else {
                $recipientCards[] = $card;
            }
        }

        $trade['user_role'] = ($trade['initiator_user_id'] == $userId) ? 'initiator' : 'recipient';
        $trade['initiator_cards'] = $initiatorCards;
        $trade['recipient_cards'] = $recipientCards;

        ApiResponse::success($trade, 'Trade details retrieved');

    } catch (Exception $e) {
        Logger::error('Get trade details error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve trade details');
    }
}

/**
 * Add cards to trade
 */
function handleAddCardsToTrade() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['trade_id']);

    $tradeId = (int)$data['trade_id'];
    $userId = $user['user_id'];

    // Handle both singular card_id and plural card_ids
    if (isset($data['card_id'])) {
        $cardIds = [(int)$data['card_id']];
    } elseif (isset($data['card_ids'])) {
        $cardIds = $data['card_ids'];
    } else {
        ApiResponse::validationError(['card_id' => 'Either card_id or card_ids is required']);
    }

    if (!is_array($cardIds) || empty($cardIds)) {
        ApiResponse::validationError(['card_ids' => 'Card IDs must be a non-empty array']);
    }

    try {
        $db = Database::getInstance();

        // Get trade
        $trade = $db->query(
            'SELECT * FROM trades WHERE trade_id = ?',
            [$tradeId]
        );

        if (empty($trade)) {
            ApiResponse::notFound('Trade not found');
        }

        $trade = $trade[0];

        // Verify user is part of trade
        if ($trade['initiator_user_id'] != $userId && $trade['recipient_user_id'] != $userId) {
            ApiResponse::forbidden('You are not part of this trade');
        }

        // Can only add cards if trade is in proposed or counter_offered state
        if (!in_array($trade['trade_status'], ['proposed', 'counter_offered'])) {
            $statusMessage = "Trade is currently '{$trade['trade_status']}'. You can only add cards when the trade is 'proposed' or 'counter_offered'.";
            ApiResponse::error($statusMessage, 400);
        }

        $db->beginTransaction();

        try {
            foreach ($cardIds as $cardId) {
                $cardId = (int)$cardId;

                // Verify card ownership
                $cardCheck = $db->query(
                    'SELECT user_id, is_in_marketplace, is_in_trade FROM user_cards WHERE user_card_id = ?',
                    [$cardId]
                );

                if (empty($cardCheck)) {
                    throw new Exception("Card $cardId not found");
                }

                if ($cardCheck[0]['user_id'] != $userId) {
                    throw new Exception("You don't own card $cardId");
                }

                if ($cardCheck[0]['is_in_marketplace']) {
                    throw new Exception("Card $cardId is in marketplace");
                }

                if ($cardCheck[0]['is_in_trade']) {
                    throw new Exception("Card $cardId is already in another trade");
                }

                // Add card to trade
                $db->execute(
                    'INSERT INTO trade_cards (trade_id, user_card_id, offered_by_user_id) VALUES (?, ?, ?)',
                    [$tradeId, $cardId, $userId]
                );

                // Mark card as in trade
                $db->execute(
                    'UPDATE user_cards SET is_in_trade = TRUE WHERE user_card_id = ?',
                    [$cardId]
                );
            }

            // Update trade timestamp
            $db->execute(
                'UPDATE trades SET updated_at = NOW() WHERE trade_id = ?',
                [$tradeId]
            );

            $db->commit();

            ApiResponse::success([
                'cards_added' => count($cardIds)
            ], 'Cards added to trade successfully');

        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }

    } catch (Exception $e) {
        Logger::error('Add cards to trade error: ' . $e->getMessage());
        ApiResponse::error($e->getMessage(), 400);
    }
}

/**
 * Add credits to trade
 */
function handleAddCreditsToTrade() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['trade_id', 'credits']);

    $tradeId = (int)$data['trade_id'];
    $credits = (float)$data['credits'];
    $userId = $user['user_id'];

    if ($credits <= 0) {
        ApiResponse::validationError(['credits' => 'Credits must be greater than zero']);
    }

    try {
        $db = Database::getInstance();

        // Get trade
        $trade = $db->query('SELECT * FROM trades WHERE trade_id = ?', [$tradeId]);

        if (empty($trade)) {
            ApiResponse::notFound('Trade not found');
        }

        $trade = $trade[0];

        // Verify user is part of trade
        if ($trade['initiator_user_id'] != $userId && $trade['recipient_user_id'] != $userId) {
            ApiResponse::forbidden('You are not part of this trade');
        }

        // Check user has enough credits
        $userCredits = $db->query('SELECT credits FROM users WHERE user_id = ?', [$userId])[0]['credits'];

        if ($userCredits < $credits) {
            ApiResponse::error('Insufficient credits', 400);
        }

        // Update trade credits
        if ($trade['initiator_user_id'] == $userId) {
            $db->execute(
                'UPDATE trades SET initiator_credits = ?, updated_at = NOW() WHERE trade_id = ?',
                [$credits, $tradeId]
            );
        } else {
            $db->execute(
                'UPDATE trades SET recipient_credits = ?, updated_at = NOW() WHERE trade_id = ?',
                [$credits, $tradeId]
            );
        }

        ApiResponse::success(['credits_offered' => $credits], 'Credits added to trade');

    } catch (Exception $e) {
        Logger::error('Add credits to trade error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to add credits to trade');
    }
}

/**
 * Remove card from trade
 */
function handleRemoveCardFromTrade() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['trade_id', 'user_card_id']);

    $tradeId = (int)$data['trade_id'];
    $userCardId = (int)$data['user_card_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        // Get trade status and confirmation status
        $trade = $db->query(
            'SELECT initiator_user_id, recipient_user_id, initiator_confirmed, recipient_confirmed, trade_status
            FROM trades WHERE trade_id = ?',
            [$tradeId]
        );

        if (empty($trade)) {
            ApiResponse::notFound('Trade not found');
        }

        $trade = $trade[0];

        // Check if user's cards are locked (user has accepted)
        $isInitiator = ($trade['initiator_user_id'] == $userId);
        if ($isInitiator && $trade['initiator_confirmed']) {
            ApiResponse::error('Cannot remove cards - you have already accepted this trade. Cancel the trade to release cards.', 400);
        }
        if (!$isInitiator && $trade['recipient_confirmed']) {
            ApiResponse::error('Cannot remove cards - you have already accepted this trade. Cancel the trade to release cards.', 400);
        }

        // Verify ownership
        $cardCheck = $db->query(
            'SELECT offered_by_user_id FROM trade_cards WHERE trade_id = ? AND user_card_id = ?',
            [$tradeId, $userCardId]
        );

        if (empty($cardCheck)) {
            ApiResponse::notFound('Card not in trade');
        }

        if ($cardCheck[0]['offered_by_user_id'] != $userId) {
            ApiResponse::forbidden('You cannot remove this card');
        }

        // Remove card from trade
        $db->execute(
            'DELETE FROM trade_cards WHERE trade_id = ? AND user_card_id = ?',
            [$tradeId, $userCardId]
        );

        // Mark card as not in trade
        $db->execute(
            'UPDATE user_cards SET is_in_trade = FALSE WHERE user_card_id = ?',
            [$userCardId]
        );

        ApiResponse::success(null, 'Card removed from trade');

    } catch (Exception $e) {
        Logger::error('Remove card from trade error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to remove card from trade');
    }
}

/**
 * Make or update an offer (counter-offer)
 */
function handleMakeOffer() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['trade_id']);

    $tradeId = (int)$data['trade_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        // Get trade
        $trade = $db->query('SELECT * FROM trades WHERE trade_id = ?', [$tradeId]);

        if (empty($trade)) {
            ApiResponse::notFound('Trade not found');
        }

        $trade = $trade[0];

        // Only recipient can make offers
        if ($trade['recipient_user_id'] != $userId) {
            ApiResponse::error('Only the recipient can make offers', 403);
        }

        // Update status to counter_offered
        $db->execute(
            'UPDATE trades SET trade_status = ?, updated_at = NOW() WHERE trade_id = ?',
            ['counter_offered', $tradeId]
        );

        // Notify initiator
        $db->execute(
            'INSERT INTO notifications (user_id, notification_type, notification_color, title, message, related_user_id, related_entity_type, related_entity_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $trade['initiator_user_id'],
                'trade',
                '#C0C0C0',
                'Trade Counter-Offer',
                'Your trade has been counter-offered',
                $userId,
                'trade',
                $tradeId
            ]
        );

        ApiResponse::success(['status' => 'counter_offered'], 'Counter-offer made');

    } catch (Exception $e) {
        Logger::error('Make offer error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to make offer');
    }
}

/**
 * Respond to trade (accept or reject)
 */
function handleRespondToTrade() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['trade_id', 'response']);

    $tradeId = (int)$data['trade_id'];
    $response = Security::sanitizeInput($data['response']); // 'accept' or 'reject'
    $userId = $user['user_id'];

    if (!in_array($response, ['accept', 'reject'])) {
        ApiResponse::validationError(['response' => 'Response must be accept or reject']);
    }

    try {
        $db = Database::getInstance();

        $trade = $db->query('SELECT * FROM trades WHERE trade_id = ?', [$tradeId]);

        if (empty($trade)) {
            ApiResponse::notFound('Trade not found');
        }

        $trade = $trade[0];

        if ($response === 'reject') {
            // Reset trade to original proposed state
            $db->execute(
                'UPDATE trades SET trade_status = ?, initiator_confirmed = FALSE, recipient_confirmed = FALSE, updated_at = NOW()
                WHERE trade_id = ?',
                ['proposed', $tradeId]
            );

            ApiResponse::success(['status' => 'proposed'], 'Trade offer rejected');
        }

        if ($response === 'accept') {
            // Move to accepted status - requires both confirmations
            $db->execute(
                'UPDATE trades SET trade_status = ?, updated_at = NOW() WHERE trade_id = ?',
                ['accepted', $tradeId]
            );

            // Determine who accepted
            $isInitiator = ($trade['initiator_user_id'] == $userId);
            $otherUserId = $isInitiator ? $trade['recipient_user_id'] : $trade['initiator_user_id'];

            // Notify other user
            $db->execute(
                'INSERT INTO notifications (user_id, notification_type, notification_color, title, message, related_user_id, related_entity_type, related_entity_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $otherUserId,
                    'trade',
                    '#C0C0C0',
                    'Trade Accepted',
                    'Your trade proposal has been accepted. Please confirm to complete.',
                    $userId,
                    'trade',
                    $tradeId
                ]
            );

            ApiResponse::success(['status' => 'accepted', 'message' => 'Trade accepted. Both parties must confirm to complete.'], 'Trade accepted');
        }

    } catch (Exception $e) {
        Logger::error('Respond to trade error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to respond to trade');
    }
}

/**
 * Accept trade - both parties can accept/unaccept until both are ready
 * Cards are NOT locked until trade completes
 */
function handleAcceptTrade() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['trade_id']);

    $tradeId = (int)$data['trade_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $trade = $db->query('SELECT * FROM trades WHERE trade_id = ?', [$tradeId]);

        if (empty($trade)) {
            ApiResponse::notFound('Trade not found');
        }

        $trade = $trade[0];

        // Verify user is part of trade
        if ($trade['initiator_user_id'] != $userId && $trade['recipient_user_id'] != $userId) {
            ApiResponse::forbidden('You are not part of this trade');
        }

        // Can't accept a cancelled or completed trade
        if (in_array($trade['trade_status'], ['cancelled', 'completed'])) {
            ApiResponse::error('Cannot accept a ' . $trade['trade_status'] . ' trade', 400);
        }

        // Determine who is accepting
        $isInitiator = ($trade['initiator_user_id'] == $userId);
        $otherUserId = $isInitiator ? $trade['recipient_user_id'] : $trade['initiator_user_id'];

        // Mark user as confirmed (can be toggled on/off)
        if ($isInitiator) {
            $db->execute(
                'UPDATE trades SET initiator_confirmed = TRUE, updated_at = NOW() WHERE trade_id = ?',
                [$tradeId]
            );
            $trade['initiator_confirmed'] = true;
        } else {
            $db->execute(
                'UPDATE trades SET recipient_confirmed = TRUE, updated_at = NOW() WHERE trade_id = ?',
                [$tradeId]
            );
            $trade['recipient_confirmed'] = true;
        }

        // Check if both parties have now accepted
        if ($trade['initiator_confirmed'] && $trade['recipient_confirmed']) {
            // Both accepted - check if parent approval needed
            $needsParentalApproval = checkNeedsParentalApproval($db, $tradeId, $trade);

            if ($needsParentalApproval) {
                // Update status to pending_approval
                $db->execute(
                    'UPDATE trades SET trade_status = ? WHERE trade_id = ?',
                    ['pending_approval', $tradeId]
                );

                // Notify parents
                notifyParentsForApproval($db, $trade, $tradeId);

                ApiResponse::success([
                    'status' => 'pending_approval',
                    'message' => 'Both parties have accepted. Waiting for parental approval for legendary card trade.',
                    'needs_approval' => true
                ], 'Trade pending parental approval');
            } else {
                // No approval needed - complete the trade
                completeTrade($db, $tradeId, $trade);

                // Notify other user that trade is complete
                $db->execute(
                    'INSERT INTO notifications (user_id, notification_type, notification_color, title, message, related_user_id, related_entity_type, related_entity_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $otherUserId,
                        'trade',
                        '#00FF00',
                        'Trade Completed!',
                        $user['username'] . ' accepted the trade. Cards have been exchanged!',
                        $userId,
                        'trade',
                        $tradeId
                    ]
                );

                ApiResponse::success([
                    'status' => 'completed',
                    'message' => 'Trade completed! Cards and credits have been exchanged.'
                ], 'Trade completed successfully');
            }
        } else {
            // Update status to accepted (one party has accepted)
            $db->execute(
                'UPDATE trades SET trade_status = ? WHERE trade_id = ?',
                ['accepted', $tradeId]
            );

            // Only one party accepted so far - notify the other
            $db->execute(
                'INSERT INTO notifications (user_id, notification_type, notification_color, title, message, related_user_id, related_entity_type, related_entity_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $otherUserId,
                    'trade',
                    '#C0C0C0',
                    'Trade Accepted',
                    $user['username'] . ' accepted the trade. Accept to complete the exchange.',
                    $userId,
                    'trade',
                    $tradeId
                ]
            );

            ApiResponse::success([
                'status' => 'accepted',
                'message' => 'Trade accepted. Waiting for other party to accept.',
                'initiator_confirmed' => $trade['initiator_confirmed'],
                'recipient_confirmed' => $trade['recipient_confirmed']
            ], 'Trade accepted - awaiting other party');
        }

    } catch (Exception $e) {
        Logger::error('Accept trade error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to accept trade');
    }
}

/**
 * Reject trade - remove your acceptance (unaccept)
 * Allows either party to change their mind before trade completes
 */
function handleRejectTrade() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['trade_id']);

    $tradeId = (int)$data['trade_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $trade = $db->query('SELECT * FROM trades WHERE trade_id = ?', [$tradeId]);

        if (empty($trade)) {
            ApiResponse::notFound('Trade not found');
        }

        $trade = $trade[0];

        // Verify user is part of trade
        if ($trade['initiator_user_id'] != $userId && $trade['recipient_user_id'] != $userId) {
            ApiResponse::forbidden('You are not part of this trade');
        }

        // Can't reject a completed or cancelled trade
        if (in_array($trade['trade_status'], ['cancelled', 'completed'])) {
            ApiResponse::error('Cannot reject a ' . $trade['trade_status'] . ' trade', 400);
        }

        // Determine who is rejecting
        $isInitiator = ($trade['initiator_user_id'] == $userId);
        $otherUserId = $isInitiator ? $trade['recipient_user_id'] : $trade['initiator_user_id'];

        // Mark user as NOT confirmed and reset trade to proposed
        if ($isInitiator) {
            $db->execute(
                'UPDATE trades SET initiator_confirmed = FALSE, trade_status = ?, updated_at = NOW() WHERE trade_id = ?',
                ['proposed', $tradeId]
            );
        } else {
            $db->execute(
                'UPDATE trades SET recipient_confirmed = FALSE, trade_status = ?, updated_at = NOW() WHERE trade_id = ?',
                ['proposed', $tradeId]
            );
        }

        // Notify other user
        $db->execute(
            'INSERT INTO notifications (user_id, notification_type, notification_color, title, message, related_user_id, related_entity_type, related_entity_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $otherUserId,
                'trade',
                '#FFA500',
                'Trade Status Changed',
                $user['username'] . ' has changed their mind on the trade. The trade is now open for modifications.',
                $userId,
                'trade',
                $tradeId
            ]
        );

        ApiResponse::success([
            'status' => 'proposed',
            'message' => 'You have removed your acceptance. Both parties can now modify the trade.'
        ], 'Trade acceptance removed');

    } catch (Exception $e) {
        Logger::error('Reject trade error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to reject trade');
    }
}

/**
 * Check if parental approval is needed for this trade
 * Required when: child account + legendary card involved
 */
function checkNeedsParentalApproval($db, $tradeId, $trade) {
    // Check if either party is a child account
    $accounts = $db->query(
        'SELECT u.user_id, at.account_type_name
        FROM users u
        JOIN account_types at ON u.account_type_id = at.account_type_id
        WHERE u.user_id IN (?, ?)',
        [$trade['initiator_user_id'], $trade['recipient_user_id']]
    );

    $hasChildAccount = false;
    foreach ($accounts as $account) {
        if ($account['account_type_name'] === 'child') {
            $hasChildAccount = true;
            break;
        }
    }

    if (!$hasChildAccount) {
        return false;
    }

    // Check if any card in the trade is Legendary
    $cards = $db->query(
        'SELECT cs.status_name
        FROM trade_cards tc
        JOIN user_cards uc ON tc.user_card_id = uc.user_card_id
        JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
        JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
        JOIN card_status cs ON ct.status_id = cs.status_id
        WHERE tc.trade_id = ?',
        [$tradeId]
    );

    foreach ($cards as $card) {
        if ($card['status_name'] === 'Legendary') {
            return true;
        }
    }

    return false;
}

/**
 * Notify parents of both parties for approval
 */
function notifyParentsForApproval($db, $trade, $tradeId) {
    // Get parent IDs for both parties if they are child accounts
    $parentIds = [];

    $initiatorParent = $db->query(
        'SELECT u.parent_user_id, at.account_type_name
        FROM users u
        JOIN account_types at ON u.account_type_id = at.account_type_id
        WHERE u.user_id = ?',
        [$trade['initiator_user_id']]
    );

    if (!empty($initiatorParent) && $initiatorParent[0]['account_type_name'] === 'child' && $initiatorParent[0]['parent_user_id']) {
        $parentIds[] = $initiatorParent[0]['parent_user_id'];
    }

    $recipientParent = $db->query(
        'SELECT u.parent_user_id, at.account_type_name
        FROM users u
        JOIN account_types at ON u.account_type_id = at.account_type_id
        WHERE u.user_id = ?',
        [$trade['recipient_user_id']]
    );

    if (!empty($recipientParent) && $recipientParent[0]['account_type_name'] === 'child' && $recipientParent[0]['parent_user_id']) {
        $parentIds[] = $recipientParent[0]['parent_user_id'];
    }

    // Send notifications to all parents
    $parentIds = array_unique($parentIds);
    foreach ($parentIds as $parentId) {
        $db->execute(
            'INSERT INTO notifications (user_id, notification_type, notification_color, title, message, related_entity_type, related_entity_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $parentId,
                'trade_approval',
                '#FFD700',
                'Trade Approval Required',
                'A trade involving a legendary card requires your approval',
                'trade',
                $tradeId
            ]
        );
    }
}

/**
 * Parent approves a pending trade
 */
function handleApproveTrade() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['trade_id']);

    $tradeId = (int)$data['trade_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $trade = $db->query('SELECT * FROM trades WHERE trade_id = ?', [$tradeId]);

        if (empty($trade)) {
            ApiResponse::notFound('Trade not found');
        }

        $trade = $trade[0];

        // Verify user is parent of one of the parties
        $isParent = $db->query(
            'SELECT user_id FROM users WHERE parent_user_id = ? AND user_id IN (?, ?)',
            [$userId, $trade['initiator_user_id'], $trade['recipient_user_id']]
        );

        if (empty($isParent)) {
            ApiResponse::forbidden('You are not authorized to approve this trade');
        }

        // Verify trade is pending approval
        if ($trade['trade_status'] !== 'pending_approval') {
            ApiResponse::error('Trade is not pending approval', 400);
        }

        // Complete the trade
        completeTrade($db, $tradeId, $trade);

        // Notify both parties
        foreach ([$trade['initiator_user_id'], $trade['recipient_user_id']] as $partyId) {
            $db->execute(
                'INSERT INTO notifications (user_id, notification_type, notification_color, title, message, related_user_id, related_entity_type, related_entity_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $partyId,
                    'trade',
                    '#00FF00',
                    'Trade Approved & Completed!',
                    'Your trade has been approved by a parent and completed',
                    $userId,
                    'trade',
                    $tradeId
                ]
            );
        }

        ApiResponse::success([
            'status' => 'completed',
            'message' => 'Trade approved and completed successfully'
        ], 'Trade approved');

    } catch (Exception $e) {
        Logger::error('Approve trade error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to approve trade');
    }
}

/**
 * Confirm trade (final confirmation before completion)
 */
function handleConfirmTrade() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['trade_id']);

    $tradeId = (int)$data['trade_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $trade = $db->query('SELECT * FROM trades WHERE trade_id = ?', [$tradeId]);

        if (empty($trade)) {
            ApiResponse::notFound('Trade not found');
        }

        $trade = $trade[0];

        if ($trade['trade_status'] !== 'accepted') {
            ApiResponse::error('Trade must be accepted before confirmation', 400);
        }

        // Mark user as confirmed
        $isInitiator = ($trade['initiator_user_id'] == $userId);

        if ($isInitiator) {
            $db->execute(
                'UPDATE trades SET initiator_confirmed = TRUE WHERE trade_id = ?',
                [$tradeId]
            );
            $trade['initiator_confirmed'] = true;
        } else {
            $db->execute(
                'UPDATE trades SET recipient_confirmed = TRUE WHERE trade_id = ?',
                [$tradeId]
            );
            $trade['recipient_confirmed'] = true;
        }

        // Check if both confirmed
        if ($trade['initiator_confirmed'] && $trade['recipient_confirmed']) {
            // Execute trade
            completeTrade($db, $tradeId, $trade);

            ApiResponse::success(['status' => 'completed'], 'Trade completed successfully!');
        } else {
            ApiResponse::success(['status' => 'awaiting_confirmation'], 'Confirmation recorded. Waiting for other party.');
        }

    } catch (Exception $e) {
        Logger::error('Confirm trade error: ' . $e->getMessage());
        ApiResponse::error($e->getMessage(), 400);
    }
}

/**
 * Complete trade - transfer cards and credits
 */
function completeTrade($db, $tradeId, $trade) {
    $db->beginTransaction();

    try {
        // Get trade cards
        $tradeCards = $db->query(
            'SELECT user_card_id, offered_by_user_id FROM trade_cards WHERE trade_id = ?',
            [$tradeId]
        );

        // Transfer cards
        foreach ($tradeCards as $card) {
            $newOwnerId = ($card['offered_by_user_id'] == $trade['initiator_user_id'])
                ? $trade['recipient_user_id']
                : $trade['initiator_user_id'];

            // Update card ownership
            $db->execute(
                'UPDATE user_cards SET user_id = ?, is_in_trade = FALSE WHERE user_card_id = ?',
                [$newOwnerId, $card['user_card_id']]
            );

            // Get user's active stamp
            $stampResult = $db->query(
                'SELECT stamp_id FROM user_stamps WHERE user_id = ? AND is_active = TRUE LIMIT 1',
                [$newOwnerId]
            );

            $stampId = !empty($stampResult) ? $stampResult[0]['stamp_id'] : 1;

            // Add to ownership history
            $db->execute(
                'INSERT INTO card_ownership_history (fk_user_card_id, fk_owner_stamp_id, stamp_position_x, stamp_position_y, stamp_rotation, card_moved_from_id)
                VALUES (?, ?, ?, ?, ?, ?)',
                [$card['user_card_id'], $stampId, rand(50, 200), rand(50, 200), rand(0, 359), $card['offered_by_user_id']]
            );
        }

        // Transfer credits
        if ($trade['initiator_credits'] > 0 || $trade['recipient_credits'] > 0) {
            if ($trade['initiator_credits'] > 0) {
                $db->callProcedure('sp_transfer_credits', [
                    ':p_sender_id' => $trade['initiator_user_id'],
                    ':p_recipient_id' => $trade['recipient_user_id'],
                    ':p_amount' => $trade['initiator_credits']
                ], ['p_error_message']);
            }

            if ($trade['recipient_credits'] > 0) {
                $db->callProcedure('sp_transfer_credits', [
                    ':p_sender_id' => $trade['recipient_user_id'],
                    ':p_recipient_id' => $trade['initiator_user_id'],
                    ':p_amount' => $trade['recipient_credits']
                ], ['p_error_message']);
            }
        }

        // Update trade status
        $db->execute(
            'UPDATE trades SET trade_status = ?, completed_at = NOW() WHERE trade_id = ?',
            ['completed', $tradeId]
        );

        $db->commit();

    } catch (Exception $e) {
        $db->rollback();
        throw new Exception('Trade completion failed: ' . $e->getMessage());
    }
}

/**
 * Cancel trade
 */
function handleCancelTrade() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['trade_id']);

    $tradeId = (int)$data['trade_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $trade = $db->query('SELECT * FROM trades WHERE trade_id = ?', [$tradeId]);

        if (empty($trade)) {
            ApiResponse::notFound('Trade not found');
        }

        $trade = $trade[0];

        // Verify user is part of trade
        if ($trade['initiator_user_id'] != $userId && $trade['recipient_user_id'] != $userId) {
            ApiResponse::forbidden('You are not part of this trade');
        }

        // Release all cards from trade
        $db->execute(
            'UPDATE user_cards SET is_in_trade = FALSE
            WHERE user_card_id IN (SELECT user_card_id FROM trade_cards WHERE trade_id = ?)',
            [$tradeId]
        );

        // Delete trade cards
        $db->execute('DELETE FROM trade_cards WHERE trade_id = ?', [$tradeId]);

        // Update trade status and reset confirmations
        $db->execute(
            'UPDATE trades SET trade_status = ?, initiator_confirmed = FALSE, recipient_confirmed = FALSE, updated_at = NOW() WHERE trade_id = ?',
            ['cancelled', $tradeId]
        );

        ApiResponse::success(null, 'Trade cancelled');

    } catch (Exception $e) {
        Logger::error('Cancel trade error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to cancel trade');
    }
}

/**
 * Get trade history
 */
function handleTradeHistory() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();
    $userId = $user['user_id'];

    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    try {
        $db = Database::getInstance();

        $trades = $db->query(
            'SELECT
                t.*,
                u_initiator.username as initiator_username,
                u_initiator.check_code as initiator_user_code,
                u_recipient.username as recipient_username,
                u_recipient.check_code as recipient_user_code
            FROM trades t
            JOIN users u_initiator ON t.initiator_user_id = u_initiator.user_id
            JOIN users u_recipient ON t.recipient_user_id = u_recipient.user_id
            WHERE (t.initiator_user_id = ? OR t.recipient_user_id = ?)
            AND t.trade_status IN (?, ?)
            ORDER BY t.completed_at DESC
            LIMIT ? OFFSET ?',
            [$userId, $userId, 'completed', 'cancelled', $limit, $offset]
        );

        // Fetch cards for each trade in history
        foreach ($trades as &$trade) {
            // Get trade cards
            $cards = $db->query(
                'SELECT
                    tc.trade_card_id,
                    tc.offered_by_user_id,
                    uc.user_card_id,
                    ct.card_name,
                    ct.character_image_path
                FROM trade_cards tc
                JOIN user_cards uc ON tc.user_card_id = uc.user_card_id
                JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
                JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
                WHERE tc.trade_id = ?',
                [$trade['trade_id']]
            );

            // Separate cards by owner
            $initiatorCards = [];
            $recipientCards = [];

            foreach ($cards as $card) {
                if ($card['offered_by_user_id'] == $trade['initiator_user_id']) {
                    $initiatorCards[] = $card;
                } else {
                    $recipientCards[] = $card;
                }
            }

            $trade['initiator_cards'] = $initiatorCards;
            $trade['recipient_cards'] = $recipientCards;
        }

        $totalCount = $db->query(
            'SELECT COUNT(*) as total FROM trades
            WHERE (initiator_user_id = ? OR recipient_user_id = ?)
            AND trade_status IN (?, ?)',
            [$userId, $userId, 'completed', 'cancelled']
        )[0]['total'];

        ApiResponse::success([
            'trades' => $trades,
            'pagination' => [
                'total' => (int)$totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $totalCount
            ]
        ], 'Trade history retrieved');

    } catch (Exception $e) {
        Logger::error('Trade history error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve trade history');
    }
}

/**
 * Add comment to trade
 */
function handleAddComment() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['trade_id', 'comment']);

    $tradeId = (int)$data['trade_id'];
    $comment = Security::sanitizeInput($data['comment']);
    $userId = $user['user_id'];

    if (strlen($comment) < 1 || strlen($comment) > 1000) {
        ApiResponse::validationError(['comment' => 'Comment must be between 1 and 1000 characters']);
    }

    try {
        $db = Database::getInstance();

        // Verify user is part of trade
        $trade = $db->query(
            'SELECT initiator_user_id, recipient_user_id FROM trades WHERE trade_id = ?',
            [$tradeId]
        );

        if (empty($trade)) {
            ApiResponse::notFound('Trade not found');
        }

        $trade = $trade[0];

        if ($trade['initiator_user_id'] != $userId && $trade['recipient_user_id'] != $userId) {
            ApiResponse::forbidden('You are not part of this trade');
        }

        // Add comment
        $result = $db->execute(
            'INSERT INTO trade_comments (trade_id, user_id, comment_text) VALUES (?, ?, ?)',
            [$tradeId, $userId, $comment]
        );

        $commentId = $result['last_insert_id'];

        // Notify other user
        $otherUserId = ($trade['initiator_user_id'] == $userId) ? $trade['recipient_user_id'] : $trade['initiator_user_id'];

        $db->execute(
            'INSERT INTO notifications (user_id, notification_type, notification_color, title, message, related_user_id, related_entity_type, related_entity_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $otherUserId,
                'trade',
                '#C0C0C0',
                'New Trade Comment',
                $user['username'] . ' commented on your trade',
                $userId,
                'trade',
                $tradeId
            ]
        );

        ApiResponse::success([
            'comment_id' => $commentId,
            'comment' => $comment,
            'username' => $user['username'],
            'created_at' => date('Y-m-d H:i:s')
        ], 'Comment added', 201);

    } catch (Exception $e) {
        Logger::error('Add comment error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to add comment');
    }
}

/**
 * Get comments for a trade
 */
function handleGetComments() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();

    if (!isset($_GET['trade_id'])) {
        ApiResponse::validationError(['trade_id' => 'Trade ID is required']);
    }

    $tradeId = (int)$_GET['trade_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        // Verify user is part of trade
        $trade = $db->query(
            'SELECT initiator_user_id, recipient_user_id FROM trades WHERE trade_id = ?',
            [$tradeId]
        );

        if (empty($trade)) {
            ApiResponse::notFound('Trade not found');
        }

        $trade = $trade[0];

        if ($trade['initiator_user_id'] != $userId && $trade['recipient_user_id'] != $userId) {
            ApiResponse::forbidden('You are not part of this trade');
        }

        // Get comments
        $comments = $db->query(
            'SELECT
                tc.comment_id,
                tc.comment_text,
                tc.created_at,
                u.user_id,
                u.username,
                u.check_code as user_code
            FROM trade_comments tc
            JOIN users u ON tc.user_id = u.user_id
            WHERE tc.trade_id = ?
            ORDER BY tc.created_at ASC',
            [$tradeId]
        );

        ApiResponse::success(['comments' => $comments], 'Comments retrieved');

    } catch (Exception $e) {
        Logger::error('Get comments error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve comments');
    }
}
