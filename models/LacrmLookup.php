<?php

declare(strict_types=1);

namespace Osmium\Services\Lacrm\Models;

/**
 * Hook handlers other services use to ask LACRM questions. Each takes the
 * hook payload and returns null (a hook's "not me") when the connection is
 * off, so asking is always safe. An API failure throws: `handle()` lets it
 * reach the asker, who decides what to do.
 */
class LacrmLookup
{
    /** @param array{contactId?: string} $payload */
    public static function contact(array $payload): ?array
    {
        $client = self::client();
        $contactId = (string) ($payload['contactId'] ?? '');
        $unavailable = $client === null || $contactId === '';
        if ($unavailable) return null;

        return $client->getContact(contactId: $contactId);
    }

    /**
     * @param array{contactIds?: string[]} $payload
     * @return array{results: array, errors: array}|null
     */
    public static function contacts(array $payload): ?array
    {
        $client = self::client();
        $ids = (array) ($payload['contactIds'] ?? []);
        if ($client === null) return null;

        return $client->getContactsParallel(contactIds: $ids);
    }

    /**
     * @param array{companyId?: string, companyName?: string} $payload
     * @return array<int, array>|null
     */
    public static function linkedPeople(array $payload): ?array
    {
        $client = self::client();
        $companyId = (string) ($payload['companyId'] ?? '');
        $unavailable = $client === null || $companyId === '';
        if ($unavailable) return null;

        return $client->getLinkedPeople(companyId: $companyId, companyName: (string) ($payload['companyName'] ?? ''));
    }

    /** @return array<int, array>|null Pipeline items at the configured Won status */
    public static function wonContacts(array $payload): ?array
    {
        return self::itemsAt(statusKey: 'wonStatusId');
    }

    /** @return array<int, array>|null Pipeline items at the configured Lost status */
    public static function lostContacts(array $payload): ?array
    {
        return self::itemsAt(statusKey: 'lostStatusId');
    }

    /** @return array<int, array>|null */
    private static function itemsAt(string $statusKey): ?array
    {
        $config = LacrmConfig::get();
        $client = self::client();
        $pipelineId = (string) ($config['pipelineId'] ?? '');
        $statusId = (string) ($config[$statusKey] ?? '');

        $unavailable = $client === null || $pipelineId === '' || $statusId === '';
        if ($unavailable) return null;

        return $client->getPipelineItemsWithStatus(pipelineId: $pipelineId, statusId: $statusId);
    }

    private static function client(): ?LacrmClient
    {
        $config = LacrmConfig::get();
        $ready = LacrmConfig::isReady($config);
        if (!$ready) return null;

        return new LacrmClient(apiKey: (string) $config['apiKey'], userId: (string) $config['userId']);
    }
}
