<?php
/**
 * Battle System API Endpoints
 * Handles battle deck management, battle initiation, and combat engine
 */

// Enable error logging
/*error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_log('battles.php accessed - ' . date('Y-m-d H:i:s'));
*/

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/ApiResponse.php';
require_once __DIR__ . '/../core/BattleEngine.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../core/ChildActivityLogger.php';

// Set CORS headers
ApiResponse::setCorsHeaders();

// Get request method and path
$method = ApiResponse::getMethod();
$path = $_GET['action'] ?? '';

Logger::logRequest('/api/battle?action=' . $path, $method);

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
    case 'set-default-deck':
//        echo 'one';
        handleSetDefaultDeck();
        break;
    case 'get-default-deck':
        handleGetDefaultDeck();
        break;
    case 'get-friend-default-deck':
        handleGetFriendDefaultDeck();
        break;
    case 'send-battle-invitation':
        handleSendBattleInvitation();
        break;
    case 'respond-battle-invitation':
        handleRespondBattleInvitation();
        break;
    case 'get-pending-battle-invitations':
        handleGetPendingBattleInvitations();
        break;
    case 'get-battle-invitation-details':
        handleGetBattleInvitationDetails();
        break;
    case 'cancel-battle-invitation':
        handleCancelBattleInvitation();
        break;
    case 'log-phase-result':
        handleLogPhaseResult();
        break;
    case 'complete-battle-frontend':
        handleCompleteBattleFrontend();
        break;
    default:
        ApiResponse::notFound('Endpoint not found');
}

function testFunc() {
    $input = ApiResponse::getJsonBody();

    $userCode = Security::requireAuth();

    ApiResponse::requireFields($input, ['deck_name', 'cards']);

    $deckName = $input['deck_name'];
    $cardIds = $input['cards'];

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

    if (!is_array($cardIds) || count($cardIds) !== 5) {
        ApiResponse::validationError(['cards' => 'Deck must contain exactly 5 cards']);
    }

    $cardIds = array_map('intval', $cardIds);

    $db = Database::getInstance();

   $result = $db->callProcedure('sp_test_proc', []);

        $output = $result['output'];
       
    ApiResponse::success(['test' => 'it works'], 'Test function executed');
}

/**
 * Create a new battle deck
*/
function handleCreateDeck() {
    $input = ApiResponse::getJsonBody();

    $userCode = Security::requireAuth();

    ApiResponse::requireFields($input, ['deck_name', 'cards']);

    $deckName = $input['deck_name'];
    $cardIds = $input['cards'];

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

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

    $userCode = Security::requireAuth();

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

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

    $userCode = Security::requireAuth();

    if (!isset($_GET['deck_id'])) {
        ApiResponse::validationError(['deck_id' => 'Deck ID is required']);
    }

    $deckId = (int)$_GET['deck_id'];

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

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

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['deck_id']);

    $deckId = (int)$data['deck_id'];

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

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

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['deck_id', 'battle_mode']);

    $deckId = (int)$data['deck_id'];
    $battleMode = $data['battle_mode'];

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

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

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['deck_id', 'battle_mode']);

    $deckId = (int)$data['deck_id'];
    $battleModeInput = $data['battle_mode'];
    $opponentType = $data['opponent_type'] ?? 'ai'; // Default to 'ai' if not specified

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

    // Handle opponent user code/id
    $opponentUserId = null;
    if (isset($data['opponent_user_code'])) {
        $opponentUserId = UserCodeHelper::getUserIdFromCode($data['opponent_user_code']);
    } elseif (isset($data['opponent_user_id'])) {
        $opponentUserId = (int)$data['opponent_user_id'];
    }

    // Convert numeric battle mode to string format if needed
    $battleModeMap = [
        1 => 'mode1_friendly',
        2 => 'mode2_competitive',
        3 => 'mode3_ultimate',
        'mode1_friendly' => 'mode1_friendly',
        'mode2_competitive' => 'mode2_competitive',
        'mode3_ultimate' => 'mode3_ultimate'
    ];

    if (!isset($battleModeMap[$battleModeInput])) {
        ApiResponse::validationError(['battle_mode' => 'Invalid battle mode']);
    }

    $battleMode = $battleModeMap[$battleModeInput];

    try {
        $db = Database::getInstance();

        // Validate deck
        $deckValidation = BattleEngine::validateDeck($db, $userId, $deckId, $battleMode);
        if (!$deckValidation['valid']) {
            ApiResponse::error($deckValidation['message'], 400);
        }

        // Handle AI battle
        if ($opponentType === 'ai') {
            $battle = BattleEngine::createAIBattle($db, $userId, $deckId, $battleMode);
            ApiResponse::success($battle, 'AI battle initiated', 201);
        }

        // Handle friend battle
        if ($opponentType === 'friend') {
            if (!$opponentUserId) {
                ApiResponse::validationError(['opponent_user_code' => 'Opponent user code required for friend battles']);
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

    $userCode = Security::requireAuth();

    if (!isset($_GET['battle_id'])) {
        ApiResponse::validationError(['battle_id' => 'Battle ID is required']);
    }

    $battleId = (int)$_GET['battle_id'];

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

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

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['battle_id', 'user_card_id']);

    $battleId = (int)$data['battle_id'];
    $userCardId = (int)$data['user_card_id'];

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

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

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['battle_id']);

    $battleId = (int)$data['battle_id'];

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

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

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['battle_id']);

    $battleId = (int)$data['battle_id'];

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

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

    $userCode = Security::requireAuth();

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

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

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['battle_id', 'deck_id']);

    $battleId = (int)$data['battle_id'];
    $deckId = (int)$data['deck_id'];

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

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

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['battle_id']);

    $battleId = (int)$data['battle_id'];

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

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

    $userCode = Security::requireAuth();

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

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

    $userCode = Security::requireAuth();

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

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

    $userCode = Security::requireAuth();

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

    // Can view own stats or another user's public stats
    $targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $userId;

    try {
        $db = Database::getInstance();

        $result = BattleEngine::getUserBattleStats($db, $targetUserId);

        // If viewing another user's stats, get their username too
        if ($targetUserId != $userId) {
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

/**
 * Set default battle deck for user
 */
function handleSetDefaultDeck() {


    ApiResponse::requireMethod('POST');

    try {
        $userCode = Security::requireAuth();

        // Convert user_code to user_id for legacy tables
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $input = ApiResponse::getJsonBody();

        ApiResponse::requireFields($input, ['deck_id']);
        $deckId = (int)$input['deck_id'];

        $db = Database::getInstance();

        // Call stored procedure
        $result = $db->callProcedure('set_default_battle_deck', [
            ':p_user_id' => $userId,
            ':p_deck_id' => $deckId
        ]);


            ApiResponse::success(['deck_id' => $deckId], 'DONE');


    } catch (Exception $e) {
        Logger::error('Set default deck error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to set default deck');
    }
}

/**
 * Get default battle deck for user
 */
function handleGetDefaultDeck() {
    ApiResponse::requireMethod('GET');

    try {
        $userCode = Security::requireAuth();

        // Convert user_code to user_id for legacy tables
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $db = Database::getInstance();

        // Call stored procedure
        $result = $db->callProcedure('get_default_battle_deck', [
            ':p_user_id' => $userId
        ]);

        $deck = !empty($result['results'][0][0]) ? $result['results'][0][0] : null;

        if ($deck) {
            ApiResponse::success($deck, 'Default deck retrieved');
        } else {
            ApiResponse::success(null, 'No default deck set');
        }

    } catch (Exception $e) {
        Logger::error('Get default deck error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to get default deck');
    }
}

/**
 * Get friend's default battle deck with full card details
 */
function handleGetFriendDefaultDeck() {
    ApiResponse::requireMethod('GET');

    try {
        $userCode = Security::requireAuth();

        // Convert user_code to user_id for legacy tables
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $friendUserCode = $_GET['friend_user_code'] ?? null;

        if (!$friendUserCode) {
            ApiResponse::error('Friend user code required', 400);
            return;
        }

        // Convert friend's user code to user ID
        $friendUserId = UserCodeHelper::getUserIdFromCode($friendUserCode);

        if (!$friendUserId) {
            ApiResponse::error('Invalid friend user code', 400);
            return;
        }

        $db = Database::getInstance();

        // Verify friendship exists
        //sp_get_user_friends    ':p_user_id' => $friendUserId
        $friendship = $db->callProcedure('sp_get_user_friends', [
            ':p_user_id' => $friendUserId
        ]);

        if (empty($friendship)) {
            ApiResponse::error('Not friends with this user', 403);
            return;
        }

        // Get friend's default deck
        $result = $db->callProcedure('get_default_battle_deck', [
            ':p_user_id' => $friendUserId
        ]);

        $deck = !empty($result['results'][0][0]) ? $result['results'][0][0] : null;

        if (!$deck) {
            ApiResponse::error('Friend has no default deck set', 404);
            return;
        }

        // Get deck cards with full details
        $deckId = $deck['battle_deck_id'];
        $cards = $db->query("
            SELECT bdc.user_card_id,card_position, times_battled, wins, losses, last_battle_datetime  
,pc.card_template_id, pc.unique_card_code, card_name, description as card_description,
                ct.character_image_path as image_url,
                ct.attack_score as attack,
                ct.defense_score as defense,
                ct.speed_score as speed,
                cs.status_name as rarity,
                ct.status_id as rarity_id,
                ct.science_fact as educational_facts,
                uc.acquired_date as acquired_at,
                ct.card_template_id as ownership_count
from battle_deck_cards bdc 
LEFT JOIN user_cards uc on bdc.user_card_id = uc.user_card_id
LEFT JOIN published_cards pc on uc.published_card_id = pc.published_card_id
LEFT JOIN card_templates ct on pc.card_template_id = ct.card_template_id
LEFT JOIN card_status cs on ct.status_id = cs.status_id
where battle_deck_id = ?
ORDER BY card_position ASC;
        ", [$deckId]);

        $deck['cards'] = $cards;

        // Get friend username
        $userData = $db->query("SELECT username FROM users WHERE user_id = ?", [$friendUserId]);
        $deck['friend_username'] = !empty($userData) ? $userData[0]['username'] : null;

        Logger::info('Friend default deck retrieved', ['user_id' => $userId, 'friend_id' => $friendUserId]);
        ApiResponse::success($deck, 'Friend default deck retrieved');

    } catch (Exception $e) {
        Logger::error('Get friend default deck error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to get friend default deck');
    }
}

/**
 * Send battle invitation to a friend
 */
function handleSendBattleInvitation() {
    ApiResponse::requireMethod('POST');

    try {
        $userCode = Security::requireAuth();

        // Convert user_code to user_id for legacy tables
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $input = ApiResponse::getJsonBody();

        ApiResponse::requireFields($input, ['friend_user_id', 'deck_id', 'battle_mode']);

        $friendUserId = (int)$input['friend_user_id'];
        $deckId = (int)$input['deck_id'];
        $battleMode = (int)$input['battle_mode'];

        // Validate battle mode
        if (!in_array($battleMode, [1, 2, 3])) {
            ApiResponse::error('Invalid battle mode', 400);
            return;
        }

        $db = Database::getInstance();

        // Call stored procedure
        $result = $db->callProcedure('create_battle_invitation', [
            ':p_sender_user_id' => $userId,
            ':p_recipient_user_id' => $friendUserId,
            ':p_sender_deck_id' => $deckId,
            ':p_battle_mode' => $battleMode
        ], ['p_status', 'p_message', 'p_invitation_id']);

        $output = $result['output'];

        if ($output['p_status'] === 'success') {
            Logger::info('Battle invitation sent', [
                'sender_id' => $userId,
                'recipient_id' => $friendUserId,
                'invitation_id' => $output['p_invitation_id']
            ]);

            ChildActivityLogger::logActivity($userId, 'battle_invitation', "Sent battle invitation to user $friendUserId");

            ApiResponse::success([
                'invitation_id' => $output['p_invitation_id']
            ], $output['p_message']);
        } else {
            ApiResponse::error($output['p_message'], 400);
        }

    } catch (Exception $e) {
        Logger::error('Send battle invitation error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to send battle invitation');
    }
}

/**
 * Respond to a battle invitation (accept or decline)
 */
function handleRespondBattleInvitation() {
    ApiResponse::requireMethod('POST');

    try {
        $userCode = Security::requireAuth();

        // Convert user_code to user_id for legacy tables
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $input = ApiResponse::getJsonBody();

        ApiResponse::requireFields($input, ['invitation_id', 'response']);

        $invitationId = (int)$input['invitation_id'];
        $response = $input['response'];
        $deckId = isset($input['deck_id']) ? (int)$input['deck_id'] : null;

        // Validate response
        if (!in_array($response, ['accepted', 'declined'])) {
            ApiResponse::error('Invalid response. Must be "accepted" or "declined"', 400);
            return;
        }

        // If accepting, deck_id is required
        if ($response === 'accepted' && !$deckId) {
            ApiResponse::error('Deck ID required to accept invitation', 400);
            return;
        }

        $db = Database::getInstance();

        // Call stored procedure
        $result = $db->callProcedure('respond_battle_invitation', [
            ':p_invitation_id' => $invitationId,
            ':p_user_id' => $userId,
            ':p_response' => $response,
            ':p_deck_id' => $deckId
        ], ['p_status', 'p_message']);

        $output = $result['output'];

        if ($output['p_status'] === 'success') {
            Logger::info('Battle invitation responded', [
                'user_id' => $userId,
                'invitation_id' => $invitationId,
                'response' => $response
            ]);

            ChildActivityLogger::logActivity($userId, 'battle_invitation', "Responded to battle invitation: $response");

            ApiResponse::success([
                'invitation_id' => $invitationId,
                'response' => $response
            ], $output['p_message']);
        } else {
            ApiResponse::error($output['p_message'], 400);
        }

    } catch (Exception $e) {
        Logger::error('Respond battle invitation error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to respond to battle invitation');
    }
}

/**
 * Get all pending battle invitations for the current user
 */
function handleGetPendingBattleInvitations() {
    ApiResponse::requireMethod('GET');

    try {
        $userCode = Security::requireAuth();

        // Convert user_code to user_id for legacy tables
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $db = Database::getInstance();

        // Call stored procedure
        $result = $db->callProcedure('get_pending_invitations', [
            ':p_user_id' => $userId
        ]);

        $invitations = !empty($result['results'][0]) ? $result['results'][0] : [];

        ApiResponse::success(['invitations' => $invitations], 'Pending invitations retrieved');

    } catch (Exception $e) {
        Logger::error('Get pending invitations error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to get pending invitations');
    }
}

/**
 * Get details of a specific battle invitation
 */
function handleGetBattleInvitationDetails() {
    ApiResponse::requireMethod('GET');

    try {
        $userCode = Security::requireAuth();

        // Convert user_code to user_id for legacy tables
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $invitationId = $_GET['invitation_id'] ?? null;

        if (!$invitationId) {
            ApiResponse::error('Invitation ID required', 400);
            return;
        }

        $invitationId = (int)$invitationId;
        $db = Database::getInstance();

        // Call stored procedure
        $result = $db->callProcedure('get_invitation_details', [
            ':p_invitation_id' => $invitationId
        ]);

        $invitation = !empty($result['results'][0][0]) ? $result['results'][0][0] : null;

        if (!$invitation) {
            ApiResponse::error('Invitation not found', 404);
            return;
        }

        // Verify user is involved in this invitation
        if ($invitation['sender_user_id'] != $userId && $invitation['recipient_user_id'] != $userId) {
            ApiResponse::error('Not authorized to view this invitation', 403);
            return;
        }

        ApiResponse::success($invitation, 'Invitation details retrieved');

    } catch (Exception $e) {
        Logger::error('Get invitation details error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to get invitation details');
    }
}

/**
 * Cancel a battle invitation (sender only)
 */
function handleCancelBattleInvitation() {
    ApiResponse::requireMethod('POST');

    try {
        $userCode = Security::requireAuth();

        // Convert user_code to user_id for legacy tables
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $input = ApiResponse::getJsonBody();

        ApiResponse::requireFields($input, ['invitation_id']);

        $invitationId = (int)$input['invitation_id'];

        $db = Database::getInstance();

        // Call stored procedure
        $result = $db->callProcedure('cancel_battle_invitation', [
            ':p_invitation_id' => $invitationId,
            ':p_user_id' => $userId
        ], ['p_status', 'p_message']);

        $output = $result['output'];

        if ($output['p_status'] === 'success') {
            Logger::info('Battle invitation cancelled', [
                'user_id' => $userId,
                'invitation_id' => $invitationId
            ]);

            ChildActivityLogger::logActivity($userId, 'battle_invitation', "Cancelled battle invitation $invitationId");

            ApiResponse::success(['invitation_id' => $invitationId], $output['p_message']);
        } else {
            ApiResponse::error($output['p_message'], 400);
        }

    } catch (Exception $e) {
        Logger::error('Cancel battle invitation error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to cancel battle invitation');
    }
}

/**
 * Log phase result (called after each phase from frontend)
 */
function handleLogPhaseResult() {
    ApiResponse::requireMethod('POST');

    try {
        $userCode = Security::requireAuth();

        // Convert user_code to user_id for legacy tables
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $input = ApiResponse::getJsonBody();

        ApiResponse::requireFields($input, ['battle_id', 'phase_number', 'winner_card_id', 'loser_card_id', 'winner_stats', 'loser_stats']);

        $battleId = (int)$input['battle_id'];
        $phaseNumber = (int)$input['phase_number'];
        $winnerCardId = (int)$input['winner_card_id'];
        $loserCardId = (int)$input['loser_card_id'];
        $winnerStats = $input['winner_stats'];
        $loserStats = $input['loser_stats'];

        $db = Database::getInstance();

        // Verify user is part of this battle
        $battle = $db->query(
            'SELECT player1_user_id, player2_user_id, battle_mode FROM battles WHERE battle_id = ?',
            [$battleId]
        );

        if (empty($battle)) {
            ApiResponse::notFound('Battle not found');
        }

        if ($battle[0]['player1_user_id'] != $userId && $battle[0]['player2_user_id'] != $userId) {
            ApiResponse::forbidden('You are not part of this battle');
        }

        // Log the phase result
        $db->callProcedure('sp_log_battle_phase', [
            ':p_battle_id' => $battleId,
            ':p_battle_mode' => $battle[0]['battle_mode'],
            ':p_phase_number' => $phaseNumber,
            ':p_winner_card_id' => $winnerCardId,
            ':p_winner_start_speed' => $winnerStats['start_speed'],
            ':p_winner_start_attack' => $winnerStats['start_attack'],
            ':p_winner_start_defense' => $winnerStats['start_defense'],
            ':p_winner_end_speed' => $winnerStats['end_speed'],
            ':p_winner_end_attack' => $winnerStats['end_attack'],
            ':p_winner_end_defense' => $winnerStats['end_defense'],
            ':p_loser_card_id' => $loserCardId,
            ':p_loser_start_speed' => $loserStats['start_speed'],
            ':p_loser_start_attack' => $loserStats['start_attack'],
            ':p_loser_start_defense' => $loserStats['start_defense'],
            ':p_loser_end_speed' => $loserStats['end_speed'],
            ':p_loser_end_attack' => $loserStats['end_attack'],
            ':p_loser_end_defense' => $loserStats['end_defense']
        ]);

        Logger::info('Phase result logged', [
            'battle_id' => $battleId,
            'phase' => $phaseNumber,
            'winner_card' => $winnerCardId
        ]);

        ApiResponse::success(['phase' => $phaseNumber], 'Phase result logged successfully');

    } catch (Exception $e) {
        Logger::error('Log phase result error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to log phase result');
    }
}

/**
 * Complete battle from frontend (called when all phases are done)
 */
function handleCompleteBattleFrontend() {
    ApiResponse::requireMethod('POST');

    try {
        $userCode = Security::requireAuth();

        // Convert user_code to user_id for legacy tables
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $input = ApiResponse::getJsonBody();

        ApiResponse::requireFields($input, ['battle_id', 'player1_wins', 'player2_wins']);

        $battleId = (int)$input['battle_id'];
        $player1Wins = (int)$input['player1_wins'];
        $player2Wins = (int)$input['player2_wins'];

        $db = Database::getInstance();

        // Verify user is part of this battle
        $battle = $db->query(
            'SELECT battle_id, player1_user_id, player2_user_id, battle_mode, battle_status FROM battles WHERE battle_id = ?',
            [$battleId]
        );

        if (empty($battle)) {
            ApiResponse::notFound('Battle not found');
        }

        $battle = $battle[0];

        if ($battle['player1_user_id'] != $userId && $battle['player2_user_id'] != $userId) {
            ApiResponse::forbidden('You are not part of this battle');
        }

        // Validate total phases
        if ($player1Wins + $player2Wins !== 5) {
            ApiResponse::validationError(['phases' => 'Total phase wins must equal 5']);
        }

        // Determine winner
        $winnerId = null;
        $isDraw = false;

        if ($player1Wins > $player2Wins) {
            $winnerId = $battle['player1_user_id'];
            $loserId = $battle['player2_user_id'];
        } elseif ($player2Wins > $player1Wins) {
            $winnerId = $battle['player2_user_id'];
            $loserId = $battle['player1_user_id'];
        } else {
            // Should never happen with 5 phases
            $isDraw = true;
            Logger::error('Impossible draw in frontend battle completion', [
                'battle_id' => $battleId,
                'player1_wins' => $player1Wins,
                'player2_wins' => $player2Wins
            ]);
        }

        if ($isDraw) {
            // Complete as draw
            $db->execute(
                'UPDATE battles SET battle_status = ?, completed_at = NOW() WHERE battle_id = ?',
                ['completed', $battleId]
            );

            // Update stats for both players
            BattleEngine::updateBattleStats($db, $battle['player1_user_id'], null, $battle['battle_mode']);
            BattleEngine::updateBattleStats($db, $battle['player2_user_id'], null, $battle['battle_mode']);

            Logger::info('Battle completed as draw (frontend)', ['battle_id' => $battleId]);
        } else {
            // Complete with winner
            $db->callProcedure('sp_complete_battle', [
                ':p_battle_id' => $battleId,
                ':p_winner_user_id' => $winnerId
            ]);

            // Update battle stats
            BattleEngine::updateBattleStats($db, $winnerId, true, $battle['battle_mode']);
            BattleEngine::updateBattleStats($db, $loserId, false, $battle['battle_mode']);

            // Transfer rewards if applicable
            BattleEngine::transferBattleRewards($db, $battleId, $winnerId, $loserId, $battle['battle_mode']);

            Logger::info('Battle completed (frontend)', [
                'battle_id' => $battleId,
                'winner_id' => $winnerId,
                'scores' => $player1Wins . '-' . $player2Wins
            ]);

            ChildActivityLogger::logBattle($winnerId, $loserId, $battle['battle_mode'], 'completed');
        }

        ApiResponse::success([
            'battle_id' => $battleId,
            'winner_id' => $winnerId,
            'is_draw' => $isDraw,
            'scores' => $player1Wins . '-' . $player2Wins
        ], 'Battle completed successfully');

    } catch (Exception $e) {
        Logger::error('Complete battle frontend error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to complete battle');
    }
}
