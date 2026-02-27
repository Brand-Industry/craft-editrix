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

        if (empty($query)) {
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
            $allSites &&
            !$license->hasFeature(LicenseService::FEATURE_MULTISITE)
        ) {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t(
                    "editrix",
                    "Multi-site search requires Standard or Pro edition"
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
                ]
            );

            $groupedResults = $this->groupResultsBySite($results);

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
                "cpEditUrl" => $result->getCpEditUrl(),
                "parentId" => $result->parentId,
                "parentTitle" => $result->parentTitle,
                "blockTypeHandle" => $result->blockTypeHandle,
                "siteId" => $result->siteId,
                "siteHandle" => $result->siteHandle,
            ];
        }

        return $grouped;
    }

    private function buildJsConfig(): array
    {
        $sites = Craft::$app->getSites()->getAllSites();
        $currentSite = Craft::$app->getSites()->getCurrentSite();
        $license = Editrix::$plugin->license;

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

            "canReplace" => Editrix::$plugin->userCan("editrix:replace"),
            "canExport" => Editrix::$plugin->userCan("editrix:export"),

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
        ];

        $translations = [];
        foreach ($keys as $key) {
            $translations[$key] = Craft::t("editrix", $key);
        }

        return $translations;
    }
}
