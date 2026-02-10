<?php

namespace brandindustry\editrix\services;

use Craft;
use craft\base\Component;
use brandindustry\editrix\Editrix;

/**
 * License Service
 * Manages edition detection and feature availability
 */
class LicenseService extends Component
{
    // Feature constants
    public const FEATURE_SEARCH_ENTRIES = 'search.entries';
    public const FEATURE_SEARCH_GLOBALS = 'search.globals';
    public const FEATURE_SEARCH_MATRIX = 'search.matrix';
    public const FEATURE_SEARCH_CATEGORIES = 'search.categories';
    public const FEATURE_SEARCH_SEO = 'search.seo';
    public const FEATURE_SEARCH_ASSETS = 'search.assets';
    public const FEATURE_SEARCH_USERS = 'search.users';
    
    public const FEATURE_REGEX = 'regex';
    public const FEATURE_REGEX_ADVANCED = 'regex.advanced';
    public const FEATURE_CASE_INSENSITIVE = 'caseInsensitive';
    public const FEATURE_WHOLE_WORDS = 'wholeWords';
    
    public const FEATURE_MULTISITE = 'multisite';
    public const FEATURE_SCOPE_FILTERS = 'scopeFilters';
    public const FEATURE_SCOPE_FILTERS_FULL = 'scopeFilters.full';
    
    public const FEATURE_DIFF_PREVIEW = 'diffPreview';
    public const FEATURE_DIFF_VISUAL = 'diffVisual';
    public const FEATURE_DRY_RUN = 'dryRun';
    
    public const FEATURE_LOGS = 'logs';
    public const FEATURE_REVERT = 'revert';
    public const FEATURE_EXPORT_CSV = 'export.csv';
    public const FEATURE_EXPORT_JSON = 'export.json';
    
    public const FEATURE_PRESETS = 'presets';
    public const FEATURE_PRESETS_UNLIMITED = 'presets.unlimited';
    public const FEATURE_PRESETS_SHARE = 'presets.share';
    
    public const FEATURE_PROTECTED_FIELDS = 'protectedFields';
    public const FEATURE_IMPACT_ANALYSIS = 'impactAnalysis';
    public const FEATURE_SEO_WARNINGS = 'seoWarnings';
    public const FEATURE_SCHEDULED = 'scheduled';
    public const FEATURE_CLI = 'cli';
    public const FEATURE_API = 'api';
    public const FEATURE_WEBHOOKS = 'webhooks';
    public const FEATURE_SNAPSHOTS = 'snapshots';
    public const FEATURE_ENV_INDICATOR = 'envIndicator';

    /**
     * Feature matrix by edition
     */
    private array $featureMatrix = [
        Editrix::EDITION_FREE => [
            self::FEATURE_SEARCH_ENTRIES => true,
            self::FEATURE_SEARCH_GLOBALS => true,
            self::FEATURE_SEARCH_MATRIX => false,
            self::FEATURE_SEARCH_CATEGORIES => false,
            self::FEATURE_SEARCH_SEO => false,
            self::FEATURE_SEARCH_ASSETS => false,
            self::FEATURE_SEARCH_USERS => false,
            
            self::FEATURE_REGEX => false,
            self::FEATURE_REGEX_ADVANCED => false,
            self::FEATURE_CASE_INSENSITIVE => true,
            self::FEATURE_WHOLE_WORDS => false,
            
            self::FEATURE_MULTISITE => false,
            self::FEATURE_SCOPE_FILTERS => false,
            self::FEATURE_SCOPE_FILTERS_FULL => false,
            
            self::FEATURE_DIFF_PREVIEW => false,
            self::FEATURE_DIFF_VISUAL => false,
            self::FEATURE_DRY_RUN => false,
            
            self::FEATURE_LOGS => true, // 7 days
            self::FEATURE_REVERT => true,
            self::FEATURE_EXPORT_CSV => false,
            self::FEATURE_EXPORT_JSON => false,
            
            self::FEATURE_PRESETS => false,
            self::FEATURE_PRESETS_UNLIMITED => false,
            self::FEATURE_PRESETS_SHARE => false,
            
            self::FEATURE_PROTECTED_FIELDS => false,
            self::FEATURE_IMPACT_ANALYSIS => false,
            self::FEATURE_SEO_WARNINGS => false,
            self::FEATURE_SCHEDULED => false,
            self::FEATURE_CLI => false,
            self::FEATURE_API => false,
            self::FEATURE_WEBHOOKS => false,
            self::FEATURE_SNAPSHOTS => false,
            self::FEATURE_ENV_INDICATOR => false,
        ],
        
        Editrix::EDITION_STANDARD => [
            self::FEATURE_SEARCH_ENTRIES => true,
            self::FEATURE_SEARCH_GLOBALS => true,
            self::FEATURE_SEARCH_MATRIX => true,
            self::FEATURE_SEARCH_CATEGORIES => true,
            self::FEATURE_SEARCH_SEO => false,
            self::FEATURE_SEARCH_ASSETS => false,
            self::FEATURE_SEARCH_USERS => false,
            
            self::FEATURE_REGEX => true,
            self::FEATURE_REGEX_ADVANCED => false,
            self::FEATURE_CASE_INSENSITIVE => true,
            self::FEATURE_WHOLE_WORDS => true,
            
            self::FEATURE_MULTISITE => true,
            self::FEATURE_SCOPE_FILTERS => true, // Sections, Sites only
            self::FEATURE_SCOPE_FILTERS_FULL => false,
            
            self::FEATURE_DIFF_PREVIEW => true, // Text only
            self::FEATURE_DIFF_VISUAL => false,
            self::FEATURE_DRY_RUN => true,
            
            self::FEATURE_LOGS => true, // 30 days
            self::FEATURE_REVERT => true,
            self::FEATURE_EXPORT_CSV => true,
            self::FEATURE_EXPORT_JSON => false,
            
            self::FEATURE_PRESETS => true, // 5 max
            self::FEATURE_PRESETS_UNLIMITED => false,
            self::FEATURE_PRESETS_SHARE => false,
            
            self::FEATURE_PROTECTED_FIELDS => false,
            self::FEATURE_IMPACT_ANALYSIS => false,
            self::FEATURE_SEO_WARNINGS => false,
            self::FEATURE_SCHEDULED => false,
            self::FEATURE_CLI => false,
            self::FEATURE_API => false,
            self::FEATURE_WEBHOOKS => false,
            self::FEATURE_SNAPSHOTS => false,
            self::FEATURE_ENV_INDICATOR => true,
        ],
        
        Editrix::EDITION_PRO => [
            self::FEATURE_SEARCH_ENTRIES => true,
            self::FEATURE_SEARCH_GLOBALS => true,
            self::FEATURE_SEARCH_MATRIX => true,
            self::FEATURE_SEARCH_CATEGORIES => true,
            self::FEATURE_SEARCH_SEO => true,
            self::FEATURE_SEARCH_ASSETS => true,
            self::FEATURE_SEARCH_USERS => true,
            
            self::FEATURE_REGEX => true,
            self::FEATURE_REGEX_ADVANCED => true,
            self::FEATURE_CASE_INSENSITIVE => true,
            self::FEATURE_WHOLE_WORDS => true,
            
            self::FEATURE_MULTISITE => true,
            self::FEATURE_SCOPE_FILTERS => true,
            self::FEATURE_SCOPE_FILTERS_FULL => true, // + Fields, Entry Types
            
            self::FEATURE_DIFF_PREVIEW => true,
            self::FEATURE_DIFF_VISUAL => true, // Side-by-side
            self::FEATURE_DRY_RUN => true,
            
            self::FEATURE_LOGS => true, // 90 days
            self::FEATURE_REVERT => true,
            self::FEATURE_EXPORT_CSV => true,
            self::FEATURE_EXPORT_JSON => true,
            
            self::FEATURE_PRESETS => true,
            self::FEATURE_PRESETS_UNLIMITED => true,
            self::FEATURE_PRESETS_SHARE => true,
            
            self::FEATURE_PROTECTED_FIELDS => true,
            self::FEATURE_IMPACT_ANALYSIS => true,
            self::FEATURE_SEO_WARNINGS => true,
            self::FEATURE_SCHEDULED => true,
            self::FEATURE_CLI => true,
            self::FEATURE_API => true,
            self::FEATURE_WEBHOOKS => true,
            self::FEATURE_SNAPSHOTS => true,
            self::FEATURE_ENV_INDICATOR => true,
        ],
    ];

    /**
     * Limits by edition
     */
    private array $limits = [
        Editrix::EDITION_FREE => [
            'operationsPerMonth' => 50,
            'logRetentionDays' => 7,
            'maxPresets' => 0,
        ],
        Editrix::EDITION_STANDARD => [
            'operationsPerMonth' => -1, // Unlimited
            'logRetentionDays' => 30,
            'maxPresets' => 5,
        ],
        Editrix::EDITION_PRO => [
            'operationsPerMonth' => -1,
            'logRetentionDays' => 90,
            'maxPresets' => -1, // Unlimited
        ],
    ];

    private ?string $cachedEdition = null;

    /**
     * Get current edition
     */
    public function getEdition(): string
    {
        if ($this->cachedEdition !== null) {
            return $this->cachedEdition;
        }

        // 1) Attempt to read edition from Craft Store (simulated via env var for testing)
        $envEdition = getenv('EDITRIX_EDITION'); // expected: 'free' | 'standard' | 'pro'
        if (in_array($envEdition, [Editrix::EDITION_FREE, Editrix::EDITION_STANDARD, Editrix::EDITION_PRO], true)) {
            $this->cachedEdition = $envEdition;
            return $this->cachedEdition;
        }

        // 2) Fallback: fallback to existing setting (if someone migrated off store, keep compatibility)
        $settings = Editrix::$plugin->getSettings();
        $storeEdition = property_exists($settings, 'edition') ? $settings->edition : null;
        if (in_array($storeEdition, [Editrix::EDITION_FREE, Editrix::EDITION_STANDARD, Editrix::EDITION_PRO], true)) {
            $this->cachedEdition = $storeEdition;
            return $this->cachedEdition;
        }

        // 3) Default to Free edition (Store will override in real deployment)
        $this->cachedEdition = Editrix::EDITION_FREE;
        return $this->cachedEdition;
    }

    /**
     * Check if a feature is available
     */
    public function hasFeature(string $feature): bool
    {
        $edition = $this->getEdition();
        return $this->featureMatrix[$edition][$feature] ?? false;
    }

    /**
     * Check if current edition is at least the given edition
     */
    public function isEdition(string $edition): bool
    {
        $editionOrder = [
            Editrix::EDITION_FREE => 0,
            Editrix::EDITION_STANDARD => 1,
            Editrix::EDITION_PRO => 2,
        ];

        $currentOrder = $editionOrder[$this->getEdition()] ?? 0;
        $requiredOrder = $editionOrder[$edition] ?? 0;

        return $currentOrder >= $requiredOrder;
    }

    /**
     * Get a limit value for current edition
     */
    public function getLimit(string $limit): int
    {
        $edition = $this->getEdition();
        return $this->limits[$edition][$limit] ?? 0;
    }

    /**
     * Get all features for current edition (for JS config)
     */
    public function getFeaturesConfig(): array
    {
        $edition = $this->getEdition();
        return [
            'edition' => $edition,
            'features' => $this->featureMatrix[$edition],
            'limits' => $this->limits[$edition],
        ];
    }

    /**
     * Check operations limit for Lite edition
     */
    public function canPerformOperation(): bool
    {
        $limit = $this->getLimit('operationsPerMonth');
        
        if ($limit === -1) {
            return true;
        }

        $count = $this->getMonthlyOperationCount();
        return $count < $limit;
    }

    /**
     * Get monthly operation count
     */
    public function getMonthlyOperationCount(): int
    {
        $startOfMonth = (new \DateTime('first day of this month'))->format('Y-m-d 00:00:00');
        
        return (int)(new \craft\db\Query())
            ->from('{{%editrix_logs}}')
            ->where(['>=', 'dateCreated', $startOfMonth])
            ->count();
    }

    /**
     * Get remaining operations for Lite
     */
    public function getRemainingOperations(): int
    {
        $limit = $this->getLimit('operationsPerMonth');
        
        if ($limit === -1) {
            return -1;
        }

        return max(0, $limit - $this->getMonthlyOperationCount());
    }

    /**
     * Clear cached edition (for testing or after license update)
     */
    public function clearCache(): void
    {
        $this->cachedEdition = null;
    }
}
