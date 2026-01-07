<?php
/**
 * Card Management API Endpoints
 * Handles card purchases, collection viewing, and card details
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
Logger::logRequest('/api/cards?action=' . $path, $method);

// Route to appropriate handler
switch ($path) {
    case 'purchase-pack':
        handlePurchasePack();
        break;
    case 'collection':
        handleGetCollection();
        break;
    case 'card-details':
        handleGetCardDetails();
        break;
    case 'view-card':
        handleViewCard();
        break;
    case 'available-packs':
        handleGetAvailablePacks();
        break;
    case 'add-stamp':
        handleAddStamp();
        break;
    case 'get-card-history':
        handleGetCardHistory();
        break;
    case 'search':
        handleSearchCards();
        break;
    case 'card-types':
        handleGetCardTypes();
        break;
    default:
        ApiResponse::notFound('Endpoint not found');
}

/**
 * Get available card pack options
 */
function handleGetAvailablePacks() {
    ApiResponse::requireMethod('GET');

    $packs = [
        [
            'size' => 3,
            'cost' => 3,
            'description' => 'Starter Pack - 3 random cards'
        ],
        [
            'size' => 5,
            'cost' => 5,
            'description' => 'Standard Pack - 5 random cards'
        ],
        [
            'size' => 10,
            'cost' => 10,
            'description' => 'Mega Pack - 10 random cards'
        ],
        [
            'size' => 20,
            'cost' => 20,
            'description' => 'Ultimate Pack - 20 random cards'
        ]
    ];

    ApiResponse::success($packs, 'Available card packs');
}

/**
 * Handle card pack purchase
 */
function handlePurchasePack() {
    ApiResponse::requireMethod('POST');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    // Validate required fields
    ApiResponse::requireFields($data, ['pack_size']);

    $packSize = (int)$data['pack_size'];

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

    // Validate pack size
    if (!in_array($packSize, [3, 5, 10, 20])) {
        ApiResponse::validationError(['pack_size' => 'Invalid pack size. Must be 3, 5, 10, or 20']);
    }

    $cost = $packSize; // 1 credit per card

    try {
        $db = Database::getInstance();

        // Check user credits
        $userResult = $db->query('SELECT credits FROM users WHERE user_id = ?', [$userId]);

        if (empty($userResult)) {
            ApiResponse::error('User not found', 404);
        }

        $currentCredits = (float)$userResult[0]['credits'];

        if ($currentCredits < $cost) {
            ApiResponse::error('Insufficient credits', 400, [
                'required' => $cost,
                'available' => $currentCredits,
                'shortage' => $cost - $currentCredits
            ]);
        }

        // Get all available published cards grouped by rarity
        $availableCards = getAvailableCardsByRarity($db);

        if (empty($availableCards)) {
            ApiResponse::error('No cards available for purchase', 503);
        }

        // Begin transaction
        $db->beginTransaction();

        try {
            // Deduct credits
            $newBalance = $currentCredits - $cost;
            $db->execute('UPDATE users SET credits = ? WHERE user_id = ?', [$newBalance, $userId]);

            // Log credit transaction
            $db->execute(
                'INSERT INTO credit_transactions (user_id, transaction_type, amount, balance_before, balance_after, description) VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, 'card_purchase', -$cost, $currentCredits, $newBalance, "Purchased $packSize card pack"]
            );

            // Allocate random cards based on rarity weights
            $allocatedCards = [];
            for ($i = 0; $i < $packSize; $i++) {
                $card = allocateRandomCard($availableCards);
                if ($card) {
                    $allocatedCards[] = $card;

                    // Add card to user's collection
                    $result = $db->execute(
                        'INSERT INTO user_cards (user_id, published_card_id) VALUES (?, ?)',
                        [$userId, $card['published_card_id']]
                    );

                    $userCardId = $result['last_insert_id'];

                    // Get user's active stamp
                    $stampResult = $db->query(
                        'SELECT stamp_id FROM user_stamps WHERE user_id = ? AND is_active = TRUE LIMIT 1',
                        [$userId]
                    );

                    $stampId = !empty($stampResult) ? $stampResult[0]['stamp_id'] : 1; // Default stamp if none

                    // Add ownership history with stamp
                    $stampX = rand(50, 200);
                    $stampY = rand(50, 200);
                    $stampRotation = rand(0, 359);

                    $db->execute(
                        'INSERT INTO card_ownership_history (fk_user_card_id, fk_owner_stamp_id, stamp_position_x, stamp_position_y, stamp_rotation, card_moved_from_id) VALUES (?, ?, ?, ?, ?, ?)',
                        [$userCardId, $stampId, $stampX, $stampY, $stampRotation, 0]
                    );
                }
            }

            // Commit transaction
            $db->commit();

            // Get detailed card information
            $cardDetails = [];
            foreach ($allocatedCards as $card) {
                $cardInfo = $db->query(
                    'SELECT
                        ct.card_name,
                        ct.description,
                        ct.character_image_path,
                        ct.speed_score,
                        ct.attack_score,
                        ct.defense_score,
                        cs.status_name,
                        ctype.type_name
                    FROM card_templates ct
                    JOIN card_status cs ON ct.status_id = cs.status_id
                    JOIN card_types ctype ON ct.card_type_id = ctype.card_type_id
                    WHERE ct.card_template_id = ?',
                    [$card['card_template_id']]
                );

                if (!empty($cardInfo)) {
                    $cardDetails[] = $cardInfo[0];
                }
            }

            // Log child activity if applicable
            ChildActivityLogger::logCardPurchase($userId, $packSize, $cost);

            Logger::info('Card pack purchased', [
                'user_id' => $userId,
                'pack_size' => $packSize,
                'cards_received' => count($allocatedCards)
            ]);

            ApiResponse::success([
                'cards_received' => count($allocatedCards),
                'credits_spent' => $cost,
                'remaining_credits' => $newBalance,
                'cards' => $cardDetails
            ], "Successfully purchased $packSize card pack!");

        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }

    } catch (Exception $e) {
        Logger::error('Card purchase error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to purchase cards. Please try again.');
    }
}

/**
 * Handle get user's card collection
 */
function handleGetCollection() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

    // Get filter and sort parameters
    $filterStatus = $_GET['status'] ?? null;
    $orderBy = $_GET['order_by'] ?? 'acquired_date';

    // Validate order_by
    $validOrderBy = ['speed', 'attack', 'defense', 'acquired_date'];
    if (!in_array($orderBy, $validOrderBy)) {
        $orderBy = 'acquired_date';
    }

    try {
        $db = Database::getInstance();

        // Call stored procedure
        $result = $db->callProcedure('sp_get_user_cards', [
            ':p_user_id' => $userCode,
            ':p_filter_status' => $filterStatus,
            ':p_order_by' => $orderBy
        ]);

        $cards = !empty($result['results'][0]) ? $result['results'][0] : [];

        // Get user stats
        $statsResult = $db->query(
            'SELECT
                COUNT(*) as total_cards,
                SUM(CASE WHEN is_in_marketplace THEN 1 ELSE 0 END) as cards_for_sale,
                SUM(CASE WHEN is_in_trade THEN 1 ELSE 0 END) as cards_in_trade
            FROM user_cards
            WHERE user_id = ?',
            [$userId]
        );

        $stats = !empty($statsResult) ? $statsResult[0] : [
            'total_cards' => 0,
            'cards_for_sale' => 0,
            'cards_in_trade' => 0
        ];

        // Get rarity breakdown
        $rarityResult = $db->query(
            'SELECT
                cs.status_name,
                COUNT(*) as count
            FROM user_cards uc
            JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
            JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
            JOIN card_status cs ON ct.status_id = cs.status_id
            WHERE uc.user_id = ?
            GROUP BY cs.status_name',
            [$userId]
        );

        $rarityBreakdown = [];
        foreach ($rarityResult as $row) {
            $rarityBreakdown[$row['status_name']] = (int)$row['count'];
        }

        ApiResponse::success([
            'stats' => $stats,
            'rarity_breakdown' => $rarityBreakdown,
            'cards' => $cards
        ], 'Card collection retrieved successfully');

    } catch (Exception $e) {
        Logger::error('Get collection error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve card collection');
    }
}

/**
 * Handle get card details
 */
function handleGetCardDetails() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

    if (!isset($_GET['user_card_id'])) {
        ApiResponse::validationError(['user_card_id' => 'Card ID is required']);
    }

    $userCardId = (int)$_GET['user_card_id'];

    try {
        $db = Database::getInstance();

        // Verify card ownership
        $ownershipCheck = $db->query(
            'SELECT user_id FROM user_cards WHERE user_card_id = ?',
            [$userCardId]
        );

        if (empty($ownershipCheck)) {
            ApiResponse::notFound('Card not found');
        }

        if ($ownershipCheck[0]['user_id'] != $userId) {
            ApiResponse::forbidden('You do not own this card');
        }

        // Get card details with ownership history
        $result = $db->callProcedure('sp_get_card_details', [
            ':p_user_card_id' => $userCardId
        ]);

        $cardDetails = !empty($result['results'][0]) ? $result['results'][0][0] : null;
        $ownershipHistory = !empty($result['results'][1]) ? $result['results'][1] : [];

        if (!$cardDetails) {
            ApiResponse::notFound('Card details not found');
        }

        // Get battle performance for this card
        $battleStats = $db->query(
            'SELECT
                COUNT(*) as total_battles,
                SUM(CASE WHEN winner_card_unique_id = ? THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN loser_card_unique_id = ? THEN 1 ELSE 0 END) as losses
            FROM battle_history
            WHERE winner_card_unique_id = ? OR loser_card_unique_id = ?',
            [$userCardId, $userCardId, $userCardId, $userCardId]
        );

        $cardDetails['battle_stats'] = !empty($battleStats) ? $battleStats[0] : [
            'total_battles' => 0,
            'wins' => 0,
            'losses' => 0
        ];

        ApiResponse::success([
            'card' => $cardDetails,
            'ownership_history' => $ownershipHistory,
            'stamp_count' => count($ownershipHistory)
        ], 'Card details retrieved successfully');

    } catch (Exception $e) {
        Logger::error('Get card details error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve card details');
    }
}

/**
 * Get available cards grouped by rarity
 */
function getAvailableCardsByRarity($db) {
    $query = "
        SELECT
            pc.published_card_id,
            pc.card_template_id,
            ct.card_name,
            cs.status_name,
            cs.rarity_weight
        FROM published_cards pc
        JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
        JOIN card_status cs ON ct.status_id = cs.status_id
        WHERE pc.published_card_id NOT IN (
            SELECT published_card_id FROM user_cards
        )
        ORDER BY cs.rarity_weight DESC
    ";

    $cards = $db->query($query);

    // Group by rarity
    $grouped = [];
    foreach ($cards as $card) {
        $rarity = $card['status_name'];
        if (!isset($grouped[$rarity])) {
            $grouped[$rarity] = [
                'weight' => (float)$card['rarity_weight'],
                'cards' => []
            ];
        }
        $grouped[$rarity]['cards'][] = $card;
    }

    return $grouped;
}

/**
 * Allocate a random card based on rarity weights
 */
function allocateRandomCard($availableCards) {
    if (empty($availableCards)) {
        return null;
    }

    // Calculate total weight
    $totalWeight = 0;
    foreach ($availableCards as $rarity => $data) {
        if (!empty($data['cards'])) {
            $totalWeight += $data['weight'];
        }
    }

    if ($totalWeight == 0) {
        return null;
    }

    // Generate random number
    $rand = (float)rand() / (float)getrandmax() * $totalWeight;

    // Select rarity based on weight
    $currentWeight = 0;
    $selectedRarity = null;

    foreach ($availableCards as $rarity => $data) {
        if (!empty($data['cards'])) {
            $currentWeight += $data['weight'];
            if ($rand <= $currentWeight) {
                $selectedRarity = $rarity;
                break;
            }
        }
    }

    // Select random card from chosen rarity
    if ($selectedRarity && !empty($availableCards[$selectedRarity]['cards'])) {
        $cards = $availableCards[$selectedRarity]['cards'];
        $randomIndex = array_rand($cards);
        return $cards[$randomIndex];
    }

    return null;
}

/**
 * View any card (public info, doesn't require ownership)
 */
function handleViewCard() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth(); // Must be logged in

    if (!isset($_GET['user_card_id'])) {
        ApiResponse::validationError(['user_card_id' => 'Card ID is required']);
    }

    $userCardId = (int)$_GET['user_card_id'];

    try {
        $db = Database::getInstance();

        // Get card details (public view - no ownership check)
        $cardResult = $db->query(
            'SELECT
                uc.user_card_id,
                uc.times_battled,
                uc.wins,
                uc.losses,
                ct.card_name,
                ct.description,
                ct.speed_score,
                ct.attack_score,
                ct.defense_score,
                ct.character_image_path,
                ct.attack_name,
                ct.attack_description,
                ct.science_fact,
                ct.header_color1,
                ct.header_color2,
                ct.border_color1,
                ct.border_color2,
                cs.status_name,
                ctype.type_name,
                u.username AS owner_username,
                (SELECT COUNT(*) FROM card_ownership_history WHERE fk_user_card_id = uc.user_card_id) AS stamp_count
            FROM user_cards uc
            JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
            JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
            JOIN card_status cs ON ct.status_id = cs.status_id
            JOIN card_types ctype ON ct.card_type_id = ctype.card_type_id
            JOIN users u ON uc.user_id = u.user_id
            WHERE uc.user_card_id = ?',
            [$userCardId]
        );

        if (empty($cardResult)) {
            ApiResponse::notFound('Card not found');
        }

        ApiResponse::success(['card' => $cardResult[0]], 'Card retrieved');

    } catch (Exception $e) {
        Logger::error('View card error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve card');
    }
}

/**
 * Add a stamp to a card (when card changes ownership)
 */
function handleAddStamp() {
    ApiResponse::requireMethod('POST');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['user_card_id', 'position_x', 'position_y']);

    $userCardId = (int)$data['user_card_id'];
    $positionX = (int)$data['position_x'];
    $positionY = (int)$data['position_y'];
    $rotation = isset($data['rotation']) ? (int)$data['rotation'] : 0;

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

    // Validate position bounds (card is roughly 300x400)
    if ($positionX < 0 || $positionX > 300 || $positionY < 0 || $positionY > 400) {
        ApiResponse::validationError(['position' => 'Position out of bounds']);
    }

    // Validate rotation
    $rotation = $rotation % 360;
    if ($rotation < 0) $rotation += 360;

    try {
        $db = Database::getInstance();

        // Verify card ownership
        $ownershipCheck = $db->query(
            'SELECT user_id FROM user_cards WHERE user_card_id = ?',
            [$userCardId]
        );

        if (empty($ownershipCheck) || $ownershipCheck[0]['user_id'] != $userId) {
            ApiResponse::forbidden('You do not own this card');
        }

        // Get user's active stamp
        $stampResult = $db->query(
            'SELECT stamp_id FROM user_stamps WHERE user_id = ? AND is_active = TRUE LIMIT 1',
            [$userId]
        );

        if (empty($stampResult)) {
            ApiResponse::error('No active stamp found. Please select a stamp first.', 400);
        }

        $stampId = $stampResult[0]['stamp_id'];

        // Add stamp to card history
        $db->execute(
            'INSERT INTO card_ownership_history (fk_user_card_id, fk_owner_stamp_id, stamp_position_x, stamp_position_y, stamp_rotation, card_moved_from_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$userCardId, $stampId, $positionX, $positionY, $rotation, $userId]
        );

        Logger::info('Stamp added to card', [
            'user_id' => $userId,
            'user_card_id' => $userCardId,
            'stamp_id' => $stampId
        ]);

        ApiResponse::success([
            'stamp_id' => $stampId,
            'position_x' => $positionX,
            'position_y' => $positionY,
            'rotation' => $rotation
        ], 'Stamp added to card');

    } catch (Exception $e) {
        Logger::error('Add stamp error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to add stamp to card');
    }
}

/**
 * Get card ownership history (all stamps)
 */
function handleGetCardHistory() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();

    if (!isset($_GET['user_card_id'])) {
        ApiResponse::validationError(['user_card_id' => 'Card ID is required']);
    }

    $userCardId = (int)$_GET['user_card_id'];

    try {
        $db = Database::getInstance();

        // Get ownership history with stamp details
        $history = $db->query(
            'SELECT
                coh.history_id,
                coh.datestamp,
                coh.stamp_position_x,
                coh.stamp_position_y,
                coh.stamp_rotation,
                s.stamp_id,
                s.stamp_name,
                s.stamp_image_path,
                s.color AS stamp_color,
                u.username AS previous_owner
            FROM card_ownership_history coh
            JOIN stamps s ON coh.fk_owner_stamp_id = s.stamp_id
            LEFT JOIN users u ON coh.card_moved_from_id = u.user_id
            WHERE coh.fk_user_card_id = ?
            ORDER BY coh.datestamp ASC',
            [$userCardId]
        );

        ApiResponse::success([
            'history' => $history,
            'total_stamps' => count($history)
        ], 'Card history retrieved');

    } catch (Exception $e) {
        Logger::error('Get card history error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve card history');
    }
}

/**
 * Search/filter cards in collection
 */
function handleSearchCards() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();

    // Convert user_code to user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

    // Get filter parameters
    $cardType = isset($_GET['card_type']) ? Security::sanitizeInput($_GET['card_type']) : null;
    $rarity = isset($_GET['rarity']) ? Security::sanitizeInput($_GET['rarity']) : null;
    $minSpeed = isset($_GET['min_speed']) ? (int)$_GET['min_speed'] : null;
    $minAttack = isset($_GET['min_attack']) ? (int)$_GET['min_attack'] : null;
    $minDefense = isset($_GET['min_defense']) ? (int)$_GET['min_defense'] : null;
    $searchName = isset($_GET['name']) ? Security::sanitizeInput($_GET['name']) : null;
    $orderBy = isset($_GET['order_by']) ? Security::sanitizeInput($_GET['order_by']) : 'card_name';
    $orderDir = isset($_GET['order_dir']) && strtolower($_GET['order_dir']) === 'desc' ? 'DESC' : 'ASC';
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    // Validate order_by
    $validOrderBy = ['card_name', 'speed_score', 'attack_score', 'defense_score', 'acquired_date', 'times_battled'];
    if (!in_array($orderBy, $validOrderBy)) {
        $orderBy = 'card_name';
    }

    try {
        $db = Database::getInstance();

        // Build query
        $sql = '
            SELECT
                uc.user_card_id,
                uc.acquired_date,
                uc.times_battled,
                uc.wins,
                uc.losses,
                uc.is_in_marketplace,
                uc.is_in_trade,
                ct.card_name,
                ct.description,
                ct.speed_score,
                ct.attack_score,
                ct.defense_score,
                ct.character_image_path,
                cs.status_name,
                ctype.type_name,
                (SELECT COUNT(*) FROM card_ownership_history WHERE fk_user_card_id = uc.user_card_id) AS stamp_count
            FROM user_cards uc
            JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
            JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
            JOIN card_status cs ON ct.status_id = cs.status_id
            JOIN card_types ctype ON ct.card_type_id = ctype.card_type_id
            WHERE uc.user_id = ?
        ';

        $params = [$userId];

        // Apply filters
        if ($cardType) {
            $sql .= ' AND ctype.type_name = ?';
            $params[] = $cardType;
        }

        if ($rarity) {
            $sql .= ' AND cs.status_name = ?';
            $params[] = $rarity;
        }

        if ($minSpeed !== null) {
            $sql .= ' AND ct.speed_score >= ?';
            $params[] = $minSpeed;
        }

        if ($minAttack !== null) {
            $sql .= ' AND ct.attack_score >= ?';
            $params[] = $minAttack;
        }

        if ($minDefense !== null) {
            $sql .= ' AND ct.defense_score >= ?';
            $params[] = $minDefense;
        }

        if ($searchName) {
            $sql .= ' AND ct.card_name LIKE ?';
            $params[] = '%' . $searchName . '%';
        }

        // Add order and pagination
        $sql .= " ORDER BY $orderBy $orderDir LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $cards = $db->query($sql, $params);

        // Get total count
        $countSql = '
            SELECT COUNT(*) as total
            FROM user_cards uc
            JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
            JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
            JOIN card_status cs ON ct.status_id = cs.status_id
            JOIN card_types ctype ON ct.card_type_id = ctype.card_type_id
            WHERE uc.user_id = ?
        ';

        $countParams = [$userId];

        if ($cardType) {
            $countSql .= ' AND ctype.type_name = ?';
            $countParams[] = $cardType;
        }
        if ($rarity) {
            $countSql .= ' AND cs.status_name = ?';
            $countParams[] = $rarity;
        }
        if ($minSpeed !== null) {
            $countSql .= ' AND ct.speed_score >= ?';
            $countParams[] = $minSpeed;
        }
        if ($minAttack !== null) {
            $countSql .= ' AND ct.attack_score >= ?';
            $countParams[] = $minAttack;
        }
        if ($minDefense !== null) {
            $countSql .= ' AND ct.defense_score >= ?';
            $countParams[] = $minDefense;
        }
        if ($searchName) {
            $countSql .= ' AND ct.card_name LIKE ?';
            $countParams[] = '%' . $searchName . '%';
        }

        $totalResult = $db->query($countSql, $countParams);
        $total = (int)$totalResult[0]['total'];

        ApiResponse::success([
            'cards' => $cards,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total
            ]
        ], 'Search results retrieved');

    } catch (Exception $e) {
        Logger::error('Search cards error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to search cards');
    }
}

/**
 * Get all card types
 */
function handleGetCardTypes() {
    ApiResponse::requireMethod('GET');

    try {
        $db = Database::getInstance();

        $types = $db->query(
            'SELECT card_type_id, type_name, type_color, is_battle_card, description
             FROM card_types
             ORDER BY type_name'
        );

        $rarities = $db->query(
            'SELECT status_id, status_name, rarity_weight, max_copies, description
             FROM card_status
             ORDER BY rarity_weight DESC'
        );

        ApiResponse::success([
            'types' => $types,
            'rarities' => $rarities
        ], 'Card types retrieved');

    } catch (Exception $e) {
        Logger::error('Get card types error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve card types');
    }
}
