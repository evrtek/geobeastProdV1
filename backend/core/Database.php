<?php
/**
 * Database Connection Handler
 * Manages MySQL database connections and stored procedure calls
 */

require_once __DIR__ . '/../config/config.php';

class Database {
    private static $instance = null;
    private $connection = null;

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        $this->connect();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Establish database connection
     */
    private function connect() {
        $dbConfig = Config::getDb();

        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['name'],
                $dbConfig['charset']
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            $this->connection = new PDO(
                $dsn,
                $dbConfig['user'],
                $dbConfig['password'],
                $options
            );

        } catch (PDOException $e) {
            error_log('Database connection error: ' . $e->getMessage());
            if (Config::isDebug()) {
                throw new Exception('Database connection failed: ' . $e->getMessage());
            }
            throw new Exception('Database connection failed');
        }
    }

    /**
     * Get PDO connection
     */
    public function getConnection() {
        if ($this->connection === null) {
            $this->connect();
        }
        return $this->connection;
    }

    /**
     * Call a stored procedure
     *
     * @param string $procedureName Name of the stored procedure
     * @param array $params Array of parameters [':param1' => value1, ':param2' => value2]
     * @param array $outParams Array of output parameter names ['param1', 'param2']
     * @return array Results and output parameters
     */
    public function callProcedure($procedureName, $params = [], $outParams = []) {
        try {
            $conn = $this->getConnection();

            // Build parameter placeholders
            $inPlaceholders = [];
            $outPlaceholders = [];

            foreach ($params as $key => $value) {
                $inPlaceholders[] = $key;
            }

            foreach ($outParams as $param) {
                $outPlaceholders[] = "@$param";
            }

            // Combine all placeholders
            $allPlaceholders = array_merge($inPlaceholders, $outPlaceholders);
            $placeholderString = implode(', ', $allPlaceholders);

            // Prepare CALL statement
            $sql = "CALL $procedureName($placeholderString)";
            $stmt = $conn->prepare($sql);

            // Bind input parameters
            foreach ($params as $key => $value) {
                $type = PDO::PARAM_STR;
                if (is_int($value)) {
                    $type = PDO::PARAM_INT;
                } elseif (is_bool($value)) {
                    $type = PDO::PARAM_BOOL;
                } elseif (is_null($value)) {
                    $type = PDO::PARAM_NULL;
                }
                $stmt->bindValue($key, $value, $type);
            }

            // Execute procedure
            $stmt->execute();

            // Fetch result sets
            $results = [];
            do {
                $rows = $stmt->fetchAll();
                if (!empty($rows)) {
                    $results[] = $rows;
                }
            } while ($stmt->nextRowset());

            // Get output parameters
            $outputValues = [];
            if (!empty($outParams)) {
                $outQuery = "SELECT " . implode(', ', array_map(function($p) {
                    return "@$p AS $p";
                }, $outParams));

                $outStmt = $conn->query($outQuery);
                $outputValues = $outStmt->fetch(PDO::FETCH_ASSOC);
            }

            return [
                'results' => $results,
                'output' => $outputValues
            ];

        } catch (PDOException $e) {
            error_log('Stored procedure error: ' . $e->getMessage());
            if (Config::isDebug()) {
                throw new Exception('Stored procedure error (' . $procedureName . '): ' . $e->getMessage());
            }
            throw new Exception('Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Execute a query and return results
     */
    public function query($sql, $params = []) {
        try {
            $conn = $this->getConnection();
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('Query error: ' . $e->getMessage());
            if (Config::isDebug()) {
                throw new Exception('Query error: ' . $e->getMessage() . ' | SQL: ' . $sql);
            }
            throw new Exception('Database query failed');
        }
    }

    /**
     * Execute an INSERT/UPDATE/DELETE query
     */
    public function execute($sql, $params = []) {
        try {
            $conn = $this->getConnection();
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return [
                'affected_rows' => $stmt->rowCount(),
                'last_insert_id' => $conn->lastInsertId()
            ];
        } catch (PDOException $e) {
            error_log('Execute error: ' . $e->getMessage());
            if (Config::isDebug()) {
                throw new Exception('Execute error: ' . $e->getMessage() . ' | SQL: ' . $sql);
            }
            throw new Exception('Database operation failed');
        }
    }

    /**
     * Insert a record into a table
     *
     * @param string $table Table name
     * @param array $data Associative array of column => value
     * @return int Last insert ID
     */
    public function insert($table, $data) {
        try {
            $columns = array_keys($data);
            $placeholders = array_fill(0, count($columns), '?');

            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $table,
                implode(', ', $columns),
                implode(', ', $placeholders)
            );

            $conn = $this->getConnection();
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_values($data));

            return $conn->lastInsertId();
        } catch (PDOException $e) {
            error_log('Insert error: ' . $e->getMessage());
            if (Config::isDebug()) {
                throw new Exception('Insert error in table ' . $table . ': ' . $e->getMessage());
            }
            throw new Exception('Database insert failed');
        }
    }

    /**
     * Update records in a table
     *
     * @param string $table Table name
     * @param array $data Associative array of column => value to update
     * @param array $where Associative array of column => value for WHERE clause
     * @return int Number of affected rows
     */
    public function update($table, $data, $where) {
        try {
            $setClauses = [];
            foreach (array_keys($data) as $column) {
                $setClauses[] = "$column = ?";
            }

            $whereClauses = [];
            foreach (array_keys($where) as $column) {
                $whereClauses[] = "$column = ?";
            }

            $sql = sprintf(
                'UPDATE %s SET %s WHERE %s',
                $table,
                implode(', ', $setClauses),
                implode(' AND ', $whereClauses)
            );

            $params = array_merge(array_values($data), array_values($where));

            $conn = $this->getConnection();
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('Update error: ' . $e->getMessage());
            if (Config::isDebug()) {
                throw new Exception('Update error in table ' . $table . ': ' . $e->getMessage());
            }
            throw new Exception('Database update failed');
        }
    }

    /**
     * Delete records from a table
     *
     * @param string $table Table name
     * @param array $where Associative array of column => value for WHERE clause
     * @return int Number of affected rows
     */
    public function delete($table, $where) {
        try {
            $whereClauses = [];
            foreach (array_keys($where) as $column) {
                $whereClauses[] = "$column = ?";
            }

            $sql = sprintf(
                'DELETE FROM %s WHERE %s',
                $table,
                implode(' AND ', $whereClauses)
            );

            $conn = $this->getConnection();
            $stmt = $conn->prepare($sql);
            $stmt->execute(array_values($where));

            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('Delete error: ' . $e->getMessage());
            if (Config::isDebug()) {
                throw new Exception('Delete error in table ' . $table . ': ' . $e->getMessage());
            }
            throw new Exception('Database delete failed');
        }
    }

    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->getConnection()->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit() {
        return $this->getConnection()->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->getConnection()->rollBack();
    }

    /**
     * Sanitize input for security
     */
    public static function sanitize($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitize'], $input);
        }

        $input = trim($input);
        $input = stripslashes($input);
        $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');

        return $input;
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
