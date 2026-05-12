<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\tests\unit;

use anvildevxyz\cartograph\elements\behaviors\CartographProximityQueryBehavior;
use anvildevxyz\cartograph\services\ProximityService;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the populate-event ordering bug fixed in 0.10.1:
 * Craft's `createElement()` runs `new $class($row)` before any
 * EVENT_AFTER_POPULATE_ELEMENT listener can intervene, so unknown alias
 * keys in the row trigger UnknownPropertyException. ProximityService now
 * strips them in EVENT_BEFORE_POPULATE_ELEMENT and stashes their values
 * for hydrateDistance. This suite locks the row-mutation contract.
 */
final class ProximityRowStripTest extends TestCase
{
    public function testStripsAliasAndStashesNumericValue(): void
    {
        $behavior = new CartographProximityQueryBehavior();
        $behavior->proximityAliases = ['distance' => 'venueMap'];

        $row = ['id' => 42, 'title' => 'X', 'distance' => '12.5'];
        $stripped = ProximityService::stripAndStashAliases($row, $behavior);

        self::assertArrayNotHasKey('distance', $stripped);
        self::assertSame(['id' => 42, 'title' => 'X'], $stripped);
        self::assertSame([42 => ['distance' => 12.5]], $behavior->pendingDistances);
    }

    public function testStripsMultipleAliases(): void
    {
        $behavior = new CartographProximityQueryBehavior();
        $behavior->proximityAliases = [
            'distance' => 'venueMap',
            'mailingDistance' => 'mailingMap',
        ];

        $row = ['id' => 7, 'distance' => 0.0, 'mailingDistance' => 3.14];
        $stripped = ProximityService::stripAndStashAliases($row, $behavior);

        self::assertSame(['id' => 7], $stripped);
        self::assertSame([7 => ['distance' => 0.0, 'mailingDistance' => 3.14]], $behavior->pendingDistances);
    }

    public function testNonNumericValueIsStrippedButNotStashed(): void
    {
        $behavior = new CartographProximityQueryBehavior();
        $behavior->proximityAliases = ['distance' => 'venueMap'];

        $row = ['id' => 1, 'distance' => 'NaN'];
        $stripped = ProximityService::stripAndStashAliases($row, $behavior);

        self::assertArrayNotHasKey('distance', $stripped);
        self::assertSame([], $behavior->pendingDistances);
    }

    public function testNullValueIsStrippedButNotStashed(): void
    {
        $behavior = new CartographProximityQueryBehavior();
        $behavior->proximityAliases = ['distance' => 'venueMap'];

        $row = ['id' => 1, 'distance' => null];
        $stripped = ProximityService::stripAndStashAliases($row, $behavior);

        self::assertArrayNotHasKey('distance', $stripped);
        self::assertSame([], $behavior->pendingDistances);
    }

    public function testRowWithoutAliasKeyIsUnchanged(): void
    {
        $behavior = new CartographProximityQueryBehavior();
        $behavior->proximityAliases = ['distance' => 'venueMap'];

        $row = ['id' => 1, 'title' => 'no distance here'];
        $stripped = ProximityService::stripAndStashAliases($row, $behavior);

        self::assertSame($row, $stripped);
        self::assertSame([], $behavior->pendingDistances);
    }

    public function testRowWithoutIdIsReturnedUnchanged(): void
    {
        $behavior = new CartographProximityQueryBehavior();
        $behavior->proximityAliases = ['distance' => 'venueMap'];

        $row = ['title' => 'orphan', 'distance' => 5.0];
        $stripped = ProximityService::stripAndStashAliases($row, $behavior);

        self::assertSame($row, $stripped);
        self::assertSame([], $behavior->pendingDistances);
    }
}
