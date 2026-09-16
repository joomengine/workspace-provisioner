<?php

declare(strict_types=1);

namespace JoomEngine\Workspace;

/** Transactional state journal. One executor, concurrent submitters, no external queue dependency. */
final class PgStore implements Store
{
    public function __construct(private readonly \PDO $pdo)
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new Fault('invalid_store', 'PostgreSQL is required for this adapter.');
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function migrate(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('SELECT pg_advisory_xact_lock(178279281, 0)');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS wp_state (
                id SMALLINT PRIMARY KEY CHECK (id = 1),
                schema_version INTEGER NOT NULL CHECK (schema_version = 1),
                revision BIGINT NOT NULL DEFAULT 0,
                document JSONB NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
            )');
            $statement = $this->pdo->prepare('INSERT INTO wp_state(id, schema_version, document)
                VALUES (1, 1, CAST(:document AS JSONB)) ON CONFLICT (id) DO NOTHING');
            $statement->execute(['document' => Json::encode(FileStore::emptyState())]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $row = $this->pdo->query('SELECT schema_version, document FROM wp_state WHERE id = 1 FOR UPDATE')->fetch(\PDO::FETCH_ASSOC);
            if (!$row || (int) $row['schema_version'] !== 1) {
                throw new Fault('invalid_state', 'Initialize a compatible operation store first.');
            }
            $state = Json::decode($row['document']);
            $result = $callback($state);
            $statement = $this->pdo->prepare('UPDATE wp_state SET document = CAST(:document AS JSONB),
                revision = revision + 1, updated_at = clock_timestamp() WHERE id = 1');
            $statement->execute(['document' => Json::encode($state)]);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    public function exclusive(callable $callback): mixed
    {
        $locked = $this->pdo->query('SELECT pg_try_advisory_lock(178279281, 1)')->fetchColumn();
        if (!in_array($locked, [true, 1, '1', 't'], true)) {
            throw new Fault('busy', 'Another executor owns this operation store.');
        }
        try {
            return $callback();
        } finally {
            $this->pdo->query('SELECT pg_advisory_unlock(178279281, 1)');
        }
    }
}
