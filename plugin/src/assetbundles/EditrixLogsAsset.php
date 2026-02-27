<?php

namespace brandindustry\editrix\assetbundles;

class EditrixLogsAsset extends BaseEditrixAsset
{
    protected function cssFiles(): array
    {
        return [
            'css/editrix-logs.css',
        ];
    }

    protected function jsFile(): ?string
    {
        return 'editrix-logs.js';
    }
}
