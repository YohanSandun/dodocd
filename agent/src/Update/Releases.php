<?php

declare(strict_types=1);

namespace Dodo\Update;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Checks GitHub for the latest DodoCD release. Only the Updates page triggers a check; the result
 * is cached in settings, and the rest of the dashboard only reads the cache.
 */
final class Releases
{
    public const REPOSITORY = 'YohanSandun/dodocd';
    public const CHECK_EVERY_HOURS = 12;

    private const SETTING = 'update_check';

    /** @return array{checked_at: string, latest: ?array{version: string, name: string, url: string, package_url: ?string, published_at: ?string}, error: ?string}|null */
    public static function cached(PDO $pdo): ?array
    {
        $statement = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $statement->execute([self::SETTING]);
        $value = $statement->fetchColumn();
        $check = is_string($value) ? json_decode($value, true) : null;

        return is_array($check) ? $check : null;
    }

    /** The cached latest release if it's newer than the running version. */
    public static function newer(PDO $pdo): ?array
    {
        $latest = self::cached($pdo)['latest'] ?? null;

        return $latest !== null && version_compare($latest['version'], DODO_VERSION, '>') ? $latest : null;
    }

    public static function stale(?array $check): bool
    {
        return $check === null || strtotime($check['checked_at'] . ' UTC') < time() - self::CHECK_EVERY_HOURS * 3600;
    }

    /** Asks GitHub now and caches the answer, including a failure, so a broken check isn't retried on every page load. */
    public static function check(PDO $pdo): array
    {
        $check = ['checked_at' => dodo_now(), 'latest' => null, 'error' => null];
        try {
            $check['latest'] = self::latest();
        } catch (Throwable $e) {
            $check['error'] = $e->getMessage();
        }

        $pdo->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)')
            ->execute([self::SETTING, json_encode($check, JSON_UNESCAPED_SLASHES)]);

        return $check;
    }

    public static function pageUrl(): string
    {
        return 'https://github.com/' . self::REPOSITORY . '/releases';
    }

    private static function latest(): ?array
    {
        [$status, $body] = self::get('https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest');
        if ($status === 404) {
            return null; // no published release yet (or the repository isn't public)
        }
        $release = json_decode($body, true);
        if ($status !== 200 || !is_array($release) || !is_string($release['tag_name'] ?? null)) {
            throw new RuntimeException("GitHub answered with HTTP {$status}.");
        }

        $version = ltrim($release['tag_name'], 'v');
        $packageUrl = null;
        foreach ($release['assets'] ?? [] as $asset) {
            if (($asset['name'] ?? null) === "dodocd-{$version}.zip") {
                $packageUrl = $asset['browser_download_url'] ?? null;
            }
        }

        return [
            'version' => $version,
            'name' => (string) (($release['name'] ?? '') ?: $release['tag_name']),
            'url' => (string) ($release['html_url'] ?? self::pageUrl()),
            'package_url' => $packageUrl,
            'published_at' => $release['published_at'] ?? null,
        ];
    }

    /** @return array{int, string} HTTP status and body. */
    private static function get(string $url): array
    {
        $headers = ['Accept: application/vnd.github+json', 'User-Agent: DodoCD/' . DODO_VERSION];

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
            ]);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($body === false) {
                throw new RuntimeException("Could not reach GitHub: {$error}");
            }

            return [$status, (string) $body];
        }

        if (ini_get('allow_url_fopen')) {
            $context = stream_context_create(['http' => [
                'header' => implode("\r\n", $headers),
                'timeout' => 10,
                'ignore_errors' => true,
            ]]);
            $body = @file_get_contents($url, false, $context);
            if ($body === false) {
                throw new RuntimeException('Could not reach GitHub.');
            }
            preg_match('#^HTTP/\S+\s+(\d+)#', $http_response_header[0] ?? '', $matches);

            return [(int) ($matches[1] ?? 0), $body];
        }

        throw new RuntimeException('This server can\'t make outgoing requests: it has no curl extension and allow_url_fopen is off.');
    }
}
