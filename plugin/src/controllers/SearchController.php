<?php

namespace brandindustry\editrix\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use yii\web\Response;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\assetbundles\EditrixAsset;
use brandindustry\editrix\services\LicenseService;

class SearchController extends Controller
{
    public function actionIndex(): Response
    {
        if (!Editrix::$plugin->userCan("editrix:search")) {
            throw new \yii\web\ForbiddenHttpException(
                "You do not have permission to access Editrix."
            );
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(EditrixAsset::class);

        $config = $this->buildJsConfig();
        $view->registerJs(
            "window.EditrixConfig = " . json_encode($config) . ";",
            View::POS_HEAD
        );

        return $this->renderTemplate("editrix/_search/index");
    }

    public function actionSearch(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:search")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $request = Craft::$app->getRequest();

        $query = $request->getBodyParam("query", "");
        $siteId = $request->getBodyParam("siteId");
        $allSites = $this->parseBoolean(
            $request->getBodyParam("allSites", false)
        );
        $useRegex = $this->parseBoolean(
            $request->getBodyParam("useRegex", false)
        );
        $caseInsensitive = $this->parseBoolean(
            $request->getBodyParam("caseInsensitive", false)
        );
        $wholeWords = $this->parseBoolean(
            $request->getBodyParam("wholeWords", false)
        );
        $dryRun = $this->parseBoolean($request->getBodyParam("dryRun", false));

        $searchEntries = $this->parseBoolean(
            $request->getBodyParam("searchEntries", true)
        );
        $searchGlobals = $this->parseBoolean(
            $request->getBodyParam("searchGlobals", true)
        );
        $searchMatrix = $this->parseBoolean(
            $request->getBodyParam("searchMatrix", true)
        );
        $searchCategories = $this->parseBoolean(
            $request->getBodyParam("searchCategories", false)
        );

        $sections = $request->getBodyParam("sections", []);
        $fields = $request->getBodyParam("fields", []);
        $entryTypes = $request->getBodyParam("entryTypes", []);
        $siteIds = array_map(
            "intval",
            (array) $request->getBodyParam("siteIds", [])
        );

        if ((string) $query === "") {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t("editrix", "Search query is required"),
            ]);
        }

        $license = Editrix::$plugin->license;

        if ($useRegex && !$license->hasFeature(LicenseService::FEATURE_REGEX)) {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t(
                    "editrix",
                    "Regex is only available in Pro edition"
                ),
                "upgradeRequired" => true,
            ]);
        }

        if (
            $wholeWords &&
            !$license->hasFeature(LicenseService::FEATURE_WHOLE_WORDS)
        ) {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t(
                    "editrix",
                    "Whole words matching requires Standard or Pro edition"
                ),
                "upgradeRequired" => true,
            ]);
        }

        if (
            ($allSites || !empty($siteIds)) &&
            !$license->hasFeature(LicenseService::FEATURE_MULTISITE)
        ) {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t(
                    "editrix",
                    "Multi-site search requires Pro edition"
                ),
                "upgradeRequired" => true,
            ]);
        }

        if (
            $searchMatrix &&
            !$license->hasFeature(LicenseService::FEATURE_SEARCH_MATRIX)
        ) {
            $searchMatrix = false; // Silently disable when feature is unavailable
        }

        if (
            $searchCategories &&
            !$license->hasFeature(LicenseService::FEATURE_SEARCH_CATEGORIES)
        ) {
            $searchCategories = false;
        }

        if ($useRegex && @preg_match("/{$query}/", "") === false) {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t("editrix", "Invalid regular expression"),
            ]);
        }

        $searchSiteId = $allSites ? null : (int) $siteId;

        try {
            $results = Editrix::$plugin->search->search(
                $query,
                $searchSiteId,
                $useRegex,
                !$caseInsensitive,
                [
                    "searchEntries" => $searchEntries,
                    "searchGlobals" => $searchGlobals,
                    "searchMatrix" => $searchMatrix,
                    "searchCategories" => $searchCategories,
                    "wholeWords" => $wholeWords,
                    "sections" => is_array($sections) ? $sections : [],
                    "fields" => is_array($fields) ? $fields : [],
                    "entryTypes" => is_array($entryTypes) ? $entryTypes : [],
                    "siteIds" => $siteIds,
                ]
            );

            $groupedResults = $this->groupResultsBySite($results);

            $logSiteId =
                $searchSiteId ?? Craft::$app->getSites()->getCurrentSite()->id;

            Editrix::$plugin->log->createSearchLog(
                (int) $logSiteId,
                $query,
                count($results),
                [
                    "siteId" => $searchSiteId,
                    "allSites" => $allSites,
                    "siteIds" => $siteIds,
                    "searchEntries" => $searchEntries,
                    "searchGlobals" => $searchGlobals,
                    "searchMatrix" => $searchMatrix,
                    "searchCategories" => $searchCategories,
                    "sections" => is_array($sections) ? $sections : [],
                    "fields" => is_array($fields) ? $fields : [],
                    "entryTypes" => is_array($entryTypes) ? $entryTypes : [],
                ],
                $useRegex,
                !$caseInsensitive,
                $wholeWords
            );

            return $this->asJson([
                "success" => true,
                "totalResults" => count($results),
                "groupedResults" => $groupedResults,
                "dryRun" => $dryRun,
            ]);
        } catch (\Throwable $e) {
            Craft::error(
                "Editrix search error: " . $e->getMessage(),
                __METHOD__
            );
            return $this->asJson([
                "success" => false,
                "error" => Craft::t("editrix", "Search failed: {message}", [
                    "message" => $e->getMessage(),
                ]),
            ]);
        }
    }

    private function parseBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        return (bool) $value;
    }

    private function groupResultsBySite(array $results): array
    {
        $grouped = [];

        foreach ($results as $result) {
            $siteHandle = $result->siteHandle;

            if (!isset($grouped[$siteHandle])) {
                $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
                $grouped[$siteHandle] = [
                    "siteName" => $site ? $site->name : $siteHandle,
                    "siteId" => $result->siteId,
                    "results" => [],
                ];
            }

            $grouped[$siteHandle]["results"][] = [
                "uniqueKey" => $result->getUniqueKey(),
                "elementType" => $result->elementType,
                "elementId" => $result->elementId,
                "elementTitle" => $result->elementTitle,
                "sectionName" => $result->sectionName,
                "sectionHandle" => $result->sectionHandle,
                "fieldName" => $result->fieldName,
                "fieldHandle" => $result->fieldHandle,
                "matchContext" => $result->matchContext,
                "fieldValue" => $result->fieldValue,
                "matchStart" => $result->matchStart,
                "matchEnd" => $result->matchEnd,
                "isRichText" => $result->isRichText,
                "cpEditUrl" => $result->getCpEditUrl(),
                "parentId" => $result->parentId,
                "parentTitle" => $result->parentTitle,
                "blockTypeHandle" => $result->blockTypeHandle,
                "siteId" => $result->siteId,
                "siteHandle" => $result->siteHandle,
                "readOnly" => $result->readOnly,
                "readOnlyReason" => $result->readOnlyReason,
            ];
        }

        return $grouped;
    }

    private function buildJsConfig(): array
    {
        $sites = Craft::$app->getSites()->getAllSites();
        $currentSite = Craft::$app->getSites()->getCurrentSite();
        $license = Editrix::$plugin->license;
        $settings = Editrix::$plugin->getSettings();

        return [
            "sites" => array_map(
                fn($site) => [
                    "id" => $site->id,
                    "name" => $site->name,
                    "handle" => $site->handle,
                ],
                $sites
            ),
            "currentSiteId" => $currentSite->id,

            "apiUrl" => \craft\helpers\UrlHelper::actionUrl(
                "editrix/search/search"
            ),
            "replaceUrl" => \craft\helpers\UrlHelper::actionUrl(
                "editrix/replace/execute"
            ),
            "previewUrl" => \craft\helpers\UrlHelper::actionUrl(
                "editrix/replace/preview"
            ),
            "cpUrl" => \craft\helpers\UrlHelper::cpUrl(),

            "scopeUrls" => [
                "sections" => \craft\helpers\UrlHelper::actionUrl(
                    "editrix/scope/sections"
                ),
                "sites" => \craft\helpers\UrlHelper::actionUrl(
                    "editrix/scope/sites"
                ),
                "fields" => \craft\helpers\UrlHelper::actionUrl(
                    "editrix/scope/fields"
                ),
                "entryTypes" => \craft\helpers\UrlHelper::actionUrl(
                    "editrix/scope/entry-types"
                ),
            ],

            "logsUrl" => \craft\helpers\UrlHelper::actionUrl(
                "editrix/log/list"
            ),
            "logsPageUrl" => \craft\helpers\UrlHelper::cpUrl("editrix/logs"),
            "dailyCountsUrl" => \craft\helpers\UrlHelper::actionUrl(
                "editrix/log/daily-counts"
            ),

            "assignmentUrls" => [
                "categories" => \craft\helpers\UrlHelper::actionUrl(
                    "editrix/assignment/categories"
                ),
                "tags" => \craft\helpers\UrlHelper::actionUrl(
                    "editrix/assignment/tags"
                ),
                "sections" => \craft\helpers\UrlHelper::actionUrl(
                    "editrix/scope/assignable-sections"
                ),
            ],

            // Searching "which entries is this category/tag assigned to"
            // makes no sense on a site with zero category/tag groups - the
            // frontend uses this to show a "create some first" notice
            // instead of a search form that could never find anything.
            "assignmentAvailability" => [
                "categories" => count(
                    Craft::$app->getCategories()->getAllGroups()
                ) > 0,
                "tags" => count(Craft::$app->getTags()->getAllTagGroups()) > 0,
            ],
            "categoriesSettingsUrl" => \craft\helpers\UrlHelper::cpUrl(
                "settings/categories"
            ),
            "tagsSettingsUrl" => \craft\helpers\UrlHelper::cpUrl(
                "settings/tags"
            ),

            "canReplace" => Editrix::$plugin->userCan("editrix:replace"),
            "canExport" => Editrix::$plugin->userCan("editrix:export"),

            "safety" => [
                "bulkConfirmationThreshold" =>
                    $settings->bulkConfirmationThreshold,
                "productionSafeMode" => $settings->productionSafeMode,
                "showEnvironmentIndicator" =>
                    $settings->showEnvironmentIndicator,
            ],

            "license" => $license->getFeaturesConfig(),

            "environment" => Craft::$app->env ?: "production",
            "isProduction" => Craft::$app->env === "production",

            "translations" => $this->getTranslations(),
        ];
    }

    /**
     * Get translations for JS
     */
    private function getTranslations(): array
    {
        $keys = [
            "Find & Replace",
            "Search",
            "Replace with",
            "Enter text to search...",
            "Enter replacement text...",
            "Search all sites",
            "Options",
            "Use regular expression",
            "Case insensitive",
            "Whole words only",
            "Dry run mode",
            "Search in",
            "Entries",
            "Globals",
            "Matrix fields",
            "Categories",
            "All Sites",
            "Search & Replace",
            "Find where content lives and export the results - nothing gets changed.",
            "Find content and replace it. Only fields safe to overwrite are offered.",
            "Export CSV",
            "General Search",
            "Segmented Search",
            "Searches every entry and field across the selected site(s).",
            "Narrow the search down to a section, entry type, and fields.",
            "Section, then Entry Type, then Fields.",
            "Select a section first.",
            "Select an entry type first.",
            "No entry types in the selected section(s).",
            "No searchable fields on the selected entry type(s).",
            "Only option",
            "Sections",
            "Entry Types",
            "Fields",
            "Sites",
            "Title",
            "Search only",
            "Rename the tag directly - it may be shared by other entries.",
            "This match spans formatting (like bold or italic) and can't be replaced automatically - edit it directly in Craft.",
            "View",
            "Match Details",
            "Element",
            "Section",
            "Field",
            "Site",
            "Open in Craft",
            "Replace",
            "Continue",
            "Close",
            "Results",
            "No results found.",
            "Select All",
            "Deselect All",
            "Replace Selected",
            "Preview Changes",
            "Apply Changes",
            "Skip",
            "Cancel",
            "Confirm",
            "Searching...",
            "Replacing...",
            "Loading...",
            "entries selected",
            "occurrences found",
            "This feature requires {edition} edition",
            "Upgrade",
            "What do you want to search?",
            "Text",
            "Tags",
            "Search for text within field content.",
            "See which entries a category is assigned to.",
            "See which entries a tag is assigned to.",
            "Category & tag assignment search requires Pro edition.",
            "CATEGORY NAME",
            "TAG NAME",
            "Enter a category name...",
            "Enter a tag name...",
            "Limit to sections (optional)",
            "entries",
            "Choose a different tool",
            "Recent Activity",
            "Re-run",
            "View all",
            "Confirmation required",
            "This will affect {count} entries, above your bulk confirmation threshold of {threshold}.",
            "You are replacing content in a production environment.",
            'Type "REPLACE" below to confirm.',
            "Show",
            "of",
            "Previous",
            "Next",
            "This occurrence won't be changed by this replacement.",
            "Category search",
            "Tag search",
            "Filter sections",
            "No sections match your filter.",
            "Clear",
            "{count} selected",
            "Show {count} more entries",
            "There are no categories yet. Create categories and assign them to entries to search them here.",
            "There are no tags yet. Create tags and assign them to entries to search them here.",
            "Go to Categories settings",
            "Go to Tags settings",
        ];

        $translations = [];
        foreach ($keys as $key) {
            $translations[$key] = Craft::t("editrix", $key);
        }

        return $translations;
    }
}
