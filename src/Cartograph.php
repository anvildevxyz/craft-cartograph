<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph;

use anvildevxyz\cartograph\fields\MapGeojsonField;
use anvildevxyz\cartograph\fields\MapPointField;
use anvildevxyz\cartograph\models\Settings;
use anvildevxyz\cartograph\services\GeoJsonFetchService;
use anvildevxyz\cartograph\services\MapConfigService;
use anvildevxyz\cartograph\services\ProximityService;
use anvildevxyz\cartograph\variables\CartographVariable;
use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\db\ElementQuery;
use craft\events\DefineGqlTypeFieldsEvent;
use craft\events\PopulateElementEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\gql\TypeManager;
use craft\services\Fields;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\View;
use GraphQL\Type\Definition\Type;
use yii\base\Event;

/**
 * @property-read MapConfigService $mapConfig
 * @property-read GeoJsonFetchService $geoJsonFetch
 * @property-read ProximityService $proximity
 * @method static Cartograph getInstance()
 */
class Cartograph extends Plugin
{
    /** @event DefineEmbedPresetsEvent */
    public const EVENT_DEFINE_EMBED_PRESETS = 'defineEmbedPresets';

    /** @event DefineEmbedClientConfigEvent */
    public const EVENT_DEFINE_EMBED_CLIENT_CONFIG = 'defineEmbedClientConfig';

    public const PERMISSION_IMPORT_GEOJSON = 'cartograph-importGeojson';

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'mapConfig' => MapConfigService::class,
                'geoJsonFetch' => GeoJsonFetchService::class,
                'proximity' => ProximityService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, static function(Event $event): void {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('cartograph', CartographVariable::class);
        });

        Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, function(RegisterTemplateRootsEvent $event): void {
            $dir = $this->getBasePath() . DIRECTORY_SEPARATOR . 'templates';
            if (is_dir($dir)) {
                $event->roots[$this->handle] = $dir;
            }
        });

        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, static function(RegisterComponentTypesEvent $event): void {
            array_push($event->types, MapPointField::class, MapGeojsonField::class);
        });

        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, static function(RegisterUserPermissionsEvent $event): void {
            $event->permissions[] = [
                'heading' => Craft::t('cartograph', 'Cartograph'),
                'permissions' => [
                    self::PERMISSION_IMPORT_GEOJSON => [
                        'label' => Craft::t('cartograph', 'Import GeoJSON from a URL'),
                    ],
                ],
            ];
        });

        $this->registerProximityHooks();
    }

    public static function displayName(): string
    {
        return Craft::t('cartograph', 'Cartograph');
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    public function getSettings(): Settings
    {
        /** @var Settings $settings */
        $settings = parent::getSettings();
        return $settings;
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('cartograph/settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    private function registerProximityHooks(): void
    {
        Event::on(ElementQuery::class, ElementQuery::EVENT_BEFORE_POPULATE_ELEMENT, static function(PopulateElementEvent $event): void {
            self::getInstance()->proximity->captureDistance($event);
        });

        Event::on(ElementQuery::class, ElementQuery::EVENT_AFTER_POPULATE_ELEMENT, static function(PopulateElementEvent $event): void {
            self::getInstance()->proximity->hydrateDistance($event);
        });

        Event::on(ElementQuery::class, ElementQuery::EVENT_BEFORE_PREPARE, static function(Event $event): void {
            /** @var ElementQuery $query */
            $query = $event->sender;
            self::getInstance()->proximity->applyPendingProximityArgs($query);
        });

        Event::on(TypeManager::class, TypeManager::EVENT_DEFINE_GQL_TYPE_FIELDS, static function(DefineGqlTypeFieldsEvent $event): void {
            if (!isset($event->fields['id']) || isset($event->fields['distance'])) {
                return;
            }
            $event->fields['distance'] = [
                'name' => 'distance',
                'type' => Type::float(),
                'description' => 'Distance in km from the proximity anchor (when a Cartograph proximity argument is in scope).',
                'resolve' => static function($source) {
                    if (!is_object($source)) {
                        return null;
                    }
                    $has = method_exists($source, 'hasProperty') ? $source->hasProperty('distance') : isset($source->distance);
                    return $has && is_numeric($source->distance) ? (float) $source->distance : null;
                },
            ];
        });
    }
}
