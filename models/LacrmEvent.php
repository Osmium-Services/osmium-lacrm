<?php

declare(strict_types=1);

namespace Osmium\Services\Lacrm\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;

/**
 * The local copy of LACRM calendar events, kept up to date by the webhook.
 */
class LacrmEvent extends Model
{
    protected ?string $sqlDir = __DIR__ . '/sql';

    private const JSON_COLUMNS = ['lacrm_user_ids', 'lacrm_contact_ids', 'attendees', 'raw_payload'];

    public function __construct(OsmiumPDO $database)
    {
        parent::__construct(database: $database, tableName: 'lacrm_events');
    }

    /** @return array<string, mixed>|null */
    public function find(string $lacrmEventId): ?array
    {
        $sql = $this->loadSqlFile('event-find.sql');
        $row = $this->database->prepare(sql: $sql, bindings: [':lacrm_event_id' => $lacrmEventId])->single();

        return $row ?: null;
    }

    /**
     * Inserts the event, or updates it (and brings it back if it was deleted).
     *
     * @param array<string, mixed> $data As LacrmEventMapper::map() gives it
     * @return array{id: int, created: bool}
     */
    public function save(array $data): array
    {
        $existing = $this->find(lacrmEventId: (string) $data['lacrm_event_id']);
        $isNew = $existing === null;

        $bindings = $this->bindings(data: $data);

        if ($isNew) {
            $this->database->prepare(sql: $this->loadSqlFile('event-insert.sql'), bindings: $bindings)->execute();
            $id = (int) $this->database->lastInsertId();

            return ['id' => $id, 'created' => true];
        }

        unset($bindings[':lacrm_event_id']);
        $bindings[':id'] = (int) $existing['id'];
        $this->database->prepare(sql: $this->loadSqlFile('event-update.sql'), bindings: $bindings)->execute();

        return ['id' => (int) $existing['id'], 'created' => false];
    }

    /** @return bool true if an event was marked deleted */
    public function softDelete(string $lacrmEventId): bool
    {
        $statement = $this->database->prepare(
            sql: $this->loadSqlFile('event-soft-delete.sql'),
            bindings: [':lacrm_event_id' => $lacrmEventId],
        );
        $statement->execute();

        return $statement->rowCount() > 0;
    }

    /** @return array<int, array<string, mixed>> Events that have not ended, soonest first */
    public function upcoming(int $limit = 50): array
    {
        $sql = $this->loadSqlFile('event-list-upcoming.sql');

        return $this->database->prepare(sql: $sql, bindings: [':row_limit' => $limit])->resultset();
    }

    /**
     * Events that overlap the two days (YYYY-MM-DD, both included), soonest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function between(string $startDay, string $endDay): array
    {
        $bindings = [':range_start' => $startDay . ' 00:00:00', ':range_end' => $endDay . ' 23:59:59'];

        return $this->database->prepare(sql: $this->loadSqlFile('event-list-between.sql'), bindings: $bindings)->resultset();
    }

    /** @return array<string, true> LACRM event ids already held (a set, keyed by id) */
    public function knownIds(): array
    {
        $rows = $this->database->prepare(sql: $this->loadSqlFile('event-known-ids.sql'), bindings: [])->resultset();

        $known = [];
        foreach ($rows as $row) {
            $known[(string) $row['lacrm_event_id']] = true;
        }

        return $known;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function bindings(array $data): array
    {
        $columns = [
            'lacrm_event_id', 'name', 'description', 'location', 'start_date', 'end_date',
            'is_all_day', 'is_recurring', 'recurrence_rule', 'recurrence_parent_id', 'series_number',
            'lacrm_user_ids', 'lacrm_contact_ids', 'attendees', 'raw_payload',
        ];

        $bindings = [];
        foreach ($columns as $column) {
            $value = $data[$column] ?? null;
            $isJson = \in_array($column, self::JSON_COLUMNS, true);
            $bindings[':' . $column] = ($isJson && $value !== null) ? \json_encode($value) : $value;
        }

        return $bindings;
    }
}
