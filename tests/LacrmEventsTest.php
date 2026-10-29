<?php

declare(strict_types=1);

namespace Tests\Services\Lacrm;

use Osmium\Services\Lacrm\Models\LacrmEventDisplay;
use Osmium\Services\Lacrm\Models\LacrmEventImport;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/LacrmEventMapper.php';
require_once __DIR__ . '/../models/LacrmEventDisplay.php';
require_once __DIR__ . '/../models/LacrmEventImport.php';

final class LacrmEventsTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array}> */
    private array $announced = [];
    /** @var array<int, array> */
    private array $saved = [];

    protected function setUp(): void
    {
        \date_default_timezone_set('UTC');
    }

    private function event(string $id, string $name = 'Visit', string $start = '2026-03-12T09:00:00+00:00', string $end = '2026-03-12T10:30:00+00:00'): array
    {
        return ['EventId' => $id, 'Name' => $name, 'StartDate' => $start, 'EndDate' => $end];
    }

    /**
     * @param array<int, array> $remote Events LACRM returns
     * @param string[] $known Event ids already held
     */
    private function import(array $remote, array $known = [], bool $failSave = false): LacrmEventImport
    {
        $this->announced = [];
        $this->saved = [];
        $knownSet = \array_fill_keys($known, true);

        return new LacrmEventImport(
            fetchRange: static fn(string $from, string $to): array => $remote,
            fetchOne: static fn(string $id): array => ['Name' => 'Single'] + ($remote[0] ?? []),
            knownIds: static fn(): array => $knownSet,
            save: function (array $row) use ($knownSet, $failSave): array {
                if ($failSave) throw new \RuntimeException('db down');
                $this->saved[] = $row;

                return ['id' => \count($this->saved), 'created' => !isset($knownSet[$row['lacrm_event_id']])];
            },
            announce: function (string $hook, array $payload): void {
                $this->announced[] = [$hook, $payload];
            },
        );
    }

    // Display

    public function test_the_time_line_reads_for_each_kind_of_event(): void
    {
        $this->assertSame('12 Mar 2026, 09:00 - 10:30', LacrmEventDisplay::time('2026-03-12 09:00:00', '2026-03-12 10:30:00', false));
        $this->assertSame('12 Mar 09:00 - 13 Mar 17:00', LacrmEventDisplay::time('2026-03-12 09:00:00', '2026-03-13 17:00:00', false));
        $this->assertSame('12 Mar 2026 (All day)', LacrmEventDisplay::time('2026-03-12 00:00:00', '2026-03-12 23:59:00', true));
        $this->assertSame('12 Mar - 14 Mar 2026 (All day)', LacrmEventDisplay::time('2026-03-12 00:00:00', '2026-03-14 00:00:00', true));
    }

    public function test_a_long_description_is_cut_and_flagged_and_a_short_one_is_not(): void
    {
        $long = LacrmEventDisplay::description(\str_repeat('a', 100));

        $this->assertSame(60, \mb_strlen($long['text']));
        $this->assertTrue($long['truncated']);
        $this->assertSame(['text' => 'Short', 'truncated' => false], LacrmEventDisplay::description('Short'));
        $this->assertSame(['text' => '', 'truncated' => false], LacrmEventDisplay::description(null));
    }

    public function test_contact_ids_come_out_of_the_json_column_once_each_as_strings(): void
    {
        $this->assertSame(['1', '2'], LacrmEventDisplay::contactIds(['lacrm_contact_ids' => '["1",2,"1"]']));
        $this->assertSame([], LacrmEventDisplay::contactIds(['lacrm_contact_ids' => null]));
        $this->assertSame([], LacrmEventDisplay::contactIds(['lacrm_contact_ids' => 'not json']));
    }

    public function test_the_customer_filter_keeps_only_events_linked_to_a_customer(): void
    {
        $events = [['id' => 1, 'lacrm_contact_ids' => '["7","8"]'], ['id' => 2, 'lacrm_contact_ids' => '["9"]'], ['id' => 3, 'lacrm_contact_ids' => null]];

        $kept = LacrmEventDisplay::withCustomers($events, ['8' => ['label' => 'Acme', 'url' => 'x']]);

        $this->assertSame([1], \array_column($kept, 'id'));
        $this->assertSame([], LacrmEventDisplay::withCustomers($events, []));
    }

    public function test_the_date_range_defaults_to_today_until_year_end_and_is_put_the_right_way_round(): void
    {
        $today = new \DateTimeImmutable('2026-10-29');

        $this->assertSame(['2026-10-29', '2026-12-31'], LacrmEventDisplay::range(null, '', $today));
        $this->assertSame(['2026-01-01', '2026-02-01'], LacrmEventDisplay::range('2026-01-01', '2026-02-01', $today));
        $this->assertSame(['2026-01-01', '2026-02-01'], LacrmEventDisplay::range('2026-02-01', '2026-01-01', $today));
        $this->assertSame(['2026-10-29', '2026-12-31'], LacrmEventDisplay::range('garbage', '2026-13-45', $today));
    }

    // Import

    public function test_scan_lists_only_events_not_held_yet_with_a_readable_time(): void
    {
        $import = $this->import([$this->event('A', 'Held'), $this->event('B', 'New one'), ['Name' => 'No id']], known: ['A']);

        $this->assertSame([['lacrm_event_id' => 'B', 'name' => 'New one', 'time' => '12 Mar 2026, 09:00 - 10:30']], $import->scan('2026-03-01', '2026-03-31'));
    }

    public function test_import_range_stores_each_event_and_announces_it_like_the_webhook(): void
    {
        $import = $this->import([$this->event('A'), $this->event('B'), ['Name' => 'No id']], known: ['A']);

        $result = $import->importRange('2026-03-01', '2026-03-31');

        $this->assertSame(['fetched' => 3, 'created' => 1, 'updated' => 1, 'skipped' => 1, 'errors' => 0], $result);
        $this->assertSame(['lacrm.event.saved', 'lacrm.event.saved'], \array_column($this->announced, 0));
        $this->assertSame(['A', 'B'], \array_column(\array_column(\array_column($this->announced, 1), 'event'), 'lacrm_event_id'));
        $this->assertFalse($this->announced[0][1]['created']);
        $this->assertTrue($this->announced[1][1]['created']);
    }

    public function test_a_failing_save_counts_as_an_error_and_announces_nothing(): void
    {
        $result = $this->import([$this->event('A')], failSave: true)->importRange('2026-03-01', '2026-03-31');

        $this->assertSame(1, $result['errors']);
        $this->assertSame([], $this->announced);
    }

    public function test_import_one_fetches_the_event_by_id_and_reports_the_outcome(): void
    {
        $import = $this->import([$this->event('A')]);

        $this->assertSame('created', $import->importOne('A'));
        $this->assertSame('skipped', $import->importOne('  '));
        $this->assertSame('A', $this->saved[0]['lacrm_event_id']);
    }
}
