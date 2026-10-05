<?php

namespace brandindustry\editrix\controllers;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\web\Controller;
use craft\web\View;
use yii\web\Response;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\assetbundles\EditrixAsset;
use brandindustry\editrix\jobs\SearchJob;
use brandindustry\editrix\migrations\m260924_000000_add_search_sessions as SearchSessions;
use brandindustry\editrix\services\LicenseService;

class SearchController extends Controller
{
    /** How long a queued or running search may be silent and still count as active. */
    private const ACTIVE_WINDOW_SECONDS = 600;

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

        $parsed = $this->parseSearchParams();
        if (isset($parsed["error"])) {
            return $this->asJson($parsed["error"]);
        }
        $p = $parsed["params"];

        try {
            $meta = [];
            $results = Editrix::$plugin->search->search(
                $p["query"],
                $p["searchSiteId"],
                $p["useRegex"],
                !$p["caseInsensitive"],
                $this->searchOptions($p),
                null,
                null,
                $meta
            );

            $groupedResults = Editrix::$plugin->search->groupResultsBySite(
                $results
            );

            $logSiteId =
                $p["searchSiteId"] ??
                Craft::$app->getSites()->getCurrentSite()->id;

            Editrix::$plugin->log->createSearchLog(
                (int) $logSiteId,
                $p["query"],
                count($results),
                $this->logScope($p),
                $p["useRegex"],
                !$p["caseInsensitive"],
                $p["wholeWords"]
            );

            return $this->asJson([
                "success" => true,
                "totalResults" => array_sum(
                    array_map(fn($site) => count($site["results"]), $groupedResults)
                ),
                "totalMatches" => count($results),
                "groupedResults" => $groupedResults,
                "dryRun" => $p["dryRun"],
                "truncated" => $meta["truncated"] ?? false,
                "stopReason" => $meta["stopReason"] ?? null,
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

    /**
     * Starts a search as a background queue job instead of running it
     * synchronously - for large sites, scanning every entry inside one web
     * request risks hitting PHP's execution time limit or starving other
     * requests of PHP-FPM workers. The frontend polls actionSearchStatus()
     * for progress and picks up the results once the job finishes.
     */
    public function actionSearchStart(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:search")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $parsed = $this->parseSearchParams();
        if (isset($parsed["error"])) {
            return $this->asJson($parsed["error"]);
        }
        $p = $parsed["params"];

        // One search per user, and a ceiling across users: each search
        // reads every entry in scope, which is heavy on a large site.
        $userId = Craft::$app->getUser()->getId();
        $mine = $this->activeSearches()->andWhere(["userId" => $userId])->one();
        if ($mine) {
            return $this->asJson([
                "success" => false,
                "code" => "searchInProgress",
                "searchId" => $mine["searchId"],
                "error" => Craft::t(
                    "editrix",
                    "You already have a search running. Open it, or cancel it before starting another."
                ),
            ]);
        }

        if (
            (int) $this->activeSearches()->count() >=
            Editrix::$plugin->getSettings()->maxConcurrentSearches
        ) {
            return $this->asJson([
                "success" => false,
                "code" => "serverBusy",
                "error" => Craft::t(
                    "editrix",
                    "Other searches are using the server right now. Try again in a few minutes."
                ),
            ]);
        }

        $searchId = StringHelper::UUID();
        $now = Db::prepareDateForDb(new \DateTime());

        Craft::$app
            ->getDb()
            ->createCommand()
            ->insert(SearchSessions::TABLE_SEARCHES, [
                "searchId" => $searchId,
                "userId" => Craft::$app->getUser()->getId(),
                "status" => "queued",
                "paramsJson" => Json::encode($this->formParams()),
                "dateCreated" => $now,
                "dateUpdated" => $now,
                "uid" => StringHelper::UUID(),
            ])
            ->execute();

        Craft::$app->getQueue()->push(
            new SearchJob([
                "searchId" => $searchId,
                "query" => $p["query"],
                "siteId" => $p["searchSiteId"],
                "siteIds" => $p["siteIds"],
                "useRegex" => $p["useRegex"],
                "caseSensitive" => !$p["caseInsensitive"],
                "options" => $this->searchOptions($p),
                "userId" => Craft::$app->getUser()->getId(),
                "logScope" => $this->logScope($p),
            ])
        );

        // Old sessions (finished long ago, or abandoned mid-search by a
        // closed tab) don't need to stick around - sweep them on the way in
        // rather than maintaining a separate cleanup job.
        Craft::$app
            ->getDb()
            ->createCommand()
            ->delete(SearchSessions::TABLE_SEARCHES, [
                "<",
                "dateCreated",
                Db::prepareDateForDb((new \DateTime())->modify("-1 day")),
            ])
            ->execute();

        return $this->asJson(["success" => true, "searchId" => $searchId]);
    }

    public function actionSearchStatus(): Response
    {
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:search")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $searchId = Craft::$app->getRequest()->getParam("id", "");
        $userId = Craft::$app->getUser()->getId();

        $row = (new Query())
            ->select([
                "status",
                "scannedCount",
                "totalCount",
                "resultCount",
                "resultsJson",
                "replacedJson",
                "errorMessage",
                "paramsJson",
                "dateCreated",
                "dateUpdated",
            ])
            ->from(SearchSessions::TABLE_SEARCHES)
            ->where(["searchId" => $searchId, "userId" => $userId])
            ->one();

        if (!$row) {
            return $this->asJson([
                "success" => false,
                "error" => "Search not found",
            ]);
        }

        $response = [
            "success" => true,
            "status" => $row["status"],
            "scannedCount" => (int) $row["scannedCount"],
            "totalCount" => (int) $row["totalCount"],
            // Seconds since the search was started, from the server's clock,
            // so a reopened search shows the real elapsed time.
            "elapsedSeconds" => max(
                0,
                time() - DateTimeHelper::toDateTime($row["dateCreated"])->getTimestamp()
            ),
            // Matches found so far while the search is still running.
            "resultCount" => (int) $row["resultCount"],
            "params" => $row["paramsJson"]
                ? Json::decode($row["paramsJson"])
                : null,
            // A running job writes progress every few hundred entries. If
            // that goes quiet, the worker was probably killed (out of
            // memory, restarted) and the row will never be finalized.
            "idleSeconds" => max(
                0,
                time() - DateTimeHelper::toDateTime($row["dateUpdated"])->getTimestamp()
            ),
        ];

        if ($row["status"] === "error") {
            $response["error"] = $row["errorMessage"];
        }

        if (in_array($row["status"], ["completed", "cancelled"], true)) {
            $decoded = $row["resultsJson"]
                ? Json::decode($row["resultsJson"])
                : ["totalResults" => 0, "groupedResults" => []];
            $response["totalResults"] = $decoded["totalResults"];
            $response["totalMatches"] = $decoded["totalMatches"] ?? $decoded["totalResults"];
            $response["groupedResults"] = $decoded["groupedResults"];
            $response["truncated"] = $decoded["truncated"] ?? false;
            $response["stopReason"] = $decoded["stopReason"] ?? null;
            $response["replaced"] = $row["replacedJson"]
                ? Json::decode($row["replacedJson"])
                : [];
        }

        return $this->asJson($response);
    }

    /**
     * Searches that are queued or running and still showing signs of life.
     * A worker that died leaves its row "running" forever, so a row that
     * has been silent this long no longer counts and can't block anyone.
     */
    private function activeSearches(): Query
    {
        return (new Query())
            ->from(SearchSessions::TABLE_SEARCHES)
            ->where(["status" => ["queued", "running"]])
            ->andWhere([
                ">=",
                "dateUpdated",
                Db::prepareDateForDb(
                    (new \DateTime())->modify("-" . self::ACTIVE_WINDOW_SECONDS . " seconds")
                ),
            ]);
    }

    /**
     * The current user's latest searches, newest first, without their
     * results (those are loaded through actionSearchStatus() on demand).
     * This is what lets someone leave the plugin, or reload, and find a
     * running search again or open a finished one instead of re-running it.
     */
    public function actionSearchRecent(): Response
    {
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:search")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $rows = (new Query())
            ->select([
                "searchId",
                "status",
                "scannedCount",
                "totalCount",
                "resultCount",
                "paramsJson",
                "dateCreated",
                "dateUpdated",
            ])
            ->from(SearchSessions::TABLE_SEARCHES)
            ->where(["userId" => Craft::$app->getUser()->getId()])
            ->orderBy(["id" => SORT_DESC])
            ->limit(10)
            ->all();

        $searches = array_map(function (array $row) {
            $params = $row["paramsJson"] ? Json::decode($row["paramsJson"]) : [];

            return [
                "searchId" => $row["searchId"],
                "status" => $row["status"],
                "query" => $params["query"] ?? "",
                "scannedCount" => (int) $row["scannedCount"],
                "totalCount" => (int) $row["totalCount"],
                "resultCount" => $row["resultCount"] === null
                    ? null
                    : (int) $row["resultCount"],
                "dateCreated" => DateTimeHelper::toDateTime($row["dateCreated"])
                    ->format(\DateTime::ATOM),
                "idleSeconds" => max(
                    0,
                    time() - DateTimeHelper::toDateTime($row["dateUpdated"])->getTimestamp()
                ),
            ];
        }, $rows);

        return $this->asJson(["success" => true, "searches" => $searches]);
    }

    /**
     * The full text of one result's field, read now. Rows of a search carry
     * only a short context, so the detail view asks for the whole value
     * when it is opened. "stale" says the field was edited after the
     * search, in which case the match positions no longer apply.
     */
    public function actionFieldValue(): Response
    {
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:search")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $request = Craft::$app->getRequest();
        $result = [
            "elementType" => (string) $request->getParam("elementType", ""),
            "elementId" => (int) $request->getParam("elementId", 0),
            "fieldHandle" => (string) $request->getParam("fieldHandle", ""),
            "siteId" => (int) $request->getParam("siteId", 0),
            "valueHash" => $request->getParam("valueHash"),
        ];

        $service = Editrix::$plugin->replace;

        if (
            !$result["elementId"] ||
            $result["fieldHandle"] === "" ||
            !$service->isReplaceableField($result)
        ) {
            return $this->asJson([
                "success" => false,
                "error" => "Invalid result data",
            ]);
        }

        $value = $service->currentValueFor($result);

        if ($value === null) {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t("editrix", "This content no longer exists."),
            ]);
        }

        return $this->asJson([
            "success" => true,
            "value" => $value,
            "stale" => !$service->matchesSearchSnapshot($result, $value),
        ]);
    }

    public function actionSearchCancel(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:search")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $searchId = Craft::$app->getRequest()->getBodyParam("id", "");
        $userId = Craft::$app->getUser()->getId();

        Craft::$app
            ->getDb()
            ->createCommand()
            ->update(
                SearchSessions::TABLE_SEARCHES,
                [
                    "status" => "cancelled",
                    "dateUpdated" => Db::prepareDateForDb(new \DateTime()),
                ],
                [
                    "searchId" => $searchId,
                    "userId" => $userId,
                    "status" => ["queued", "running"],
                ]
            )
            ->execute();

        return $this->asJson(["success" => true]);
    }

    /**
     * Parses and validates the search request body - shared by the
     * synchronous actionSearch() and the background actionSearchStart(), so
     * both apply the exact same license gates and defaults.
     *
     * @return array{error?: array, params?: array}
     */
    private function parseSearchParams(): array
    {
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
            return [
                "error" => [
                    "success" => false,
                    "error" => Craft::t(
                        "editrix",
                        "Search query is required"
                    ),
                ],
            ];
        }

        $license = Editrix::$plugin->license;

        if ($useRegex && !$license->hasFeature(LicenseService::FEATURE_REGEX)) {
            return [
                "error" => [
                    "success" => false,
                    "error" => Craft::t(
                        "editrix",
                        "Regex is only available in Pro edition"
                    ),
                    "upgradeRequired" => true,
                ],
            ];
        }

        if (
            $wholeWords &&
            !$license->hasFeature(LicenseService::FEATURE_WHOLE_WORDS)
        ) {
            return [
                "error" => [
                    "success" => false,
                    "error" => Craft::t(
                        "editrix",
                        "Whole words matching requires Standard or Pro edition"
                    ),
                    "upgradeRequired" => true,
                ],
            ];
        }

        if (
            ($allSites || !empty($siteIds)) &&
            !$license->hasFeature(LicenseService::FEATURE_MULTISITE)
        ) {
            return [
                "error" => [
                    "success" => false,
                    "error" => Craft::t(
                        "editrix",
                        "Multi-site search requires Pro edition"
                    ),
                    "upgradeRequired" => true,
                ],
            ];
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
            return [
                "error" => [
                    "success" => false,
                    "error" => Craft::t(
                        "editrix",
                        "Invalid regular expression"
                    ),
                ],
            ];
        }

        return [
            "params" => [
                "query" => $query,
                "searchSiteId" => $allSites ? null : (int) $siteId,
                "allSites" => $allSites,
                "useRegex" => $useRegex,
                "caseInsensitive" => $caseInsensitive,
                "wholeWords" => $wholeWords,
                "dryRun" => $dryRun,
                "searchEntries" => $searchEntries,
                "searchGlobals" => $searchGlobals,
                "searchMatrix" => $searchMatrix,
                "searchCategories" => $searchCategories,
                "sections" => is_array($sections) ? $sections : [],
                "fields" => is_array($fields) ? $fields : [],
                "entryTypes" => is_array($entryTypes) ? $entryTypes : [],
                "siteIds" => $siteIds,
            ],
        ];
    }

    /**
     * The search form as the user filled it, in the frontend's own shape,
     * so a saved search can put the form back exactly as it was.
     */
    private function formParams(): array
    {
        $body = Craft::$app->getRequest()->getBodyParams();

        return array_intersect_key($body, array_flip([
            "query",
            "siteId",
            "allSites",
            "siteIds",
            "useRegex",
            "caseInsensitive",
            "wholeWords",
            "dryRun",
            "searchEntries",
            "searchGlobals",
            "searchMatrix",
            "searchCategories",
            "sections",
            "fields",
            "entryTypes",
        ]));
    }

    private function searchOptions(array $p): array
    {
        return [
            "searchEntries" => $p["searchEntries"],
            "searchGlobals" => $p["searchGlobals"],
            "searchMatrix" => $p["searchMatrix"],
            "searchCategories" => $p["searchCategories"],
            "wholeWords" => $p["wholeWords"],
            "sections" => $p["sections"],
            "fields" => $p["fields"],
            "entryTypes" => $p["entryTypes"],
            "siteIds" => $p["siteIds"],
        ];
    }

    private function logScope(array $p): array
    {
        return [
            "siteId" => $p["searchSiteId"],
            "allSites" => $p["allSites"],
            "siteIds" => $p["siteIds"],
            "searchEntries" => $p["searchEntries"],
            "searchGlobals" => $p["searchGlobals"],
            "searchMatrix" => $p["searchMatrix"],
            "searchCategories" => $p["searchCategories"],
            "sections" => $p["sections"],
            "fields" => $p["fields"],
            "entryTypes" => $p["entryTypes"],
        ];
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
            "searchStartUrl" => \craft\helpers\UrlHelper::actionUrl(
                "editrix/search/search-start"
            ),
            "searchStatusUrl" => \craft\helpers\UrlHelper::actionUrl(
                "editrix/search/search-status"
            ),
            "searchRecentUrl" => \craft\helpers\UrlHelper::actionUrl(
                "editrix/search/search-recent"
            ),
            "fieldValueUrl" => \craft\helpers\UrlHelper::actionUrl(
                "editrix/search/field-value"
            ),
            "searchCancelUrl" => \craft\helpers\UrlHelper::actionUrl(
                "editrix/search/search-cancel"
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
            "Scanning {scanned} of {total} entries ({pct}%)…",
            "Starting search…",
            "Cancel search",
            "{count} matches",
            "Hide matches",
            "Show all {count} matches",
            "and {count} more",
            "Sending your search…",
            "Waiting for the server to pick up your search…",
            "Counting the entries to search. On a large site this takes a few seconds.",
            "Preparing your results…",
            "Elapsed {time}",
            "Up to about {time} left",
            "You can leave this page. The search keeps running on the server, and you will find it here when you come back.",
            "Limit the search to a section or field in Segmented Search to finish much faster.",
            "A search stops at 5,000 matches. A more specific phrase gives you a complete list.",
            "Finished searches stay under Recent searches for a day.",
            "Dry Run Mode shows what a replace would do without saving anything.",
            "Every replace is logged, and you can revert it from Logs & History.",
            "Replaced",
            "Changed since search",
            "Its content changed after the search. Search again to see the current text.",
            "Replaced {count} matches.",
            "View in Logs & History",
            "{count} skipped because their content changed after the search. Search again to see the current text.",
            "Search again",
            "You already have a search running. Open it, or cancel it before starting another.",
            "Other searches are using the server right now. Try again in a few minutes.",
            "Search in progress",
            "Searching for \"{query}\"",
            "{count} matches so far",
            "Recent searches",
            "{count} results",
            "cancelled",
            "failed",
            "in progress",
            "Open",
            "This search is still waiting to start. Craft's queue may not be running on this server. Ask your host or developer to set up a queue worker or cron job, as described in the Editrix README.",
            "This search has stopped reporting progress, so the server may have killed it. Cancel it and try a narrower search.",
            "Showing the first {count} results. Narrow your search for more precise results.",
            "Search stopped early to protect the server (memory). Showing the matches found so far. Narrow your search to a section or field to cover everything.",
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
