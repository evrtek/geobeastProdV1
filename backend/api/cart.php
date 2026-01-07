<?php
/**
 * Shopping Cart API Endpoints
 * Handles cart operations: add, remove, update, get cart details
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
    case 'get':
        handleGetCart();
        break;
    case 'add':
        handleAddToCart();
        break;
    case 'update':
        handleUpdateCartItem();
        break;
    case 'remove':
        handleRemoveFromCart();
        break;
    case 'clear':
        handleClearCart();
        break;
    case 'apply-promo':
        handleApplyPromoCode();
        break;
    case 'get-products':
        handleGetProducts();
        break;
    default:
        ApiResponse::notFound('Endpoint not found');
}

/**
 * Get all available products
 */
function handleGetProducts() {
    ApiResponse::requireMethod('GET');

    try {
        $db = Database::getInstance();

        $type = $_GET['type'] ?? null;

        $query = "
            SELECT
                product_id,
                product_type,
                product_name,
                product_description,
                sku,
                price_gbp,
                price_usd,
                price_eur,
                credits_amount,
                bonus_credits,
                is_featured,
                is_available,
                stock_quantity,
                max_per_order,
                image_url,
                sort_order
            FROM products
            WHERE is_available = TRUE
        ";

        $params = [];
        if ($type) {
            $query .= " AND product_type = ?";
            $params[] = $type;
        }

        $query .= " ORDER BY sort_order ASC, product_id ASC";

        $products = $db->query($query, $params);

        ApiResponse::success(['products' => $products], 'Products retrieved successfully');

    } catch (Exception $e) {
        Logger::error('Get products error', ['error' => $e->getMessage()]);
        ApiResponse::serverError('Failed to retrieve products');
    }
}

/**
 * Get user's shopping cart
 */
function handleGetCart() {
    ApiResponse::requireMethod('GET');

    try {
        $userId = Security::requireAuth();
        $db = Database::getInstance();

        // Get or create cart
        $cartResult = $db->callProcedure('sp_get_or_create_cart', [
            ':p_user_id' => $userId
        ]);

        if (empty($cartResult['results']) || empty($cartResult['results'][0])) {
            Logger::error('Failed to get cart - stored procedure may not exist or returned empty', [
                'user_id' => $userId,
                'result' => $cartResult
            ]);
            ApiResponse::error('Failed to get cart. Please ensure database is properly initialized.');
            return;
        }

        $cart = $cartResult['results'][0][0];

        // Get cart items
        $items = $db->query("
            SELECT
                ci.cart_item_id,
                ci.product_id,
                p.product_name,
                p.product_description,
                p.product_type,
                p.sku,
                p.credits_amount,
                p.bonus_credits,
                p.image_url,
                ci.quantity,
                ci.price_at_add,
                ci.currency,
                ci.discount_amount,
                ci.promo_code,
                (ci.price_at_add * ci.quantity - ci.discount_amount) as item_total,
                ci.added_at
            FROM cart_items ci
            JOIN products p ON ci.product_id = p.product_id
            WHERE ci.cart_id = ?
            ORDER BY ci.added_at DESC
        ", [$cart['cart_id']]);

        // Calculate totals
        $subtotal = 0;
        $totalDiscount = 0;
        $totalCredits = 0;

        foreach ($items as $item) {
            $subtotal += ($item['price_at_add'] * $item['quantity']);
            $totalDiscount += $item['discount_amount'];
            if ($item['credits_amount']) {
                $totalCredits += ($item['credits_amount'] + $item['bonus_credits']) * $item['quantity'];
            }
        }

        $total = $subtotal - $totalDiscount;

        ApiResponse::success([
            'cart' => [
                'cart_id' => $cart['cart_id'],
                'item_count' => count($items),
                'items' => $items,
                'subtotal' => $subtotal,
                'discount' => $totalDiscount,
                'total' => $total,
                'total_credits' => $totalCredits,
                'currency' => $items[0]['currency'] ?? 'GBP',
                'updated_at' => $cart['updated_at']
            ]
        ], 'Cart retrieved successfully');

    } catch (Exception $e) {
        Logger::error('Get cart error', ['error' => $e->getMessage(), 'user_id' => $userId ?? null]);
        ApiResponse::serverError('Failed to retrieve cart');
    }
}

/**
 * Add item to cart
 */
function handleAddToCart() {
    ApiResponse::requireMethod('POST');

    try {
        $userId = Security::requireAuth();
        $data = ApiResponse::getJsonBody();

        // Validate input
        if (!isset($data['product_id'])) {
            ApiResponse::badRequest('Product ID is required');
            return;
        }

        $productId = (int)$data['product_id'];
        $quantity = isset($data['quantity']) ? (int)$data['quantity'] : 1;
        $currency = $data['currency'] ?? 'GBP';

        // Validate currency
        if (!in_array($currency, ['GBP', 'USD', 'EUR'])) {
            ApiResponse::badRequest('Invalid currency');
            return;
        }

        // Validate quantity
        if ($quantity < 1 || $quantity > 99) {
            ApiResponse::badRequest('Invalid quantity');
            return;
        }

        $db = Database::getInstance();

        // Check product availability
        $product = $db->query("
            SELECT product_id, is_available, stock_quantity, max_per_order
            FROM products
            WHERE product_id = ?
        ", [$productId]);

        if (empty($product)) {
            ApiResponse::notFound('Product not found');
            return;
        }

        $product = $product[0];

        if (!$product['is_available']) {
            ApiResponse::error('Product is not available');
            return;
        }

        // Check stock
        if ($product['stock_quantity'] !== null && $product['stock_quantity'] < $quantity) {
            ApiResponse::error('Insufficient stock');
            return;
        }

        // Check max per order
        if ($product['max_per_order'] !== null && $quantity > $product['max_per_order']) {
            ApiResponse::error("Maximum {$product['max_per_order']} per order");
            return;
        }

        // Add to cart using stored procedure
        $result = $db->callProcedure('sp_add_to_cart', [
            ':p_user_code' => $userId,
            ':p_product_id' => $productId,
            ':p_quantity' => $quantity,
            ':p_currency' => $currency
        ]);

        // Log activity for child accounts
        $user = $db->query("SELECT account_type_id FROM users WHERE user_id = ?", [$userId]);
        if (!empty($user)) {
            $accountTypeId = $user[0]['account_type_id'];
            // Check if child account (account_type_id = 1 for 'child')
            if ($accountTypeId == 1) {
                $productName = $db->query("SELECT product_name FROM products WHERE product_id = ?", [$productId]);
                $db->execute("
                    INSERT INTO child_activity_log (child_user_id, activity_type, activity_description)
                    VALUES (?, 'card_purchase', ?)
                ", [
                    $userId,
                    "Added to cart: {$productName[0]['product_name']} (Qty: {$quantity})"
                ]);
            }
        }

        ApiResponse::success('Item added to cart successfully', [
            'cart' => $result['results'][0][0] ?? []
        ]);

    } catch (Exception $e) {
        Logger::error('Add to cart error', [
            'error' => $e->getMessage(),
            'user_id' => $userId ?? null,
            'product_id' => $productId ?? null
        ]);
        ApiResponse::serverError('Failed to add item to cart');
    }
}

/**
 * Update cart item quantity
 */
function handleUpdateCartItem() {
    ApiResponse::requireMethod('PUT');

    try {
        $userId = Security::requireAuth();
        $data = ApiResponse::getJsonBody();

        if (!isset($data['cart_item_id']) || !isset($data['quantity'])) {
            ApiResponse::badRequest('Cart item ID and quantity are required');
            return;
        }

        $cartItemId = (int)$data['cart_item_id'];
        $quantity = (int)$data['quantity'];

        if ($quantity < 1 || $quantity > 99) {
            ApiResponse::badRequest('Invalid quantity');
            return;
        }

        $db = Database::getInstance();

        // Verify cart item belongs to user
        $cartItem = $db->query("
            SELECT ci.cart_item_id, ci.product_id, p.max_per_order, p.stock_quantity
            FROM cart_items ci
            JOIN shopping_carts sc ON ci.cart_id = sc.cart_id
            JOIN products p ON ci.product_id = p.product_id
            WHERE ci.cart_item_id = ? AND sc.user_id = ?
        ", [$cartItemId, $userId]);

        if (empty($cartItem)) {
            ApiResponse::notFound('Cart item not found');
            return;
        }

        $item = $cartItem[0];

        // Check constraints
        if ($item['max_per_order'] !== null && $quantity > $item['max_per_order']) {
            ApiResponse::error("Maximum {$item['max_per_order']} per order");
            return;
        }

        if ($item['stock_quantity'] !== null && $quantity > $item['stock_quantity']) {
            ApiResponse::error('Insufficient stock');
            return;
        }

        // Update quantity
        $db->execute("
            UPDATE cart_items
            SET quantity = ?
            WHERE cart_item_id = ?
        ", [$quantity, $cartItemId]);

        // Update cart timestamp
        $db->execute("
            UPDATE shopping_carts sc
            JOIN cart_items ci ON sc.cart_id = ci.cart_id
            SET sc.updated_at = NOW()
            WHERE ci.cart_item_id = ?
        ", [$cartItemId]);

        ApiResponse::success('Cart item updated successfully');

    } catch (Exception $e) {
        Logger::error('Update cart item error', [
            'error' => $e->getMessage(),
            'user_id' => $userId ?? null,
            'cart_item_id' => $cartItemId ?? null
        ]);
        ApiResponse::serverError('Failed to update cart item');
    }
}

/**
 * Remove item from cart
 */
function handleRemoveFromCart() {
    ApiResponse::requireMethod('DELETE');

    try {
        $userId = Security::requireAuth();
        $cartItemId = (int)($_GET['cart_item_id'] ?? 0);

        if (!$cartItemId) {
            ApiResponse::badRequest('Cart item ID is required');
            return;
        }

        $db = Database::getInstance();

        // Verify cart item belongs to user and delete
        $result = $db->execute("
            DELETE ci FROM cart_items ci
            JOIN shopping_carts sc ON ci.cart_id = sc.cart_id
            WHERE ci.cart_item_id = ? AND sc.user_id = ?
        ", [$cartItemId, $userId]);

        if ($result === 0) {
            ApiResponse::notFound('Cart item not found');
            return;
        }

        ApiResponse::success('Item removed from cart successfully');

    } catch (Exception $e) {
        Logger::error('Remove from cart error', [
            'error' => $e->getMessage(),
            'user_id' => $userId ?? null,
            'cart_item_id' => $cartItemId ?? null
        ]);
        ApiResponse::serverError('Failed to remove item from cart');
    }
}

/**
 * Clear entire cart
 */
function handleClearCart() {
    ApiResponse::requireMethod('DELETE');

    try {
        $userId = Security::requireAuth();
        $db = Database::getInstance();

        // Delete all cart items for user
        $db->execute("
            DELETE ci FROM cart_items ci
            JOIN shopping_carts sc ON ci.cart_id = sc.cart_id
            WHERE sc.user_id = ?
        ", [$userId]);

        ApiResponse::success('Cart cleared successfully');

    } catch (Exception $e) {
        Logger::error('Clear cart error', ['error' => $e->getMessage(), 'user_id' => $userId ?? null]);
        ApiResponse::serverError('Failed to clear cart');
    }
}

/**
 * Apply promo code to cart
 */
function handleApplyPromoCode() {
    ApiResponse::requireMethod('POST');

    try {
        $userId = Security::requireAuth();
        $data = ApiResponse::getJsonBody();

        if (!isset($data['promo_code'])) {
            ApiResponse::badRequest('Promo code is required');
            return;
        }

        $promoCode = strtoupper(trim($data['promo_code']));

        $db = Database::getInstance();

        // Validate promo code
        $promo = $db->query("
            SELECT promo_code, discount_percentage, bonus_credits, max_uses, times_used, valid_until
            FROM promotion_codes
            WHERE promo_code = ? AND active = TRUE
        ", [$promoCode]);

        if (empty($promo)) {
            ApiResponse::error('Invalid promo code');
            return;
        }

        $promo = $promo[0];

        // Check expiry
        if ($promo['valid_until'] && strtotime($promo['valid_until']) < time()) {
            ApiResponse::error('Promo code has expired');
            return;
        }

        // Check usage limit
        if ($promo['max_uses'] !== null && $promo['times_used'] >= $promo['max_uses']) {
            ApiResponse::error('Promo code has reached maximum uses');
            return;
        }

        // Get user's cart
        $cart = $db->query("
            SELECT cart_id FROM shopping_carts WHERE user_id = ?
        ", [$userId]);

        if (empty($cart)) {
            ApiResponse::error('Cart not found');
            return;
        }

        $cartId = $cart[0]['cart_id'];

        // Apply discount to all items
        $discountPercentage = $promo['discount_percentage'];

        $db->execute("
            UPDATE cart_items
            SET
                promo_code = ?,
                discount_amount = ROUND(price_at_add * quantity * (? / 100), 2)
            WHERE cart_id = ?
        ", [$promoCode, $discountPercentage, $cartId]);

        ApiResponse::success('Promo code applied successfully', [
            'promo_code' => $promoCode,
            'discount_percentage' => $discountPercentage,
            'bonus_credits' => $promo['bonus_credits']
        ]);

    } catch (Exception $e) {
        Logger::error('Apply promo code error', [
            'error' => $e->getMessage(),
            'user_id' => $userId ?? null,
            'promo_code' => $promoCode ?? null
        ]);
        ApiResponse::serverError('Failed to apply promo code');
    }
}
