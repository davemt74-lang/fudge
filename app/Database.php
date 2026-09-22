<?php
final class Database
{
    private PDO $pdo;
    private int $transactionDepth = 0;

    public function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'] ?? 3306,
            $config['database'],
            $config['charset'] ?? 'utf8mb4'
        );
        $this->pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public function pdo(): PDO { return $this->pdo; }

    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function exec(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->exec($sql, $params);
        return (int)$this->pdo->lastInsertId();
    }

    public function transaction(callable $callback): mixed
    {
        $isOuter = $this->transactionDepth === 0;
        $savepoint = 'sp_' . $this->transactionDepth;

        if ($isOuter) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }
        $this->transactionDepth++;

        try {
            $result = $callback($this);
            $this->transactionDepth--;

            if ($isOuter) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return $result;
        } catch (Throwable $e) {
            $this->transactionDepth = max(0, $this->transactionDepth - 1);

            if ($isOuter) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            } else {
                try {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                } catch (Throwable $ignored) {
                }
            }
            throw $e;
        }
    }
}
