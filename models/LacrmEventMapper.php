<?php

declare(strict_types=1);

namespace Osmium\Services\Lacrm\Models;

/**
 * Turns a LACRM calendar event (API response or webhook payload) into the
 * columns of lacrm_events.
 */
class LacrmEventMapper
{
    /**
     * @param array<string, mixed>      $event      LACRM event object
     * @param array<string, mixed>|null $rawPayload Full payload kept for debugging (defaults to the event)
     * @return array<string, mixed>
     */
    public static function map(array $event, ?array $rawPayload = null): array
    {
        $start = self::formatDateTime(dateString: (string) ($event['StartDate'] ?? ''));
        $end = self::formatDateTime(dateString: (string) ($event['EndDate'] ?? ''));

        $noEnd = ($event['EndDate'] ?? '') === '';
        if ($noEnd) $end = $start;

        $userIds = self::asList($event['UserIds'] ?? $event['AssignedUserIds'] ?? []);
        $contactIds = self::asList($event['ContactIds'] ?? $event['LinkedContactIds'] ?? []);
        $attendees = $event['Attendees'] ?? [];
        $recurrenceRule = $event['RecurrenceRule'] ?? null;

        return [
            'lacrm_event_id' => (string) ($event['EventId'] ?? ''),
            'name' => \trim((string) ($event['Name'] ?? 'Untitled Event')),
            'description' => $event['Description'] ?? null,
            'location' => $event['Location'] ?? null,
            'start_date' => $start,
            'end_date' => $end,
            'is_all_day' => (int) ($event['IsAllDay'] ?? false),
            'is_recurring' => (int) !empty($recurrenceRule),
            'recurrence_rule' => $recurrenceRule,
            'recurrence_parent_id' => $event['RecurrenceEventId'] ?? null,
            'series_number' => isset($event['SeriesNumber']) ? (int) $event['SeriesNumber'] : null,
            'lacrm_user_ids' => $userIds === [] ? null : $userIds,
            'lacrm_contact_ids' => $contactIds === [] ? null : $contactIds,
            'attendees' => empty($attendees) ? null : $attendees,
            'raw_payload' => $rawPayload ?? $event,
        ];
    }

    /** @return array<int, mixed> */
    private static function asList(mixed $value): array
    {
        $empty = empty($value);
        if ($empty) return [];

        return \is_array($value) ? $value : [$value];
    }

    /** ISO 8601 to a MySQL datetime in the site's timezone; now if blank or unreadable. */
    public static function formatDateTime(string $dateString): string
    {
        $blank = $dateString === '';
        if ($blank) return \date('Y-m-d H:i:s');

        try {
            $when = new \DateTime($dateString);
        } catch (\Exception $e) {
            \error_log("LacrmEventMapper: cannot read date '{$dateString}': {$e->getMessage()}");
            return \date('Y-m-d H:i:s');
        }

        $when->setTimezone(new \DateTimeZone(\date_default_timezone_get()));

        return $when->format('Y-m-d H:i:s');
    }
}
