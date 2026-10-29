<?php

declare(strict_types=1);

namespace Osmium\Services\Lacrm\Controllers;

use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;
use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\Lacrm\Models\LacrmClient;
use Osmium\Services\Lacrm\Models\LacrmConfig;
use Osmium\Services\Lacrm\Models\LacrmEvent;
use Osmium\Services\Lacrm\Models\LacrmEventDisplay;
use Osmium\Services\Lacrm\Models\LacrmEventImport;

/**
 * The local copy of LACRM calendar events: a list for a date range (with the customers each event is linked to),
 * a details view, and an import from LACRM.
 *
 * Routes:
 *   - index()  → /admin/lacrm/events/         (the page; ?start=YYYY-MM-DD&end=YYYY-MM-DD filters it)
 *   - action() → /admin/lacrm/events/action/  (JSON: get_event, scan, import_range, import_one)
 */
class LacrmEventsController extends AdminController
{
    private const RECORD_TYPE = 'lacrm_event';

    public function index(): void
    {
        $params = $this->queryParams();
        [$start, $end] = LacrmEventDisplay::range(start: $params['start'] ?? null, end: $params['end'] ?? null, today: new \DateTimeImmutable('today'));

        $events = (new LacrmEvent($this->osmium->dataSource))->between(startDay: $start, endDay: $end);
        $linked = $this->linkedCustomers($events);

        $this->data['admin']['lists'] = require __DIR__ . '/../lists/lacrm-events.php';
        $this->data['admin']['events'] = \array_map(fn(array $event): array => $this->formatEvent(event: $event, linked: $linked), $events);
        $this->data['admin']['rangeStart'] = $start;
        $this->data['admin']['rangeEnd'] = $end;
        $this->data['admin']['isAdmin'] = $this->isAdmin();
        $this->data['admin']['connected'] = LacrmConfig::isReady(LacrmConfig::get());

        $this->setView('lacrm-events/index.phtml');
    }

    public function action()
    {
        \header('Content-Type: application/json');

        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if (!$isPost) $this->admin->jsonError('Method not allowed');

        $input = $this->admin->auth->getJsonInput();
        $csrfValid = $this->admin->auth->validateCsrfJson($input);
        if (!$csrfValid) $this->admin->jsonError('Invalid request token. Please refresh and try again.');

        $notAdmin = !$this->isAdmin();
        if ($notAdmin) $this->admin->jsonError('Only an administrator can use this');

        \set_time_limit(300); // LACRM is asked for a whole date range

        // The response is sent outside the try: jsonSuccess() ends the request by throwing
        try {
            $result = $this->run(action: (string) ($input['action'] ?? ''), input: $input);
        } catch (\Throwable $e) {
            $this->admin->jsonError($e->getMessage());
        }

        $this->admin->jsonSuccess($result);
    }

    /** @return array<string, mixed> */
    private function run(string $action, array $input): array
    {
        if ($action === 'get_event') return $this->getEvent((string) ($input['lacrm_event_id'] ?? ''));

        [$start, $end] = LacrmEventDisplay::range(start: $input['start'] ?? null, end: $input['end'] ?? null, today: new \DateTimeImmutable('today'));
        $importer = $this->importer();

        if ($action === 'scan') return ['success' => true, 'events' => $importer->scan(startDay: $start, endDay: $end)];

        if ($action === 'import_range') {
            $result = $importer->importRange(startDay: $start, endDay: $end);
            $this->logImport("Imported LACRM calendar events {$start} to {$end}: {$result['created']} new, {$result['updated']} updated");

            return ['success' => true] + $result;
        }

        if ($action === 'import_one') {
            $outcome = $importer->importOne((string) ($input['lacrm_event_id'] ?? ''));
            $this->logImport('Imported one LACRM calendar event');

            return ['success' => true, 'outcome' => $outcome];
        }

        throw new \InvalidArgumentException('Unknown action');
    }

    /** @return array<string, mixed> */
    private function getEvent(string $lacrmEventId): array
    {
        $event = (new LacrmEvent($this->osmium->dataSource))->find($lacrmEventId);
        if ($event === null) throw new \InvalidArgumentException('Event not found');

        foreach (['lacrm_user_ids', 'lacrm_contact_ids', 'attendees'] as $column) {
            $event[$column] = $event[$column] === null ? [] : \json_decode((string) $event[$column], true);
        }
        unset($event['raw_payload']); // the raw webhook body is for debugging, not the page

        return ['success' => true, 'event' => $event];
    }

    private function importer(): LacrmEventImport
    {
        $config = LacrmConfig::get();
        $notReady = !LacrmConfig::isReady($config);
        if ($notReady) throw new \RuntimeException('Less Annoying CRM is not connected. Add your API key under Settings > Less Annoying CRM.');

        $client = new LacrmClient(apiKey: (string) $config['apiKey'], userId: (string) $config['userId']);
        $events = new LacrmEvent($this->osmium->dataSource);
        $db = $this->osmium->dataSource;
        $hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($db)));

        return new LacrmEventImport(
            fetchRange: static fn(string $from, string $to): array => $client->getEvents(startDate: $from, endDate: $to),
            fetchOne: static fn(string $id): array => $client->getEvent($id),
            knownIds: static fn(): array => $events->knownIds(),
            save: static fn(array $row): array => $events->save(data: $row),
            announce: static fn(string $hook, array $payload) => $hooks->dispatch($hook, $payload + ['dataSource' => $db]),
        );
    }

    /**
     * Asks any service that knows customers which of these LACRM contacts are customers. This service never calls
     * theirs: the hook is crm.contacts.linked, answered with a map of contact id to ['label' => ..., 'url' => ...].
     *
     * @param array<int, array<string, mixed>> $events
     * @return array<string, array{label: string, url: string}>
     */
    private function linkedCustomers(array $events): array
    {
        $ids = [];
        foreach ($events as $event) {
            foreach (LacrmEventDisplay::contactIds($event) as $id) {
                $ids[$id] = true;
            }
        }

        $none = $ids === [];
        if ($none) return [];

        $hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($this->osmium->dataSource)));
        $answers = $hooks->collectAll('crm.contacts.linked', ['source' => 'lacrm', 'ids' => \array_map('strval', \array_keys($ids)), 'dataSource' => $this->osmium->dataSource]);

        $linked = [];
        foreach ($answers as $answer) {
            foreach ((array) $answer as $contactId => $customer) {
                $usable = \is_array($customer) && isset($customer['label'], $customer['url']);
                if ($usable) $linked[(string) $contactId] = ['label' => (string) $customer['label'], 'url' => (string) $customer['url']];
            }
        }

        return $linked;
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, array{label: string, url: string}> $linked
     * @return array<string, mixed>
     */
    private function formatEvent(array $event, array $linked): array
    {
        $links = [];
        foreach (LacrmEventDisplay::contactIds($event) as $contactId) {
            $customer = $linked[$contactId] ?? null;
            if ($customer === null) continue;

            $links[] = '<a href="' . \htmlspecialchars($this->getBasePath() . \ltrim($customer['url'], '/')) . '">' . \htmlspecialchars($customer['label']) . '</a>';
        }

        $description = LacrmEventDisplay::description($event['description'] ?? null);

        return [
            'id' => (int) $event['id'],
            'lacrm_event_id' => (string) $event['lacrm_event_id'],
            'name' => (string) $event['name'],
            'recurringLabel' => $event['is_recurring'] ? 'Recurring' : '',
            'time' => LacrmEventDisplay::time(startDate: $event['start_date'], endDate: $event['end_date'], isAllDay: (bool) $event['is_all_day']),
            'start_date' => $event['start_date'],
            'location' => (string) ($event['location'] ?? ''),
            'linkedHtml' => $links === [] ? '<span class="text-muted">-</span>' : \implode(', ', $links),
            'syncedAt' => \date('d M H:i', (int) \strtotime((string) $event['synced_at'])),
            'synced_at' => $event['synced_at'],
            'description' => $description['text'] . ($description['truncated'] ? '...' : ''),
        ];
    }

    /** @return array<string, mixed> The filter's query string; core stashes it in the session */
    private function queryParams(): array
    {
        \parse_str((string) ($_SESSION['qs'] ?? ''), $params);
        unset($_SESSION['qs']);

        return $params;
    }

    private function logImport(string $description): void
    {
        $this->admin->model->changelog->log(description: $description, recordType: self::RECORD_TYPE, recordId: 0);
    }
}
