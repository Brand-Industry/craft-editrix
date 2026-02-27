<?php

namespace brandindustry\editrix\models;

use craft\base\Model;
use brandindustry\editrix\Editrix;

class Settings extends Model
{
    public int $logRetentionDays = 30;

    public bool $searchEntries = true;

    public bool $searchGlobals = true;

    public bool $searchMatrix = true;

    public bool $searchCategories = false;

    public array $searchableFieldTypes = [
        'craft\\fields\\PlainText',
        'craft\\fields\\Textarea',
        'craft\\redactor\\Field',
        "craft\\ckeditor\\Field",
    ];

    public array $protectedFields = [];

    public int $bulkConfirmationThreshold = 100;

    public bool $showEnvironmentIndicator = true;

    public bool $productionSafeMode = false;

    public function rules(): array
    {
        return [
            [["logRetentionDays"], "integer", "min" => 1, "max" => 365],
            [["bulkConfirmationThreshold"], "integer", "min" => 1],
            [
                [
                    "searchEntries",
                    "searchGlobals",
                    "searchMatrix",
                    "searchCategories",
                ],
                "boolean",
            ],
            [["showEnvironmentIndicator", "productionSafeMode"], "boolean"],
            [
                ["searchableFieldTypes", "protectedFields"],
                "each",
                "rule" => ["string"],
            ],
        ];
    }

    public function getEffectiveLogRetention(): int
    {
        $editionLimit = Editrix::$plugin->license->getLimit("logRetentionDays");
        return min($this->logRetentionDays, $editionLimit);
    }
}
