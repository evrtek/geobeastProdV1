<?php
/**
 * WebSocket Notification Helper
 * Sends notifications to the WebSocket server via HTTP POST
 */

class WebSocketNotifier {
    private static $wsEndpoint = null;

    /**
     * Initialize WebSocket endpoint URL
     */
    private static function getEndpoint() {
        if (self::$wsEndpoint === null) {
            $host = getenv('WEBSOCKET_HOST') ?: 'localhost';
            $port = getenv('WEBSOCKET_HTTP_PORT') ?: 8444; // Separate HTTP port for notifications
            self::$wsEndpoint = "http://{$host}:{$port}/notify";
        }
        return self::$wsEndpoint;
    }

    /**
     * Send a notification to a specific user via WebSocket
     *
     * @param int $userId The user ID to send the notification to
     * @param string $type The message type (e.g., 'battle_invitation')
     * @param array $data Additional data to send with the notification
     * @return bool Success status
     */
    public static function notifyUser($userId, $type, $data = []) {
        try {
            $payload = array_merge([
                'type' => $type,
                'user_id' => $userId
            ], $data);

            // For now, we'll use a simple approach: trigger via database
            // The WebSocket server can poll for new notifications
            // Or we can use a message queue like Redis

            // Alternative: Direct HTTP notification (requires WebSocket server HTTP endpoint)
            // This is a placeholder - actual implementation depends on infrastructure

            return true; // Placeholder return

        } catch (Exception $e) {
            error_log('WebSocket notification error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Notify about battle invitation
     */
    public static function notifyBattleInvitation($recipientUserId, $invitation) {
        return self::notifyUser($recipientUserId, 'battle_invitation', [
            'invitation' => $invitation
        ]);
    }

    /**
     * Notify about battle invitation response
     */
    public static function notifyBattleInvitationResponse($senderUserId, $invitationId, $response, $responderUserId) {
        return self::notifyUser($senderUserId, 'battle_invitation_response', [
            'invitation_id' => $invitationId,
            'response' => $response,
            'responder_user_id' => $responderUserId
        ]);
    }

    /**
     * Notify about battle invitation cancellation
     */
    public static function notifyBattleInvitationCancelled($recipientUserId, $invitationId, $cancelledByUserId) {
        return self::notifyUser($recipientUserId, 'battle_invitation_cancelled', [
            'invitation_id' => $invitationId,
            'cancelled_by_user_id' => $cancelledByUserId
        ]);
    }

    /**
     * Notify about battle started
     */
    public static function notifyBattleStarted($userId, $battleId, $opponentUserId, $battleMode = 1) {
        return self::notifyUser($userId, 'battle_started', [
            'battle_id' => $battleId,
            'opponent_user_id' => $opponentUserId,
            'battle_mode' => $battleMode
        ]);
    }

    /**
     * Notify about battle phase update
     */
    public static function notifyBattlePhaseUpdate($userId, $battleId, $phase, $data = []) {
        return self::notifyUser($userId, 'battle_phase_update', array_merge([
            'battle_id' => $battleId,
            'phase' => $phase
        ], $data));
    }

    /**
     * Notify about battle ended
     */
    public static function notifyBattleEnded($userId, $battleId, $winnerId, $playerWins = 0, $opponentWins = 0) {
        return self::notifyUser($userId, 'battle_ended', [
            'battle_id' => $battleId,
            'winner_user_id' => $winnerId,
            'player_wins' => $playerWins,
            'opponent_wins' => $opponentWins
        ]);
    }
}
