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
    public const FEATURE_SEARCH_ENTRIES = "search.entries";
    public const FEATURE_SEARCH_GLOBALS = "search.globals";
    public const FEATURE_SEARCH_MATRIX = "search.matrix";
    public const FEATURE_SEARCH_CATEGORIES = "search.categories";
    public const FEATURE_SEARCH_SEO = "search.seo";
    public const FEATURE_SEARCH_ASSETS = "search.assets";
    public const FEATURE_SEARCH_USERS = "search.users";

    // Which entries a category/tag is assigned to - a relationship lookup,
    // not a text search (see AssignmentService).
    public const FEATURE_ASSIGNMENT_SEARCH = "assignmentSearch";

    public const FEATURE_REGEX = "regex";
    public const FEATURE_REGEX_ADVANCED = "regex.advanced";
    public const FEATURE_CASE_INSENSITIVE = "caseInsensitive";
    public const FEATURE_WHOLE_WORDS = "wholeWords";

    public const FEATURE_MULTISITE = "multisite";
    public const FEATURE_SCOPE_FILTERS = "scopeFilters";
    public const FEATURE_SCOPE_FILTERS_FULL = "scopeFilters.full";

    public const FEATURE_DIFF_PREVIEW = "diffPreview";
    public const FEATURE_DIFF_VISUAL = "diffVisual";
    public const FEATURE_DRY_RUN = "dryRun";

    public const FEATURE_LOGS = "logs";
    public const FEATURE_REVERT = "revert";
    public const FEATURE_EXPORT_CSV = "export.csv";
    public const FEATURE_EXPORT_JSON = "export.json";

    public const FEATURE_PRESETS = "presets";
    public const FEATURE_PRESETS_UNLIMITED = "presets.unlimited";
    public const FEATURE_PRESETS_SHARE = "presets.share";

    public const FEATURE_PROTECTED_FIELDS = "protectedFields";
    public const FEATURE_IMPACT_ANALYSIS = "impactAnalysis";
    public const FEATURE_SEO_WARNINGS = "seoWarnings";
    public const FEATURE_SCHEDULED = "scheduled";
    public const FEATURE_CLI = "cli";
    public const FEATURE_API = "api";
    public const FEATURE_WEBHOOKS = "webhooks";
    public const FEATURE_SNAPSHOTS = "snapshots";
    public const FEATURE_ENV_INDICATOR = "envIndicator";

    private array $featureMatrix = [
        // Standard is the free edition: general Search and Search & Replace
        // (Entries + Globals, no segmented scope) stay useful without a
        // license. Everything that used to be "Standard or Pro" (Matrix/Neo,
        // Categories, segmented scope, multi-site, CSV export) now requires
        // Pro.
        Editrix::EDITION_STANDARD => [
            self::FEATURE_SEARCH_ENTRIES => true,
            self::FEATURE_SEARCH_GLOBALS => true,
            self::FEATURE_SEARCH_MATRIX => false,
            self::FEATURE_SEARCH_CATEGORIES => false,
            self::FEATURE_SEARCH_SEO => false,
            self::FEATURE_SEARCH_ASSETS => false,
            self::FEATURE_SEARCH_USERS => false,
            self::FEATURE_ASSIGNMENT_SEARCH => false,

            self::FEATURE_REGEX => true,
            self::FEATURE_REGEX_ADVANCED => false,
            self::FEATURE_CASE_INSENSITIVE => true,
            self::FEATURE_WHOLE_WORDS => true,

            self::FEATURE_MULTISITE => false,
            self::FEATURE_SCOPE_FILTERS => false,
            self::FEATURE_SCOPE_FILTERS_FULL => false,

            self::FEATURE_DIFF_PREVIEW => true, // Text only
            self::FEATURE_DIFF_VISUAL => false,
            self::FEATURE_DRY_RUN => true,

            self::FEATURE_LOGS => true, // 30 days
            self::FEATURE_REVERT => true,
            self::FEATURE_EXPORT_CSV => false,
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
            self::FEATURE_ASSIGNMENT_SEARCH => true,

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
        Editrix::EDITION_STANDARD => [
            "logRetentionDays" => 30,
            "maxPresets" => 5,
        ],
        Editrix::EDITION_PRO => [
            "logRetentionDays" => 90,
            "maxPresets" => -1, // Unlimited
        ],
    ];

    /**
     * The licensed edition, per Craft's own edition/licensing system -
     * Editrix::$edition is managed by Craft core (project config, the CP's
     * edition switcher, and the Plugin Store's license checks), not by us.
     */
    public function hasFeature(string $feature): bool
    {
        return $this->featureMatrix[Editrix::$plugin->edition][$feature] ?? false;
    }

    public function getLimit(string $limit): int
    {
        return $this->limits[Editrix::$plugin->edition][$limit] ?? 0;
    }

    public function getFeaturesConfig(): array
    {
        $edition = Editrix::$plugin->edition;
        return [
            "edition" => $edition,
            "features" => $this->featureMatrix[$edition],
            "limits" => $this->limits[$edition],
        ];
    }
}
