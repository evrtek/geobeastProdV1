<?php
/**
 * Battle Engine
 * Core game logic for GeoBeasts card battles
 * Implements all three battle modes and combat mechanics
 */

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/ChildActivityLogger.php';
require_once __DIR__ . '/EmailService.php';

class BattleEngine {

    // Battle modes
    const MODE_FRIENDLY = 'mode1_friendly';
    const MODE_COMPETITIVE = 'mode2_competitive';
    const MODE_ULTIMATE = 'mode3_ultimate';

    // Battle status
    const STATUS_PENDING = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_ABANDONED = 'abandoned';
    const STATUS_EXPIRED = 'expired';

    // Type advantages - each type has advantage over certain other types (1.5x damage)
    private static $typeAdvantages = [
        1 => [3, 8],  // Igneous beats Sedimentary, Fossil
        2 => [1, 6],  // Metamorphic beats Igneous, Jewel
        3 => [7, 5],  // Sedimentary beats Crystal, Metal
        4 => [2, 3],  // Ore beats Metamorphic, Sedimentary
        5 => [4, 7],  // Metal beats Ore, Crystal
        6 => [5, 8],  // Jewel beats Metal, Fossil
        7 => [6, 1],  // Crystal beats Jewel, Igneous
        8 => [2, 4],  // Fossil beats Metamorphic, Ore
    ];

    /**
     * Validate a battle deck
     */
    public static function validateDeck($db, $userId, $deckId, $battleMode) {
        // Check deck exists and belongs to user
        $deckCheck = $db->query(
            'SELECT user_id FROM battle_decks WHERE battle_deck_id = ? AND user_id = ?',
            [$deckId, $userId]
        );

        if (empty($deckCheck)) {
            return ['valid' => false, 'message' => 'Deck not found or access denied'];
        }

        // Get deck cards
        $cards = $db->query(
            'SELECT
                bdc.user_card_id,
                uc.is_in_marketplace,
                uc.is_in_trade,
                ct.card_name,
                ctype.is_battle_card
            FROM battle_deck_cards bdc
            JOIN user_cards uc ON bdc.user_card_id = uc.user_card_id
            JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
            JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
            JOIN card_types ctype ON ct.card_type_id = ctype.card_type_id
            WHERE bdc.battle_deck_id = ?',
            [$deckId]
        );

        if (count($cards) !== 5) {
            return ['valid' => false, 'message' => 'Deck must contain exactly 5 cards'];
        }

        $issues = [];

        foreach ($cards as $card) {
            // Check if card is battle card
            if (!$card['is_battle_card']) {
                $issues[] = "{$card['card_name']} is not a battle card";
            }

            // Check if card is in marketplace
            if ($card['is_in_marketplace']) {
                $issues[] = "{$card['card_name']} is currently listed in marketplace";
            }

            // Check if card is in trade
            if ($card['is_in_trade']) {
                $issues[] = "{$card['card_name']} is currently in a trade";
            }
        }

        // Check child account restrictions for modes 2 & 3
        if (in_array($battleMode, ['mode2_competitive', 'mode3_ultimate'])) {
            $userInfo = $db->query(
                'SELECT account_type_id FROM users WHERE user_id = ?',
                [$userId]
            )[0];

            $childTypeId = $db->query(
                "SELECT account_type_id FROM account_types WHERE account_type_name = 'child'"
            )[0]['account_type_id'];

            if ($userInfo['account_type_id'] == $childTypeId) {
                // Check parent permissions
                $permissions = $db->query(
                    'SELECT allow_mode2_battles, allow_mode3_battles FROM parent_controls WHERE child_user_id = ?',
                    [$userId]
                );

                if (!empty($permissions)) {
                    if ($battleMode === 'mode2_competitive' && !$permissions[0]['allow_mode2_battles']) {
                        return ['valid' => false, 'message' => 'Parent approval required for Mode 2 battles'];
                    }
                    if ($battleMode === 'mode3_ultimate' && !$permissions[0]['allow_mode3_battles']) {
                        return ['valid' => false, 'message' => 'Parent approval required for Mode 3 battles'];
                    }
                }
            }
        }

        if (!empty($issues)) {
            return [
                'valid' => false,
                'message' => 'Deck validation failed',
                'issues' => $issues
            ];
        }

        return ['valid' => true];
    }

    /**
     * Create an AI battle
     */
    public static function createAIBattle($db, $userId, $deckId, $battleMode) {
        // Get user's deck cards
        $userCards = $db->query(
            'SELECT
                uc.user_card_id,
                ct.speed_score,
                ct.attack_score,
                ct.defense_score
            FROM battle_deck_cards bdc
            JOIN user_cards uc ON bdc.user_card_id = uc.user_card_id
            JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
            JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
            WHERE bdc.battle_deck_id = ?
            ORDER BY bdc.card_position',
            [$deckId]
        );

        // Calculate average scores for AI deck matching
        $avgSpeed = array_sum(array_column($userCards, 'speed_score')) / 5;
        $avgAttack = array_sum(array_column($userCards, 'attack_score')) / 5;
        $avgDefense = array_sum(array_column($userCards, 'defense_score')) / 5;

        // Create AI user if doesn't exist
        $aiUser = $db->query(
            "SELECT user_id FROM users WHERE username = 'AI_Opponent' LIMIT 1"
        );

        if (empty($aiUser)) {
            // Create AI user
            $db->execute(
                "INSERT INTO users (username, given_name, surname, email, password_hash, dob, account_type_id, check_code, confirmed, active)
                VALUES ('AI_Opponent', 'AI', 'Opponent', 'ai@geobeasts.local', '', '2000-01-01',
                (SELECT account_type_id FROM account_types WHERE account_type_name = 'parent'), 'AI_CHECK', TRUE, TRUE)"
            );
            $aiUserId = $db->query("SELECT user_id FROM users WHERE username = 'AI_Opponent'")[0]['user_id'];
        } else {
            $aiUserId = $aiUser[0]['user_id'];
        }

        // Create battle
        $result = $db->callProcedure('sp_create_battle', [
            ':p_player1_id' => $userId,
            ':p_player2_id' => $aiUserId,
            ':p_battle_mode' => $battleMode,
            ':p_is_ai_battle' => true
        ], ['p_battle_id']);

        $battleId = $result['output']['p_battle_id'];

        // Store battle state in temporary table
        $battleState = [
            'battle_id' => $battleId,
            'player1_id' => $userId,
            'player2_id' => $aiUserId,
            'player1_deck' => $deckId,
            'current_phase' => 1,
            'player1_score' => 0,
            'player2_score' => 0,
            'player1_cards' => $userCards,
            'player2_cards' => [], // AI cards will be generated as needed
            'phase_history' => []
        ];

        // Store in sessions or cache (for now, using a temporary JSON storage)
        self::saveBattleState($battleId, $battleState);

        return [
            'battle_id' => $battleId,
            'opponent' => 'AI',
            'battle_mode' => $battleMode,
            'status' => 'in_progress',
            'current_phase' => 1
        ];
    }

    /**
     * Create a friend battle
     */
    public static function createFriendBattle($db, $userId, $opponentId, $deckId, $battleMode) {
        // Create battle in pending state
        $result = $db->callProcedure('sp_create_battle', [
            ':p_player1_id' => $userId,
            ':p_player2_id' => $opponentId,
            ':p_battle_mode' => $battleMode,
            ':p_is_ai_battle' => false
        ], ['p_battle_id']);

        $battleId = $result['output']['p_battle_id'];

        // Update to pending status
        $db->execute(
            'UPDATE battles SET battle_status = ? WHERE battle_id = ?',
            ['pending', $battleId]
        );

        // Create notification for opponent
        $db->execute(
            'INSERT INTO notifications (user_id, notification_type, notification_color, title, message, related_user_id, related_entity_type, related_entity_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $opponentId,
                'battle',
                '#FF0000',
                'Battle Challenge!',
                'You have been challenged to a battle',
                $userId,
                'battle',
                $battleId
            ]
        );

        // Send email notification to opponent
        try {
            $challengerInfo = $db->query(
                'SELECT username FROM users WHERE user_id = ?',
                [$userId]
            );

            $defenderInfo = $db->query(
                'SELECT email, username FROM users WHERE user_id = ?',
                [$opponentId]
            );

            $battleModeNames = [
                1 => 'friendly',
                'mode1_friendly' => 'friendly',
                2 => 'competitive',
                'mode2_competitive' => 'competitive',
                3 => 'ultimate',
                'mode3_ultimate' => 'ultimate'
            ];

            $battleTypeName = $battleModeNames[$battleMode] ?? 'standard';

            if (!empty($challengerInfo) && !empty($defenderInfo)) {
                EmailService::sendBattleChallengeEmail(
                    $defenderInfo[0]['email'],
                    $defenderInfo[0]['username'],
                    $challengerInfo[0]['username'],
                    $battleTypeName
                );
            }
        } catch (Exception $emailError) {
            Logger::error('Failed to send battle challenge email: ' . $emailError->getMessage());
            // Don't fail the battle creation if email fails
        }

        return [
            'battle_id' => $battleId,
            'status' => 'pending',
            'message' => 'Battle invitation sent. Waiting for opponent to accept.'
        ];
    }

    /**
     * Get battle status
     */
    public static function getBattleStatus($db, $battleId, $userId) {
        $battle = $db->query(
            'SELECT * FROM battles WHERE battle_id = ?',
            [$battleId]
        );

        if (empty($battle)) {
            return null;
        }

        $battle = $battle[0];

        // Check access
        if ($battle['player1_user_id'] != $userId && $battle['player2_user_id'] != $userId) {
            return null;
        }

        // Get battle state
        $state = self::loadBattleState($battleId);

        if (!$state) {
            $state = [
                'current_phase' => 1,
                'player1_score' => 0,
                'player2_score' => 0
            ];
        } else {
            // Ensure scores are set even if loading old battle state
            $state['player1_score'] = $state['player1_score'] ?? 0;
            $state['player2_score'] = $state['player2_score'] ?? 0;
        }

        return [
            'battle_id' => $battleId,
            'battle_mode' => $battle['battle_mode'],
            'battle_status' => $battle['battle_status'],
            'is_ai_battle' => (bool)$battle['is_ai_battle'],
            'current_phase' => $state['current_phase'] ?? 1,
            'player1_id' => $battle['player1_user_id'],
            'player2_id' => $battle['player2_user_id'],
            'winner_id' => $battle['winner_user_id'],
            'started_at' => $battle['started_at'],
            'completed_at' => $battle['completed_at']
        ];
    }

    /**
     * Execute a single attack in current phase
     */
    public static function executeAttack($db, $battleId, $userId) {
        $state = self::loadBattleState($battleId);

        if (!$state) {
            throw new Exception('Battle state not found');
        }

        $phase = $state['current_phase_state'] ?? null;

        if (!$phase) {
            throw new Exception('No active phase');
        }

        // Determine attacker and defender
        if ($phase['attacker'] === 'player1') {
            $attackerCard = &$phase['player1_card'];
            $defenderCard = &$phase['player2_card'];
        } else {
            $attackerCard = &$phase['player2_card'];
            $defenderCard = &$phase['player1_card'];
        }

        // Speed check
        if ($defenderCard['speed'] > $attackerCard['speed']) {
            // Attack missed, defender becomes attacker
            $phase['attacker'] = ($phase['attacker'] === 'player1') ? 'player2' : 'player1';
            $result = [
                'attack_result' => 'missed',
                'message' => 'Attack missed! Defender is faster and counters.',
                'attacker_speed' => $attackerCard['speed'],
                'defender_speed' => $defenderCard['speed']
            ];
        } else {
            // Attack hits
            $damage = max($attackerCard['attack'] - $defenderCard['defense'], 10);
            $defenderCard['defense'] -= $damage;
            $attackerCard['speed'] -= 10;

            $result = [
                'attack_result' => 'hit',
                'damage' => $damage,
                'message' => "Attack hit for $damage damage!",
                'attacker_card' => $attackerCard,
                'defender_card' => $defenderCard
            ];

            // Check if defender is defeated
            if ($defenderCard['defense'] <= 0) {
                $result['phase_complete'] = true;
                $result['winner'] = $phase['attacker'];
                $result['message'] .= ' Card defeated!';

                // Update phase winner
                if ($phase['attacker'] === 'player1') {
                    $state['player1_score']++;
                } else {
                    $state['player2_score']++;
                }

                // Log phase to battle history
                self::logPhaseResult($db, $battleId, $state, $phase);

                // Move to next phase or end battle
                if ($state['current_phase'] >= 5) {
                    // Check for winner or tie
                    $result['battle_complete'] = true;
                    self::completeBattle($db, $battleId, $state);
                } else {
                    $state['current_phase']++;
                    unset($state['current_phase_state']);
                }
            }
        }

        $state['current_phase_state'] = $phase;
        self::saveBattleState($battleId, $state);

        return $result;
    }

    /**
     * Select card for current phase
     */
    public static function selectCardForPhase($db, $battleId, $userId, $userCardId) {
        $state = self::loadBattleState($battleId);

        if (!$state) {
            throw new Exception('Battle not found');
        }

        // Verify card is in user's deck
        $isPlayer1 = ($state['player1_id'] == $userId);

        // Get card details
        $card = $db->query(
            'SELECT
                ct.card_name,
                ct.speed_score,
                ct.attack_score,
                ct.defense_score
            FROM user_cards uc
            JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
            JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
            WHERE uc.user_card_id = ?',
            [$userCardId]
        );

        if (empty($card)) {
            throw new Exception('Card not found');
        }

        // Apply ±5% variance to represent good/bad days for the beast
        $speedVariance = (int)round($card[0]['speed_score'] * (rand(-5, 5) / 100));
        $attackVariance = (int)round($card[0]['attack_score'] * (rand(-5, 5) / 100));
        $defenseVariance = (int)round($card[0]['defense_score'] * (rand(-5, 5) / 100));

        $cardData = [
            'user_card_id' => $userCardId,
            'name' => $card[0]['card_name'],
            'speed' => max(1, (int)$card[0]['speed_score'] + $speedVariance),
            'attack' => max(1, (int)$card[0]['attack_score'] + $attackVariance),
            'defense' => max(1, (int)$card[0]['defense_score'] + $defenseVariance),
            'original_speed' => (int)$card[0]['speed_score'],
            'original_attack' => (int)$card[0]['attack_score'],
            'original_defense' => (int)$card[0]['defense_score']
        ];

        // Initialize phase state
        if (!isset($state['current_phase_state'])) {
            $state['current_phase_state'] = [
                'phase_number' => $state['current_phase'],
                'player1_card' => null,
                'player2_card' => null,
                'attacker' => null
            ];
        }

        if ($isPlayer1) {
            $state['current_phase_state']['player1_card'] = $cardData;
        } else {
            $state['current_phase_state']['player2_card'] = $cardData;
        }

        // If both cards selected, determine initial attacker
        if ($state['current_phase_state']['player1_card'] && $state['current_phase_state']['player2_card']) {
            $p1Attack = $state['current_phase_state']['player1_card']['attack'];
            $p2Attack = $state['current_phase_state']['player2_card']['attack'];

            $state['current_phase_state']['attacker'] = ($p1Attack >= $p2Attack) ? 'player1' : 'player2';
        }

        self::saveBattleState($battleId, $state);

        return [
            'phase' => $state['current_phase'],
            'card_selected' => $cardData['name'],
            'ready_for_battle' => ($state['current_phase_state']['player1_card'] && $state['current_phase_state']['player2_card'])
        ];
    }

    /**
     * Forfeit battle
     */
    public static function forfeitBattle($db, $battleId, $userId) {
        $battle = $db->query(
            'SELECT player1_user_id, player2_user_id FROM battles WHERE battle_id = ?',
            [$battleId]
        );

        if (empty($battle)) {
            throw new Exception('Battle not found');
        }

        $winnerId = ($battle[0]['player1_user_id'] == $userId) ? $battle[0]['player2_user_id'] : $battle[0]['player1_user_id'];

        // Complete battle with forfeit
        $db->callProcedure('sp_complete_battle', [
            ':p_battle_id' => $battleId,
            ':p_winner_user_id' => $winnerId
        ]);

        $db->execute(
            'UPDATE battles SET battle_status = ? WHERE battle_id = ?',
            ['abandoned', $battleId]
        );

        return ['message' => 'Battle forfeited'];
    }

    /**
     * Complete battle
     */
    private static function completeBattle($db, $battleId, $state) {
        $winnerId = null;
        $isDraw = false;

        // Get battle mode
        $battle = $db->query('SELECT battle_mode, player1_user_id, player2_user_id FROM battles WHERE battle_id = ?', [$battleId])[0];

        // Validate score integrity and use null coalescing to ensure scores are never undefined
        $player1Score = $state['player1_score'] ?? 0;
        $player2Score = $state['player2_score'] ?? 0;
        $totalPhases = $player1Score + $player2Score;

        if ($totalPhases !== 5) {
            Logger::warning('Battle score mismatch', [
                'battle_id' => $battleId,
                'player1_score' => $player1Score,
                'player2_score' => $player2Score,
                'total' => $totalPhases,
                'expected' => 5
            ]);
        }

        if ($player1Score > $player2Score) {
            $winnerId = $state['player1_id'];
            $loserId = $state['player2_id'];
        } elseif ($player2Score > $player1Score) {
            $winnerId = $state['player2_id'];
            $loserId = $state['player1_id'];
        } else {
            // This should never happen with 5 phases
            Logger::error('Impossible draw detected', [
                'battle_id' => $battleId,
                'player1_score' => $player1Score,
                'player2_score' => $player2Score
            ]);
            $isDraw = true;
        }

        if ($isDraw) {
            // Update battle status to completed with no winner
            $db->execute(
                'UPDATE battles SET battle_status = ?, completed_at = NOW() WHERE battle_id = ?',
                ['completed', $battleId]
            );

            // Update stats for both players - no wins/losses for draws (pass null)
            self::updateBattleStats($db, $state['player1_id'], null, $battle['battle_mode']);
            self::updateBattleStats($db, $state['player2_id'], null, $battle['battle_mode']);

            Logger::info('Battle completed as draw', ['battle_id' => $battleId, 'scores' => $player1Score . '-' . $player2Score]);
        } else {
            // Complete battle with winner
            $db->callProcedure('sp_complete_battle', [
                ':p_battle_id' => $battleId,
                ':p_winner_user_id' => $winnerId
            ]);

            // Update battle stats for both players
            self::updateBattleStats($db, $winnerId, true, $battle['battle_mode']);
            self::updateBattleStats($db, $loserId, false, $battle['battle_mode']);

            // Transfer rewards if applicable
            self::transferBattleRewards($db, $battleId, $winnerId, $loserId, $battle['battle_mode']);

            Logger::info('Battle completed', ['battle_id' => $battleId, 'winner_id' => $winnerId, 'scores' => $player1Score . '-' . $player2Score]);
        }

        return $winnerId;
    }

    /**
     * Log phase result to battle history
     */
    private static function logPhaseResult($db, $battleId, $state, $phase) {
        $battle = $db->query('SELECT battle_mode FROM battles WHERE battle_id = ?', [$battleId])[0];

        $winnerCard = ($phase['attacker'] === 'player1') ? $phase['player1_card'] : $phase['player2_card'];
        $loserCard = ($phase['attacker'] === 'player1') ? $phase['player2_card'] : $phase['player1_card'];

        $db->callProcedure('sp_log_battle_phase', [
            ':p_battle_id' => $battleId,
            ':p_battle_mode' => $battle['battle_mode'],
            ':p_phase_number' => $state['current_phase'],
            ':p_winner_card_id' => $winnerCard['user_card_id'],
            ':p_winner_start_speed' => $winnerCard['original_speed'],
            ':p_winner_start_attack' => $winnerCard['original_attack'],
            ':p_winner_start_defense' => $winnerCard['original_defense'],
            ':p_winner_end_speed' => $winnerCard['speed'],
            ':p_winner_end_attack' => $winnerCard['attack'],
            ':p_winner_end_defense' => $winnerCard['defense'],
            ':p_loser_card_id' => $loserCard['user_card_id'],
            ':p_loser_start_speed' => $loserCard['original_speed'],
            ':p_loser_start_attack' => $loserCard['original_attack'],
            ':p_loser_start_defense' => $loserCard['original_defense'],
            ':p_loser_end_speed' => $loserCard['speed'],
            ':p_loser_end_attack' => $loserCard['attack'],
            ':p_loser_end_defense' => $loserCard['defense']
        ]);
    }

    /**
     * Save battle state (temporary file-based storage)
     */
    private static function saveBattleState($battleId, $state) {
        $stateDir = __DIR__ . '/../storage/battle_states';
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0755, true);
        }

        $file = $stateDir . '/battle_' . $battleId . '.json';
        file_put_contents($file, json_encode($state));
    }

    /**
     * Load battle state
     */
    private static function loadBattleState($battleId) {
        $file = __DIR__ . '/../storage/battle_states/battle_' . $battleId . '.json';

        if (!file_exists($file)) {
            return null;
        }

        return json_decode(file_get_contents($file), true);
    }

    /**
     * Accept a battle invitation
     */
    public static function acceptBattleInvitation($db, $battleId, $userId, $deckId) {
        // Get battle details
        $battle = $db->query(
            'SELECT * FROM battles WHERE battle_id = ? AND player2_user_id = ? AND battle_status = ?',
            [$battleId, $userId, self::STATUS_PENDING]
        );

        if (empty($battle)) {
            Logger::warning('Battle invitation not found or already responded', ['battle_id' => $battleId]);
            return ['success' => false, 'message' => 'Battle invitation not found or already responded'];
        }

        $battle = $battle[0];

        // Check if invitation expired (24 hours)
        if (isset($battle['created_at'])) {
            $createdAt = strtotime($battle['created_at']);
            if (time() - $createdAt > 86400) {
                $db->execute(
                    'UPDATE battles SET battle_status = ? WHERE battle_id = ?',
                    [self::STATUS_EXPIRED, $battleId]
                );
                return ['success' => false, 'message' => 'Battle invitation has expired'];
            }
        }

        // Validate opponent's deck
        $validation = self::validateDeck($db, $userId, $deckId, $battle['battle_mode']);
        if (!$validation['valid']) {
            return ['success' => false, 'message' => $validation['message'], 'issues' => $validation['issues'] ?? []];
        }

        // Update battle to in_progress
        $db->execute(
            'UPDATE battles SET battle_status = ?, player2_deck_id = ?, started_at = NOW() WHERE battle_id = ?',
            [self::STATUS_IN_PROGRESS, $deckId, $battleId]
        );

        // Initialize battle state
        $userCards = $db->query(
            'SELECT uc.user_card_id, ct.speed_score, ct.attack_score, ct.defense_score
             FROM battle_deck_cards bdc
             JOIN user_cards uc ON bdc.user_card_id = uc.user_card_id
             JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
             JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
             WHERE bdc.battle_deck_id = ?
             ORDER BY bdc.card_position',
            [$deckId]
        );

        $battleState = [
            'battle_id' => $battleId,
            'player1_id' => $battle['player1_user_id'],
            'player2_id' => $userId,
            'player1_deck' => $battle['player1_deck_id'] ?? null,
            'player2_deck' => $deckId,
            'current_phase' => 1,
            'player1_score' => 0,
            'player2_score' => 0,
            'player2_cards' => $userCards,
            'phase_history' => []
        ];

        self::saveBattleState($battleId, $battleState);

        // Notify challenger
        $db->execute(
            'INSERT INTO notifications (user_id, notification_type, notification_color, title, message, related_entity_type, related_entity_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $battle['player1_user_id'],
                'battle',
                '#00FF00',
                'Battle Accepted!',
                'Your battle challenge has been accepted. The battle is starting!',
                'battle',
                $battleId
            ]
        );

        Logger::info('Battle invitation accepted', ['battle_id' => $battleId, 'user_id' => $userId]);

        // Log for child accounts
        ChildActivityLogger::logBattle($userId, $battle['player1_user_id'], $battle['battle_mode'], 'accepted');

        return [
            'success' => true,
            'battle_id' => $battleId,
            'message' => 'Battle started! Select your card for Phase 1.'
        ];
    }

    /**
     * Decline a battle invitation
     */
    public static function declineBattleInvitation($db, $battleId, $userId) {
        $battle = $db->query(
            'SELECT * FROM battles WHERE battle_id = ? AND player2_user_id = ? AND battle_status = ?',
            [$battleId, $userId, self::STATUS_PENDING]
        );

        if (empty($battle)) {
            return ['success' => false, 'message' => 'Battle invitation not found'];
        }

        $battle = $battle[0];

        // Update battle status
        $db->execute(
            'UPDATE battles SET battle_status = ? WHERE battle_id = ?',
            [self::STATUS_EXPIRED, $battleId]
        );

        // Get decliner's username
        $declinerName = $db->query(
            'SELECT username FROM users WHERE user_id = ?',
            [$userId]
        )[0]['username'] ?? 'Unknown';

        // Notify challenger
        $db->execute(
            'INSERT INTO notifications (user_id, notification_type, notification_color, title, message, related_entity_type, related_entity_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $battle['player1_user_id'],
                'battle',
                '#FF6600',
                'Battle Declined',
                "$declinerName has declined your battle challenge.",
                'battle',
                $battleId
            ]
        );

        Logger::info('Battle invitation declined', ['battle_id' => $battleId, 'user_id' => $userId]);

        return ['success' => true, 'message' => 'Battle invitation declined'];
    }

    /**
     * Get pending battle invitations for a user
     */
    public static function getPendingInvitations($db, $userId) {
        $invitations = $db->query(
            'SELECT b.battle_id, b.battle_mode, b.created_at,
                    u.username as challenger_username, u.avatar_id as challenger_avatar_id
             FROM battles b
             JOIN users u ON b.player1_user_id = u.user_id
             WHERE b.player2_user_id = ? AND b.battle_status = ?
               AND b.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY b.created_at DESC',
            [$userId, self::STATUS_PENDING]
        );

        return ['success' => true, 'invitations' => $invitations];
    }

    /**
     * Get battle history for a user
     */
    public static function getBattleHistory($db, $userId, $limit = 20, $offset = 0) {
        $battles = $db->query(
            'SELECT b.battle_id, b.battle_mode, b.battle_status, b.winner_user_id,
                    b.started_at, b.completed_at, b.is_ai_battle,
                    u1.username as player1_username,
                    u2.username as player2_username,
                    CASE
                        WHEN b.player1_user_id = ? THEN "player1"
                        ELSE "player2"
                    END as your_role
             FROM battles b
             JOIN users u1 ON b.player1_user_id = u1.user_id
             JOIN users u2 ON b.player2_user_id = u2.user_id
             WHERE (b.player1_user_id = ? OR b.player2_user_id = ?)
               AND b.battle_status IN (?, ?, ?)
             ORDER BY COALESCE(b.completed_at, b.started_at) DESC
             LIMIT ? OFFSET ?',
            [$userId, $userId, $userId, self::STATUS_COMPLETED, self::STATUS_ABANDONED, self::STATUS_EXPIRED,
             $limit, $offset]
        );

        // Add win/loss status for each battle
        foreach ($battles as &$battle) {
            if ($battle['winner_user_id'] == $userId) {
                $battle['result'] = 'win';
            } elseif ($battle['winner_user_id'] === null) {
                $battle['result'] = 'draw';
            } else {
                $battle['result'] = 'loss';
            }
        }

        return ['success' => true, 'battles' => $battles];
    }

    /**
     * Get leaderboard
     */
    public static function getLeaderboard($db, $type = 'overall', $limit = 100) {
        $orderBy = 'wins DESC, win_rate DESC';
        $where = 'total_battles > 0';

        switch ($type) {
            case 'competitive':
                $where .= ' AND competitive_battles > 0';
                break;
            case 'ultimate':
                $where .= ' AND ultimate_battles > 0';
                break;
            case 'win_rate':
                $where .= ' AND total_battles >= 10'; // Minimum 10 battles for win rate ranking
                $orderBy = 'win_rate DESC, wins DESC';
                break;
        }

        $leaders = $db->query(
            "SELECT u.user_id, u.username, u.avatar_id,
                    ubs.total_battles, ubs.wins, ubs.losses, ubs.win_rate,
                    ubs.friendly_battles, ubs.competitive_battles, ubs.ultimate_battles
             FROM user_battle_stats ubs
             JOIN users u ON ubs.user_id = u.user_id
             WHERE $where AND u.username NOT LIKE 'AI_%' AND u.username != 'GeoBeasts_AI'
             ORDER BY $orderBy
             LIMIT ?",
            [$limit]
        );

        // Add rank
        $rank = 1;
        foreach ($leaders as &$leader) {
            $leader['rank'] = $rank++;
        }

        return ['success' => true, 'leaderboard' => $leaders, 'type' => $type];
    }

    /**
     * Get type advantage bonus multiplier
     */
    public static function getTypeAdvantageBonus($attackerTypeId, $defenderTypeId) {
        if (isset(self::$typeAdvantages[$attackerTypeId]) &&
            in_array($defenderTypeId, self::$typeAdvantages[$attackerTypeId])) {
            return 1.5; // 50% bonus damage
        }
        return 1.0;
    }

    /**
     * Check if user has type advantage
     */
    public static function hasTypeAdvantage($attackerTypeId, $defenderTypeId) {
        return isset(self::$typeAdvantages[$attackerTypeId]) &&
               in_array($defenderTypeId, self::$typeAdvantages[$attackerTypeId]);
    }

    /**
     * Generate AI card selection with basic strategy
     */
    public static function selectAICard($db, $battleId, $phaseNumber, $opponentCard = null) {
        $state = self::loadBattleState($battleId);
        if (!$state) {
            return null;
        }

        // Get AI's available cards (not yet used)
        $usedCards = $state['ai_used_cards'] ?? [];

        $aiCards = $db->query(
            'SELECT uc.user_card_id, ct.card_name, ct.speed_score, ct.attack_score, ct.defense_score, ct.card_type_id
             FROM battle_deck_cards bdc
             JOIN user_cards uc ON bdc.user_card_id = uc.user_card_id
             JOIN published_cards pc ON uc.published_card_id = pc.published_card_id
             JOIN card_templates ct ON pc.card_template_id = ct.card_template_id
             WHERE bdc.battle_deck_id = ?
             AND uc.user_card_id NOT IN (' . (empty($usedCards) ? '0' : implode(',', $usedCards)) . ')
             ORDER BY bdc.card_position',
            [$state['player2_deck'] ?? $state['ai_deck_id']]
        );

        if (empty($aiCards)) {
            return null;
        }

        // Simple AI strategy
        $selectedCard = null;

        if ($opponentCard && isset($opponentCard['card_type_id'])) {
            // Try to find a card with type advantage
            foreach ($aiCards as $card) {
                if (self::hasTypeAdvantage($card['card_type_id'], $opponentCard['card_type_id'])) {
                    $selectedCard = $card;
                    break;
                }
            }
        }

        // If no type advantage found, select randomly
        if (!$selectedCard) {
            $selectedCard = $aiCards[array_rand($aiCards)];
        }

        // Track used card
        $state['ai_used_cards'] = $usedCards;
        $state['ai_used_cards'][] = $selectedCard['user_card_id'];
        self::saveBattleState($battleId, $state);

        return $selectedCard;
    }

    /**
     * Update user battle statistics after battle completion
     * @param Database $db Database instance
     * @param int $userId User ID to update stats for
     * @param bool|null $isWinner TRUE for win, FALSE for loss, NULL for draw
     * @param string $battleMode Battle mode
     */
    public static function updateBattleStats($db, $userId, $isWinner, $battleMode) {
        // Check if user has battle stats record
        $existing = $db->query(
            'SELECT user_id FROM user_battle_stats WHERE user_id = ?',
            [$userId]
        );

        if (empty($existing)) {
            $db->execute(
                'INSERT INTO user_battle_stats (user_id) VALUES (?)',
                [$userId]
            );
        }

        $modeField = '';
        switch ($battleMode) {
            case self::MODE_FRIENDLY:
                $modeField = 'friendly_battles';
                break;
            case self::MODE_COMPETITIVE:
                $modeField = 'competitive_battles';
                break;
            case self::MODE_ULTIMATE:
                $modeField = 'ultimate_battles';
                break;
        }

        // Build SQL based on result
        $sql = "UPDATE user_battle_stats SET total_battles = total_battles + 1";

        if ($isWinner === true) {
            // Win
            $sql .= ", wins = wins + 1";
        } elseif ($isWinner === false) {
            // Loss
            $sql .= ", losses = losses + 1";
        }
        // If $isWinner is null, it's a draw - only increment total_battles

        if ($modeField) {
            $sql .= ", $modeField = $modeField + 1";
        }

        $sql .= ", last_battle_at = NOW() WHERE user_id = ?";

        $db->execute($sql, [$userId]);

        // Recalculate win rate
        $db->execute(
            'UPDATE user_battle_stats
             SET win_rate = ROUND((wins / GREATEST(total_battles, 1)) * 100, 2)
             WHERE user_id = ?',
            [$userId]
        );

        Logger::debug('Battle stats updated', ['user_id' => $userId, 'winner' => $isWinner]);
    }

    /**
     * Transfer cards after competitive/ultimate battle
     */
    public static function transferBattleRewards($db, $battleId, $winnerId, $loserId, $battleMode) {
        if ($battleMode === self::MODE_FRIENDLY) {
            return; // No card transfer in friendly mode
        }

        $battle = $db->query(
            'SELECT player1_user_id, player2_user_id, player1_deck_id, player2_deck_id
             FROM battles WHERE battle_id = ?',
            [$battleId]
        )[0] ?? null;

        if (!$battle) {
            Logger::error('Battle not found for reward transfer', ['battle_id' => $battleId]);
            return;
        }

        // Determine loser's deck
        $loserDeckId = ($loserId == $battle['player1_user_id'])
            ? $battle['player1_deck_id']
            : $battle['player2_deck_id'];

        if ($battleMode === self::MODE_COMPETITIVE) {
            // Winner takes one random card from loser's deck
            $randomCard = $db->query(
                'SELECT bdc.user_card_id FROM battle_deck_cards bdc
                 WHERE bdc.battle_deck_id = ?
                 ORDER BY RAND() LIMIT 1',
                [$loserDeckId]
            );

            if (!empty($randomCard)) {
                $db->execute(
                    'UPDATE user_cards SET user_id = ? WHERE user_card_id = ?',
                    [$winnerId, $randomCard[0]['user_card_id']]
                );

                Logger::info('Battle card transferred (competitive)', [
                    'card_id' => $randomCard[0]['user_card_id'],
                    'from_user' => $loserId,
                    'to_user' => $winnerId
                ]);
            }

        } elseif ($battleMode === self::MODE_ULTIMATE) {
            // Winner takes entire loser's deck
            $loserCards = $db->query(
                'SELECT bdc.user_card_id FROM battle_deck_cards bdc WHERE bdc.battle_deck_id = ?',
                [$loserDeckId]
            );

            foreach ($loserCards as $card) {
                $db->execute(
                    'UPDATE user_cards SET user_id = ? WHERE user_card_id = ?',
                    [$winnerId, $card['user_card_id']]
                );
            }

            Logger::info('Battle deck transferred (ultimate)', [
                'deck_id' => $loserDeckId,
                'cards_count' => count($loserCards),
                'from_user' => $loserId,
                'to_user' => $winnerId
            ]);
        }
    }

    /**
     * Check for expired pending battles and clean up
     */
    public static function cleanupExpiredBattles($db) {
        $expired = $db->execute(
            'UPDATE battles
             SET battle_status = ?
             WHERE battle_status = ?
               AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)',
            [self::STATUS_EXPIRED, self::STATUS_PENDING]
        );

        if ($expired > 0) {
            Logger::info('Cleaned up expired battle invitations', ['count' => $expired]);
        }

        return $expired;
    }

    /**
     * Get user's active battles (in progress)
     */
    public static function getActiveBattles($db, $userId) {
        $battles = $db->query(
            'SELECT b.battle_id, b.battle_mode, b.started_at, b.is_ai_battle,
                    u1.username as player1_username,
                    u2.username as player2_username,
                    CASE WHEN b.player1_user_id = ? THEN "player1" ELSE "player2" END as your_role
             FROM battles b
             JOIN users u1 ON b.player1_user_id = u1.user_id
             JOIN users u2 ON b.player2_user_id = u2.user_id
             WHERE (b.player1_user_id = ? OR b.player2_user_id = ?)
               AND b.battle_status = ?
             ORDER BY b.started_at DESC',
            [$userId, $userId, $userId, self::STATUS_IN_PROGRESS]
        );

        return ['success' => true, 'active_battles' => $battles];
    }

    /**
     * Get user battle statistics
     */
    public static function getUserBattleStats($db, $userId) {
        $stats = $db->query(
            'SELECT * FROM user_battle_stats WHERE user_id = ?',
            [$userId]
        );

        if (empty($stats)) {
            return [
                'success' => true,
                'stats' => [
                    'total_battles' => 0,
                    'wins' => 0,
                    'losses' => 0,
                    'win_rate' => 0,
                    'friendly_battles' => 0,
                    'competitive_battles' => 0,
                    'ultimate_battles' => 0
                ]
            ];
        }

        return ['success' => true, 'stats' => $stats[0]];
    }
}
