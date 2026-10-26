<?php

namespace AgilePixel\AllsWell\Laravel\State;

use AgilePixel\AllsWell\State\State;
use AgilePixel\AllsWell\State\StateStore;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Keeps the state as JSON in the single row of the alls_well_state table.
 */
final class DatabaseStateStore implements StateStore
{
    const TABLE = 'alls_well_state';

    const UNREADABLE = "The database couldn't be reached, so the saved state can't be read. "
        .'If this app has no database, set ALLS_WELL_STORE=cache.';

    const NO_TABLE = "The alls_well_state table doesn't exist yet. Run php artisan migrate.";

    const READY = 'ready';

    const MISSING = 'missing';

    const UNREACHABLE = 'unreachable';

    /** @var ConnectionResolverInterface */
    private $db;

    /** @var string|null */
    private $connection;

    /** @var string|null */
    private $table;

    /**
     * @param  string|null  $connection
     */
    public function __construct(ConnectionResolverInterface $db, $connection = null)
    {
        $this->db = $db;
        $this->connection = $connection;
    }

    public function load()
    {
        if ($this->tableState() !== self::READY) {
            return State::empty();
        }

        try {
            $row = $this->db->connection($this->connection)->table(self::TABLE)->where('id', 1)->first();
            $json = is_object($row) && isset($row->state) ? $row->state : null;
        } catch (\Throwable $e) {
            $this->table = self::UNREACHABLE;

            return State::empty();
        }

        $data = is_string($json) ? json_decode($json, true) : null;

        return is_array($data) ? State::fromArray($data) : State::empty();
    }

    public function save(State $state)
    {
        $json = json_encode($state->toArray());
        if (! is_string($json) || $this->tableState() !== self::READY) {
            throw new \RuntimeException('The state could not be saved.');
        }

        $failed = false;

        try {
            $query = $this->db->connection($this->connection)->table(self::TABLE);
            $now = date('Y-m-d H:i:s');

            $changed = $query->where('id', 1)->update(['state' => $json, 'updated_at' => $now]);

            if ($changed === 0 && ! $this->db->connection($this->connection)->table(self::TABLE)->where('id', 1)->exists()) {
                $this->db->connection($this->connection)->table(self::TABLE)->insert([
                    'id' => 1, 'state' => $json, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            $failed = true;
        }

        if ($failed) {
            throw new \RuntimeException('The state could not be saved.');
        }
    }

    public function clear()
    {
        try {
            $this->db->connection($this->connection)->table(self::TABLE)->where('id', 1)->delete();
        } catch (\Throwable $e) {
            return;
        }
    }

    public function name()
    {
        return 'the alls_well_state table';
    }

    public function isPersistent()
    {
        return true;
    }

    public function problem()
    {
        switch ($this->tableState()) {
            case self::UNREACHABLE:
                return self::UNREADABLE;
            case self::MISSING:
                return self::NO_TABLE;
            default:
                return null;
        }
    }

    public function isUnreadable()
    {
        return $this->tableState() === self::UNREACHABLE;
    }

    /**
     * A failure of the table check itself means the database is unreachable;
     * a check that returns false means the table is missing. Checked once.
     *
     * @return string
     */
    private function tableState()
    {
        if ($this->table === null) {
            try {
                $connection = $this->db->connection($this->connection);
                if ($connection instanceof Connection) {
                    $exists = $connection->getSchemaBuilder()->hasTable(self::TABLE);
                    $this->table = $exists ? self::READY : self::MISSING;
                } else {
                    $this->table = self::UNREACHABLE;
                }
            } catch (\Throwable $e) {
                $this->table = self::UNREACHABLE;
            }
        }

        return $this->table;
    }
}
