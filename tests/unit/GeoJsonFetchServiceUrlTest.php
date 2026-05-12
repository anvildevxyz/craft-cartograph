<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\tests\unit;

use anvildevxyz\cartograph\services\GeoJsonFetchService;
use PHPUnit\Framework\TestCase;

final class GeoJsonFetchServiceUrlTest extends TestCase
{
    public function testRejectsEmptyUrl(): void
    {
        $r = GeoJsonFetchService::validateUrlString('');
        self::assertFalse($r['ok']);
        self::assertSame('empty', $r['error']);
    }

    public function testRejectsHttp(): void
    {
        $r = GeoJsonFetchService::validateUrlString('http://example.org/data.geojson');
        self::assertFalse($r['ok']);
        self::assertSame('not_https', $r['error']);
    }

    public function testRejectsTooLongUrl(): void
    {
        $url = 'https://example.org/' . str_repeat('a', 2100);
        $r = GeoJsonFetchService::validateUrlString($url);
        self::assertFalse($r['ok']);
        self::assertSame('too_long', $r['error']);
    }

    public function testRejectsLocalhost(): void
    {
        $r = GeoJsonFetchService::validateUrlString('https://localhost/data.geojson');
        self::assertFalse($r['ok']);
        self::assertSame('host_blocked', $r['error']);
    }

    public function testRejectsLocalhostWithTrailingDot(): void
    {
        // 'localhost.' is the FQDN form — must hit the same allow-deny rule
        // as 'localhost' (rtrim of trailing dot in validateUrlString).
        $r = GeoJsonFetchService::validateUrlString('https://localhost./data.geojson');
        self::assertFalse($r['ok']);
        self::assertSame('host_blocked', $r['error']);
    }

    public function testRejectsBlockedSuffixWithTrailingDot(): void
    {
        $r = GeoJsonFetchService::validateUrlString('https://compute.metadata.google.internal./foo');
        self::assertFalse($r['ok']);
        self::assertSame('host_blocked', $r['error']);
    }

    public function testRejectsCloudMetadataHostnames(): void
    {
        foreach (['metadata.google.internal', 'metadata.azure.com', 'instance-data.ec2.internal'] as $h) {
            $r = GeoJsonFetchService::validateUrlString("https://{$h}/x");
            self::assertFalse($r['ok'], "Expected {$h} to be blocked");
            self::assertSame('host_blocked', $r['error']);
        }
    }

    public function testRejectsSubdomainOfBlockedHost(): void
    {
        $r = GeoJsonFetchService::validateUrlString('https://compute.metadata.google.internal/foo');
        self::assertFalse($r['ok']);
        self::assertSame('host_blocked', $r['error']);
    }

    public function testRejectsLiteralPrivateIp(): void
    {
        foreach (['10.0.0.1', '192.168.1.1', '172.16.0.1', '127.0.0.1', '169.254.169.254'] as $ip) {
            $r = GeoJsonFetchService::validateUrlString("https://{$ip}/data.geojson");
            self::assertFalse($r['ok'], "Expected {$ip} to be blocked");
            self::assertSame('private_ip', $r['error']);
        }
    }

    public function testRejectsLiteralIpv6PrivateRanges(): void
    {
        foreach (['::1', 'fc00::1', 'fe80::1'] as $ip) {
            $r = GeoJsonFetchService::validateUrlString("https://[{$ip}]/data.geojson");
            self::assertFalse($r['ok'], "Expected {$ip} to be blocked");
            self::assertSame('private_ip', $r['error']);
        }
    }

    public function testAcceptsPublicHostname(): void
    {
        $r = GeoJsonFetchService::validateUrlString('https://example.org:8443/data.geojson');
        self::assertTrue($r['ok']);
        self::assertSame('example.org', $r['host']);
        self::assertSame(8443, $r['port']);
    }

    public function testRejectsNonStandardPorts(): void
    {
        // Defense in depth: prevents using the importer to fingerprint internal
        // service ports (Redis, SSH, SMTP, …) from the server's egress IP.
        foreach ([22, 25, 3306, 6379, 11211, 9200, 8080] as $port) {
            $r = GeoJsonFetchService::validateUrlString("https://example.org:{$port}/x");
            self::assertFalse($r['ok'], "Expected port {$port} to be blocked");
            self::assertSame('port_blocked', $r['error']);
        }
    }

    public function testDefaultPortIs443(): void
    {
        $r = GeoJsonFetchService::validateUrlString('https://example.org/data.geojson');
        self::assertTrue($r['ok']);
        self::assertSame(443, $r['port']);
    }

    public function testIsHostnameBlockedHandlesEmpty(): void
    {
        self::assertTrue(GeoJsonFetchService::isHostnameBlocked(''));
    }

    public function testIsIpBlockedRejectsAliyunMetadata(): void
    {
        self::assertTrue(GeoJsonFetchService::isIpBlocked('100.100.100.200'));
    }

    public function testIsIpBlockedAcceptsPublicIp(): void
    {
        self::assertFalse(GeoJsonFetchService::isIpBlocked('8.8.8.8'));
    }

    public function testIsIpBlockedRejectsBogusIp(): void
    {
        self::assertTrue(GeoJsonFetchService::isIpBlocked('not-an-ip'));
    }
}
