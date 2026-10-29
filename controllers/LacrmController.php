<?php

declare(strict_types=1);

namespace Osmium\Services\Lacrm\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\Lacrm\Models\LacrmClient;
use Osmium\Services\Lacrm\Models\LacrmConfig;
use Osmium\Services\Lacrm\Models\LacrmWebhook;

/**
 * Less Annoying CRM settings: a full-page form POST/redirect flow.
 *
 * Routes:
 *   - index() → /admin/settings/lacrm/  (GET shows the page; POST saves, or with
 *     action=test | register | pipelines runs that button against the saved settings)
 */
class LacrmController extends AdminController
{
    private const FLASH_KEYS = ['lacrm_saved', 'lacrm_error', 'lacrm_result'];

    public function index(): void
    {
        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($isPost) $this->handleSubmit();

        $config = LacrmConfig::get();
        $page = $config;
        $page['apiKeySet'] = LacrmConfig::hasApiKey($config);
        $page['webhookSecretSet'] = ($config['webhookSecret'] ?? '') !== '';
        unset($page['apiKey'], $page['webhookSecret']); // Secrets never reach the page

        $this->data['admin']['config']['lacrm'] = $page;
        $this->data['admin']['webhookUrl'] = LacrmClient::webhookUrl(siteUrl: (string) $this->osmium->config->site->FQDN);
        $this->data['admin']['settingsSaved'] = $_SESSION['lacrm_saved'] ?? false;
        $this->data['admin']['settingsError'] = $_SESSION['lacrm_error'] ?? false;
        $this->data['admin']['result'] = $_SESSION['lacrm_result'] ?? null;
        foreach (self::FLASH_KEYS as $key) {
            unset($_SESSION[$key]);
        }

        $this->setView('lacrm/index.phtml');
    }

    private function handleSubmit(): void
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) $this->fail('Invalid form submission. Please try again.');

        $action = (string) ($_POST['action'] ?? 'save');

        match ($action) {
            'test' => $this->test(),
            'register' => $this->register(),
            'pipelines' => $this->pipelines(),
            default => $this->save(),
        };
    }

    private function save(): void
    {
        $saved = LacrmConfig::get();
        $postedKey = \trim((string) ($_POST['api_key'] ?? ''));
        $apiKey = $postedKey === '' ? (string) $saved['apiKey'] : $postedKey; // Blank keeps the stored secret

        $values = [
            'enabled' => isset($_POST['enabled']),
            'apiKey' => $apiKey,
            'userId' => \trim((string) ($_POST['user_id'] ?? '')),
            'pipelineId' => \trim((string) ($_POST['pipeline_id'] ?? '')),
            'wonStatusId' => \trim((string) ($_POST['won_status_id'] ?? '')),
            'lostStatusId' => \trim((string) ($_POST['lost_status_id'] ?? '')),
        ];

        $error = $this->validate(values: $values);
        if ($error !== null) $this->fail($error);

        LacrmConfig::save($values);
        $this->admin->model->changelog->log(description: 'Updated Less Annoying CRM settings', recordType: 'settings');

        $_SESSION['lacrm_saved'] = true;
        $this->redirect('settings/lacrm/');
    }

    /** @param array<string, mixed> $values */
    private function validate(array $values): ?string
    {
        $needsKey = $values['enabled'] && $values['apiKey'] === '';
        if ($needsKey) return 'Enter your LACRM API key to switch the connection on.';

        foreach (['pipelineId', 'wonStatusId', 'lostStatusId', 'userId'] as $field) {
            $value = (string) $values[$field];
            $badId = $value !== '' && !LacrmConfig::isValidId($value);
            if ($badId) return 'Pipeline, status and user IDs are letters, digits and dashes only.';
        }

        return null;
    }

    private function test(): void
    {
        $client = $this->client();
        $outcome = $client->testConnection();
        $_SESSION['lacrm_result'] = ['title' => 'Test connection', 'ok' => $outcome['success'], 'text' => $outcome['message']];
        $this->redirect('settings/lacrm/');
    }

    private function register(): void
    {
        $config = LacrmConfig::get();
        $notOn = empty($config['enabled']);
        if ($notOn) $this->fail('Switch the connection on and save before registering webhooks.');

        $client = $this->client();
        LacrmConfig::save(['handshakeOpenUntil' => \time() + LacrmWebhook::HANDSHAKE_WINDOW_SECONDS]);
        $url = LacrmClient::webhookUrl(siteUrl: (string) $this->osmium->config->site->FQDN);

        try {
            $client->createWebhook(url: $url, events: LacrmWebhook::EVENTS);
        } catch (\Throwable $e) {
            LacrmConfig::save(['handshakeOpenUntil' => 0]);
            $this->fail('Registering failed: ' . $e->getMessage());
        }

        $captured = (string) LacrmConfig::get()['webhookSecret'] !== '';
        $text = $captured
            ? 'Webhook registered at ' . $url . ' and its secret saved.'
            : 'LACRM accepted the webhook but its secret has not arrived yet. Check this page again in a minute; if it still says "not saved", registering did not complete.';
        $_SESSION['lacrm_result'] = ['title' => 'Register webhooks', 'ok' => $captured, 'text' => $text];
        $this->admin->model->changelog->log(description: 'Registered Less Annoying CRM webhook', recordType: 'settings');
        $this->redirect('settings/lacrm/');
    }

    private function pipelines(): void
    {
        $client = $this->client();

        try {
            $pipelines = $client->getPipelines();
        } catch (\Throwable $e) {
            $this->fail('Could not list pipelines: ' . $e->getMessage());
        }

        $text = \json_encode(value: $pipelines, flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $_SESSION['lacrm_result'] = ['title' => 'Pipelines (copy the IDs you need)', 'ok' => true, 'text' => (string) $text, 'pre' => true];
        $this->redirect('settings/lacrm/');
    }

    private function client(): LacrmClient
    {
        $config = LacrmConfig::get();
        $noKey = !LacrmConfig::hasApiKey($config);
        if ($noKey) $this->fail('Save your API key first.');

        return new LacrmClient(apiKey: (string) $config['apiKey'], userId: (string) $config['userId']);
    }

    private function fail(string $message): never
    {
        $_SESSION['lacrm_error'] = $message;
        $this->redirect('settings/lacrm/');
        exit;
    }
}
