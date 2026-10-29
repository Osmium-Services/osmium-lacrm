<?php

use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;
use Osmium\Services\Lacrm\Models\LacrmConfig;
use Osmium\Services\Lacrm\Models\LacrmEvent;
use Osmium\Services\Lacrm\Models\LacrmEventMapper;
use Osmium\Services\Lacrm\Models\LacrmWebhook;

/**
 * Receives Less Annoying CRM webhooks (POST /api/lacrm-webhook/).
 *
 * Calendar events are stored in lacrm_events. Everything else is only
 * announced as a lacrm.* hook, so this service never touches another
 * service's data. Every hook is announced after the payload is verified.
 *
 * Security: HMAC SHA-256 of the raw body in X-Hook-Signature, checked against
 * the saved webhook secret.
 */

\header('Content-Type: application/json');

$respond = function (int $code, array $body): never {
    \http_response_code($code);
    echo \json_encode($body);
    exit;
};

$config = LacrmConfig::get();
$off = !LacrmConfig::isReady($config);
if ($off) $respond(404, ['success' => false, 'error' => 'Not found']); // Off: hide the endpoint

$notPost = ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST';
if ($notPost) $respond(405, ['success' => false, 'error' => 'Method not allowed']);

// Registration handshake: LACRM sends X-Hook-Secret, which must be echoed back.
// The secret is kept only while an admin has just pressed Register webhooks,
// so nobody else can set it.
$handshakeSecret = (string) ($_SERVER['HTTP_X_HOOK_SECRET'] ?? '');
if ($handshakeSecret !== '') {
    $windowOpen = LacrmWebhook::handshakeOpen(config: $config, now: \time());
    if ($windowOpen) LacrmConfig::save(['webhookSecret' => $handshakeSecret, 'handshakeOpenUntil' => 0]);
    \header('X-Hook-Secret: ' . $handshakeSecret);
    $respond(200, ['success' => true, 'message' => 'Handshake']);
}

$rawBody = (string) \file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_X_HOOK_SIGNATURE'] ?? '');
$secret = (string) ($config['webhookSecret'] ?? '');

$noSecret = $secret === '';
if ($noSecret) $respond(500, ['success' => false, 'error' => 'Webhook not registered']);

$signatureValid = LacrmWebhook::signatureValid(rawBody: $rawBody, signature: $signature, secret: $secret);
if (!$signatureValid) {
    \error_log('LACRM webhook: invalid signature');
    $respond(401, ['success' => false, 'error' => 'Invalid signature']);
}

$payload = \json_decode(json: $rawBody, associative: true);
$badJson = !\is_array($payload);
if ($badJson) $respond(400, ['success' => false, 'error' => 'Invalid JSON payload']);

$db = $this->osmium->dataSource;
$hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($db)));
$triggering = (string) ($payload['TriggeringEvent'] ?? '');
$announcements = LacrmWebhook::announcements(payload: $payload, config: $config);

$isEventSave = \in_array($triggering, ['Event.Create', 'Event.Update'], true);
$isEventDelete = $triggering === 'Event.Delete';
$event = $payload['Event'] ?? [];
$eventId = (string) ($event['EventId'] ?? '');

if (($isEventSave || $isEventDelete) && $eventId === '') {
    $respond(400, ['success' => false, 'error' => 'Missing EventId']);
}

if ($isEventSave) {
    $row = LacrmEventMapper::map(event: $event, rawPayload: $payload);
    $saved = (new LacrmEvent($db))->save(data: $row);
    $announcements[] = ['event' => 'lacrm.event.saved', 'payload' => ['id' => $saved['id'], 'created' => $saved['created'], 'event' => $row]];
}

if ($isEventDelete) {
    $deleted = (new LacrmEvent($db))->softDelete(lacrmEventId: $eventId);
    $announcements[] = ['event' => 'lacrm.event.deleted', 'payload' => ['lacrmEventId' => $eventId, 'deleted' => $deleted]];
}

foreach ($announcements as $announcement) {
    $payloadWithDb = $announcement['payload'] + ['dataSource' => $db];
    $hooks->dispatch($announcement['event'], $payloadWithDb);
}

$respond(200, ['success' => true, 'event' => $triggering, 'announced' => \count($announcements)]);
