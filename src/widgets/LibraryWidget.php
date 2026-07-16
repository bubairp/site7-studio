<?php

namespace site7\studio\widgets;

use Craft;
use craft\base\Widget;

class LibraryWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('site7-studio', 'Site7 Studio Library');
    }

    public static function iconPath(): ?string
    {
        return Craft::getAlias('@site7/studio/icon.svg');
    }

    public function getBodyHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'site7-studio/widgets/library/body',
            [
                'widget' => $this,
                'version' => '1.0.0',
                'platformStatus' => 'Active',
                'currentPhase' => 'Phase 3.3',
                'componentsCount' => 0,
                'templatesCount' => 0,
                'packagesCount' => 0,
            ]
        );
    }
}
