<?php

namespace brandindustry\editrix\models;

use craft\base\Model;
use brandindustry\editrix\Editrix;

class Settings extends Model
{
    // License key is no longer stored locally; licensing is managed by Craft Plugin Store

    /**
     * Log retention in days (overridden by edition limits)
     */
    public int $logRetentionDays = 30;

    /**
     * Default: Search in entries
     */
    public bool $searchEntries = true;

    /**
     * Default: Search in globals
     */
    public bool $searchGlobals = true;

    /**
     * Default: Search in Matrix/nested fields
     */
    public bool $searchMatrix = true;

    /**
     * Default: Search in Categories
     */
    public bool $searchCategories = false;

    /**
     * Field types to search in
     */
    public array $searchableFieldTypes = [
        'craft\\fields\\PlainText',
        'craft\\fields\\Textarea',
        'craft\\redactor\\Field',
        'craft\\ckeditor\\Field',
    ];

    /**
     * Protected fields (Pro only) - fields that require extra confirmation
     */
    public array $protectedFields = [];

    /**
     * Require confirmation for bulk operations affecting more than X entries
     */
    public int $bulkConfirmationThreshold = 100;

    /**
     * Show environment indicator
     */
    public bool $showEnvironmentIndicator = true;

    /**
     * Block operations in production (requires confirmation code)
     */
    public bool $productionSafeMode = false;

    /**
     * Validation rules
     */
        public function rules(): array
        {
            return [
            [['logRetentionDays'], 'integer', 'min' => 1, 'max' => 365],
            [['bulkConfirmationThreshold'], 'integer', 'min' => 1],
            [['searchEntries', 'searchGlobals', 'searchMatrix', 'searchCategories'], 'boolean'],
            [['showEnvironmentIndicator', 'productionSafeMode'], 'boolean'],
            [['searchableFieldTypes', 'protectedFields'], 'each', 'rule' => ['string']],
        ];
    }

    /**
     * Get effective log retention based on edition
     */
    public function getEffectiveLogRetention(): int
    {
        $editionLimit = Editrix::$plugin->license->getLimit('logRetentionDays');
        return min($this->logRetentionDays, $editionLimit);
    }
}
