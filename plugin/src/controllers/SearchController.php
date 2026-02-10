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
    /**
     * Main search page
     */
    public function actionIndex(): Response
    {
        if (!Editrix::$plugin->userCan('editrix:search')) {
            throw new \yii\web\ForbiddenHttpException('You do not have permission to access Editrix.');
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(EditrixAsset::class);

        // Build config for Vue app
        $config = $this->buildJsConfig();
        $view->registerJs(
            'window.EditrixConfig = ' . json_encode($config) . ';',
            View::POS_HEAD
        );

        return $this->renderTemplate('editrix/_search/index');
    }

    /**
     * Search API endpoint
     */
    public function actionSearch(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan('editrix:search')) {
            return $this->asJson([
                'success' => false,
                'error' => 'Permission denied',
            ]);
        }

        $request = Craft::$app->getRequest();

        // Parse parameters with proper boolean handling
        $query = $request->getBodyParam('query', '');
        $siteId = $request->getBodyParam('siteId');
        $allSites = $this->parseBoolean($request->getBodyParam('allSites', false));
        $useRegex = $this->parseBoolean($request->getBodyParam('useRegex', false));
        $caseInsensitive = $this->parseBoolean($request->getBodyParam('caseInsensitive', false));
        $wholeWords = $this->parseBoolean($request->getBodyParam('wholeWords', false));
        $dryRun = $this->parseBoolean($request->getBodyParam('dryRun', false));

        // Scope filters
        $searchEntries = $this->parseBoolean($request->getBodyParam('searchEntries', true));
        $searchGlobals = $this->parseBoolean($request->getBodyParam('searchGlobals', true));
        $searchMatrix = $this->parseBoolean($request->getBodyParam('searchMatrix', true));
        $searchCategories = $this->parseBoolean($request->getBodyParam('searchCategories', false));

        // Advanced scope (Standard+)
        $sections = $request->getBodyParam('sections', []);
        $fields = $request->getBodyParam('fields', []);
        $entryTypes = $request->getBodyParam('entryTypes', []);

        // Validation
        if (empty($query)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('editrix', 'Search query is required'),
            ]);
        }

        // Feature checks
        $license = Editrix::$plugin->license;

        if ($useRegex && !$license->hasFeature(LicenseService::FEATURE_REGEX)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('editrix', 'Regex is not available in Lite edition'),
                'upgradeRequired' => true,
            ]);
        }

        if ($wholeWords && !$license->hasFeature(LicenseService::FEATURE_WHOLE_WORDS)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('editrix', 'Whole words matching requires Standard or Pro edition'),
                'upgradeRequired' => true,
            ]);
        }

        if ($allSites && !$license->hasFeature(LicenseService::FEATURE_MULTISITE)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('editrix', 'Multi-site search requires Standard or Pro edition'),
                'upgradeRequired' => true,
            ]);
        }

        if ($searchMatrix && !$license->hasFeature(LicenseService::FEATURE_SEARCH_MATRIX)) {
            $searchMatrix = false; // Silently disable for Lite
        }

        if ($searchCategories && !$license->hasFeature(LicenseService::FEATURE_SEARCH_CATEGORIES)) {
            $searchCategories = false;
        }

        // Validate regex if used
        if ($useRegex && @preg_match("/{$query}/", '') === false) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('editrix', 'Invalid regular expression'),
            ]);
        }

        // Perform search
        $searchSiteId = $allSites ? null : (int)$siteId;

        try {
            $results = Editrix::$plugin->search->search(
                $query,
                $searchSiteId,
                $useRegex,
                !$caseInsensitive, // caseSensitive is the inverse
                [
                    'searchEntries' => $searchEntries,
                    'searchGlobals' => $searchGlobals,
                    'searchMatrix' => $searchMatrix,
                    'searchCategories' => $searchCategories,
                    'wholeWords' => $wholeWords,
                    'sections' => is_array($sections) ? $sections : [],
                    'fields' => is_array($fields) ? $fields : [],
                    'entryTypes' => is_array($entryTypes) ? $entryTypes : [],
                ]
            );

            // Group results by site
            $groupedResults = $this->groupResultsBySite($results);

            return $this->asJson([
                'success' => true,
                'totalResults' => count($results),
                'groupedResults' => $groupedResults,
                'dryRun' => $dryRun,
            ]);

        } catch (\Throwable $e) {
            Craft::error('Editrix search error: ' . $e->getMessage(), __METHOD__);
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('editrix', 'Search failed: {message}', ['message' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * Parse boolean from various input formats
     */
    private function parseBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        return (bool)$value;
    }

    /**
     * Group results by site for display
     */
    private function groupResultsBySite(array $results): array
    {
        $grouped = [];

        foreach ($results as $result) {
            $siteHandle = $result->siteHandle;

            if (!isset($grouped[$siteHandle])) {
                $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
                $grouped[$siteHandle] = [
                    'siteName' => $site ? $site->name : $siteHandle,
                    'siteId' => $result->siteId,
                    'results' => [],
                ];
            }

            $grouped[$siteHandle]['results'][] = [
                'uniqueKey' => $result->getUniqueKey(),
                'elementType' => $result->elementType,
                'elementId' => $result->elementId,
                'elementTitle' => $result->elementTitle,
                'sectionName' => $result->sectionName,
                'sectionHandle' => $result->sectionHandle,
                'fieldName' => $result->fieldName,
                'fieldHandle' => $result->fieldHandle,
                'matchContext' => $result->matchContext,
                'fieldValue' => $result->fieldValue,
                'matchStart' => $result->matchStart,
                'matchEnd' => $result->matchEnd,
                'cpEditUrl' => $result->getCpEditUrl(),
                'parentId' => $result->parentId,
                'parentTitle' => $result->parentTitle,
                'blockTypeHandle' => $result->blockTypeHandle,
                'siteId' => $result->siteId,
                'siteHandle' => $result->siteHandle,
            ];
        }

        return $grouped;
    }

    /**
     * Build JavaScript configuration
     */
    private function buildJsConfig(): array
    {
        $sites = Craft::$app->getSites()->getAllSites();
        $currentSite = Craft::$app->getSites()->getCurrentSite();
        $license = Editrix::$plugin->license;

        return [
            // Sites
            'sites' => array_map(fn($site) => [
                'id' => $site->id,
                'name' => $site->name,
                'handle' => $site->handle,
            ], $sites),
            'currentSiteId' => $currentSite->id,

            // URLs
            'apiUrl' => \craft\helpers\UrlHelper::actionUrl('editrix/search/search'),
            'replaceUrl' => \craft\helpers\UrlHelper::actionUrl('editrix/replace/execute'),
            'previewUrl' => \craft\helpers\UrlHelper::actionUrl('editrix/replace/preview'),
            'cpUrl' => \craft\helpers\UrlHelper::cpUrl(),

            // Scope data URLs
            'scopeUrls' => [
                'sections' => \craft\helpers\UrlHelper::actionUrl('editrix/scope/sections'),
                'sites' => \craft\helpers\UrlHelper::actionUrl('editrix/scope/sites'),
                'fields' => \craft\helpers\UrlHelper::actionUrl('editrix/scope/fields'),
                'entryTypes' => \craft\helpers\UrlHelper::actionUrl('editrix/scope/entry-types'),
            ],

            // Permissions
            'canReplace' => Editrix::$plugin->userCan('editrix:replace'),
            'canExport' => Editrix::$plugin->userCan('editrix:export'),

            // License/Features
            'license' => $license->getFeaturesConfig(),
            'remainingOperations' => $license->getRemainingOperations(),

            // Environment
            'environment' => Craft::$app->env ?: 'production',
            'isProduction' => Craft::$app->env === 'production',

            // Translations
            'translations' => $this->getTranslations(),
        ];
    }

    /**
     * Get translations for JS
     */
    private function getTranslations(): array
    {
        $keys = [
            'Find & Replace',
            'Search',
            'Replace with',
            'Enter text to search...',
            'Enter replacement text...',
            'Search all sites',
            'Options',
            'Use regular expression',
            'Case insensitive',
            'Whole words only',
            'Dry run mode',
            'Search in',
            'Entries',
            'Globals',
            'Matrix fields',
            'Categories',
            'Results',
            'No results found.',
            'Select All',
            'Deselect All',
            'Replace Selected',
            'Preview Changes',
            'Apply Changes',
            'Skip',
            'Cancel',
            'Confirm',
            'Searching...',
            'Replacing...',
            'Loading...',
            'entries selected',
            'occurrences found',
            'This feature requires {edition} edition',
            'Upgrade',
            'operations remaining this month',
            'Unlimited operations',
        ];

        $translations = [];
        foreach ($keys as $key) {
            $translations[$key] = Craft::t('editrix', $key);
        }

        return $translations;
    }
}
