<?php

declare(strict_types=1);

namespace Osmium\Services\Lacrm\Models;

/**
 * How a stored LACRM event reads in the admin: the time line and a short description. Pure; values are not escaped
 * (the view does that).
 */
final class LacrmEventDisplay
{
    public const DESCRIPTION_LENGTH = 60;

    /** "12 Mar 2026, 09:00 - 10:30", "12 Mar - 14 Mar 2026 (All day)" and so on. */
    public static function time(string $startDate, string $endDate, bool $isAllDay): string
    {
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $sameDay = $start->format('Y-m-d') === $end->format('Y-m-d');

        if ($isAllDay && $sameDay) return $start->format('d M Y') . ' (All day)';
        if ($isAllDay) return $start->format('d M') . ' - ' . $end->format('d M Y') . ' (All day)';
        if ($sameDay) return $start->format('d M Y') . ', ' . $start->format('H:i') . ' - ' . $end->format('H:i');

        return $start->format('d M H:i') . ' - ' . $end->format('d M H:i');
    }

    /** @return array{text: string, truncated: bool} */
    public static function description(?string $description): array
    {
        $text = (string) $description;
        $truncated = \mb_strlen($text) > self::DESCRIPTION_LENGTH;

        return ['text' => $truncated ? \mb_substr($text, 0, self::DESCRIPTION_LENGTH) : $text, 'truncated' => $truncated];
    }

    /**
     * A stored row as the contact ids it links to (the column holds JSON).
     *
     * @param array<string, mixed> $row
     * @return string[]
     */
    public static function contactIds(array $row): array
    {
        $decoded = \json_decode((string) ($row['lacrm_contact_ids'] ?? ''), true);
        $ids = \is_array($decoded) ? $decoded : [];

        return \array_values(\array_unique(\array_map('strval', $ids)));
    }

    /**
     * The two days of a date filter, tidied; today to the end of the year when blank or unreadable, and swapped if backwards.
     *
     * @return array{0: string, 1: string}
     */
    public static function range(?string $start, ?string $end, \DateTimeInterface $today): array
    {
        $from = self::day($start) ?? $today->format('Y-m-d');
        $to = self::day($end) ?? $today->format('Y') . '-12-31';

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    private static function day(?string $value): ?string
    {
        $value = \trim((string) $value);
        $valid = \preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && \strtotime($value) !== false;

        return $valid ? \date('Y-m-d', (int) \strtotime($value)) : null;
    }
}
