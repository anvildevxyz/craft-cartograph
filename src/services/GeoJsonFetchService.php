<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\services;

use anvildevxyz\cartograph\Cartograph;
use anvildevxyz\cartograph\helpers\GeoJsonHelper;
use Craft;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\HttpFoundation\IpUtils;
use yii\base\Component;

final class GeoJsonFetchService extends Component
{
    public const BLOCKED_HOSTNAMES = [
        'localhost',
        'metadata.google.internal',
        'metadata.google',
        'metadata.azure.com',
        'metadata.aws.internal',
        'instance-data.ec2.internal',
        'metadata.packet.net',
        'metadata.tencentyun.com',
    ];

    public const BLOCKED_IPS = [
        '169.254.169.254',
        '100.100.100.200',
        'fd00:ec2::254',
    ];

    public const ALLOWED_PORTS = [443, 8443];

    /** @return array{ok: bool, featureCollection: ?array, error: ?string} */
    public function fetchFromUrl(string $url, int $maxBytes, int $maxFeatures): array
    {
        $check = self::validateUrlString($url);
        if ($check['ok'] === false) {
            return $this->err(self::translateValidationError($check['error']));
        }

        $resolution = self::resolveAndValidate($check['host']);
        if ($resolution['ok'] === false) {
            return $this->err(self::translateValidationError($resolution['error']));
        }

        $safeMaxBytes = max(4096, min(5 * 1024 * 1024, $maxBytes));
        $safeMaxFeatures = max(1, min(5000, $maxFeatures));

        $client = Craft::createGuzzleClient([
            'timeout' => 15,
            'connect_timeout' => 8,
            'headers' => [
                'Accept' => 'application/json, application/geo+json, */*;q=0.1',
                'User-Agent' => $this->userAgent(),
            ],
            'http_errors' => false,
            'allow_redirects' => false,
        ]);

        try {
            $response = $client->request('GET', $url, [
                'curl' => [
                    CURLOPT_RESOLVE => self::buildResolveOptions($check['host'], $check['port'], $resolution['ips']),
                ],
            ]);
        } catch (GuzzleException $e) {
            Craft::warning('Cartograph GeoJSON fetch failed: ' . $e->getMessage(), __METHOD__);

            return $this->err(Craft::t('cartograph', 'Request failed.'));
        }

        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            return $this->err(Craft::t('cartograph', 'Redirects are not followed; paste the canonical HTTPS URL that returns GeoJSON JSON directly.'));
        }
        if ($status < 200 || $status >= 300) {
            return $this->err(Craft::t('cartograph', 'Remote server returned an error ({code}).', ['code' => (string) $status]));
        }

        $claimed = $response->getHeaderLine('Content-Length');
        if ($claimed !== '' && is_numeric($claimed) && (int) $claimed > $safeMaxBytes) {
            return $this->err(Craft::t('cartograph', 'Remote file is too large.'));
        }

        $stream = $response->getBody();
        $accum = '';
        try {
            while (!$stream->eof()) {
                $accum .= $stream->read(8192);
                if (strlen($accum) > $safeMaxBytes) {
                    return $this->err(Craft::t('cartograph', 'Download exceeded the size limit.'));
                }
            }
        } catch (\Throwable $e) {
            Craft::warning('Cartograph GeoJSON stream read: ' . $e->getMessage(), __METHOD__);

            return $this->err(Craft::t('cartograph', 'Could not read the response.'));
        }

        $decoded = json_decode($accum, true);
        if (!is_array($decoded)) {
            return $this->err(Craft::t('cartograph', 'Response was not JSON.'));
        }

        $fc = GeoJsonHelper::normalizeToFeatureCollection($decoded);
        if ($fc === null || ($fc['features'] ?? []) === []) {
            return $this->err(Craft::t('cartograph', 'GeoJSON must be parseable into a FeatureCollection with at least one feature.'));
        }

        $errors = GeoJsonHelper::validationErrors($fc, $safeMaxFeatures);
        if ($errors !== []) {
            return $this->err((string) $errors[0]);
        }

        return ['ok' => true, 'featureCollection' => $fc, 'error' => null];
    }

    /**
     * @return array{ok: true, host: string, port: int}|array{ok: false, host: null, port: null, error: string}
     */
    public static function validateUrlString(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return self::validationFail('empty');
        }
        if (strlen($url) > 2048) {
            return self::validationFail('too_long');
        }
        if (!preg_match('#^https://#i', $url)) {
            return self::validationFail('not_https');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return self::validationFail('invalid');
        }

        $rawHost = strtolower((string) $parts['host']);
        $host = (str_starts_with($rawHost, '[') && str_ends_with($rawHost, ']'))
            ? substr($rawHost, 1, -1)
            : rtrim($rawHost, '.');
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;

        if (!in_array($port, self::ALLOWED_PORTS, true)) {
            return self::validationFail('port_blocked');
        }
        if (self::isHostnameBlocked($host)) {
            return self::validationFail('host_blocked');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) && self::isIpBlocked($host)) {
            return self::validationFail('private_ip');
        }

        return ['ok' => true, 'host' => $host, 'port' => $port];
    }

    public static function isHostnameBlocked(string $host): bool
    {
        if ($host === '') {
            return true;
        }
        foreach (self::BLOCKED_HOSTNAMES as $b) {
            if ($host === $b || str_ends_with($host, '.' . $b)) {
                return true;
            }
        }

        return false;
    }

    public static function isIpBlocked(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }
        foreach (self::BLOCKED_IPS as $blocked) {
            if (strcasecmp($ip, $blocked) === 0) {
                return true;
            }
        }

        return IpUtils::checkIp($ip, IpUtils::PRIVATE_SUBNETS);
    }

    /**
     * @return array{ok: true, ips: list<string>}|array{ok: false, ips: null, error: string}
     */
    public static function resolveAndValidate(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ['ok' => true, 'ips' => [$host]];
        }

        /** @var list<array<string, mixed>>|false $answers */
        $answers = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($answers === false || $answers === []) {
            return ['ok' => false, 'ips' => null, 'error' => 'dns_unsafe'];
        }

        $ips = [];
        foreach ($answers as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ip = match ($row['type'] ?? '') {
                'A' => isset($row['ip']) ? (string) $row['ip'] : '',
                'AAAA' => isset($row['ipv6']) ? (string) $row['ipv6'] : '',
                default => '',
            };
            if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            if (self::isIpBlocked($ip)) {
                return ['ok' => false, 'ips' => null, 'error' => 'private_ip'];
            }
            $ips[] = $ip;
        }

        if ($ips === []) {
            return ['ok' => false, 'ips' => null, 'error' => 'dns_unsafe'];
        }

        return ['ok' => true, 'ips' => $ips];
    }

    /**
     * @param list<string> $ips
     * @return list<string>
     */
    public static function buildResolveOptions(string $host, int $port, array $ips): array
    {
        return array_map(static fn(string $ip): string => "{$host}:{$port}:{$ip}", $ips);
    }

    private static function validationFail(string $code): array
    {
        return ['ok' => false, 'host' => null, 'port' => null, 'error' => $code];
    }

    private static function translateValidationError(string $code): string
    {
        return match ($code) {
            'empty' => Craft::t('cartograph', 'URL is empty.'),
            'too_long' => Craft::t('cartograph', 'URL is too long.'),
            'not_https' => Craft::t('cartograph', 'Only https:// URLs are allowed.'),
            'host_blocked' => Craft::t('cartograph', 'This host is not allowed.'),
            'private_ip' => Craft::t('cartograph', 'Private or loopback addresses are not allowed.'),
            'port_blocked' => Craft::t('cartograph', 'Only standard HTTPS ports (443, 8443) are allowed.'),
            'dns_unsafe' => Craft::t('cartograph', 'Could not verify a safe DNS target for this host.'),
            default => Craft::t('cartograph', 'Invalid URL.'),
        };
    }

    /**
     * @return array{ok: bool, featureCollection: null, error: string}
     */
    private function err(string $msg): array
    {
        return ['ok' => false, 'featureCollection' => null, 'error' => $msg];
    }

    private function userAgent(): string
    {
        try {
            $base = (string) Craft::$app->getSites()->getPrimarySite()->getBaseUrl(true);
            $host = parse_url($base !== '' ? $base : 'craftcms', PHP_URL_HOST);
            $siteHost = is_string($host) && $host !== '' ? mb_substr($host, 0, 120) : 'craftcms';
        } catch (\Throwable) {
            $siteHost = 'craftcms';
        }

        return sprintf('%s (+Cartograph/%s; GeoJSON-import)', $siteHost, Cartograph::getInstance()->getVersion() ?: 'unknown');
    }
}
