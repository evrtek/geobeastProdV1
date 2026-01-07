<?php
/**
 * Battle System API Endpoints
 * Handles battle deck management, battle initiation, and combat engine
 */

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_log('battles.php accessed - ' . date('Y-m-d H:i:s'));

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/ApiResponse.php';
//require_once __DIR__ . '/../core/BattleEngine.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../core/ChildActivityLogger.php';

// Set CORS headers
ApiResponse::setCorsHeaders();

// Get request method and path
$method = ApiResponse::getMethod();
$path = $_GET['action'] ?? '';

// Route to appropriate handler
switch ($path) {
    case 'create-deck':
        handleCreateDeck();
        break;
    case 'get-decks':
        handleGetDecks();
        break;
    case 'get-deck-details':
        handleGetDeckDetails();
        break;
    case 'delete-deck':
        handleDeleteDeck();
        break;
    case 'validate-deck':
        handleValidateDeck();
        break;
    case 'initiate-battle':
        handleInitiateBattle();
        break;
    case 'get-battle-status':
        handleGetBattleStatus();
        break;
    case 'select-card':
        handleSelectCard();
        break;
    case 'execute-attack':
        handleExecuteAttack();
        break;
    case 'forfeit-battle':
        handleForfeitBattle();
        break;
    case 'battle-history':
        handleBattleHistory();
        break;
    case 'accept-invitation':
        handleAcceptInvitation();
        break;
    case 'decline-invitation':
        handleDeclineInvitation();
        break;
    case 'pending-invitations':
        handlePendingInvitations();
        break;
    case 'active-battles':
        handleActiveBattles();
        break;
    case 'leaderboard':
        handleLeaderboard();
        break;
    case 'battle-stats':
        handleBattleStats();
        break;
    case 'type-advantages':
        handleTypeAdvantages();
        break;
    default:
        ApiResponse::notFound('Endpoint not found');
}

/**
 * Create a new battle deck
 */
function handleCreateDeck() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    // Validate required fields
    ApiResponse::requireFields($data, ['deck_name', 'cards']);

    $deckName = Security::sanitizeInput($data['deck_name']);
    $cardIds = $data['cards'];
    $userId = $user['user_id'];

    // Validate cards array
    if (!is_array($cardIds) || count($cardIds) !== 5) {
        ApiResponse::validationError(['cards' => 'Deck must contain exactly 5 cards']);
    }

    // Ensure all are integers
    $cardIds = array_map('intval', $cardIds);

    try {
        $db = Database::getInstance();

        // Validate deck using stored procedure
        $result = $db->callProcedure('sp_create_battle_deck', [
            ':p_user_id' => $userId,
            ':p_deck_name' => $deckName,
            ':p_card1_id' => $cardIds[0],
            ':p_card2_id' => $cardIds[1],
            ':p_card3_id' => $cardIds[2],
            ':p_card4_id' => $cardIds[3],
            ':p_card5_id' => $cardIds[4]
        ], ['p_deck_id', 'p_error_message']);

        $output = $result['output'];

        if (!empty($output['p_error_message'])) {
            ApiResponse::error($output['p_error_message'], 400);
        }

        $deckId = $output['p_deck_id'];

        // Get deck details
        $deckDetails = $db->callProcedure('sp_get_battle_deck_cards', [
            ':p_battle_deck_id' => $deckId
        ]);

        ApiResponse::success([
            'deck_id' => $deckId,
            'deck_name' => $deckName,
            'cards' => !empty($deckDetails['results'][0]) ? $deckDetails['results'][0] : []
        ], 'Battle deck created successfully', 201);

    } catch (Exception $e) {
        error_log('Create deck error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to create battle deck');
    }
}

/**
 * Get user's battle decks
 */
function handleGetDecks() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $result = $db->callProcedure('sp_get_user_battle_decks', [
            ':p_user_id' => $userId
        ]);

        $decks = !empty($result['results'][0]) ? $result['results'][0] : [];

        ApiResponse::success(['decks' => $decks], 'Battle decks retrieved');

    } catch (Exception $e) {
        error_log('Get decks error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve battle decks');
    }
}

/**
 * Get battle deck details
 */
function handleGetDeckDetails() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();

    if (!isset($_GET['deck_id'])) {
        ApiResponse::validationError(['deck_id' => 'Deck ID is required']);
    }

    $deckId = (int)$_GET['deck_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        // Verify ownership
        $deckCheck = $db->query(
            'SELECT user_id, deck_name FROM battle_decks WHERE battle_deck_id = ?',
            [$deckId]
        );

        if (empty($deckCheck)) {
            ApiResponse::notFound('Deck not found');
        }

        if ($deckCheck[0]['user_id'] != $userId) {
            ApiResponse::forbidden('You do not own this deck');
        }

        // Get deck cards
        $result = $db->callProcedure('sp_get_battle_deck_cards', [
            ':p_battle_deck_id' => $deckId
        ]);

        $cards = !empty($result['results'][0]) ? $result['results'][0] : [];

        ApiResponse::success([
            'deck_id' => $deckId,
            'deck_name' => $deckCheck[0]['deck_name'],
            'cards' => $cards
        ], 'Deck details retrieved');

    } catch (Exception $e) {
        error_log('Get deck details error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve deck details');
    }
}

/**
 * Delete a battle deck
 */
function handleDeleteDeck() {
    ApiResponse::requireMethod('DELETE');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['deck_id']);

    $deckId = (int)$data['deck_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        // Verify ownership
        $deckCheck = $db->query(
            'SELECT user_id FROM battle_decks WHERE battle_deck_id = ?',
            [$deckId]
        );

        if (empty($deckCheck)) {
            ApiResponse::notFound('Deck not found');
        }

        if ($deckCheck[0]['user_id'] != $userId) {
            ApiResponse::forbidden('You do not own this deck');
        }

        // Delete deck (cascade will delete deck_cards)
        $db->execute('DELETE FROM battle_decks WHERE battle_deck_id = ?', [$deckId]);

        ApiResponse::success(null, 'Battle deck deleted successfully');

    } catch (Exception $e) {
        error_log('Delete deck error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to delete deck');
    }
}

/**
 * Validate a deck before battle
 */
function handleValidateDeck() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['deck_id', 'battle_mode']);

    $deckId = (int)$data['deck_id'];
    $battleMode = $data['battle_mode'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $validation = BattleEngine::validateDeck($db, $userId, $deckId, $battleMode);

        if ($validation['valid']) {
            ApiResponse::success(['valid' => true], 'Deck is valid for battle');
        } else {
            ApiResponse::error($validation['message'], 400, ['issues' => $validation['issues']]);
        }

    } catch (Exception $e) {
        error_log('Validate deck error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to validate deck');
    }
}

/**
 * Initiate a battle
 */
function handleInitiateBattle() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['deck_id', 'battle_mode', 'opponent_type']);

    $deckId = (int)$data['deck_id'];
    $battleMode = $data['battle_mode'];
    $opponentType = $data['opponent_type']; // 'ai' or 'friend'
    $opponentUserId = isset($data['opponent_user_id']) ? (int)$data['opponent_user_id'] : null;
    $userId = $user['user_id'];

    // Validate battle mode
    $validModes = ['mode1_friendly', 'mode2_competitive', 'mode3_ultimate'];
    if (!in_array($battleMode, $validModes)) {
        ApiResponse::validationError(['battle_mode' => 'Invalid battle mode']);
    }

    try {
        $db = Database::getInstance();

        // Validate deck
        $deckValidation = BattleEngine::validateDeck($db, $userId, $deckId, $battleMode);
        if (!$deckValidation['valid']) {
            ApiResponse::error($deckValidation['message'], 400);
        }

        // Handle AI battle
        if ($opponentType === 'ai') {
            if ($battleMode !== 'mode1_friendly') {
                ApiResponse::error('AI battles must be Mode 1 (Friendly)', 400);
            }

            $battle = BattleEngine::createAIBattle($db, $userId, $deckId, $battleMode);
            ApiResponse::success($battle, 'AI battle initiated', 201);
        }

        // Handle friend battle
        if ($opponentType === 'friend') {
            if (!$opponentUserId) {
                ApiResponse::validationError(['opponent_user_id' => 'Opponent user ID required for friend battles']);
            }

            // Verify friendship
            $friendCheck = $db->query(
                'SELECT friendship_id FROM friendships
                WHERE ((requester_user_id = ? AND recipient_user_id = ?)
                   OR (requester_user_id = ? AND recipient_user_id = ?))
                AND status = ?',
                [$userId, $opponentUserId, $opponentUserId, $userId, 'approved']
            );

            if (empty($friendCheck)) {
                ApiResponse::error('You can only battle approved friends', 403);
            }

            $battle = BattleEngine::createFriendBattle($db, $userId, $opponentUserId, $deckId, $battleMode);
            ApiResponse::success($battle, 'Battle invitation created', 201);
        }

        ApiResponse::validationError(['opponent_type' => 'Invalid opponent type']);

    } catch (Exception $e) {
        error_log('Initiate battle error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to initiate battle');
    }
}

/**
 * Get battle status
 */
function handleGetBattleStatus() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();

    if (!isset($_GET['battle_id'])) {
        ApiResponse::validationError(['battle_id' => 'Battle ID is required']);
    }

    $battleId = (int)$_GET['battle_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $battleStatus = BattleEngine::getBattleStatus($db, $battleId, $userId);

        if (!$battleStatus) {
            ApiResponse::notFound('Battle not found or access denied');
        }

        ApiResponse::success($battleStatus, 'Battle status retrieved');

    } catch (Exception $e) {
        error_log('Get battle status error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve battle status');
    }
}

/**
 * Select card for next phase
 */
function handleSelectCard() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['battle_id', 'user_card_id']);

    $battleId = (int)$data['battle_id'];
    $userCardId = (int)$data['user_card_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $result = BattleEngine::selectCardForPhase($db, $battleId, $userId, $userCardId);

        ApiResponse::success($result, 'Card selected for battle phase');

    } catch (Exception $e) {
        error_log('Select card error: ' . $e->getMessage());
        ApiResponse::error($e->getMessage(), 400);
    }
}

/**
 * Execute attack in current phase
 */
function handleExecuteAttack() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['battle_id']);

    $battleId = (int)$data['battle_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $result = BattleEngine::executeAttack($db, $battleId, $userId);

        ApiResponse::success($result, 'Attack executed');

    } catch (Exception $e) {
        error_log('Execute attack error: ' . $e->getMessage());
        ApiResponse::error($e->getMessage(), 400);
    }
}

/**
 * Forfeit battle
 */
function handleForfeitBattle() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['battle_id']);

    $battleId = (int)$data['battle_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $result = BattleEngine::forfeitBattle($db, $battleId, $userId);

        ApiResponse::success($result, 'Battle forfeited');

    } catch (Exception $e) {
        error_log('Forfeit battle error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to forfeit battle');
    }
}

/**
 * Get battle history
 */
function handleBattleHistory() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();
    $userId = $user['user_id'];

    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 20;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    try {
        $db = Database::getInstance();

        $battles = $db->query(
            'SELECT
                b.battle_id,
                b.battle_mode,
                b.is_ai_battle,
                b.battle_status,
                b.winner_user_id,
                b.started_at,
                b.completed_at,
                u1.username as player1_username,
                u2.username as player2_username
            FROM battles b
            JOIN users u1 ON b.player1_user_id = u1.user_id
            JOIN users u2 ON b.player2_user_id = u2.user_id
            WHERE b.player1_user_id = ? OR b.player2_user_id = ?
            ORDER BY b.started_at DESC
            LIMIT ? OFFSET ?',
            [$userId, $userId, $limit, $offset]
        );

        $totalCount = $db->query(
            'SELECT COUNT(*) as total FROM battles WHERE player1_user_id = ? OR player2_user_id = ?',
            [$userId, $userId]
        )[0]['total'];

        ApiResponse::success([
            'battles' => $battles,
            'pagination' => [
                'total' => (int)$totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $totalCount
            ]
        ], 'Battle history retrieved');

    } catch (Exception $e) {
        Logger::error('Battle history error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve battle history');
    }
}

/**
 * Accept a battle invitation
 */
function handleAcceptInvitation() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['battle_id', 'deck_id']);

    $battleId = (int)$data['battle_id'];
    $deckId = (int)$data['deck_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $result = BattleEngine::acceptBattleInvitation($db, $battleId, $userId, $deckId);

        if ($result['success']) {
            Logger::info('Battle invitation accepted', ['battle_id' => $battleId, 'user_id' => $userId]);
            ApiResponse::success($result, 'Battle invitation accepted');
        } else {
            ApiResponse::error($result['message'], 400, ['issues' => $result['issues'] ?? []]);
        }

    } catch (Exception $e) {
        Logger::error('Accept invitation error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to accept battle invitation');
    }
}

/**
 * Decline a battle invitation
 */
function handleDeclineInvitation() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['battle_id']);

    $battleId = (int)$data['battle_id'];
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $result = BattleEngine::declineBattleInvitation($db, $battleId, $userId);

        if ($result['success']) {
            Logger::info('Battle invitation declined', ['battle_id' => $battleId, 'user_id' => $userId]);
            ApiResponse::success($result, 'Battle invitation declined');
        } else {
            ApiResponse::error($result['message'], 400);
        }

    } catch (Exception $e) {
        Logger::error('Decline invitation error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to decline battle invitation');
    }
}

/**
 * Get pending battle invitations
 */
function handlePendingInvitations() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $result = BattleEngine::getPendingInvitations($db, $userId);

        ApiResponse::success($result, 'Pending invitations retrieved');

    } catch (Exception $e) {
        Logger::error('Get pending invitations error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve pending invitations');
    }
}

/**
 * Get user's active battles
 */
function handleActiveBattles() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $result = BattleEngine::getActiveBattles($db, $userId);

        ApiResponse::success($result, 'Active battles retrieved');

    } catch (Exception $e) {
        Logger::error('Get active battles error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve active battles');
    }
}

/**
 * Get battle leaderboard
 */
function handleLeaderboard() {
    ApiResponse::requireMethod('GET');

    // Leaderboard is public, no auth required
    $type = $_GET['type'] ?? 'overall';
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 100;

    // Validate type
    $validTypes = ['overall', 'competitive', 'ultimate', 'win_rate'];
    if (!in_array($type, $validTypes)) {
        $type = 'overall';
    }

    try {
        $db = Database::getInstance();

        $result = BattleEngine::getLeaderboard($db, $type, $limit);

        ApiResponse::success($result, 'Leaderboard retrieved');

    } catch (Exception $e) {
        Logger::error('Get leaderboard error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve leaderboard');
    }
}

/**
 * Get user's battle statistics
 */
function handleBattleStats() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();

    // Can view own stats or another user's public stats
    $targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $user['user_id'];

    try {
        $db = Database::getInstance();

        $result = BattleEngine::getUserBattleStats($db, $targetUserId);

        // If viewing another user's stats, get their username too
        if ($targetUserId != $user['user_id']) {
            $userData = $db->query(
                'SELECT username, avatar_id FROM users WHERE user_id = ?',
                [$targetUserId]
            );
            if (!empty($userData)) {
                $result['user'] = [
                    'user_id' => $targetUserId,
                    'username' => $userData[0]['username'],
                    'avatar_id' => $userData[0]['avatar_id']
                ];
            }
        }

        ApiResponse::success($result, 'Battle statistics retrieved');

    } catch (Exception $e) {
        Logger::error('Get battle stats error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve battle statistics');
    }
}

/**
 * Get card type advantages reference
 */
function handleTypeAdvantages() {
    ApiResponse::requireMethod('GET');

    // Type advantages are public information
    $typeAdvantages = [
        'description' => 'Each card type has advantages over certain other types, dealing 1.5x damage',
        'advantages' => [
            ['attacker' => 'Igneous', 'attacker_id' => 1, 'strong_against' => ['Sedimentary', 'Fossil'], 'strong_against_ids' => [3, 8]],
            ['attacker' => 'Metamorphic', 'attacker_id' => 2, 'strong_against' => ['Igneous', 'Jewel'], 'strong_against_ids' => [1, 6]],
            ['attacker' => 'Sedimentary', 'attacker_id' => 3, 'strong_against' => ['Crystal', 'Metal'], 'strong_against_ids' => [7, 5]],
            ['attacker' => 'Ore', 'attacker_id' => 4, 'strong_against' => ['Metamorphic', 'Sedimentary'], 'strong_against_ids' => [2, 3]],
            ['attacker' => 'Metal', 'attacker_id' => 5, 'strong_against' => ['Ore', 'Crystal'], 'strong_against_ids' => [4, 7]],
            ['attacker' => 'Jewel', 'attacker_id' => 6, 'strong_against' => ['Metal', 'Fossil'], 'strong_against_ids' => [5, 8]],
            ['attacker' => 'Crystal', 'attacker_id' => 7, 'strong_against' => ['Jewel', 'Igneous'], 'strong_against_ids' => [6, 1]],
            ['attacker' => 'Fossil', 'attacker_id' => 8, 'strong_against' => ['Metamorphic', 'Ore'], 'strong_against_ids' => [2, 4]]
        ],
        'damage_multiplier' => 1.5
    ];

    ApiResponse::success($typeAdvantages, 'Type advantages retrieved');
}
