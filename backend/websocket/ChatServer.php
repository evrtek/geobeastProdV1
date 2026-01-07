<?php
/**
 * WebSocket Chat Server
 * Handles real-time chat connections using Ratchet WebSocket library
 *
 * To run this server:
 * php backend/websocket/ChatServer.php
 *
 * The server will listen on wss://localhost:8443 (SSL)
 * Note: SSL termination should be handled by reverse proxy (nginx/IIS)
 */

namespace GeoBeasts\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../core/Database.php';

class ChatServer implements MessageComponentInterface {
    protected $clients;
    protected $userConnections;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->userConnections = []; // userId => [connections]
    }

    public function onOpen(ConnectionInterface $conn) {
        // Store connection
        $this->clients->attach($conn);

        echo "New connection: {$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);

        if (!$data || !isset($data['type'])) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Invalid message format'
            ]));
            return;
        }

        switch ($data['type']) {
            case 'authenticate':
                $this->handleAuthenticate($from, $data);
                break;

            case 'chat_message':
                $this->handleChatMessage($from, $data);
                break;

            case 'typing':
                $this->handleTyping($from, $data);
                break;

            case 'battle_invitation_sent':
                $this->handleBattleInvitationSent($from, $data);
                break;

            case 'battle_invitation_response':
                $this->handleBattleInvitationResponse($from, $data);
                break;

            case 'battle_invitation_cancelled':
                $this->handleBattleInvitationCancelled($from, $data);
                break;

            case 'battle_started':
                $this->handleBattleStarted($from, $data);
                break;

            case 'battle_phase_update':
                $this->handleBattlePhaseUpdate($from, $data);
                break;

            case 'battle_ended':
                $this->handleBattleEnded($from, $data);
                break;

            case 'ping':
                $from->send(json_encode(['type' => 'pong']));
                break;

            default:
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Unknown message type'
                ]));
        }
    }

    public function onClose(ConnectionInterface $conn) {
        // Remove connection from user mapping
        if (isset($conn->userId)) {
            $userId = $conn->userId;
            if (isset($this->userConnections[$userId])) {
                $key = array_search($conn, $this->userConnections[$userId], true);
                if ($key !== false) {
                    unset($this->userConnections[$userId][$key]);
                }

                // Remove user entry if no more connections
                if (empty($this->userConnections[$userId])) {
                    unset($this->userConnections[$userId]);
                }
            }
        }

        $this->clients->detach($conn);

        echo "Connection closed: {$conn->resourceId}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }

    protected function handleAuthenticate(ConnectionInterface $conn, $data) {
        if (!isset($data['auth_token'])) {
            $conn->send(json_encode([
                'type' => 'auth_error',
                'message' => 'Auth token required'
            ]));
            return;
        }

        $authToken = $data['auth_token'];

        // Try to authenticate with user_code first (new method)
        $userId = $this->verifyUserCode($authToken);

        // Fall back to cookie-based auth if user_code auth fails
        if (!$userId) {
            $userId = $this->verifyAuthToken($authToken);
        }

        if (!$userId) {
            $conn->send(json_encode([
                'type' => 'auth_error',
                'message' => 'Invalid auth token'
            ]));
            return;
        }

        // Store user ID on connection
        $conn->userId = $userId;

        // Add to user connections mapping
        if (!isset($this->userConnections[$userId])) {
            $this->userConnections[$userId] = [];
        }
        $this->userConnections[$userId][] = $conn;

        // Get user_code for the authenticated response
        try {
            $db = \Database::getInstance();
            $userCodeResult = $db->query('SELECT check_code FROM users WHERE user_id = ?', [$userId]);
            $userCode = !empty($userCodeResult) ? $userCodeResult[0]['check_code'] : null;
        } catch (\Exception $e) {
            $userCode = null;
        }

        $conn->send(json_encode([
            'type' => 'authenticated',
            'user_id' => $userId,
            'user_code' => $userCode
        ]));

        echo "User {$userId} (code: {$userCode}) authenticated on connection {$conn->resourceId}\n";
    }

    protected function handleChatMessage(ConnectionInterface $from, $data) {
        if (!isset($from->userId)) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Not authenticated'
            ]));
            return;
        }

        if (!isset($data['recipient_user_id']) || !isset($data['message_text'])) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Missing required fields'
            ]));
            return;
        }

        $senderId = $from->userId;
        $recipientId = (int)$data['recipient_user_id'];
        $messageText = $data['message_text'];

        // Verify friendship and save to database via API would be better
        // For now, broadcast to recipient if online

        $messageData = [
            'type' => 'chat_message',
            'sender_user_id' => $senderId,
            'recipient_user_id' => $recipientId,
            'message_text' => $messageText,
            'sent_at' => date('Y-m-d H:i:s')
        ];

        // Send to recipient if online
        if (isset($this->userConnections[$recipientId])) {
            foreach ($this->userConnections[$recipientId] as $recipientConn) {
                $recipientConn->send(json_encode($messageData));
            }
        }

        // Send confirmation back to sender
        $from->send(json_encode([
            'type' => 'message_sent',
            'data' => $messageData
        ]));
    }

    protected function handleTyping(ConnectionInterface $from, $data) {
        if (!isset($from->userId)) {
            return;
        }

        if (!isset($data['recipient_user_id'])) {
            return;
        }

        $recipientId = (int)$data['recipient_user_id'];
        $isTyping = isset($data['is_typing']) ? (bool)$data['is_typing'] : true;

        // Send typing indicator to recipient if online
        if (isset($this->userConnections[$recipientId])) {
            $typingData = [
                'type' => 'typing',
                'user_id' => $from->userId,
                'is_typing' => $isTyping
            ];

            foreach ($this->userConnections[$recipientId] as $recipientConn) {
                $recipientConn->send(json_encode($typingData));
            }
        }
    }

    protected function verifyUserCode($userCode) {
        // Authenticate using user_code (check_code in database)
        try {
            $db = \Database::getInstance();

            // Look up user by check_code
            $user = $db->query('SELECT user_id FROM users WHERE check_code = ? AND active = TRUE', [$userCode]);

            if (empty($user)) {
                return false;
            }

            return (int)$user[0]['user_id'];
        } catch (\Exception $e) {
            echo "Error verifying user code: {$e->getMessage()}\n";
            return false;
        }
    }

    protected function verifyAuthToken($token) {
        // This should match the authentication logic in Security.php
        // For now, simplified version that checks the database

        try {
            $db = \Database::getInstance();

            // Parse cookie token (format: user_id:timestamp:signature)
            $parts = explode(':', $token);
            if (count($parts) !== 3) {
                return false;
            }

            $userId = (int)$parts[0];
            $timestamp = (int)$parts[1];
            $signature = $parts[2];

            // Check if token is expired (24 hours)
            if (time() - $timestamp > 86400) {
                return false;
            }

            // Verify signature
            $expected = hash_hmac('sha256', $userId . ':' . $timestamp, getenv('AUTH_SECRET') ?: 'default_secret');

            if (!hash_equals($expected, $signature)) {
                return false;
            }

            // Verify user exists and is active
            $user = $db->query('SELECT user_id FROM users WHERE user_id = ? AND active = TRUE', [$userId]);

            if (empty($user)) {
                return false;
            }

            return $userId;

        } catch (\Exception $e) {
            error_log('Auth verification error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Broadcast message to specific user (all their connections)
     */
    public function sendToUser($userId, $message) {
        if (isset($this->userConnections[$userId])) {
            foreach ($this->userConnections[$userId] as $conn) {
                $conn->send(json_encode($message));
            }
        }
    }

    /**
     * Broadcast message to all connected users
     */
    public function broadcast($message, $excludeUserId = null) {
        foreach ($this->clients as $client) {
            if ($excludeUserId && isset($client->userId) && $client->userId == $excludeUserId) {
                continue;
            }
            $client->send(json_encode($message));
        }
    }

    /**
     * Handle battle invitation sent notification
     * Notifies the recipient that they received a battle invitation
     */
    protected function handleBattleInvitationSent(ConnectionInterface $from, $data) {
        if (!isset($from->userId)) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Not authenticated'
            ]));
            return;
        }

        if (!isset($data['recipient_user_id']) || !isset($data['invitation'])) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Missing required fields'
            ]));
            return;
        }

        $recipientId = (int)$data['recipient_user_id'];
        $invitation = $data['invitation'];

        // Send invitation to recipient if online
        if (isset($this->userConnections[$recipientId])) {
            $notificationData = [
                'type' => 'battle_invitation',
                'invitation' => $invitation,
                'sender_user_id' => $from->userId
            ];

            foreach ($this->userConnections[$recipientId] as $recipientConn) {
                $recipientConn->send(json_encode($notificationData));
            }

            echo "Battle invitation sent from user {$from->userId} to user {$recipientId}\n";
        } else {
            echo "User {$recipientId} is offline, invitation will be in database\n";
        }
    }

    /**
     * Handle battle invitation response (accept/decline)
     * Notifies the sender that their invitation was responded to
     */
    protected function handleBattleInvitationResponse(ConnectionInterface $from, $data) {
        if (!isset($from->userId)) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Not authenticated'
            ]));
            return;
        }

        if (!isset($data['sender_user_id']) || !isset($data['response']) || !isset($data['invitation_id'])) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Missing required fields'
            ]));
            return;
        }

        $senderId = (int)$data['sender_user_id'];
        $response = $data['response']; // 'accepted' or 'declined'
        $invitationId = $data['invitation_id'];

        // Notify sender of response if online
        if (isset($this->userConnections[$senderId])) {
            $responseData = [
                'type' => 'battle_invitation_response',
                'invitation_id' => $invitationId,
                'response' => $response,
                'responder_user_id' => $from->userId
            ];

            foreach ($this->userConnections[$senderId] as $senderConn) {
                $senderConn->send(json_encode($responseData));
            }

            echo "Battle invitation {$invitationId} {$response} by user {$from->userId}\n";
        }
    }

    /**
     * Handle battle invitation cancellation
     * Notifies the recipient that the invitation was cancelled
     */
    protected function handleBattleInvitationCancelled(ConnectionInterface $from, $data) {
        if (!isset($from->userId)) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Not authenticated'
            ]));
            return;
        }

        if (!isset($data['recipient_user_id']) || !isset($data['invitation_id'])) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Missing required fields'
            ]));
            return;
        }

        $recipientId = (int)$data['recipient_user_id'];
        $invitationId = $data['invitation_id'];

        // Notify recipient if online
        if (isset($this->userConnections[$recipientId])) {
            $cancelData = [
                'type' => 'battle_invitation_cancelled',
                'invitation_id' => $invitationId,
                'cancelled_by_user_id' => $from->userId
            ];

            foreach ($this->userConnections[$recipientId] as $recipientConn) {
                $recipientConn->send(json_encode($cancelData));
            }

            echo "Battle invitation {$invitationId} cancelled by user {$from->userId}\n";
        }
    }

    /**
     * Handle battle started notification
     * Notifies both players that the battle has begun
     */
    protected function handleBattleStarted(ConnectionInterface $from, $data) {
        if (!isset($from->userId)) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Not authenticated'
            ]));
            return;
        }

        if (!isset($data['opponent_user_id']) || !isset($data['battle_id'])) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Missing required fields'
            ]));
            return;
        }

        $opponentId = (int)$data['opponent_user_id'];
        $battleId = $data['battle_id'];

        $battleData = [
            'type' => 'battle_started',
            'battle_id' => $battleId,
            'opponent_user_id' => $from->userId,
            'battle_mode' => $data['battle_mode'] ?? 1
        ];

        // Notify opponent if online
        if (isset($this->userConnections[$opponentId])) {
            foreach ($this->userConnections[$opponentId] as $opponentConn) {
                $opponentConn->send(json_encode($battleData));
            }

            echo "Battle {$battleId} started between user {$from->userId} and user {$opponentId}\n";
        }

        // Confirm to sender
        $from->send(json_encode([
            'type' => 'battle_started',
            'battle_id' => $battleId,
            'status' => 'confirmed'
        ]));
    }

    /**
     * Handle battle phase update
     * Broadcasts battle progress to both players
     */
    protected function handleBattlePhaseUpdate(ConnectionInterface $from, $data) {
        if (!isset($from->userId)) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Not authenticated'
            ]));
            return;
        }

        if (!isset($data['opponent_user_id']) || !isset($data['battle_id']) || !isset($data['phase'])) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Missing required fields'
            ]));
            return;
        }

        $opponentId = (int)$data['opponent_user_id'];
        $battleId = $data['battle_id'];
        $phase = $data['phase'];

        $phaseData = [
            'type' => 'battle_phase_update',
            'battle_id' => $battleId,
            'phase' => $phase,
            'player_card' => $data['player_card'] ?? null,
            'opponent_card' => $data['opponent_card'] ?? null,
            'phase_winner' => $data['phase_winner'] ?? null
        ];

        // Send to opponent if online
        if (isset($this->userConnections[$opponentId])) {
            foreach ($this->userConnections[$opponentId] as $opponentConn) {
                $opponentConn->send(json_encode($phaseData));
            }
        }

        echo "Battle {$battleId} phase {$phase} update from user {$from->userId}\n";
    }

    /**
     * Handle battle ended notification
     * Notifies both players of battle results
     */
    protected function handleBattleEnded(ConnectionInterface $from, $data) {
        if (!isset($from->userId)) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Not authenticated'
            ]));
            return;
        }

        if (!isset($data['opponent_user_id']) || !isset($data['battle_id'])) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Missing required fields'
            ]));
            return;
        }

        $opponentId = (int)$data['opponent_user_id'];
        $battleId = $data['battle_id'];
        $winnerId = $data['winner_user_id'] ?? null;

        $endData = [
            'type' => 'battle_ended',
            'battle_id' => $battleId,
            'winner_user_id' => $winnerId,
            'player_wins' => $data['player_wins'] ?? 0,
            'opponent_wins' => $data['opponent_wins'] ?? 0
        ];

        // Send to opponent if online
        if (isset($this->userConnections[$opponentId])) {
            foreach ($this->userConnections[$opponentId] as $opponentConn) {
                $opponentConn->send(json_encode($endData));
            }
        }

        echo "Battle {$battleId} ended, winner: " . ($winnerId ?? 'draw') . "\n";

        // Confirm to sender
        $from->send(json_encode([
            'type' => 'battle_ended',
            'battle_id' => $battleId,
            'status' => 'confirmed'
        ]));
    }

    /**
     * Process message queue file and broadcast messages to connected clients
     */
    public function processMessageQueue() {
        $queueFile = __DIR__ . '/message_queue.json';

        if (!file_exists($queueFile)) {
            return;
        }

        $queue = json_decode(file_get_contents($queueFile), true);
        if (empty($queue)) {
            return;
        }

        // Process all queued messages
        foreach ($queue as $item) {
            if ($item['type'] === 'chat_message' && isset($item['message'])) {
                $message = $item['message'];

                echo "Processing message from queue: " . json_encode($message) . "\n";

                // Get recipient and sender IDs from user codes
                $recipientId = $this->getUserIdFromCode($message['recipient_user_code'] ?? null);
                $senderId = $this->getUserIdFromCode($message['sender_user_code'] ?? null);

                echo "Recipient ID: {$recipientId}, Sender ID: {$senderId}\n";
                echo "Online users: " . json_encode(array_keys($this->userConnections)) . "\n";

                if ($recipientId) {
                    // Send to recipient if online
                    if (isset($this->userConnections[$recipientId])) {
                        foreach ($this->userConnections[$recipientId] as $conn) {
                            $conn->send(json_encode([
                                'type' => 'chat_message',
                                'message' => $message
                            ]));
                        }
                        echo "Broadcasted message to user {$recipientId}\n";
                    } else {
                        echo "Recipient user {$recipientId} is not connected\n";
                    }
                } else {
                    echo "Could not resolve recipient ID from code: " . ($message['recipient_user_code'] ?? 'null') . "\n";
                }

                // Also send to sender if online (for multi-device sync)
                if ($senderId && isset($this->userConnections[$senderId])) {
                    foreach ($this->userConnections[$senderId] as $conn) {
                        $conn->send(json_encode([
                            'type' => 'chat_message',
                            'message' => $message
                        ]));
                    }
                }
            }
        }

        // Clear the queue after processing
        file_put_contents($queueFile, json_encode([], JSON_PRETTY_PRINT));
    }

    /**
     * Helper to get user ID from user code
     */
    private function getUserIdFromCode($userCode) {
        if (!$userCode) {
            return null;
        }

        try {
            $db = \Database::getInstance();
            $result = $db->query('SELECT user_id FROM users WHERE check_code = ?', [$userCode]);
            return !empty($result) ? (int)$result[0]['user_id'] : null;
        } catch (\Exception $e) {
            echo "Error getting user ID from code: " . $e->getMessage() . "\n";
            return null;
        }
    }
}

// Start the WebSocket server
if (php_sapi_name() === 'cli') {
    $port = getenv('WEBSOCKET_PORT') ?: 8443;

    $chatServer = new ChatServer();

    $server = \Ratchet\Server\IoServer::factory(
        new \Ratchet\Http\HttpServer(
            new \Ratchet\WebSocket\WsServer(
                $chatServer
            )
        ),
        $port
    );

    // Set up periodic queue checking
    $server->loop->addPeriodicTimer(0.5, function() use ($chatServer) {
        $chatServer->processMessageQueue();
    });

    echo "WebSocket server started on port {$port}\n";
    echo "Connect to wss://localhost:{$port} (via SSL proxy)\n";
    echo "Message queue polling enabled (0.5s interval)\n";

    $server->run();
}
