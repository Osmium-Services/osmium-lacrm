<?php

declare(strict_types=1);

namespace Osmium\Services\Lacrm\Models;

/**
 * Pure webhook logic: checking LACRM's signature and turning a payload into
 * the hooks this service announces. No database, no network, no globals.
 */
class LacrmWebhook
{
    /** The LACRM events registered with the webhook. */
    public const EVENTS = [
        'PublicForm.Submit',
        'PipelineItemStatus.Create',
        'PipelineItemStatus.Update',
        'Contact.Update',
    ];

    /** How long after pressing Register webhooks the handshake secret is accepted. */
    public const HANDSHAKE_WINDOW_SECONDS = 300;

    public static function signatureValid(string $rawBody, string $signature, string $secret): bool
    {
        $noSecret = $secret === '';
        if ($noSecret) return false;

        $expected = \hash_hmac(algo: 'sha256', data: $rawBody, key: $secret);

        return \hash_equals(known_string: $expected, user_string: $signature);
    }

    /** @param array<string, mixed> $config */
    public static function handshakeOpen(array $config, int $now): bool
    {
        $openUntil = (int) ($config['handshakeOpenUntil'] ?? 0);

        return $openUntil >= $now;
    }

    /**
     * The hooks to announce for a payload.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $config
     * @return array<int, array{event: string, payload: array<string, mixed>}>
     */
    public static function announcements(array $payload, array $config): array
    {
        $triggering = (string) ($payload['TriggeringEvent'] ?? '');

        return match ($triggering) {
            'PublicForm.Submit' => self::formSubmitted(payload: $payload, config: $config),
            'PipelineItemStatus.Create',
            'PipelineItemStatus.Update' => self::pipelineChanges(payload: $payload, config: $config),
            'Contact.Update' => self::contactUpdated(payload: $payload),
            default => [],
        };
    }

    /**
     * A form submission, announced with the form's ID. When a Form ID is set in
     * settings only that form is announced; an unreadable ID is never assumed
     * to match.
     *
     * @return array<int, array{event: string, payload: array<string, mixed>}>
     */
    private static function formSubmitted(array $payload, array $config): array
    {
        $formId = self::formId(payload: $payload);
        $wanted = (string) ($config['formId'] ?? '');

        $filtered = $wanted !== '';
        $otherForm = $filtered && $formId !== $wanted;
        if ($otherForm) return [];

        $submission = $payload['FormSubmission'] ?? [];
        $fields = $submission['FormFields'] ?? [];

        return [[
            'event' => 'lacrm.form.submitted',
            'payload' => ['formId' => $formId, 'fields' => $fields, 'submission' => $submission, 'raw' => $payload],
        ]];
    }

    /** The form's ID. LACRM's docs put it at PublicFormId, and in PublicFormMetaData; older payloads nest it in FormSubmission. */
    public static function formId(array $payload): string
    {
        $candidates = [
            $payload['PublicFormId'] ?? null,
            $payload['PublicFormMetaData']['PublicFormId'] ?? null,
            $payload['FormSubmission']['PublicFormId'] ?? null,
            $payload['FormSubmission']['FormId'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $usable = \is_scalar($candidate) && (string) $candidate !== '';
            if ($usable) return (string) $candidate;
        }

        return '';
    }

    /** @return array<int, array{event: string, payload: array<string, mixed>}> */
    private static function contactUpdated(array $payload): array
    {
        $contacts = $payload['Contacts'] ?? [];
        $none = $contacts === [];
        if ($none) return [];

        return [['event' => 'lacrm.contact.updated', 'payload' => ['contacts' => $contacts]]];
    }

    /**
     * A sale won or lost per pipeline item, but only in the configured pipeline
     * and only for the configured Won / Lost statuses. Unset means nothing is
     * announced: this service never guesses which pipeline matters.
     *
     * @return array<int, array{event: string, payload: array<string, mixed>}>
     */
    private static function pipelineChanges(array $payload, array $config): array
    {
        $pipelineId = (string) ($config['pipelineId'] ?? '');
        $wonId = (string) ($config['wonStatusId'] ?? '');
        $lostId = (string) ($config['lostStatusId'] ?? '');

        $announcements = [];
        foreach ($payload['PipelineItems'] ?? [] as $item) {
            $contactId = (string) ($item['ContactId'] ?? '');
            $statusId = (string) ($item['StatusId'] ?? '');

            $wrongPipeline = $pipelineId === '' || ($item['PipelineId'] ?? '') !== $pipelineId;
            $noContact = $contactId === '';
            if ($wrongPipeline || $noContact) continue;

            $isWon = $wonId !== '' && $statusId === $wonId;
            $isLost = $lostId !== '' && $statusId === $lostId;
            if ($isWon) $announcements[] = ['event' => 'lacrm.sale.won', 'payload' => ['contactId' => $contactId, 'item' => $item]];
            if ($isLost) $announcements[] = ['event' => 'lacrm.sale.lost', 'payload' => ['contactId' => $contactId, 'item' => $item]];
        }

        return $announcements;
    }
}
