<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\tests\unit;

use anvildevxyz\cartograph\services\ProximityService;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the 0.10.3 fix where proximity SQL was keyed by the
 * field UID instead of the layout-element UID. Craft 5 stores `elements_sites.content`
 * keyed by *layout-element* UID; the same field handle can sit under different
 * layout-element UIDs in different entry types, so `extractLayoutElementUids`
 * must walk every layout config and collect every layout-element UID that
 * points at the target field UID.
 */
final class ProximityLayoutElementUidsTest extends TestCase
{
    private const FIELD_UID = 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa';
    private const OTHER_FIELD_UID = 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb';

    public function testReturnsSingleLayoutElementUid(): void
    {
        $config = [
            'tabs' => [[
                'elements' => [[
                    'uid' => 'le-1111-1111',
                    'fieldUid' => self::FIELD_UID,
                ]],
            ]],
        ];

        $uids = ProximityService::extractLayoutElementUids([json_encode($config)], self::FIELD_UID);

        self::assertSame(['le-1111-1111'], $uids);
    }

    public function testReturnsAllLayoutElementUidsAcrossLayouts(): void
    {
        // Same field handle attached under two different entry-type layouts.
        $layoutA = ['tabs' => [['elements' => [['uid' => 'le-A', 'fieldUid' => self::FIELD_UID]]]]];
        $layoutB = ['tabs' => [['elements' => [['uid' => 'le-B', 'fieldUid' => self::FIELD_UID]]]]];

        $uids = ProximityService::extractLayoutElementUids([
            json_encode($layoutA),
            json_encode($layoutB),
        ], self::FIELD_UID);

        sort($uids);
        self::assertSame(['le-A', 'le-B'], $uids);
    }

    public function testIgnoresUnrelatedFieldUids(): void
    {
        $config = [
            'tabs' => [[
                'elements' => [
                    ['uid' => 'le-target', 'fieldUid' => self::FIELD_UID],
                    ['uid' => 'le-other', 'fieldUid' => self::OTHER_FIELD_UID],
                ],
            ]],
        ];

        $uids = ProximityService::extractLayoutElementUids([json_encode($config)], self::FIELD_UID);

        self::assertSame(['le-target'], $uids);
    }

    public function testWalksMultipleTabs(): void
    {
        $config = [
            'tabs' => [
                ['elements' => [['uid' => 'le-tab1', 'fieldUid' => self::FIELD_UID]]],
                ['elements' => [['uid' => 'le-tab2', 'fieldUid' => self::FIELD_UID]]],
            ],
        ];

        $uids = ProximityService::extractLayoutElementUids([json_encode($config)], self::FIELD_UID);

        sort($uids);
        self::assertSame(['le-tab1', 'le-tab2'], $uids);
    }

    public function testDeduplicatesRepeatedLayoutElementUids(): void
    {
        // Defensive: if the same layout-element UID somehow appears twice, return it once.
        $config = [
            'tabs' => [[
                'elements' => [
                    ['uid' => 'le-dup', 'fieldUid' => self::FIELD_UID],
                    ['uid' => 'le-dup', 'fieldUid' => self::FIELD_UID],
                ],
            ]],
        ];

        $uids = ProximityService::extractLayoutElementUids([json_encode($config)], self::FIELD_UID);

        self::assertSame(['le-dup'], $uids);
    }

    public function testAcceptsAlreadyDecodedConfig(): void
    {
        // findLayoutElementUidsForField passes JSON strings, but the parser supports
        // pre-decoded arrays for callers that already have one in hand.
        $config = [
            'tabs' => [[
                'elements' => [['uid' => 'le-decoded', 'fieldUid' => self::FIELD_UID]],
            ]],
        ];

        $uids = ProximityService::extractLayoutElementUids([$config], self::FIELD_UID);

        self::assertSame(['le-decoded'], $uids);
    }

    public function testSkipsMalformedJson(): void
    {
        $good = ['tabs' => [['elements' => [['uid' => 'le-good', 'fieldUid' => self::FIELD_UID]]]]];

        $uids = ProximityService::extractLayoutElementUids([
            'not-json',
            json_encode($good),
            '{"this":"is":"broken"}',
        ], self::FIELD_UID);

        self::assertSame(['le-good'], $uids);
    }

    public function testSkipsConfigsWithoutTabs(): void
    {
        $uids = ProximityService::extractLayoutElementUids([
            json_encode(['unrelated' => 'shape']),
            json_encode(['tabs' => 'not-an-array']),
        ], self::FIELD_UID);

        self::assertSame([], $uids);
    }

    public function testSkipsElementsMissingUid(): void
    {
        $config = [
            'tabs' => [[
                'elements' => [
                    ['fieldUid' => self::FIELD_UID], // no uid key
                    ['uid' => 'le-valid', 'fieldUid' => self::FIELD_UID],
                ],
            ]],
        ];

        $uids = ProximityService::extractLayoutElementUids([json_encode($config)], self::FIELD_UID);

        self::assertSame(['le-valid'], $uids);
    }

    public function testReturnsEmptyForUnusedField(): void
    {
        $config = [
            'tabs' => [['elements' => [['uid' => 'le-x', 'fieldUid' => self::OTHER_FIELD_UID]]]],
        ];

        $uids = ProximityService::extractLayoutElementUids([json_encode($config)], self::FIELD_UID);

        self::assertSame([], $uids);
    }
}
