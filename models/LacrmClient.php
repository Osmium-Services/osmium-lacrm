<?php

declare(strict_types=1);

namespace Osmium\Services\Lacrm\Models;

/**
 * Less Annoying CRM API v2 client.
 *
 * API docs: https://account.lessannoyingcrm.com/api_docs/v2
 */
class LacrmClient
{
    private const API_URL = 'https://api.lessannoyingcrm.com/v2/';
    private const BATCH_SIZE = 8; // Parallel fetches go out in small batches, never all at once
    private const BATCH_PAUSE_MICROSECONDS = 250000;
    private const MAX_PAGES = 20;

    public function __construct(private string $apiKey, private string $userId = '') {}

    // Webhooks

    /** @param string[] $events */
    public function createWebhook(string $url, array $events, string $scope = 'Account'): array
    {
        return $this->call(function: 'CreateWebhook', parameters: [
            'EndpointUrl' => $url,
            'Events' => $events,
            'WebhookScope' => $scope,
        ]);
    }

    public function listWebhooks(): array
    {
        return $this->call(function: 'GetWebhooks', parameters: []);
    }

    public function deleteWebhook(string $webhookId): array
    {
        return $this->call(function: 'DeleteWebhook', parameters: ['WebhookId' => $webhookId]);
    }

    // Contacts

    public function searchContactsByEmail(string $email): array
    {
        return $this->call(function: 'SearchContacts', parameters: ['SearchTerms' => $email]);
    }

    public function getContact(string $contactId): array
    {
        return $this->call(function: 'GetContact', parameters: ['ContactId' => $contactId]);
    }

    /**
     * @param string[] $contactIds
     * @return array{results: array<string, array>, errors: array<string, string>} keyed by contact id, with the reason for each failure
     */
    public function getContactsParallel(array $contactIds): array
    {
        $none = $contactIds === [];
        if ($none) return ['results' => [], 'errors' => []];

        $results = [];
        $errors = [];
        $batches = \array_chunk($contactIds, self::BATCH_SIZE);
        $lastIndex = \count($batches) - 1;

        foreach ($batches as $index => $batch) {
            [$batchResults, $batchErrors] = $this->fetchBatch(function: 'GetContact', parameterName: 'ContactId', ids: $batch);
            $results += $batchResults;
            $errors += $batchErrors;

            $isLast = $index === $lastIndex;
            if (!$isLast) \usleep(self::BATCH_PAUSE_MICROSECONDS);
        }

        return ['results' => $results, 'errors' => $errors];
    }

    /** People linked to a company, found by searching its name and keeping those whose CompanyId matches. */
    public function getLinkedPeople(string $companyId, string $companyName): array
    {
        $result = $this->call(function: 'GetContacts', parameters: [
            'RecordTypeFilter' => 'Contacts',
            'SearchTerms' => $companyName,
        ]);

        $people = [];
        foreach ($result['Results'] ?? [] as $contact) {
            $isCompany = $contact['IsCompany'] ?? true;
            $linkedCompanyId = $contact['CompanyId'] ?? '';

            $isLinkedPerson = !$isCompany && $linkedCompanyId === $companyId;
            if ($isLinkedPerson) $people[] = $contact;
        }

        return $people;
    }

    // Pipelines

    public function getPipelines(): array
    {
        return $this->call(function: 'GetPipelines', parameters: []);
    }

    /** @return array<int, array> Every pipeline item in the pipeline that has the status */
    public function getPipelineItemsWithStatus(string $pipelineId, string $statusId): array
    {
        return $this->paged(function: 'GetPipelineItems', parameters: [
            'PipelineId' => $pipelineId,
            'StatusFilter' => [$statusId],
        ], maxPages: 10);
    }

    // Connection

    /** @return array{success: bool, message: string, user?: array} */
    public function testConnection(): array
    {
        $parameters = $this->userId === '' ? [] : ['UserId' => $this->userId];

        try {
            $user = $this->call(function: 'GetUser', parameters: $parameters);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'message' => 'API connection successful', 'user' => $user];
    }

    public static function webhookUrl(string $siteUrl): string
    {
        $base = \rtrim(string: $siteUrl, characters: '/');

        return $base . '/api/lacrm-webhook/';
    }

    // Transport

    /** @throws \RuntimeException On a network error, bad JSON, or an error from LACRM */
    private function call(string $function, array $parameters): array
    {
        $body = \json_encode(
            value: ['Function' => $function, 'Parameters' => $parameters],
            flags: JSON_THROW_ON_ERROR,
        );

        $ch = \curl_init(self::API_URL);
        \curl_setopt_array($ch, $this->curlOptions(body: $body));

        $response = \curl_exec($ch);
        $curlError = \curl_error($ch);
        $failed = $curlError !== '' || !\is_string($response);
        if ($failed) throw new \RuntimeException('LACRM API network error: ' . $curlError);

        $status = (int) \curl_getinfo(handle: $ch, option: CURLINFO_HTTP_CODE);

        return self::decode(response: $response, status: $status);
    }

    /**
     * @param int $status The HTTP status, for the message when there is no usable body (LACRM answers a bad key with an empty 400)
     * @return array<string, mixed>
     */
    public static function decode(string $response, int $status = 0): array
    {
        $decoded = \json_decode(json: $response, associative: true);
        $notJson = !\is_array($decoded);
        if ($notJson) throw new \RuntimeException(self::unusableReply(response: $response, status: $status));

        $error = $decoded['Error'] ?? null;
        $hasError = $error !== null;
        if ($hasError) throw new \RuntimeException('LACRM API error: ' . (\is_array($error) ? ($error['Message'] ?? 'Unknown error') : (string) $error));

        return $decoded;
    }

    private static function unusableReply(string $response, int $status): string
    {
        $empty = \trim($response) === '';
        $badStatus = $status >= 400;
        if ($empty && $badStatus) return "LACRM refused the request (HTTP {$status}, no details). Check the API key.";

        return 'LACRM API returned something that is not JSON' . ($status > 0 ? " (HTTP {$status})" : '');
    }

    /** @return array<int, array> Results of every page, up to $maxPages */
    private function paged(string $function, array $parameters, int $maxPages): array
    {
        $all = [];
        $page = 1;

        do {
            $response = $this->call(function: $function, parameters: $parameters + ['Page' => $page]);
            $all = \array_merge($all, $response['Results'] ?? []);
            $hasMore = (bool) ($response['HasMoreResults'] ?? false);
            $page++;
        } while ($hasMore && $page <= $maxPages);

        return $all;
    }

    /**
     * One batch of single-record calls in parallel.
     *
     * @param string[] $ids
     * @return array{0: array<string, array>, 1: array<string, string>} [results by id, error reason by id]
     */
    private function fetchBatch(string $function, string $parameterName, array $ids): array
    {
        $multi = \curl_multi_init();
        $handles = [];

        foreach ($ids as $id) {
            $body = \json_encode(['Function' => $function, 'Parameters' => [$parameterName => $id]]);
            $ch = \curl_init(self::API_URL);
            \curl_setopt_array($ch, $this->curlOptions(body: (string) $body));
            \curl_multi_add_handle($multi, $ch);
            $handles[$id] = $ch;
        }

        do {
            $status = \curl_multi_exec($multi, $active);
            \curl_multi_select($multi);
        } while ($active && $status === CURLM_OK);

        $results = [];
        $errors = [];
        foreach ($handles as $id => $ch) {
            $curlError = \curl_error($ch);
            $response = (string) \curl_multi_getcontent($ch);
            $status = (int) \curl_getinfo(handle: $ch, option: CURLINFO_HTTP_CODE);
            \curl_multi_remove_handle($multi, $ch);

            $networkFailed = $curlError !== '';
            if ($networkFailed) {
                $errors[(string) $id] = 'Network error: ' . $curlError;
                continue;
            }

            try {
                $results[(string) $id] = self::decode(response: $response, status: $status);
            } catch (\RuntimeException $e) {
                $errors[(string) $id] = $e->getMessage();
            }
        }

        \curl_multi_close($multi);

        return [$results, $errors];
    }

    /** @return array<int, mixed> */
    private function curlOptions(string $body): array
    {
        return [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: ' . $this->apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
    }
}
