<?php
/**
 * Marketplace API Endpoints
 * Handles public marketplace for buying and selling cards
 */

// Debug logging at the very start
file_put_contents(__DIR__ . '/../debug_marketplace.log',
    date('Y-m-d H:i:s') . ' MARKETPLACE INIT: ' . json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'action' => $_GET['action'] ?? 'none',
        'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
    ]) . "\n",
    FILE_APPEND
);

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/ApiResponse.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../core/ChildActivityLogger.php';
require_once __DIR__ . '/../core/EmailService.php';

// Set CORS headers
ApiResponse::setCorsHeaders();

// Get request method and path
$method = ApiResponse::getMethod();
$path = $_GET['action'] ?? '';

// Log API request
Logger::logRequest('/api/marketplace?action=' . $path, $method);

// Route to appropriate handler
switch ($path) {
    case 'create-listing':
        handleCreateListing();
        break;
    case 'search-listings':
        handleSearchListings();
        break;
    case 'get-listing':
        handleGetListing();
        break;
    case 'my-listings':
        handleGetMyListings();
        break;
    case 'make-offer':
        handleMakeOffer();
        break;
    case 'respond-to-offer':
        handleRespondToOffer();
        break;
    case 'confirm-purchase':
        handleConfirmPurchase();
        break;
    case 'cancel-listing':
        handleCancelListing();
        break;
    case 'update-listing':
        handleUpdateListing();
        break;
    case 'purchase-history':
        handlePurchaseHistory();
        break;
    case 'sales-history':
        handleSalesHistory();
        break;
    case 'my-offers-received':
        handleGetOffersReceived();
        break;
    case 'my-offers-made':
        handleGetOffersMade();
        break;
    default:
        ApiResponse::notFound('Endpoint not found');
}

/**
 * Create marketplace listing
 */
function handleCreateListing() {
    // Log the raw request for debugging
    file_put_contents(__DIR__ . '/../debug_marketplace.log',
        date('Y-m-d H:i:s') . ' CREATE LISTING REQUEST: ' . json_encode([
            'method' => $_SERVER['REQUEST_METHOD'],
            'action' => $_GET['action'] ?? '',
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
            'raw_body' => file_get_contents('php://input')
        ]) . "\n",
        FILE_APPEND
    );

    ApiResponse::requireMethod('POST');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['user_card_id', 'asking_price']);

    $userCardId = (int)$data['user_card_id'];
    $askingPrice = (float)$data['asking_price'];

    if ($askingPrice <= 0) {
        ApiResponse::validationError(['asking_price' => 'Price must be greater than zero']);
    }

    try {
        $db = Database::getInstance();

        // Need user_id for legacy tables
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        // Call stored procedure
        $result = $db->callProcedure('sp_create_marketplace_listing', [
            ':p_seller_id' => $userId,
            ':p_user_card_id' => $userCardId,
            ':p_asking_price' => $askingPrice
        ], ['p_listing_id', 'p_error_message']);

        $output = $result['output'];

        if (!empty($output['p_error_message'])) {
            ApiResponse::error($output['p_error_message'], 400);
        }

        $listingId = $output['p_listing_id'];

        // Get listing details
        $listing = $db->query(
            'SELECT listing_status, parent_approved FROM marketplace_listings WHERE listing_id = ?',
            [$listingId]
        )[0];

        $message = 'Listing created successfully';
        if ($listing['parent_approved'] === 0) {
            $message .= ' - Pending parent approval';
        }

        // Log child activity if seller is a child account
        $sellerAccountType = $db->query(
            "SELECT at.account_type_name FROM users u
            JOIN account_types at ON u.account_type_id = at.account_type_id
            WHERE u.check_code = ?",
            [$userCode]
        );

        if (!empty($sellerAccountType) && $sellerAccountType[0]['account_type_name'] === 'child') {
            ChildActivityLogger::log($userId, 'marketplace_listing_created', [
                'listing_id' => $listingId,
                'asking_price' => $askingPrice,
                'card_id' => $userCardId
            ]);
        }

        ApiResponse::success([
            'listing_id' => $listingId,
            'status' => $listing['listing_status'],
            'requires_parent_approval' => $listing['parent_approved'] === 0
        ], $message, 201);

    } catch (Exception $e) {
        Logger::error('Create listing error: ' . $e->getMessage());

        // Extract user-friendly error message from exception
        $errorMessage = $e->getMessage();

        // Check for specific error conditions
        if (strpos($errorMessage, 'Card in battle deck') !== false) {
            ApiResponse::error('Card is currently in a battle deck. Remove it from all decks before listing.', 400);
        } elseif (strpos($errorMessage, 'Card unavailable') !== false) {
            ApiResponse::error('Card is currently in a trade.', 400);
        } elseif (strpos($errorMessage, 'Card ownership mismatch') !== false) {
            ApiResponse::error('Card does not belong to user.', 400);
        } else {
            ApiResponse::serverError('Failed to create listing');
        }
    }
}

/**
 * Search marketplace listings
 */
function handleSearchListings() {
    ApiResponse::requireMethod('GET');

    // Optional authentication
    $user = ApiResponse::getCurrentUser();

    // Search filters
    $cardType = isset($_GET['card_type']) ? Security::sanitizeInput($_GET['card_type']) : null;
    $rarity = isset($_GET['rarity']) ? Security::sanitizeInput($_GET['rarity']) : null;
    $minSpeed = isset($_GET['min_speed']) ? (int)$_GET['min_speed'] : null;
    $minAttack = isset($_GET['min_attack']) ? (int)$_GET['min_attack'] : null;
    $minDefense = isset($_GET['min_defense']) ? (int)$_GET['min_defense'] : null;
    $maxPrice = isset($_GET['max_price']) ? (float)$_GET['max_price'] : null;

    // Pagination
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 20;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    // Sorting
    $orderBy = isset($_GET['order_by']) ? $_GET['order_by'] : 'listed_at';
    $orderDir = isset($_GET['order_dir']) && strtoupper($_GET['order_dir']) === 'ASC' ? 'ASC' : 'DESC';

    $validOrderBy = ['listed_at', 'asking_price', 'speed_score', 'attack_score', 'defense_score', 'stamp_count'];
    if (!in_array($orderBy, $validOrderBy)) {
        $orderBy = 'listed_at';
    }

    try {
        $db = Database::getInstance();

        // Call search stored procedure
        $result = $db->callProcedure('sp_search_marketplace', [
            ':p_card_type' => $cardType,
            ':p_rarity' => $rarity,
            ':p_min_speed' => $minSpeed,
            ':p_min_attack' => $minAttack,
            ':p_min_defense' => $minDefense,
            ':p_max_price' => $maxPrice
        ]);

        $listings = !empty($result['results'][0]) ? $result['results'][0] : [];

        // Apply sorting and pagination (since procedure doesn't handle it)
        usort($listings, function($a, $b) use ($orderBy, $orderDir) {
            $aVal = $a[$orderBy] ?? 0;
            $bVal = $b[$orderBy] ?? 0;

            if ($orderDir === 'ASC') {
                return $aVal <=> $bVal;
            } else {
                return $bVal <=> $aVal;
            }
        });

        $totalCount = count($listings);
        $listings = array_slice($listings, $offset, $limit);

        // Mask seller usernames (show as "Seller #XXX")
        foreach ($listings as &$listing) {
            // Handle both old and new stored procedure formats
            if (isset($listing['seller_user_id'])) {
                $listing['seller_username'] = 'Seller #' . substr(md5($listing['seller_user_id']), 0, 6);
                unset($listing['seller_user_id']); // Remove actual ID for privacy
            } else {
                // Fallback if seller_user_id is not in the result
                $listing['seller_username'] = 'Seller #' . substr(md5($listing['listing_id']), 0, 6);
            }
        }

        ApiResponse::success([
            'listings' => $listings,
            'pagination' => [
                'total' => $totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $totalCount
            ]
        ], 'Marketplace listings retrieved');

    } catch (Exception $e) {
        Logger::error('Search listings error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to search marketplace');
    }
}

/**
 * Get specific listing details
 */
function handleGetListing() {
    ApiResponse::requireMethod('GET');

    if (!isset($_GET['listing_id'])) {
        ApiResponse::validationError(['listing_id' => 'Listing ID is required']);
    }

    $listingId = (int)$_GET['listing_id'];
    $user = ApiResponse::getCurrentUser();

    try {
        $db = Database::getInstance();

        $listing = $db->query(
            'SELECT
                ml.listing_id,
                ml.asking_price,
                ml.listing_status,
                ml.listed_at,
                ml.seller_user_id,
                uc.user_card_id,
                uc.wins,
                uc.losses,
                uc.times_battled,
                ct.card_name,
                ct.description,
                ct.speed_score,
                ct.attack_score,
                ct.defense_score,
                ct.character_image_path,
                ct.attack_name,
                ct.attack_description,
                cs.status_name,
                ctype.type_name,
                (SELECT COUNT(*) FROM card_ownership_history WHERE fk_user_card_id = uc.user_card_id) AS stamp_count
            FROM marketplace_listings ml
            JOIN user_cards uc ON ml.user_card_id = uc.user_card_id
            JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
            JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
            JOIN card_status cs ON ct.status_id = cs.status_id
            JOIN card_types ctype ON ct.card_type_id = ctype.card_type_id
            WHERE ml.listing_id = ?',
            [$listingId]
        );

        if (empty($listing)) {
            ApiResponse::notFound('Listing not found');
        }

        $listing = $listing[0];

        // Check if user is the seller
        $currentUserId = null;
        if ($user) {
            require_once __DIR__ . '/../core/UserCodeHelper.php';
            $currentUserId = UserCodeHelper::getUserIdFromCode($user['user_code']);
        }
        $isSeller = $user && $listing['seller_user_id'] == $currentUserId;

        if (!$isSeller) {
            // Mask seller username for buyers
            $listing['seller_username'] = 'Seller #' . substr(md5($listing['seller_user_id']), 0, 6);
            unset($listing['seller_user_id']);
        } else {
            // Show actual info to seller
            $sellerInfo = $db->query('SELECT username FROM users WHERE user_id = ?', [$listing['seller_user_id']]);
            $listing['seller_username'] = $sellerInfo[0]['username'];
        }

        // Get card ownership history
        $history = $db->query(
            'SELECT
                coh.datestamp,
                coh.card_moved_from_id,
                u.username as previous_owner
            FROM card_ownership_history coh
            LEFT JOIN users u ON coh.card_moved_from_id = u.user_id
            WHERE coh.fk_user_card_id = ?
            ORDER BY coh.datestamp DESC',
            [$listing['user_card_id']]
        );

        $listing['ownership_history'] = $history;
        $listing['is_seller'] = $isSeller;

        ApiResponse::success($listing, 'Listing details retrieved');

    } catch (Exception $e) {
        Logger::error('Get listing error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve listing');
    }
}

/**
 * Get user's marketplace listings
 */
function handleGetMyListings() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();

    $status = isset($_GET['status']) ? Security::sanitizeInput($_GET['status']) : null;

    try {
        $db = Database::getInstance();

        // Need user_id for legacy table
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $query = 'SELECT
            ml.listing_id,
            ml.asking_price,
            ml.listing_status,
            ml.parent_approved,
            ml.listed_at,
            ct.card_name,
            ct.character_image_path,
            cs.status_name,
            (SELECT COUNT(*) FROM marketplace_offers WHERE listing_id = ml.listing_id) as offer_count
        FROM marketplace_listings ml
        JOIN user_cards uc ON ml.user_card_id = uc.user_card_id
        JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
        JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
        JOIN card_status cs ON ct.status_id = cs.status_id
        WHERE ml.seller_user_id = ?';

        $params = [$userId];

        if ($status) {
            $query .= ' AND ml.listing_status = ?';
            $params[] = $status;
        }

        $query .= ' ORDER BY ml.listed_at DESC';

        $listings = $db->query($query, $params);

        ApiResponse::success(['listings' => $listings], 'Your listings retrieved');

    } catch (Exception $e) {
        Logger::error('Get my listings error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve your listings');
    }
}

/**
 * Make offer on listing
 */
function handleMakeOffer() {
    ApiResponse::requireMethod('POST');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['listing_id', 'offer_amount']);

    $listingId = (int)$data['listing_id'];
    $offerPrice = (float)$data['offer_amount'];

    if ($offerPrice <= 0) {
        ApiResponse::validationError(['offer' => 'Offer price must be greater than zero']);
    }

    try {
        $db = Database::getInstance();

        // Need user_id for legacy tables
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        // Get listing details
        $listing = $db->query(
            'SELECT seller_user_id, asking_price, listing_status FROM marketplace_listings WHERE listing_id = ?',
            [$listingId]
        );

        if (empty($listing)) {
            ApiResponse::notFound('Listing not found');
        }

        $listing = $listing[0];

        if ($listing['listing_status'] !== 'active') {
            ApiResponse::error('Listing is not active', 400);
        }

        if ($listing['seller_user_id'] == $userId) {
            ApiResponse::error('Cannot buy your own listing', 400);
        }

        // Check buyer has enough credits
        $buyerCredits = $db->query('SELECT credits FROM users WHERE check_code = ?', [$userCode])[0]['credits'];

        if ($buyerCredits < $offerPrice) {
            ApiResponse::error('Insufficient credits', 400, [
                'required' => $offerPrice,
                'available' => $buyerCredits
            ]);
        }

        // Create offer
        $result = $db->execute(
            'INSERT INTO marketplace_offers (listing_id, buyer_user_id, offer_price, offer_status)
            VALUES (?, ?, ?, ?)',
            [$listingId, $userId, $offerPrice, 'pending_seller']
        );

        $offerId = $result['last_insert_id'];

        // Notify seller
        $db->execute(
            'INSERT INTO notifications (user_id, notification_type, notification_color, title, message, related_user_id, related_entity_type, related_entity_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $listing['seller_user_id'],
                'marketplace',
                '#C0C0C0',
                'New Marketplace Offer',
                'You received an offer on your listing',
                $userId,
                'marketplace_offer',
                $offerId
            ]
        );

        // Send email notification to seller
        try {
            $sellerInfo = $db->query(
                'SELECT u.email, u.username
                FROM users u
                WHERE u.user_id = ?',
                [$listing['seller_user_id']]
            );

            $buyerInfo = $db->query('SELECT username FROM users WHERE check_code = ?', [$userCode]);

            $cardInfo = $db->query(
                'SELECT ct.card_name
                FROM marketplace_listings ml
                JOIN user_cards uc ON ml.user_card_id = uc.user_card_id
                JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
                JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
                WHERE ml.listing_id = ?',
                [$listingId]
            );

            if (!empty($sellerInfo) && !empty($buyerInfo) && !empty($cardInfo)) {
                EmailService::sendMarketplaceOfferEmail(
                    $sellerInfo[0]['email'],
                    $sellerInfo[0]['username'],
                    $cardInfo[0]['card_name'],
                    $offerPrice,
                    $listing['asking_price'],
                    $buyerInfo[0]['username']
                );
            }
        } catch (Exception $emailError) {
            Logger::error('Failed to send offer email: ' . $emailError->getMessage());
            // Don't fail the offer creation if email fails
        }

        $isCounterOffer = $offerPrice < $listing['asking_price'];

        // Log child activity if buyer is a child account
        $buyerAccountType = $db->query(
            "SELECT at.account_type_name FROM users u
            JOIN account_types at ON u.account_type_id = at.account_type_id
            WHERE u.check_code = ?",
            [$userCode]
        );

        if (!empty($buyerAccountType) && $buyerAccountType[0]['account_type_name'] === 'child') {
            ChildActivityLogger::log($userId, 'marketplace_offer_made', [
                'listing_id' => $listingId,
                'offer_id' => $offerId,
                'offer_price' => $offerPrice
            ]);
        }

        ApiResponse::success([
            'offer_id' => $offerId,
            'offer_price' => $offerPrice,
            'asking_price' => $listing['asking_price'],
            'is_counter_offer' => $isCounterOffer,
            'status' => 'pending_seller'
        ], $isCounterOffer ? 'Counter-offer submitted' : 'Offer submitted', 201);

    } catch (Exception $e) {
        Logger::error('Make offer error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to make offer');
    }
}

/**
 * Respond to offer (seller accepts/rejects)
 */
function handleRespondToOffer() {
    ApiResponse::requireMethod('POST');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['offer_id', 'response']);

    $offerId = (int)$data['offer_id'];
    $response = Security::sanitizeInput($data['response']); // 'accept' or 'reject'

    // Need user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

    if (!in_array($response, ['accept', 'reject'])) {
        ApiResponse::validationError(['response' => 'Response must be accept or reject']);
    }

    try {
        $db = Database::getInstance();

        // Get offer and verify seller
        $offer = $db->query(
            'SELECT
                mo.*,
                ml.seller_user_id,
                ml.user_card_id
            FROM marketplace_offers mo
            JOIN marketplace_listings ml ON mo.listing_id = ml.listing_id
            WHERE mo.offer_id = ?',
            [$offerId]
        );

        if (empty($offer)) {
            ApiResponse::notFound('Offer not found');
        }

        $offer = $offer[0];

        if ($offer['seller_user_id'] != $userId) {
            ApiResponse::forbidden('Only the seller can respond to offers');
        }

        if ($response === 'reject') {
            $db->execute(
                'UPDATE marketplace_offers SET offer_status = ? WHERE offer_id = ?',
                ['rejected', $offerId]
            );

            // Notify buyer
            $db->execute(
                'INSERT INTO notifications (user_id, notification_type, notification_color, title, message, related_user_id, related_entity_type, related_entity_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $offer['buyer_user_id'],
                    'marketplace',
                    '#C0C0C0',
                    'Offer Rejected',
                    'Your marketplace offer was rejected',
                    $userId,
                    'marketplace_offer',
                    $offerId
                ]
            );

            ApiResponse::success(['status' => 'rejected'], 'Offer rejected');
        }

        if ($response === 'accept') {
            // Update offer status
            $db->execute(
                'UPDATE marketplace_offers SET offer_status = ?, seller_confirmed = TRUE WHERE offer_id = ?',
                ['seller_approved', $offerId]
            );

            // Notify buyer for final confirmation
            $db->execute(
                'INSERT INTO notifications (user_id, notification_type, notification_color, title, message, related_user_id, related_entity_type, related_entity_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $offer['buyer_user_id'],
                    'marketplace',
                    '#C0C0C0',
                    'Offer Accepted',
                    'Your offer was accepted. Please confirm purchase.',
                    $userId,
                    'marketplace_offer',
                    $offerId
                ]
            );

            // Send email notification to buyer
            try {
                $buyerInfo = $db->query(
                    'SELECT u.email, u.username
                    FROM users u
                    WHERE u.user_id = ?',
                    [$offer['buyer_user_id']]
                );

                $cardInfo = $db->query(
                    'SELECT ct.card_name
                    FROM marketplace_listings ml
                    JOIN user_cards uc ON ml.user_card_id = uc.user_card_id
                    JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
                    JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
                    WHERE ml.listing_id = ?',
                    [$offer['listing_id']]
                );

                if (!empty($buyerInfo) && !empty($cardInfo)) {
                    EmailService::sendOfferAcceptedEmail(
                        $buyerInfo[0]['email'],
                        $buyerInfo[0]['username'],
                        $cardInfo[0]['card_name'],
                        $offer['offer_price']
                    );
                }
            } catch (Exception $emailError) {
                Logger::error('Failed to send offer accepted email: ' . $emailError->getMessage());
            }

            ApiResponse::success([
                'status' => 'seller_approved',
                'message' => 'Offer accepted. Awaiting buyer confirmation.'
            ], 'Offer accepted');
        }

    } catch (Exception $e) {
        Logger::error('Respond to offer error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to respond to offer');
    }
}

/**
 * Confirm purchase (buyer final confirmation)
 */
function handleConfirmPurchase() {
    ApiResponse::requireMethod('POST');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['offer_id']);

    $offerId = (int)$data['offer_id'];

    // Need user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

    try {
        $db = Database::getInstance();

        $offer = $db->query(
            'SELECT
                mo.*,
                ml.seller_user_id,
                ml.user_card_id,
                ml.listing_id
            FROM marketplace_offers mo
            JOIN marketplace_listings ml ON mo.listing_id = ml.listing_id
            WHERE mo.offer_id = ?',
            [$offerId]
        );

        if (empty($offer)) {
            ApiResponse::notFound('Offer not found');
        }

        $offer = $offer[0];

        if ($offer['buyer_user_id'] != $userId) {
            ApiResponse::forbidden('Only the buyer can confirm purchase');
        }

        if ($offer['offer_status'] !== 'seller_approved') {
            ApiResponse::error('Offer must be approved by seller first', 400);
        }

        // Complete purchase
        completePurchase($db, $offer);

        ApiResponse::success([
            'status' => 'completed',
            'card_id' => $offer['user_card_id']
        ], 'Purchase completed successfully!');

    } catch (Exception $e) {
        Logger::error('Confirm purchase error: ' . $e->getMessage());
        ApiResponse::error($e->getMessage(), 400);
    }
}

/**
 * Complete the purchase transaction
 */
function completePurchase($db, $offer) {
    $db->beginTransaction();

    try {
        // Verify buyer still has enough credits
        $buyerResult = $db->query(
            'SELECT credits, user_id, username FROM users WHERE user_id = ?',
            [$offer['buyer_user_id']]
        );

        if (empty($buyerResult)) {
            Logger::error('Buyer not found: ' . $offer['buyer_user_id']);
            throw new Exception('Buyer account not found');
        }

        $buyerCredits = $buyerResult[0]['credits'];

        if ($buyerCredits < $offer['offer_price']) {
            throw new Exception('Insufficient credits');
        }

        // Get user codes for the stored procedure
        $buyerCode = $db->query('SELECT check_code FROM users WHERE user_id = ?', [$offer['buyer_user_id']])[0]['check_code'];
        $sellerCode = $db->query('SELECT check_code FROM users WHERE user_id = ?', [$offer['seller_user_id']])[0]['check_code'];

        // Transfer credits from buyer to seller
        Logger::info('Transferring credits: buyer_id=' . $offer['buyer_user_id'] . ', seller_id=' . $offer['seller_user_id'] . ', amount=' . $offer['offer_price']);
        $result = $db->callProcedure('sp_transfer_credits', [
            ':p_sender_user_code' => $buyerCode,
            ':p_recipient_user_code' => $sellerCode,
            ':p_amount' => $offer['offer_price']
        ], ['p_error_message']);

        // Check for errors from stored procedure
        $output = $result['output'];
        if (!empty($output['p_error_message'])) {
            throw new Exception($output['p_error_message']);
        }

        // Transfer card to buyer
        $db->execute(
            'UPDATE user_cards SET user_id = ?, is_in_marketplace = FALSE WHERE user_card_id = ?',
            [$offer['buyer_user_id'], $offer['user_card_id']]
        );

        // Get buyer's active stamp (or default stamp_id = 1)
        $stampResult = $db->query(
            'SELECT stamp_id FROM user_stamps WHERE user_id = ? AND is_active = TRUE LIMIT 1',
            [$offer['buyer_user_id']]
        );

        $stampId = !empty($stampResult) ? $stampResult[0]['stamp_id'] : 1;

        // Generate random stamp placement
        $stampX = rand(20, 180);
        $stampY = rand(20, 180);
        $stampRotation = rand(0, 359);

        // Add to ownership history (stamp the card with new owner)
        $db->execute(
            'INSERT INTO card_ownership_history (fk_user_card_id, fk_owner_stamp_id, stamp_position_x, stamp_position_y, stamp_rotation, card_moved_from_id, datestamp)
            VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$offer['user_card_id'], $stampId, $stampX, $stampY, $stampRotation, $offer['seller_user_id']]
        );

        // Update offer status
        $db->execute(
            'UPDATE marketplace_offers SET offer_status = ?, buyer_confirmed = TRUE, completed_at = NOW() WHERE offer_id = ?',
            ['completed', $offer['offer_id']]
        );

        // Update listing status
        $db->execute(
            'UPDATE marketplace_listings SET listing_status = ?, sold_at = NOW() WHERE listing_id = ?',
            ['sold', $offer['listing_id']]
        );

        // Cancel other offers on this listing
        $db->execute(
            'UPDATE marketplace_offers SET offer_status = ? WHERE listing_id = ? AND offer_id != ?',
            ['cancelled', $offer['listing_id'], $offer['offer_id']]
        );

        // Get card name for notifications
        $cardInfo = $db->query(
            'SELECT ct.card_name
            FROM user_cards uc
            JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
            JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
            WHERE uc.user_card_id = ?',
            [$offer['user_card_id']]
        );
        $cardName = !empty($cardInfo) ? $cardInfo[0]['card_name'] : 'Card';

        // Notify buyer of successful purchase
        $db->execute(
            'INSERT INTO notifications (user_id, notification_type, notification_color, title, message, related_user_id, related_entity_type, related_entity_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $offer['buyer_user_id'],
                'marketplace',
                '#00FF00',
                'Purchase Complete!',
                'You successfully purchased ' . $cardName . ' for ' . $offer['offer_price'] . ' credits',
                $offer['seller_user_id'],
                'user_card',
                $offer['user_card_id']
            ]
        );

        // Notify seller of successful sale
        $db->execute(
            'INSERT INTO notifications (user_id, notification_type, notification_color, title, message, related_user_id, related_entity_type, related_entity_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $offer['seller_user_id'],
                'marketplace',
                '#00FF00',
                'Sale Complete!',
                'You sold ' . $cardName . ' for ' . $offer['offer_price'] . ' credits',
                $offer['buyer_user_id'],
                'user_card',
                $offer['user_card_id']
            ]
        );

        // Send completion emails to both parties
        try {
            $buyerInfo = $db->query(
                'SELECT email, username FROM users WHERE user_id = ?',
                [$offer['buyer_user_id']]
            );

            $sellerInfo = $db->query(
                'SELECT email, username FROM users WHERE user_id = ?',
                [$offer['seller_user_id']]
            );

            if (!empty($buyerInfo)) {
                EmailService::sendPurchaseCompleteEmail(
                    $buyerInfo[0]['email'],
                    $buyerInfo[0]['username'],
                    $cardName,
                    $offer['offer_price'],
                    true // is buyer
                );
            }

            if (!empty($sellerInfo)) {
                EmailService::sendPurchaseCompleteEmail(
                    $sellerInfo[0]['email'],
                    $sellerInfo[0]['username'],
                    $cardName,
                    $offer['offer_price'],
                    false // is seller
                );
            }
        } catch (Exception $emailError) {
            Logger::error('Failed to send purchase complete emails: ' . $emailError->getMessage());
        }

        $db->commit();

    } catch (Exception $e) {
        $db->rollback();
        throw new Exception('Purchase failed: ' . $e->getMessage());
    }
}

/**
 * Cancel marketplace listing
 */
function handleCancelListing() {
    ApiResponse::requireMethod('POST');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['listing_id']);

    $listingId = (int)$data['listing_id'];

    // Need user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

    try {
        $db = Database::getInstance();

        $listing = $db->query(
            'SELECT seller_user_id, user_card_id FROM marketplace_listings WHERE listing_id = ?',
            [$listingId]
        );

        if (empty($listing)) {
            ApiResponse::notFound('Listing not found');
        }

        if ($listing[0]['seller_user_id'] != $userId) {
            ApiResponse::forbidden('Only the seller can cancel this listing');
        }

        // Update listing and card
        $db->execute(
            'UPDATE marketplace_listings SET listing_status = ? WHERE listing_id = ?',
            ['cancelled', $listingId]
        );

        $db->execute(
            'UPDATE user_cards SET is_in_marketplace = FALSE WHERE user_card_id = ?',
            [$listing[0]['user_card_id']]
        );

        // Cancel pending offers
        $db->execute(
            'UPDATE marketplace_offers SET offer_status = ? WHERE listing_id = ? AND offer_status NOT IN (?, ?)',
            ['cancelled', $listingId, 'completed', 'rejected']
        );

        ApiResponse::success(null, 'Listing cancelled');

    } catch (Exception $e) {
        Logger::error('Cancel listing error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to cancel listing');
    }
}

/**
 * Update listing price
 */
function handleUpdateListing() {
    ApiResponse::requireMethod('POST');

    $userCode = Security::requireAuth();
    $data = ApiResponse::getJsonBody();

    ApiResponse::requireFields($data, ['listing_id', 'asking_price']);

    $listingId = (int)$data['listing_id'];
    $askingPrice = (float)$data['asking_price'];

    // Need user_id for legacy tables
    require_once __DIR__ . '/../core/UserCodeHelper.php';
    $userId = UserCodeHelper::getUserIdFromCode($userCode);

    if ($askingPrice <= 0) {
        ApiResponse::validationError(['asking_price' => 'Price must be greater than zero']);
    }

    try {
        $db = Database::getInstance();

        $listing = $db->query(
            'SELECT seller_user_id, listing_status FROM marketplace_listings WHERE listing_id = ?',
            [$listingId]
        );

        if (empty($listing)) {
            ApiResponse::notFound('Listing not found');
        }

        if ($listing[0]['seller_user_id'] != $userId) {
            ApiResponse::forbidden('Only the seller can update this listing');
        }

        if ($listing[0]['listing_status'] !== 'active') {
            ApiResponse::validationError(['listing' => 'Only active listings can be updated']);
        }

        // Update listing price
        $db->execute(
            'UPDATE marketplace_listings SET asking_price = ?, updated_at = CURRENT_TIMESTAMP WHERE listing_id = ?',
            [$askingPrice, $listingId]
        );

        ApiResponse::success(null, 'Listing price updated successfully');

    } catch (Exception $e) {
        Logger::error('Update listing error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to update listing');
    }
}

/**
 * Get purchase history
 */
function handlePurchaseHistory() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();

    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 20;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    try {
        $db = Database::getInstance();

        // Need user_id for legacy tables
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $purchases = $db->query(
            'SELECT
                mo.offer_id,
                mo.offer_price,
                mo.completed_at,
                ct.card_name,
                ct.character_image_path,
                cs.status_name
            FROM marketplace_offers mo
            JOIN marketplace_listings ml ON mo.listing_id = ml.listing_id
            JOIN user_cards uc ON ml.user_card_id = uc.user_card_id
            JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
            JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
            JOIN card_status cs ON ct.status_id = cs.status_id
            WHERE mo.buyer_user_id = ? AND mo.offer_status = ?
            ORDER BY mo.completed_at DESC
            LIMIT ? OFFSET ?',
            [$userId, 'completed', $limit, $offset]
        );

        $totalCount = $db->query(
            'SELECT COUNT(*) as total FROM marketplace_offers WHERE buyer_user_id = ? AND offer_status = ?',
            [$userId, 'completed']
        )[0]['total'];

        ApiResponse::success([
            'purchases' => $purchases,
            'pagination' => [
                'total' => (int)$totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $totalCount
            ]
        ], 'Purchase history retrieved');

    } catch (Exception $e) {
        Logger::error('Purchase history error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve purchase history');
    }
}

/**
 * Get sales history
 */
function handleSalesHistory() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();

    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 20;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    try {
        $db = Database::getInstance();

        // Need user_id for legacy tables
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $sales = $db->query(
            'SELECT
                ml.listing_id,
                ml.asking_price,
                mo.offer_price as sold_price,
                ml.sold_at,
                ct.card_name,
                ct.character_image_path,
                cs.status_name
            FROM marketplace_listings ml
            JOIN marketplace_offers mo ON ml.listing_id = mo.listing_id AND mo.offer_status = ?
            JOIN user_cards uc ON ml.user_card_id = uc.user_card_id
            JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
            JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
            JOIN card_status cs ON ct.status_id = cs.status_id
            WHERE ml.seller_user_id = ? AND ml.listing_status = ?
            ORDER BY ml.sold_at DESC
            LIMIT ? OFFSET ?',
            ['completed', $userId, 'sold', $limit, $offset]
        );

        $totalCount = $db->query(
            'SELECT COUNT(*) as total FROM marketplace_listings WHERE seller_user_id = ? AND listing_status = ?',
            [$userId, 'sold']
        )[0]['total'];

        ApiResponse::success([
            'sales' => $sales,
            'pagination' => [
                'total' => (int)$totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $totalCount
            ]
        ], 'Sales history retrieved');

    } catch (Exception $e) {
        Logger::error('Sales history error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve sales history');
    }
}

/**
 * Get offers received on user's listings (seller view)
 */
function handleGetOffersReceived() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();

    $status = isset($_GET['status']) ? Security::sanitizeInput($_GET['status']) : null;

    try {
        $db = Database::getInstance();

        // Need user_id for legacy tables
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $query = 'SELECT
            mo.offer_id,
            mo.offer_price,
            mo.offer_status,
            mo.seller_confirmed,
            mo.buyer_confirmed,
            mo.created_at,
            ml.listing_id,
            ml.asking_price,
            ct.card_name,
            ct.character_image_path,
            u.username as buyer_username
        FROM marketplace_offers mo
        JOIN marketplace_listings ml ON mo.listing_id = ml.listing_id
        JOIN user_cards uc ON ml.user_card_id = uc.user_card_id
        JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
        JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
        JOIN users u ON mo.buyer_user_id = u.user_id
        WHERE ml.seller_user_id = ?';

        $params = [$userId];

        if ($status) {
            $query .= ' AND mo.offer_status = ?';
            $params[] = $status;
        }

        $query .= ' ORDER BY mo.created_at DESC';

        $offers = $db->query($query, $params);

        ApiResponse::success([
            'offers' => $offers
        ], 'Offers retrieved');

    } catch (Exception $e) {
        Logger::error('Get offers received error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve offers');
    }
}

/**
 * Get offers made by user (buyer view)
 */
function handleGetOffersMade() {
    ApiResponse::requireMethod('GET');

    $userCode = Security::requireAuth();

    $status = isset($_GET['status']) ? Security::sanitizeInput($_GET['status']) : null;

    try {
        $db = Database::getInstance();

        // Need user_id for legacy tables
        require_once __DIR__ . '/../core/UserCodeHelper.php';
        $userId = UserCodeHelper::getUserIdFromCode($userCode);

        $query = 'SELECT
            mo.offer_id,
            mo.offer_price,
            mo.offer_status,
            mo.seller_confirmed,
            mo.buyer_confirmed,
            mo.created_at,
            mo.completed_at,
            ml.listing_id,
            ml.asking_price,
            ml.listing_status,
            ct.card_name,
            ct.character_image_path
        FROM marketplace_offers mo
        JOIN marketplace_listings ml ON mo.listing_id = ml.listing_id
        JOIN user_cards uc ON ml.user_card_id = uc.user_card_id
        JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
        JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
        WHERE mo.buyer_user_id = ?';

        $params = [$userId];

        if ($status) {
            $query .= ' AND mo.offer_status = ?';
            $params[] = $status;
        }

        $query .= ' ORDER BY mo.created_at DESC';

        $offers = $db->query($query, $params);

        ApiResponse::success([
            'offers' => $offers
        ], 'Your offers retrieved');

    } catch (Exception $e) {
        Logger::error('Get offers made error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve your offers');
    }
}
