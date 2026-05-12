<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\services;

use anvildevxyz\cartograph\Cartograph;
use anvildevxyz\cartograph\elements\behaviors\CartographProximityQueryBehavior;
use anvildevxyz\cartograph\elements\behaviors\ProximityDistanceBehavior;
use anvildevxyz\cartograph\fields\MapPointField;
use anvildevxyz\cartograph\helpers\HaversineSql;
use Craft;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\events\PopulateElementEvent;
use yii\base\Component;
use yii\db\Expression;

final class ProximityService extends Component
{
    public const ALIAS_DEFAULT = 'distance';

    public const RADIUS_HARD_CAP_KM = 20015.0;

    private const ALIAS_RE = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var array<string, true>|null */
    private ?array $mapPointHandlesCache = null;

    /**
     * @param array<string, mixed> $input
     * @return array{lat: float, lng: float, radius: float, orderByDistance: bool, as: string}
     */
    public static function parseAndValidate(array $input, ?float $maxRadiusKm = null): array
    {
        $near = $input['near'] ?? null;
        if (!is_array($near) || count($near) !== 2 || !is_numeric($near[0] ?? null) || !is_numeric($near[1] ?? null)) {
            throw new \InvalidArgumentException('Cartograph proximity requires near: [lat, lng].');
        }
        $lat = (float) $near[0];
        $lng = (float) $near[1];
        if ($lat < -90.0 || $lat > 90.0) {
            throw new \InvalidArgumentException("Latitude out of range: {$lat}.");
        }
        if ($lng < -180.0 || $lng > 180.0) {
            throw new \InvalidArgumentException("Longitude out of range: {$lng}.");
        }

        $radius = $input['radius'] ?? null;
        if (!is_numeric($radius) || (float) $radius <= 0.0) {
            throw new \InvalidArgumentException('Radius must be > 0.');
        }
        $cap = ($maxRadiusKm !== null && $maxRadiusKm > 0.0)
            ? min($maxRadiusKm, self::RADIUS_HARD_CAP_KM)
            : self::RADIUS_HARD_CAP_KM;
        if ((float) $radius > $cap) {
            throw new \InvalidArgumentException('Radius too large; use a sensible value.');
        }

        $as = (string) ($input['as'] ?? self::ALIAS_DEFAULT);
        if (!preg_match(self::ALIAS_RE, $as)) {
            throw new \InvalidArgumentException("Alias must be a PHP identifier: '{$as}'.");
        }

        return [
            'lat' => $lat,
            'lng' => $lng,
            'radius' => (float) $radius,
            'orderByDistance' => (bool) ($input['orderByDistance'] ?? false),
            'as' => $as,
        ];
    }

    /**
     * @param array{0: float, 1: float} $near
     */
    public static function buildInput(array $near, float $radius, ?string $as = null, bool $orderByDistance = false): array
    {
        $out = ['near' => $near, 'radius' => $radius, 'orderByDistance' => $orderByDistance];
        if ($as !== null && $as !== '') {
            $out['as'] = $as;
        }

        return $out;
    }

    /**
     * @param array{lat: float, lng: float, radius: float, orderByDistance: bool, as: string} $parsed
     */
    public function applyToQuery(ElementQuery $query, string $fieldHandle, array $parsed): void
    {
        $field = $this->resolveField($fieldHandle);
        $columnExpr = $this->resolveColumnExpr($field);
        $driver = $this->resolveDriver();

        /** @var CartographProximityQueryBehavior $behavior */
        $behavior = $query->getBehavior(CartographProximityQueryBehavior::BEHAVIOR_KEY)
            ?? $query->attachBehavior(CartographProximityQueryBehavior::BEHAVIOR_KEY, new CartographProximityQueryBehavior());
        $behavior->stampProximityAlias($parsed['as'], $fieldHandle);

        [$latExpr, $lngExpr] = HaversineSql::buildExtractedCoords($driver, $columnExpr);
        $distanceExpr = HaversineSql::buildDistanceExpression($latExpr, $lngExpr, $parsed['lat'], $parsed['lng']);
        $bbox = HaversineSql::buildBboxConditions($parsed['lat'], $parsed['lng'], $parsed['radius']);

        $alias = $parsed['as'];
        $query->addSelect([$alias => new Expression($distanceExpr)]);

        $query->andWhere([
            'and',
            new Expression("{$latExpr} >= :cartograph_lat_min_{$alias}", [":cartograph_lat_min_{$alias}" => $bbox['latMin']]),
            new Expression("{$latExpr} <= :cartograph_lat_max_{$alias}", [":cartograph_lat_max_{$alias}" => $bbox['latMax']]),
        ]);

        $lngOr = ['or'];
        foreach ($bbox['lngRanges'] as $i => [$min, $max]) {
            $minKey = ":cartograph_lng_min_{$alias}_{$i}";
            $maxKey = ":cartograph_lng_max_{$alias}_{$i}";
            $lngOr[] = [
                'and',
                new Expression("{$lngExpr} >= {$minKey}", [$minKey => $min]),
                new Expression("{$lngExpr} <= {$maxKey}", [$maxKey => $max]),
            ];
        }
        $query->andWhere($lngOr);

        $radiusKey = ":cartograph_radius_{$alias}";
        $query->andWhere(new Expression("{$distanceExpr} <= {$radiusKey}", [$radiusKey => $parsed['radius']]));

        if ($parsed['orderByDistance']) {
            $query->addOrderBy([new Expression($distanceExpr . ' ASC')]);
        }
    }

    public function applyPendingProximityArgs(ElementQuery $query): void
    {
        $behavior = $query->getBehavior('customFields');
        if ($behavior === null) {
            return;
        }

        $handles = $this->loadMapPointHandles();
        if ($handles === []) {
            return;
        }

        $cap = $this->configuredMaxRadiusKm();
        foreach ($handles as $handle => $_) {
            $value = $behavior->{$handle} ?? null;
            if (!is_array($value) || !isset($value['lat'], $value['lng'], $value['radius'])) {
                continue;
            }

            $parsed = self::parseAndValidate([
                'near' => [$value['lat'], $value['lng']],
                'radius' => $value['radius'],
                'as' => $value['as'] ?? null,
                'orderByDistance' => $value['orderByDistance'] ?? false,
            ], $cap);
            $this->applyToQuery($query, $handle, $parsed);

            $behavior->{$handle} = null;
        }
    }

    /** @return array<string, true> */
    private function loadMapPointHandles(): array
    {
        if ($this->mapPointHandlesCache !== null) {
            return $this->mapPointHandlesCache;
        }

        $handles = [];
        try {
            foreach (Craft::$app->getFields()->getAllFields() as $field) {
                if ($field instanceof MapPointField && is_string($field->handle) && $field->handle !== '') {
                    $handles[$field->handle] = true;
                }
            }
        } catch (\Throwable) {
        }

        return $this->mapPointHandlesCache = $handles;
    }

    public function captureDistance(PopulateElementEvent $event): void
    {
        /** @var ElementQuery $query */
        $query = $event->sender;
        /** @var CartographProximityQueryBehavior|null $behavior */
        $behavior = $query->getBehavior(CartographProximityQueryBehavior::BEHAVIOR_KEY);
        if ($behavior === null || $behavior->proximityAliases === []) {
            return;
        }

        $event->row = self::stripAndStashAliases($event->row ?? [], $behavior);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function stripAndStashAliases(array $row, CartographProximityQueryBehavior $behavior): array
    {
        if (!isset($row['id'])) {
            return $row;
        }

        $captured = [];
        foreach ($behavior->proximityAliases as $alias => $_handle) {
            if (!array_key_exists($alias, $row)) {
                continue;
            }
            $val = $row[$alias];
            unset($row[$alias]);
            if (is_numeric($val)) {
                $captured[$alias] = (float) $val;
            }
        }

        if ($captured !== []) {
            $behavior->pendingDistances[(int) $row['id']] = $captured;
        }

        return $row;
    }

    public function hydrateDistance(PopulateElementEvent $event): void
    {
        /** @var ElementQuery $query */
        $query = $event->sender;
        /** @var CartographProximityQueryBehavior|null $behavior */
        $behavior = $query->getBehavior(CartographProximityQueryBehavior::BEHAVIOR_KEY);
        if ($behavior === null || $behavior->pendingDistances === []) {
            return;
        }

        $elementId = (int) ($event->element->id ?? 0);
        if (!isset($behavior->pendingDistances[$elementId])) {
            return;
        }

        $distances = $behavior->pendingDistances[$elementId];
        unset($behavior->pendingDistances[$elementId]);

        $event->element->attachBehavior(
            ProximityDistanceBehavior::BEHAVIOR_KEY,
            new ProximityDistanceBehavior(['distances' => $distances]),
        );
    }

    public function configuredMaxRadiusKm(): float
    {
        $configured = (float) (Cartograph::getInstance()->getSettings()->proximityMaxRadiusKm ?? self::RADIUS_HARD_CAP_KM);

        return $configured <= 0.0 ? self::RADIUS_HARD_CAP_KM : min($configured, self::RADIUS_HARD_CAP_KM);
    }

    private function resolveField(string $handle): MapPointField
    {
        $field = Craft::$app->getFields()->getFieldByHandle($handle);
        if (!$field instanceof MapPointField) {
            throw new \InvalidArgumentException("Cartograph proximity: '{$handle}' is not a Map Point field.");
        }

        return $field;
    }

    private function resolveColumnExpr(MapPointField $field): string
    {
        $sql = $field->getValueSql();
        if (is_string($sql) && $sql !== '') {
            return $sql;
        }

        $uids = self::findLayoutElementUidsForField($field->uid);
        if ($uids === []) {
            throw new \RuntimeException("Cartograph proximity: field '{$field->handle}' is not used in any field layout.");
        }

        $db = Craft::$app->getDb();
        $col = $db->quoteTableName('elements_sites') . '.' . $db->quoteColumnName('content');
        $driver = $this->resolveDriver();

        $exprs = array_map(
            static fn(string $uid): string => HaversineSql::buildContentValueSql($driver, $col, $uid),
            $uids,
        );

        return count($exprs) === 1 ? $exprs[0] : 'COALESCE(' . implode(', ', $exprs) . ')';
    }

    /**
     * @return list<string>
     */
    public static function findLayoutElementUidsForField(string $fieldUid): array
    {
        $rows = (new Query())
            ->select(['config'])
            ->from('{{%fieldlayouts}}')
            ->where(['like', 'config', $fieldUid])
            ->column();

        return self::extractLayoutElementUids($rows, $fieldUid);
    }

    /**
     * @param list<string|array<string, mixed>> $configs
     * @return list<string>
     */
    public static function extractLayoutElementUids(array $configs, string $fieldUid): array
    {
        $uids = [];
        foreach ($configs as $config) {
            if (is_string($config)) {
                $config = json_decode($config, true);
            }
            if (!is_array($config)) {
                continue;
            }
            $tabs = $config['tabs'] ?? [];
            if (!is_array($tabs)) {
                continue;
            }
            foreach ($tabs as $tab) {
                if (!is_array($tab)) {
                    continue;
                }
                $elements = $tab['elements'] ?? [];
                if (!is_array($elements)) {
                    continue;
                }
                foreach ($elements as $element) {
                    if (!is_array($element)) {
                        continue;
                    }
                    if (
                        ($element['fieldUid'] ?? null) === $fieldUid
                        && isset($element['uid'])
                        && is_string($element['uid'])
                    ) {
                        $uids[$element['uid']] = true;
                    }
                }
            }
        }

        return array_keys($uids);
    }

    private function resolveDriver(): string
    {
        $driverName = Craft::$app->getDb()->getDriverName();

        return match (strtolower((string) $driverName)) {
            'mysql', 'mariadb' => HaversineSql::DRIVER_MYSQL,
            'pgsql', 'postgres', 'postgresql' => HaversineSql::DRIVER_PGSQL,
            default => throw new \RuntimeException(
                'Cartograph proximity requires MySQL ≥ 8.0.17 or PostgreSQL ≥ 13; got driver: ' . $driverName,
            ),
        };
    }
}
