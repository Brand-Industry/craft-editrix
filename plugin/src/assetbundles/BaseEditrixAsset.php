<?php

namespace brandindustry\editrix\assetbundles;

use craft\web\AssetBundle;
use craft\web\View;

abstract class BaseEditrixAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = '@brandindustry/editrix/web/dist';
        $this->css = $this->cssFiles();
        $this->js = [];

        parent::init();
    }

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        $jsFile = $this->jsFile();
        if ($jsFile === null) {
            return;
        }

        $view->registerJsFile($this->baseUrl . '/js/' . $jsFile, $this->jsOptions());
    }

    protected function cssFiles(): array
    {
        return [];
    }

    protected function jsFile(): ?string
    {
        return null;
    }

    protected function jsOptions(): array
    {
        return [
            'type' => 'module',
            'position' => View::POS_END,
        ];
    }
}
