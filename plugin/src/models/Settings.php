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

    /**
     * Field handles Replace must never change. Their matches still show in
     * a search, marked as protected, and a replace refuses them even when
     * called directly.
     */
    public array $protectedFields = [];

    /** Section handles whose entries Replace must never change. */
    public array $protectedSections = [];

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
     * Lowers the number of rows one Replace may change below what the
     * edition allows (Standard 200, Pro 5,000). Null keeps the edition's
     * limit; it can never raise it. A replace above the limit is refused
     * rather than cut short, so what is changed is always what was asked.
     */
    public ?int $maxRowsPerReplace = null;

    /**
     * A replace of more rows than this runs in the queue, with progress and
     * a Cancel button, instead of inside the request that started it:
     * saving an entry takes about half a second, and a request that
     * outlasts the server's time limit is cut off partway.
     */
    public int $replaceQueueThreshold = 25;

    /**
     * Ask the database for the rows whose text contains the searched words
     * before loading any entry. On a site with hundreds of thousands of
     * entries this turns minutes into seconds. It finds everything except
     * a word that formatting splits in the middle (Fea<b>tured</b>); turn
     * it off if that matters more than speed.
     */
    public bool $sqlPrefilter = true;

    /**
     * Whether Replace validates an entry before saving it. Off by default:
     * an entry that has been in the database for years may not satisfy a
     * field that was made required last month, and refusing to fix a typo
     * in it helps nobody. Craft still keeps URIs unique on save.
     */
    public bool $validateOnReplace = false;

    /** Whether each replace leaves a revision on the entries it changes. */
    public bool $createRevisionsOnReplace = true;

    public bool $showEnvironmentIndicator = true;

    public bool $productionSafeMode = false;

    public function rules(): array
    {
        return [
            [["logRetentionDays"], "integer", "min" => 1, "max" => 365],
            [["bulkConfirmationThreshold", "maxConcurrentSearches", "replaceQueueThreshold"], "integer", "min" => 1],
            [["maxSearchResults", "maxRowsPerReplace"], "integer", "min" => 1, "skipOnEmpty" => true],
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
                ["searchableFieldTypes", "protectedFields", "protectedSections"],
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
