<?php

declare(strict_types=1);

/**
 * The calendar events list, rendered by Osmium\Modules\Admin\Core\ListView. Row fields are prepared by
 * LacrmEventsController::formatEvent().
 */
return [
    'events' => [
        'id' => 'lacrm-events',
        'title' => 'Calendar events',
        'noun' => ['event', 'events'],
        'endpoint' => 'lacrm/events/action/',
        'emptyText' => 'No events in this date range.',
        'order' => [1, 'asc'],
        'columns' => [
            ['key' => 'name', 'label' => 'Event', 'type' => 'text', 'strong' => true, 'sub' => ['key' => 'recurringLabel', 'style' => 'muted']],
            ['key' => 'time', 'label' => 'Date / time', 'type' => 'text', 'small' => true, 'nowrap' => true, 'sortKey' => 'start_date'],
            ['key' => 'location', 'label' => 'Location', 'type' => 'text', 'small' => true, 'fallback' => '-'],
            [
                'key' => 'linkedHtml',
                'label' => 'Customers',
                'type' => 'html',
                'small' => true,
                'fill' => true,
                'render' => static fn(array $row, array $context): string => (string) $row['linkedHtml'],
            ],
            ['key' => 'syncedAt', 'label' => 'Synced', 'type' => 'text', 'small' => true, 'nowrap' => true, 'sortKey' => 'synced_at'],
        ],
        'actions' => [
            ['label' => 'View', 'icon' => 'bx-show', 'event' => 'view'],
        ],
    ],
];
