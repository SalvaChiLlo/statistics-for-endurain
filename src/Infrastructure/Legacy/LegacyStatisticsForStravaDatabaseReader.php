<?php

declare(strict_types=1);

namespace App\Infrastructure\Legacy;

/**
 * Read-only access to an existing statistics-for-strava SQLite database, used
 * by the one-time "migrate from statistics-for-strava" command.
 *
 * This deliberately does NOT go through Doctrine/DBAL: the app's single DBAL
 * connection is wired (via DATABASE_DIRECTORY) to this app's own database, and
 * we don't want to touch that wiring just to read a second, unrelated SQLite
 * file. A separate, plain PDO connection opened in SQLite's read-only URI mode
 * keeps the source file connection fully isolated from the app's own
 * connection/schema and guarantees we can never accidentally write to it.
 */
final class LegacyStatisticsForStravaDatabaseReader
{
    private ?\PDO $connection = null;

    public function __construct(
        private readonly string $databaseFilePath,
    ) {
    }

    public function assertIsReadable(): void
    {
        if (!is_file($this->databaseFilePath)) {
            throw new \RuntimeException(sprintf('Source database file "%s" does not exist', $this->databaseFilePath));
        }

        if (!is_readable($this->databaseFilePath)) {
            throw new \RuntimeException(sprintf('Source database file "%s" is not readable', $this->databaseFilePath));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchGear(): array
    {
        return $this->fetchAll('Gear');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchActivities(): array
    {
        return $this->fetchAll('Activity');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchActivityStreams(): array
    {
        return $this->fetchAll('ActivityStream');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAll(string $table): array
    {
        $connection = $this->connect();

        if (!$this->tableExists($connection, $table)) {
            return [];
        }

        $statement = $connection->query(sprintf('SELECT * FROM "%s"', $table));
        if (false === $statement) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    private function tableExists(\PDO $connection, string $table): bool
    {
        $statement = $connection->prepare('SELECT name FROM sqlite_master WHERE type = \'table\' AND name = :name');
        $statement->execute(['name' => $table]);

        return false !== $statement->fetchColumn();
    }

    private function connect(): \PDO
    {
        if ($this->connection instanceof \PDO) {
            return $this->connection;
        }

        $this->assertIsReadable();

        $realPath = realpath($this->databaseFilePath);
        if (false === $realPath) {
            throw new \RuntimeException(sprintf('Could not resolve source database file "%s"', $this->databaseFilePath));
        }

        // "mode=ro" opens the SQLite file in read-only mode at the driver
        // level: any write attempt (accidental or otherwise) will fail
        // instead of touching the source file.
        $connection = new \PDO(sprintf('sqlite:file:%s?mode=ro', $realPath), options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $connection->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        return $this->connection = $connection;
    }
}
