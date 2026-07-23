<?php

namespace site7\studio\models\marketplace;

use craft\base\Model;

/**
 * A single catalog entry returned by a MarketplaceRepositoryInterface - one
 * distributable .s7pkg file and the metadata needed to display and fetch it,
 * without having to fully validate/extract it first.
 */
class MarketplaceListing extends Model
{
    public ?string $handle = null;
    public ?string $type = null;
    public string $version = '0.0.0';
    public ?string $checksum = null;

    /** Absolute filesystem path to the .s7pkg file. */
    public string $filePath = '';
    public string $fileName = '';
    public int $size = 0;

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['handle', 'type'], 'required'];
        $rules[] = [['handle', 'type', 'version', 'checksum', 'filePath', 'fileName'], 'string'];
        $rules[] = [['size'], 'integer'];
        return $rules;
    }
}
