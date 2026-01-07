<?php
// Test file to isolate battles.php issue
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "Test 1: Basic PHP works\n";

try {
    require_once __DIR__ . '/../core/Database.php';
    echo "Test 2: Database.php loaded\n";
} catch (Exception $e) {
    die("Database.php error: " . $e->getMessage());
}

try {
    require_once __DIR__ . '/../core/Security.php';
    echo "Test 3: Security.php loaded\n";
} catch (Exception $e) {
    die("Security.php error: " . $e->getMessage());
}

try {
    require_once __DIR__ . '/../core/ApiResponse.php';
    echo "Test 4: ApiResponse.php loaded\n";
} catch (Exception $e) {
    die("ApiResponse.php error: " . $e->getMessage());
}

try {
    require_once __DIR__ . '/../core/Logger.php';
    echo "Test 5: Logger.php loaded\n";
} catch (Exception $e) {
    die("Logger.php error: " . $e->getMessage());
}

try {
    require_once __DIR__ . '/../core/ChildActivityLogger.php';
    echo "Test 6: ChildActivityLogger.php loaded\n";
} catch (Exception $e) {
    die("ChildActivityLogger.php error: " . $e->getMessage());
}

try {
    require_once __DIR__ . '/../core/BattleEngine.php';
    echo "Test 7: BattleEngine.php loaded\n";
} catch (Exception $e) {
    die("BattleEngine.php error: " . $e->getMessage());
}

echo "\nAll files loaded successfully!";
