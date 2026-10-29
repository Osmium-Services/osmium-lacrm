<?php

declare(strict_types=1);

namespace Osmium\Services\Lacrm\Models;

/**
 * Pulls calendar events from LACRM into the local copy: scan a date range for the ones not held yet, import a
 * range, or import one. Each saved event is announced as lacrm.event.saved, exactly as the webhook does. Everything
 * outside is passed in, so this can be tested without LACRM or a database.
 */
final class LacrmEventImport
{
    /**
     * @param callable(string, string): array $fetchRange LACRM events between two YYYY-MM-DD days
     * @param callable(string): array $fetchOne One LACRM event by id
     * @param callable(): array<string, true> $knownIds The event ids already held (a set)
     * @param callable(array): array{id: int, created: bool} $save Stores a mapped event
     * @param callable(string, array): void $announce Fires a hook
     */
    public function __construct(
        private $fetchRange,
        private $fetchOne,
        private $knownIds,
        private $save,
        private $announce,
    ) {
    }

    /** @return array<int, array{lacrm_event_id: string, name: string, time: string}> Events in LACRM that are not held yet */
    public function scan(string $startDay, string $endDay): array
    {
        $known = ($this->knownIds)();

        $new = [];
        foreach (($this->fetchRange)($startDay, $endDay) as $event) {
            $id = (string) ($event['EventId'] ?? '');
            $usable = $id !== '' && !isset($known[$id]);
            if (!$usable) continue;

            $row = LacrmEventMapper::map(event: $event);
            $new[] = [
                'lacrm_event_id' => $id,
                'name' => $row['name'],
                'time' => LacrmEventDisplay::time(startDate: $row['start_date'], endDate: $row['end_date'], isAllDay: (bool) $row['is_all_day']),
            ];
        }

        return $new;
    }

    /** @return array{fetched: int, created: int, updated: int, skipped: int, errors: int} */
    public function importRange(string $startDay, string $endDay): array
    {
        $events = ($this->fetchRange)($startDay, $endDay);

        $result = ['fetched' => \count($events), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        foreach ($events as $event) {
            $outcome = $this->store($event);
            $result[$outcome]++;
        }

        return $result;
    }

    /** @return string created, updated, skipped or errors, for the one event */
    public function importOne(string $eventId): string
    {
        $noId = \trim($eventId) === '';
        if ($noId) return 'skipped';

        $event = ($this->fetchOne)($eventId);
        $event['EventId'] ??= $eventId;

        return $this->store($event);
    }

    // Inside

    /** @return 'created'|'updated'|'skipped'|'errors' */
    private function store(array $event): string
    {
        $noId = (string) ($event['EventId'] ?? '') === '';
        if ($noId) return 'skipped';

        try {
            $row = LacrmEventMapper::map(event: $event);
            $saved = ($this->save)($row);
            ($this->announce)('lacrm.event.saved', ['id' => $saved['id'], 'created' => $saved['created'], 'event' => $row]);
        } catch (\Throwable $e) {
            \error_log('LACRM event import: ' . $e->getMessage());

            return 'errors';
        }

        return $saved['created'] ? 'created' : 'updated';
    }
}
