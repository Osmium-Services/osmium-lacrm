<?php

declare(strict_types=1);

namespace Tests\Services\Lacrm;

use Osmium\Services\Lacrm\Models\LacrmClient;
use Osmium\Services\Lacrm\Models\LacrmConfig;
use Osmium\Services\Lacrm\Models\LacrmEventMapper;
use Osmium\Services\Lacrm\Models\LacrmWebhook;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/LacrmConfig.php';
require_once __DIR__ . '/../models/LacrmClient.php';
require_once __DIR__ . '/../models/LacrmEventMapper.php';
require_once __DIR__ . '/../models/LacrmWebhook.php';

final class LacrmTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = \sys_get_temp_dir() . '/lacrm-test-' . \bin2hex(\random_bytes(4)) . '.json.php';
    }

    protected function tearDown(): void
    {
        @\unlink($this->path);
    }

    // Config

    public function test_a_missing_file_gives_the_defaults(): void
    {
        $config = LacrmConfig::read(path: $this->path);

        $this->assertFalse($config['enabled']);
        $this->assertSame('', $config['apiKey']);
        $this->assertSame('', $config['pipelineId']);
    }

    public function test_saved_values_are_merged_over_what_is_already_saved(): void
    {
        LacrmConfig::save(values: ['apiKey' => 'abc', 'enabled' => true], path: $this->path);
        LacrmConfig::save(values: ['pipelineId' => 'p1'], path: $this->path);

        $config = LacrmConfig::read(path: $this->path);

        $this->assertSame('abc', $config['apiKey']);
        $this->assertTrue($config['enabled']);
        $this->assertSame('p1', $config['pipelineId']);
    }

    public function test_unknown_keys_are_not_written_and_the_file_cannot_be_run_as_php(): void
    {
        LacrmConfig::save(values: ['apiKey' => 'abc', 'bogus' => 'x'], path: $this->path);

        $content = (string) \file_get_contents($this->path);

        $this->assertStringStartsWith('<?php exit(); ?>', $content);
        $this->assertStringNotContainsString('bogus', $content);
    }

    public function test_the_connection_is_ready_only_when_on_and_keyed(): void
    {
        $this->assertFalse(LacrmConfig::isReady(['enabled' => true, 'apiKey' => '']));
        $this->assertFalse(LacrmConfig::isReady(['enabled' => false, 'apiKey' => 'abc']));
        $this->assertTrue(LacrmConfig::isReady(['enabled' => true, 'apiKey' => 'abc']));
    }

    public function test_ids_allow_only_letters_digits_dashes_and_underscores(): void
    {
        $this->assertTrue(LacrmConfig::isValidId('3967451986188498865408243229868'));
        $this->assertFalse(LacrmConfig::isValidId('12 34'));
        $this->assertFalse(LacrmConfig::isValidId('1;DROP'));
        $this->assertFalse(LacrmConfig::isValidId(''));
    }

    // Signature and handshake

    public function test_a_correct_signature_passes_and_a_wrong_or_missing_one_fails(): void
    {
        $body = '{"TriggeringEvent":"Contact.Update"}';
        $good = \hash_hmac('sha256', $body, 's3cret');

        $this->assertTrue(LacrmWebhook::signatureValid(rawBody: $body, signature: $good, secret: 's3cret'));
        $this->assertFalse(LacrmWebhook::signatureValid(rawBody: $body, signature: 'nope', secret: 's3cret'));
        $this->assertFalse(LacrmWebhook::signatureValid(rawBody: $body . ' ', signature: $good, secret: 's3cret'));
    }

    public function test_no_saved_secret_never_validates_even_an_empty_signature(): void
    {
        $this->assertFalse(LacrmWebhook::signatureValid(rawBody: 'x', signature: '', secret: ''));
    }

    public function test_the_handshake_is_accepted_only_inside_the_window(): void
    {
        $this->assertTrue(LacrmWebhook::handshakeOpen(config: ['handshakeOpenUntil' => 1000], now: 999));
        $this->assertFalse(LacrmWebhook::handshakeOpen(config: ['handshakeOpenUntil' => 1000], now: 1001));
        $this->assertFalse(LacrmWebhook::handshakeOpen(config: [], now: 5));
    }

    // Announcements

    public function test_a_form_submission_is_announced_with_its_fields(): void
    {
        $payload = ['TriggeringEvent' => 'PublicForm.Submit', 'FormSubmission' => ['FormFields' => ['Email' => 'a@b.co']]];

        $announced = LacrmWebhook::announcements(payload: $payload, config: []);

        $this->assertSame('lacrm.form.submitted', $announced[0]['event']);
        $this->assertSame(['Email' => 'a@b.co'], $announced[0]['payload']['fields']);
    }

    public function test_the_form_id_is_read_from_wherever_lacrm_puts_it(): void
    {
        $this->assertSame('f1', LacrmWebhook::formId(['PublicFormId' => 'f1']));
        $this->assertSame('f2', LacrmWebhook::formId(['PublicFormMetaData' => ['PublicFormId' => 'f2']]));
        $this->assertSame('f3', LacrmWebhook::formId(['FormSubmission' => ['PublicFormId' => 'f3']]));
        $this->assertSame('', LacrmWebhook::formId(['TriggeringEvent' => 'PublicForm.Submit']));
    }

    public function test_a_set_form_id_announces_only_that_form_and_never_an_unreadable_one(): void
    {
        $mine = ['TriggeringEvent' => 'PublicForm.Submit', 'PublicFormId' => 'f1'];
        $other = ['TriggeringEvent' => 'PublicForm.Submit', 'PublicFormId' => 'f9'];
        $unknown = ['TriggeringEvent' => 'PublicForm.Submit'];
        $config = ['formId' => 'f1'];

        $announced = LacrmWebhook::announcements(payload: $mine, config: $config);

        $this->assertSame('f1', $announced[0]['payload']['formId']);
        $this->assertSame([], LacrmWebhook::announcements(payload: $other, config: $config));
        $this->assertSame([], LacrmWebhook::announcements(payload: $unknown, config: $config));
        $this->assertCount(1, LacrmWebhook::announcements(payload: $other, config: []));
    }

    public function test_sales_are_announced_only_for_the_configured_pipeline_and_statuses(): void
    {
        $config = ['pipelineId' => 'P', 'wonStatusId' => 'W', 'lostStatusId' => 'L'];
        $payload = ['TriggeringEvent' => 'PipelineItemStatus.Update', 'PipelineItems' => [
            ['PipelineId' => 'P', 'StatusId' => 'W', 'ContactId' => 'c1'],
            ['PipelineId' => 'P', 'StatusId' => 'L', 'ContactId' => 'c2'],
            ['PipelineId' => 'P', 'StatusId' => 'other', 'ContactId' => 'c3'],
            ['PipelineId' => 'X', 'StatusId' => 'W', 'ContactId' => 'c4'],
            ['PipelineId' => 'P', 'StatusId' => 'W', 'ContactId' => ''],
        ]];

        $announced = LacrmWebhook::announcements(payload: $payload, config: $config);

        $this->assertSame(['lacrm.sale.won', 'lacrm.sale.lost'], \array_column($announced, 'event'));
        $this->assertSame('c1', $announced[0]['payload']['contactId']);
        $this->assertSame('c2', $announced[1]['payload']['contactId']);
    }

    public function test_nothing_is_announced_for_sales_until_the_pipeline_is_configured(): void
    {
        $payload = ['TriggeringEvent' => 'PipelineItemStatus.Create', 'PipelineItems' => [
            ['PipelineId' => 'P', 'StatusId' => 'W', 'ContactId' => 'c1'],
        ]];

        $this->assertSame([], LacrmWebhook::announcements(payload: $payload, config: []));
        $this->assertSame([], LacrmWebhook::announcements(payload: $payload, config: ['pipelineId' => 'P']));
    }

    public function test_a_contact_update_is_announced_and_an_empty_one_is_not(): void
    {
        $with = ['TriggeringEvent' => 'Contact.Update', 'Contacts' => [['ContactId' => 'c1']]];
        $without = ['TriggeringEvent' => 'Contact.Update', 'Contacts' => []];

        $this->assertSame('lacrm.contact.updated', LacrmWebhook::announcements(payload: $with, config: [])[0]['event']);
        $this->assertSame([], LacrmWebhook::announcements(payload: $without, config: []));
    }

    public function test_unknown_events_and_calendar_events_are_left_to_the_controller(): void
    {
        $this->assertSame([], LacrmWebhook::announcements(payload: ['TriggeringEvent' => 'Event.Create'], config: []));
        $this->assertSame([], LacrmWebhook::announcements(payload: ['TriggeringEvent' => 'Something.Else'], config: []));
        $this->assertSame([], LacrmWebhook::announcements(payload: [], config: []));
    }

    // Event mapping

    public function test_an_event_maps_to_the_table_columns(): void
    {
        $row = LacrmEventMapper::map(event: [
            'EventId' => 'e1',
            'Name' => '  Show  ',
            'StartDate' => '2026-11-01T09:00:00+00:00',
            'EndDate' => '2026-11-01T17:00:00+00:00',
            'IsAllDay' => false,
            'UserIds' => 'u1',
            'ContactIds' => ['c1', 'c2'],
            'RecurrenceRule' => 'FREQ=WEEKLY',
        ]);

        $this->assertSame('e1', $row['lacrm_event_id']);
        $this->assertSame('Show', $row['name']);
        $this->assertSame(1, $row['is_recurring']);
        $this->assertSame(['u1'], $row['lacrm_user_ids']);
        $this->assertSame(['c1', 'c2'], $row['lacrm_contact_ids']);
        $this->assertNull($row['attendees']);
    }

    public function test_a_missing_end_date_falls_back_to_the_start(): void
    {
        $row = LacrmEventMapper::map(event: ['EventId' => 'e1', 'StartDate' => '2026-11-01T09:00:00+00:00']);

        $this->assertSame($row['start_date'], $row['end_date']);
    }

    // API response decoding

    public function test_an_api_error_in_the_body_is_thrown_with_its_message(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Bad key');

        LacrmClient::decode(response: '{"Error":{"Message":"Bad key"}}');
    }

    public function test_invalid_json_is_refused_and_good_json_is_returned(): void
    {
        $this->assertSame(['Results' => []], LacrmClient::decode(response: '{"Results":[]}'));

        $this->expectException(\RuntimeException::class);
        LacrmClient::decode(response: '<html>');
    }

    public function test_an_empty_error_reply_tells_you_to_check_the_key(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 400');

        LacrmClient::decode(response: '', status: 400);
    }

    public function test_the_webhook_url_has_one_trailing_slash(): void
    {
        $this->assertSame('https://x.co/api/lacrm-webhook/', LacrmClient::webhookUrl(siteUrl: 'https://x.co/'));
        $this->assertSame('https://x.co/api/lacrm-webhook/', LacrmClient::webhookUrl(siteUrl: 'https://x.co'));
    }
}
