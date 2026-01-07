<?php
/**
 * Credits & Payment API Endpoints
 * Handles credit purchases, transfers, and transaction history
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/ApiResponse.php';

// Set CORS headers
ApiResponse::setCorsHeaders();

// Get request method and path
$method = ApiResponse::getMethod();
$path = $_GET['action'] ?? '';

// Route to appropriate handler
switch ($path) {
    case 'balance':
        handleGetBalance();
        break;
    case 'purchase':
        handlePurchaseCredits();
        break;
    case 'transfer':
        handleTransferCredits();
        break;
    case 'transactions':
        handleGetTransactions();
        break;
    case 'validate-promo':
        handleValidatePromoCode();
        break;
    case 'packages':
        handleGetCreditPackages();
        break;
    default:
        ApiResponse::notFound('Endpoint not found');
}

/**
 * Get available credit packages
 */
function handleGetCreditPackages() {
    ApiResponse::requireMethod('GET');

    $packages = [
        [
            'id' => 'starter',
            'credits' => 25,
            'price_gbp' => 5.00,
            'price_usd' => 7.50,
            'price_eur' => 7.50,
            'discount' => 0,
            'popular' => false
        ],
        [
            'id' => 'standard',
            'credits' => 50,
            'price_gbp' => 10.00,
            'price_usd' => 15.00,
            'price_eur' => 15.00,
            'discount' => 0,
            'popular' => true
        ],
        [
            'id' => 'mega',
            'credits' => 125,
            'price_gbp' => 25.00,
            'price_usd' => 37.50,
            'price_eur' => 37.50,
            'discount' => 0,
            'popular' => false
        ],
        [
            'id' => 'ultimate',
            'credits' => 250,
            'price_gbp' => 50.00,
            'price_usd' => 75.00,
            'price_eur' => 75.00,
            'discount' => 0,
            'popular' => false
        ]
    ];

    ApiResponse::success($packages, 'Credit packages available');
}

/**
 * Get user credit balance
 */
function handleGetBalance() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();
    $userId = $user['user_id'];

    try {
        $db = Database::getInstance();

        $result = $db->query('SELECT credits FROM users WHERE user_id = ?', [$userId]);

        if (empty($result)) {
            ApiResponse::error('User not found', 404);
        }

        $credits = (float)$result[0]['credits'];

        ApiResponse::success([
            'credits' => $credits,
            'formatted' => number_format($credits, 2)
        ], 'Credit balance retrieved');

    } catch (Exception $e) {
        error_log('Get balance error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve balance');
    }
}

/**
 * Handle credit purchase (PLACEHOLDER - Simulated payment)
 */
function handlePurchaseCredits() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    // Validate required fields
    ApiResponse::requireFields($data, ['package_id', 'currency']);

    $packageId = Security::sanitizeInput($data['package_id']);
    $currency = Security::sanitizeInput($data['currency']);
    $promoCode = isset($data['promo_code']) ? Security::sanitizeInput($data['promo_code']) : null;
    $userId = $user['user_id'];

    // Get package details
    $packages = [
        'starter' => ['credits' => 25, 'prices' => ['gbp' => 5.00, 'usd' => 7.50, 'eur' => 7.50]],
        'standard' => ['credits' => 50, 'prices' => ['gbp' => 10.00, 'usd' => 15.00, 'eur' => 15.00]],
        'mega' => ['credits' => 125, 'prices' => ['gbp' => 25.00, 'usd' => 37.50, 'eur' => 37.50]],
        'ultimate' => ['credits' => 250, 'prices' => ['gbp' => 50.00, 'usd' => 75.00, 'eur' => 75.00]]
    ];

    if (!isset($packages[$packageId])) {
        ApiResponse::validationError(['package_id' => 'Invalid package selected']);
    }

    $package = $packages[$packageId];
    $currency = strtolower($currency);

    if (!isset($package['prices'][$currency])) {
        ApiResponse::validationError(['currency' => 'Invalid currency']);
    }

    $price = $package['prices'][$currency];
    $credits = $package['credits'];
    $bonusCredits = 0;

    try {
        $db = Database::getInstance();

        // Check promo code if provided
        if ($promoCode) {
            $promoResult = $db->query(
                'SELECT discount_percentage, bonus_credits, max_uses, times_used, active
                FROM promotion_codes
                WHERE promo_code = ? AND active = TRUE
                AND (valid_until IS NULL OR valid_until > NOW())',
                [$promoCode]
            );

            if (!empty($promoResult)) {
                $promo = $promoResult[0];

                if ($promo['max_uses'] === null || $promo['times_used'] < $promo['max_uses']) {
                    $bonusCredits = (int)$promo['bonus_credits'];

                    // Update promo code usage
                    $db->execute(
                        'UPDATE promotion_codes SET times_used = times_used + 1 WHERE promo_code = ?',
                        [$promoCode]
                    );
                }
            }
        }

        $totalCredits = $credits + $bonusCredits;

        // PLACEHOLDER: In production, this would integrate with Revolut payment gateway
        // For now, we simulate a successful payment

        // Add credits to user account
        $db->callProcedure('sp_add_credits', [
            ':p_user_id' => $userId,
            ':p_amount' => $totalCredits,
            ':p_transaction_type' => 'purchase',
            ':p_description' => "Purchased $credits credits" . ($bonusCredits > 0 ? " (+ $bonusCredits bonus)" : "") . " for $price $currency"
        ]);

        // Get new balance
        $balanceResult = $db->query('SELECT credits FROM users WHERE user_id = ?', [$userId]);
        $newBalance = (float)$balanceResult[0]['credits'];

        ApiResponse::success([
            'credits_purchased' => $credits,
            'bonus_credits' => $bonusCredits,
            'total_credits_added' => $totalCredits,
            'new_balance' => $newBalance,
            'amount_paid' => $price,
            'currency' => strtoupper($currency),
            'payment_method' => 'simulated', // In production: 'card', 'revolut', etc.
            'transaction_id' => 'SIM-' . strtoupper(uniqid())
        ], 'Credits purchased successfully!', 201);

    } catch (Exception $e) {
        error_log('Purchase credits error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to complete purchase');
    }
}

/**
 * Handle credit transfer between users
 */
function handleTransferCredits() {
    ApiResponse::requireMethod('POST');

    $user = ApiResponse::requireAuth();
    $data = ApiResponse::getJsonBody();

    // Validate required fields
    ApiResponse::requireFields($data, ['recipient_username', 'amount']);

    $recipientUsername = Security::sanitizeInput($data['recipient_username']);
    $amount = (float)$data['amount'];
    $senderId = $user['user_id'];

    // Validate amount
    if ($amount <= 0) {
        ApiResponse::validationError(['amount' => 'Amount must be greater than zero']);
    }

    if ($amount > 100) {
        ApiResponse::validationError(['amount' => 'Maximum transfer amount is 100 credits']);
    }

    try {
        $db = Database::getInstance();

        // Get recipient user
        $recipientResult = $db->query(
            'SELECT user_id, username, account_type_id FROM users WHERE username = ? AND active = TRUE',
            [$recipientUsername]
        );

        if (empty($recipientResult)) {
            ApiResponse::error('Recipient user not found', 404);
        }

        $recipient = $recipientResult[0];
        $recipientId = $recipient['user_id'];

        // Can't transfer to self
        if ($recipientId == $senderId) {
            ApiResponse::error('Cannot transfer credits to yourself', 400);
        }

        // Check if sender and recipient are friends
        $friendshipCheck = $db->query(
            'SELECT friendship_id FROM friendships
            WHERE ((requester_user_id = ? AND recipient_user_id = ?)
               OR (requester_user_id = ? AND recipient_user_id = ?))
            AND status = ?',
            [$senderId, $recipientId, $recipientId, $senderId, 'approved']
        );

        if (empty($friendshipCheck)) {
            ApiResponse::error('You can only transfer credits to friends', 403);
        }

        // Check if recipient is child account and amount > 3 (requires parent approval)
        $childAccountTypeId = $db->query(
            "SELECT account_type_id FROM account_types WHERE account_type_name = 'child'"
        )[0]['account_type_id'];

        if ($recipient['account_type_id'] == $childAccountTypeId && $amount > 3) {
            // TODO: Create pending approval request for parent
            ApiResponse::error('Transfers over 3 credits to child accounts require parent approval', 403);
        }

        // Perform transfer
        $result = $db->callProcedure('sp_transfer_credits', [
            ':p_sender_id' => $senderId,
            ':p_recipient_id' => $recipientId,
            ':p_amount' => $amount
        ], ['p_error_message']);

        if (!empty($result['output']['p_error_message'])) {
            ApiResponse::error($result['output']['p_error_message'], 400);
        }

        // Get new balance
        $balanceResult = $db->query('SELECT credits FROM users WHERE user_id = ?', [$senderId]);
        $newBalance = (float)$balanceResult[0]['credits'];

        ApiResponse::success([
            'amount_transferred' => $amount,
            'recipient' => $recipientUsername,
            'new_balance' => $newBalance
        ], "Successfully transferred $amount credits to $recipientUsername");

    } catch (Exception $e) {
        error_log('Transfer credits error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to transfer credits');
    }
}

/**
 * Get transaction history
 */
function handleGetTransactions() {
    ApiResponse::requireMethod('GET');

    $user = ApiResponse::requireAuth();
    $userId = $user['user_id'];

    // Pagination
    $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    try {
        $db = Database::getInstance();

        $transactions = $db->query(
            'SELECT
                transaction_id,
                transaction_type,
                amount,
                balance_before,
                balance_after,
                description,
                created_at
            FROM credit_transactions
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?',
            [$userId, $limit, $offset]
        );

        // Get total count
        $countResult = $db->query(
            'SELECT COUNT(*) as total FROM credit_transactions WHERE user_id = ?',
            [$userId]
        );

        $totalCount = (int)$countResult[0]['total'];

        ApiResponse::success([
            'transactions' => $transactions,
            'pagination' => [
                'total' => $totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $totalCount
            ]
        ], 'Transaction history retrieved');

    } catch (Exception $e) {
        error_log('Get transactions error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve transactions');
    }
}

/**
 * Validate promo code
 */
function handleValidatePromoCode() {
    ApiResponse::requireMethod('POST');

    ApiResponse::requireAuth(); // Must be logged in

    $data = ApiResponse::getJsonBody();
    ApiResponse::requireFields($data, ['promo_code']);

    $promoCode = Security::sanitizeInput($data['promo_code']);

    try {
        $db = Database::getInstance();

        $result = $db->query(
            'SELECT
                promo_code,
                discount_percentage,
                bonus_credits,
                max_uses,
                times_used,
                valid_until
            FROM promotion_codes
            WHERE promo_code = ?
            AND active = TRUE
            AND (valid_until IS NULL OR valid_until > NOW())',
            [$promoCode]
        );

        if (empty($result)) {
            ApiResponse::error('Invalid or expired promo code', 404);
        }

        $promo = $result[0];

        // Check if max uses reached
        if ($promo['max_uses'] !== null && $promo['times_used'] >= $promo['max_uses']) {
            ApiResponse::error('This promo code has been fully redeemed', 400);
        }

        ApiResponse::success([
            'valid' => true,
            'promo_code' => $promo['promo_code'],
            'discount_percentage' => (float)$promo['discount_percentage'],
            'bonus_credits' => (int)$promo['bonus_credits'],
            'remaining_uses' => $promo['max_uses'] !== null ? ($promo['max_uses'] - $promo['times_used']) : null
        ], 'Promo code is valid');

    } catch (Exception $e) {
        error_log('Validate promo error: ' . $e->getMessage());
        ApiResponse::serverError('Failed to validate promo code');
    }
}
