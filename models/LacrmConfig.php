<?php

declare(strict_types=1);

namespace Osmium\Services\Lacrm\Models;

/**
 * Less Annoying CRM settings, read from and saved to this service's own file
 * (app/config/services/osmium-lacrm.json.php). Nothing secret ships in the
 * service itself: every site enters its own API key.
 */
class LacrmConfig
{
    public const PATH = 'app/config/services/osmium-lacrm.json.php';
    private const HEADER = "<?php exit(); ?>\n";

    private static ?array $config = null;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'apiKey' => '',
            'userId' => '',
            'webhookSecret' => '',
            'handshakeOpenUntil' => 0,
            'pipelineId' => '',
            'wonStatusId' => '',
            'lostStatusId' => '',
        ];
    }

    /** @return array<string, mixed> */
    public static function get(): array
    {
        $cached = self::$config !== null;
        if ($cached) return self::$config;

        self::$config = self::read(path: self::PATH);

        return self::$config;
    }

    public static function clearCache(): void
    {
        self::$config = null;
    }

    /** @return array<string, mixed> The saved settings laid over the defaults; defaults if the file is missing or broken */
    public static function read(string $path): array
    {
        $fileExists = \file_exists($path);
        if (!$fileExists) return self::defaults();

        $content = (string) \file_get_contents($path);
        $jsonStart = \strpos(haystack: $content, needle: '{');
        $noJson = $jsonStart === false;
        if ($noJson) return self::defaults();

        $decoded = \json_decode(json: \substr(string: $content, offset: $jsonStart), associative: true);
        $saved = \is_array($decoded) ? ($decoded['lacrm'] ?? []) : [];

        return \array_merge(self::defaults(), \is_array($saved) ? $saved : []);
    }

    /** @param array<string, mixed> $values Merged over what is saved; unknown keys are dropped */
    public static function save(array $values, string $path = self::PATH): void
    {
        $merged = \array_merge(self::read(path: $path), $values);
        $known = \array_intersect_key($merged, self::defaults());

        $directory = \dirname($path);
        $missing = !\is_dir($directory);
        if ($missing) \mkdir(directory: $directory, permissions: 0755, recursive: true);

        $json = \json_encode(
            value: ['lacrm' => $known],
            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        \file_put_contents($path, self::HEADER . $json . "\n");

        self::clearCache();
    }

    /** @param array<string, mixed> $config */
    public static function hasApiKey(array $config): bool
    {
        return \trim((string) ($config['apiKey'] ?? '')) !== '';
    }

    /** @param array<string, mixed> $config On, with a key: the connection can be used */
    public static function isReady(array $config): bool
    {
        $enabled = !empty($config['enabled']);
        $hasKey = self::hasApiKey($config);

        return $enabled && $hasKey;
    }

    /** LACRM IDs are long digit strings; allow digits and dashes only so a pasted ID cannot carry anything else. */
    public static function isValidId(string $id): bool
    {
        return \preg_match(pattern: '/^[0-9A-Za-z_-]{1,80}$/', subject: $id) === 1;
    }
}
