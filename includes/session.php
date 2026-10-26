<?php
declare(strict_types=1);

const SESSIONS_TABLE_SQL = "CREATE TABLE IF NOT EXISTS sessions (
  id VARCHAR(128) NOT NULL PRIMARY KEY,
  data MEDIUMBLOB NOT NULL,
  updated_at INT UNSIGNED NOT NULL,
  INDEX (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

/**
 * Stores PHP sessions in MySQL so a login survives across serverless
 * instances. Creates its own table on first use, before /install.php runs.
 */
final class DbSessionHandler implements SessionHandlerInterface
{
    private function run(string $sql, array $params = []): PDOStatement
    {
        try {
            return q($sql, $params);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) !== 1146) {    // 1146 = table doesn't exist
                throw $e;
            }
            db()->exec(SESSIONS_TABLE_SQL);
            return q($sql, $params);
        }
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $data = $this->run('SELECT data FROM sessions WHERE id = ?', [$id])->fetchColumn();
        return $data === false ? '' : (string)$data;
    }

    public function write(string $id, string $data): bool
    {
        $this->run('REPLACE INTO sessions (id, data, updated_at) VALUES (?, ?, ?)', [$id, $data, time()]);
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->run('DELETE FROM sessions WHERE id = ?', [$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->run('DELETE FROM sessions WHERE updated_at < ?', [time() - $max_lifetime])->rowCount();
    }
}
