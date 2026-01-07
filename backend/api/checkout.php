<?php
/**
 * Checkout & Order Management API
 * Handles order creation, payment processing, and order fulfillment
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/ApiResponse.php';
require_once __DIR__ . '/../core/Logger.php';

// Set CORS headers
ApiResponse::setCorsHeaders();

// Get request method and action
$method = ApiResponse::getMethod();
$action = $_GET['action'] ?? '';

// Route to appropriate handler
switch ($action) {
    case 'create-order':
        handleCreateOrder();
        break;
    case 'create-payment':
        handleCreatePayment();
        break;
    case 'confirm-payment':
        handleConfirmPayment();
        break;
    case 'get-order':
        handleGetOrder();
        break;
    case 'get-orders':
        handleGetOrders();
        break;
    case 'webhook':
        handlePaymentWebhook();
        break;
    default:
        ApiResponse::notFound('Endpoint not found');
}

/**
 * Create order from cart
 */
function handleCreateOrder() {
    ApiResponse::requireMethod('POST');

    try {
        $userId = Security::requireAuth();
        $data = ApiResponse::getJsonBody();

        // Validate input
        if (!isset($data['customer_email']) || !isset($data['customer_name'])) {
            ApiResponse::badRequest('Customer email and name are required');
            return;
        }

        $customerEmail = filter_var($data['customer_email'], FILTER_VALIDATE_EMAIL);
        if (!$customerEmail) {
            ApiResponse::badRequest('Invalid email address');
            return;
        }

        $customerName = trim($data['customer_name']);
        $billingCountry = $data['billing_country'] ?? null;
        $currency = $data['currency'] ?? 'GBP';
        $promoCode = isset($data['promo_code']) ? strtoupper(trim($data['promo_code'])) : null;

        $db = Database::getInstance();

        // Get user's cart
        $cart = $db->query("
            SELECT cart_id FROM shopping_carts WHERE user_id = ?
        ", [$userId]);

        if (empty($cart)) {
            ApiResponse::error('Cart not found');
            return;
        }

        $cartId = $cart[0]['cart_id'];

        // Get cart items
        $cartItems = $db->query("
            SELECT
                ci.cart_item_id,
                ci.product_id,
                p.product_name,
                p.sku,
                p.credits_amount,
                p.bonus_credits,
                ci.quantity,
                ci.price_at_add,
                ci.discount_amount,
                (ci.price_at_add * ci.quantity) as subtotal
            FROM cart_items ci
            JOIN products p ON ci.product_id = p.product_id
            WHERE ci.cart_id = ?
        ", [$cartId]);

        if (empty($cartItems)) {
            ApiResponse::error('Cart is empty');
            return;
        }

        // Calculate totals
        $subtotal = 0;
        $totalDiscount = 0;

        foreach ($cartItems as $item) {
            $subtotal += $item['subtotal'];
            $totalDiscount += $item['discount_amount'];
        }

        $total = $subtotal - $totalDiscount;

        if ($total <= 0) {
            ApiResponse::error('Invalid order total');
            return;
        }

        // Generate unique order number
        $orderNumber = 'GB-' . date('Y') . '-' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);

        // Start transaction
        $db->beginTransaction();

        try {
            // Create order
            $db->execute("
                INSERT INTO orders (
                    user_id,
                    order_number,
                    subtotal,
                    discount_amount,
                    total_amount,
                    currency,
                    promo_code,
                    customer_email,
                    customer_name,
                    billing_country,
                    order_status,
                    payment_status,
                    ip_address,
                    user_agent
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', ?, ?)
            ", [
                $userId,
                $orderNumber,
                $subtotal,
                $totalDiscount,
                $total,
                $currency,
                $promoCode,
                $customerEmail,
                $customerName,
                $billingCountry,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            $orderId = $db->getLastInsertId();

            // Create order items
            foreach ($cartItems as $item) {
                $db->execute("
                    INSERT INTO order_items (
                        order_id,
                        product_id,
                        product_name,
                        product_sku,
                        quantity,
                        unit_price,
                        discount_amount,
                        subtotal,
                        credits_amount,
                        bonus_credits
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ", [
                    $orderId,
                    $item['product_id'],
                    $item['product_name'],
                    $item['sku'],
                    $item['quantity'],
                    $item['price_at_add'],
                    $item['discount_amount'],
                    $item['subtotal'] - $item['discount_amount'],
                    $item['credits_amount'],
                    $item['bonus_credits']
                ]);
            }

            // Clear cart
            $db->execute("DELETE FROM cart_items WHERE cart_id = ?", [$cartId]);

            // Increment promo code usage if applicable
            if ($promoCode) {
                $db->execute("
                    UPDATE promotion_codes
                    SET times_used = times_used + 1
                    WHERE promo_code = ?
                ", [$promoCode]);
            }

            $db->commit();

            // Get complete order details
            $order = $db->query("
                SELECT * FROM orders WHERE order_id = ?
            ", [$orderId]);

            $orderItems = $db->query("
                SELECT * FROM order_items WHERE order_id = ?
            ", [$orderId]);

            ApiResponse::success('Order created successfully', [
                'order' => $order[0],
                'items' => $orderItems
            ]);

        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }

    } catch (Exception $e) {
        Logger::error('Create order error', [
            'error' => $e->getMessage(),
            'user_id' => $userId ?? null
        ]);
        ApiResponse::serverError('Failed to create order');
    }
}

/**
 * Create Revolut payment intent
 */
function handleCreatePayment() {
    ApiResponse::requireMethod('POST');

    try {
        $userId = Security::requireAuth();
        $data = ApiResponse::getJsonBody();

        if (!isset($data['order_id'])) {
            ApiResponse::badRequest('Order ID is required');
            return;
        }

        $orderId = (int)$data['order_id'];

        $db = Database::getInstance();

        // Get order
        $order = $db->query("
            SELECT * FROM orders WHERE order_id = ? AND user_id = ?
        ", [$orderId, $userId]);

        if (empty($order)) {
            ApiResponse::notFound('Order not found');
            return;
        }

        $order = $order[0];

        if ($order['order_status'] !== 'pending') {
            ApiResponse::error('Order is not pending');
            return;
        }

        // Get Revolut API credentials from config
        $config = require __DIR__ . '/../config/config.php';
        $revolutApiKey = $config['revolut_api_key'] ?? null;
        $revolutApiUrl = $config['revolut_api_url'] ?? 'https://merchant.revolut.com/api/1.0';

        if (!$revolutApiKey) {
            Logger::error('Revolut API key not configured');
            ApiResponse::serverError('Payment system not configured');
            return;
        }

        // Create payment request
        $paymentData = [
            'amount' => (int)($order['total_amount'] * 100), // Convert to cents
            'currency' => $order['currency'],
            'merchant_order_ext_ref' => $order['order_number'],
            'customer_email' => $order['customer_email'],
            'description' => "GeoBeasts Order #{$order['order_number']}",
            'settlement_currency' => 'GBP',
            'merchant_customer_ext_ref' => "USER_{$userId}",
            'capture_mode' => 'AUTOMATIC'
        ];

        // Call Revolut API
        $ch = curl_init("{$revolutApiUrl}/orders");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$revolutApiKey}",
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($paymentData)
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 201) {
            Logger::error('Revolut payment creation failed', [
                'http_code' => $httpCode,
                'response' => $response,
                'order_id' => $orderId
            ]);
            ApiResponse::serverError('Failed to create payment');
            return;
        }

        $paymentResponse = json_decode($response, true);

        // Update order with payment intent ID
        $db->execute("
            UPDATE orders
            SET
                payment_intent_id = ?,
                order_status = 'processing'
            WHERE order_id = ?
        ", [$paymentResponse['id'], $orderId]);

        // Log payment transaction
        $db->execute("
            INSERT INTO payment_transactions (
                order_id,
                payment_provider,
                transaction_id,
                payment_intent_id,
                amount,
                currency,
                transaction_status,
                gateway_response
            ) VALUES (?, 'revolut', ?, ?, ?, ?, 'pending', ?)
        ", [
            $orderId,
            $paymentResponse['id'],
            $paymentResponse['id'],
            $order['total_amount'],
            $order['currency'],
            json_encode($paymentResponse)
        ]);

        ApiResponse::success('Payment created successfully', [
            'payment_intent_id' => $paymentResponse['id'],
            'public_id' => $paymentResponse['public_id'],
            'checkout_url' => $paymentResponse['checkout_url'] ?? null
        ]);

    } catch (Exception $e) {
        Logger::error('Create payment error', [
            'error' => $e->getMessage(),
            'user_id' => $userId ?? null,
            'order_id' => $orderId ?? null
        ]);
        ApiResponse::serverError('Failed to create payment');
    }
}

/**
 * Confirm payment and fulfill order
 */
function handleConfirmPayment() {
    ApiResponse::requireMethod('POST');

    try {
        $userId = Security::requireAuth();
        $data = ApiResponse::getJsonBody();

        if (!isset($data['order_id'])) {
            ApiResponse::badRequest('Order ID is required');
            return;
        }

        $orderId = (int)$data['order_id'];

        $db = Database::getInstance();

        // Get order
        $order = $db->query("
            SELECT * FROM orders WHERE order_id = ? AND user_id = ?
        ", [$orderId, $userId]);

        if (empty($order)) {
            ApiResponse::notFound('Order not found');
            return;
        }

        $order = $order[0];

        // Verify payment with Revolut
        $config = require __DIR__ . '/../config/config.php';
        $revolutApiKey = $config['revolut_api_key'] ?? null;
        $revolutApiUrl = $config['revolut_api_url'] ?? 'https://merchant.revolut.com/api/1.0';

        $ch = curl_init("{$revolutApiUrl}/orders/{$order['payment_intent_id']}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$revolutApiKey}"
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            ApiResponse::serverError('Failed to verify payment');
            return;
        }

        $paymentDetails = json_decode($response, true);

        // Check payment status
        if ($paymentDetails['state'] === 'COMPLETED') {
            // Complete order and add credits
            $result = $db->callProcedure('sp_complete_order', [
                ':p_order_id' => $orderId
            ]);

            // Create notification
            $db->execute("
                INSERT INTO notifications (
                    user_id,
                    notification_type,
                    notification_color,
                    title,
                    message,
                    related_entity_type,
                    related_entity_id
                ) VALUES (?, 'system', '#00FF00', 'Purchase Successful', ?, 'order', ?)
            ", [
                $userId,
                "Your order #{$order['order_number']} has been completed. Credits have been added to your account!",
                $orderId
            ]);

            ApiResponse::success('Payment confirmed and order completed', [
                'order_id' => $orderId,
                'credits_balance' => $result['results'][0][0]['new_balance'] ?? null
            ]);

        } else {
            ApiResponse::error('Payment not completed', [
                'payment_status' => $paymentDetails['state']
            ]);
        }

    } catch (Exception $e) {
        Logger::error('Confirm payment error', [
            'error' => $e->getMessage(),
            'user_id' => $userId ?? null,
            'order_id' => $orderId ?? null
        ]);
        ApiResponse::serverError('Failed to confirm payment');
    }
}

/**
 * Get single order details
 */
function handleGetOrder() {
    ApiResponse::requireMethod('GET');

    try {
        $userId = Security::requireAuth();
        $orderId = (int)($_GET['order_id'] ?? 0);

        if (!$orderId) {
            ApiResponse::badRequest('Order ID is required');
            return;
        }

        $db = Database::getInstance();

        $order = $db->query("
            SELECT * FROM orders WHERE order_id = ? AND user_id = ?
        ", [$orderId, $userId]);

        if (empty($order)) {
            ApiResponse::notFound('Order not found');
            return;
        }

        $items = $db->query("
            SELECT * FROM order_items WHERE order_id = ?
        ", [$orderId]);

        ApiResponse::success('Order retrieved successfully', [
            'order' => $order[0],
            'items' => $items
        ]);

    } catch (Exception $e) {
        Logger::error('Get order error', [
            'error' => $e->getMessage(),
            'user_id' => $userId ?? null,
            'order_id' => $orderId ?? null
        ]);
        ApiResponse::serverError('Failed to retrieve order');
    }
}

/**
 * Get user's order history
 */
function handleGetOrders() {
    ApiResponse::requireMethod('GET');

    try {
        $userId = Security::requireAuth();
        $limit = min((int)($_GET['limit'] ?? 20), 100);
        $offset = (int)($_GET['offset'] ?? 0);

        $db = Database::getInstance();

        $orders = $db->query("
            SELECT
                order_id,
                order_number,
                total_amount,
                currency,
                order_status,
                payment_status,
                created_at,
                completed_at
            FROM orders
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ", [$userId, $limit, $offset]);

        $totalCount = $db->query("
            SELECT COUNT(*) as total FROM orders WHERE user_id = ?
        ", [$userId])[0]['total'];

        ApiResponse::success('Orders retrieved successfully', [
            'orders' => $orders,
            'total' => $totalCount,
            'limit' => $limit,
            'offset' => $offset
        ]);

    } catch (Exception $e) {
        Logger::error('Get orders error', [
            'error' => $e->getMessage(),
            'user_id' => $userId ?? null
        ]);
        ApiResponse::serverError('Failed to retrieve orders');
    }
}

/**
 * Handle Revolut payment webhook
 */
function handlePaymentWebhook() {
    ApiResponse::requireMethod('POST');

    try {
        // Get webhook payload
        $payload = file_get_contents('php://input');
        $data = json_decode($payload, true);

        // Verify webhook signature (Revolut specific)
        $config = require __DIR__ . '/../config/config.php';
        $webhookSecret = $config['revolut_webhook_secret'] ?? null;

        $signature = $_SERVER['HTTP_REVOLUT_SIGNATURE'] ?? '';

        if ($webhookSecret) {
            $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);
            if (!hash_equals($expectedSignature, $signature)) {
                Logger::error('Invalid webhook signature');
                ApiResponse::unauthorized('Invalid signature');
                return;
            }
        }

        // Log webhook
        Logger::info('Revolut webhook received', $data);

        $db = Database::getInstance();

        // Get order by payment intent ID
        $paymentIntentId = $data['id'] ?? null;
        if (!$paymentIntentId) {
            ApiResponse::badRequest('Missing payment intent ID');
            return;
        }

        $order = $db->query("
            SELECT order_id, user_id, order_status, credits_added
            FROM orders
            WHERE payment_intent_id = ?
        ", [$paymentIntentId]);

        if (empty($order)) {
            Logger::error('Order not found for webhook', ['payment_intent_id' => $paymentIntentId]);
            ApiResponse::notFound('Order not found');
            return;
        }

        $order = $order[0];
        $orderId = $order['order_id'];
        $userId = $order['user_id'];

        // Handle different event types
        $eventType = $data['event'] ?? $data['type'] ?? null;

        switch ($eventType) {
            case 'ORDER_COMPLETED':
            case 'order.completed':
                if (!$order['credits_added']) {
                    // Complete order and add credits
                    $db->callProcedure('sp_complete_order', [
                        ':p_order_id' => $orderId
                    ]);

                    // Create notification
                    $db->execute("
                        INSERT INTO notifications (
                            user_id,
                            notification_type,
                            notification_color,
                            title,
                            message,
                            related_entity_type,
                            related_entity_id
                        ) VALUES (?, 'system', '#00FF00', 'Purchase Successful', 'Your credit purchase has been completed!', 'order', ?)
                    ", [$userId, $orderId]);

                    Logger::info('Order completed via webhook', ['order_id' => $orderId]);
                }
                break;

            case 'ORDER_PAYMENT_DECLINED':
            case 'order.payment_declined':
                $db->execute("
                    UPDATE orders
                    SET order_status = 'failed', payment_status = 'failed'
                    WHERE order_id = ?
                ", [$orderId]);

                Logger::info('Order payment declined', ['order_id' => $orderId]);
                break;

            case 'ORDER_CANCELLED':
            case 'order.cancelled':
                $db->execute("
                    UPDATE orders
                    SET order_status = 'cancelled', payment_status = 'failed'
                    WHERE order_id = ?
                ", [$orderId]);

                Logger::info('Order cancelled', ['order_id' => $orderId]);
                break;
        }

        // Update payment transaction
        $db->execute("
            UPDATE payment_transactions
            SET
                transaction_status = ?,
                gateway_response = ?
            WHERE payment_intent_id = ?
        ", [
            $data['state'] ?? 'unknown',
            json_encode($data),
            $paymentIntentId
        ]);

        ApiResponse::success('Webhook processed successfully');

    } catch (Exception $e) {
        Logger::error('Webhook processing error', ['error' => $e->getMessage()]);
        ApiResponse::serverError('Failed to process webhook');
    }
}
