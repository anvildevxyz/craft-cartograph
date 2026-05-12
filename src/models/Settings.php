<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\models;

use Craft;
use craft\base\Model;

class Settings extends Model
{
    public string $defaultStyleUrl = 'https://tiles.openfreemap.org/styles/bright';

    public float $defaultLng = 8.5417;

    public float $defaultLat = 47.3769;

    public int $defaultZoom = 10;

    public string $maplibreSelfHostBase = '';

    public int $indexThumbnailLimit = 12;

    public float $proximityMaxRadiusKm = 1000.0;

    public function rules(): array
    {
        return [
            [['defaultStyleUrl', 'maplibreSelfHostBase'], 'string'],
            [['defaultStyleUrl', 'maplibreSelfHostBase'], 'validateHttpsUrlOrAbsolutePath', 'skipOnEmpty' => true],
            [['defaultLng'], 'number', 'min' => -180, 'max' => 180],
            [['defaultLat'], 'number', 'min' => -85, 'max' => 85],
            [['defaultZoom'], 'integer', 'min' => 0, 'max' => 22],
            [['indexThumbnailLimit'], 'integer', 'min' => 1, 'max' => 64],
            [['proximityMaxRadiusKm'], 'number', 'min' => 1, 'max' => 20015],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'defaultStyleUrl' => Craft::t('cartograph', 'Default style URL'),
            'defaultLng' => Craft::t('cartograph', 'Default longitude'),
            'defaultLat' => Craft::t('cartograph', 'Default latitude'),
            'defaultZoom' => Craft::t('cartograph', 'Default zoom'),
            'maplibreSelfHostBase' => Craft::t('cartograph', 'Self-hosted MapLibre asset base'),
        ];
    }

    public function getMaplibreSelfHostBaseTrimmed(): string
    {
        return rtrim(trim($this->maplibreSelfHostBase), '/');
    }

    public function validateHttpsUrlOrAbsolutePath(string $attribute): void
    {
        $value = trim((string) $this->$attribute);
        if ($value === '') {
            return;
        }

        if (str_starts_with($value, '//')) {
            $this->addError($attribute, Craft::t('cartograph', 'Must be an https:// URL or an absolute path starting with /.'));
            return;
        }

        if ($value[0] === '/') {
            if (!preg_match('#^/[A-Za-z0-9._/~\-]*$#', $value)) {
                $this->addError($attribute, Craft::t('cartograph', 'Absolute path may only contain letters, digits, and the characters . _ - / ~.'));
            }
            return;
        }

        if (!preg_match('#^https://#i', $value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            $this->addError($attribute, Craft::t('cartograph', 'Must be an https:// URL or an absolute path starting with /.'));
        }
    }
}
