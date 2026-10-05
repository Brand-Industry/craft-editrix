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

    /**
     * Searches that may run at once across all users. Each one reads every
     * entry in scope, so on a large site a few at a time already load the
     * database.
     */
    public int $maxConcurrentSearches = 2;

    /**
     * Lowers the number of result rows a search returns below what the
     * edition allows (Standard 3,000, Pro 25,000). Null keeps the
     * edition's limit; it can never raise it.
     */
    public ?int $maxSearchResults = null;

    /**
     * Rows one Replace may change in a single request. Saving an entry
     * takes roughly half a second, and a request that runs longer than the
     * server allows is cut off after changing some entries but before they
     * are logged, which leaves them impossible to revert. Lifting this
     * needs replacing in the queue.
     */
    public int $maxRowsPerReplace = 150;

    public bool $showEnvironmentIndicator = true;

    public bool $productionSafeMode = false;

    public function rules(): array
    {
        return [
            [["logRetentionDays"], "integer", "min" => 1, "max" => 365],
            [["bulkConfirmationThreshold", "maxConcurrentSearches", "maxRowsPerReplace"], "integer", "min" => 1],
            [["maxSearchResults"], "integer", "min" => 1, "skipOnEmpty" => true],
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
