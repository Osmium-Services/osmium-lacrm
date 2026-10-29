<?php

declare(strict_types=1);

namespace Osmium\Services\Lacrm\Models;

/**
 * The dashboard card: calendar events in the next seven days, the first eight.
 */
final class LacrmDashboard
{
    private const DAYS = 7;
    private const SHOWN = 8;

    /**
     * @param array{osmium: object, role?: string} $payload
     * @return array<string, mixed>
     */
    public static function widget(array $payload): array
    {
        $today = new \DateTimeImmutable('today');
        $events = (new LacrmEvent($payload['osmium']->dataSource))->between(
            startDay: $today->format('Y-m-d'),
            endDay: $today->modify('+' . self::DAYS . ' days')->format('Y-m-d'),
        );

        $lines = [];
        foreach (\array_slice($events, 0, self::SHOWN) as $event) {
            $lines[] = [
                'text' => (string) $event['name'],
                'sub' => LacrmEventDisplay::time(startDate: $event['start_date'], endDate: $event['end_date'], isAllDay: (bool) $event['is_all_day']),
                'route' => 'lacrm/events',
            ];
        }

        return [
            'id' => 'osmium-lacrm-events',
            'title' => 'Upcoming calendar events',
            'icon' => 'bx-calendar',
            'roles' => ['admin'],
            'order' => 30,
            'count' => \count($events),
            'link' => ['label' => 'Open calendar events', 'route' => 'lacrm/events'],
            'lines' => $lines,
            'emptyText' => 'Nothing in the next ' . self::DAYS . ' days.',
        ];
    }
}
