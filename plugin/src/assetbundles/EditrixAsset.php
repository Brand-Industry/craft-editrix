<?php

namespace brandindustry\editrix\assetbundles;

use craft\web\AssetBundle;
use craft\web\View;

class EditrixAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@brandindustry/editrix/web/dist';

        $this->css = [
            'css/editrix.css',
        ];

        // JS will be registered manually as module
        $this->js = [];

        parent::init();
    }

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        // Register JS as ES module
        $jsUrl = $this->baseUrl . '/js/editrix.js';
        $view->registerJsFile($jsUrl, [
            'type' => 'module',
            'position' => View::POS_END,
        ]);
    }
}
