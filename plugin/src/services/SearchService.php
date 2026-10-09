<?php

namespace brandindustry\editrix\services;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Category;
use craft\fields\Matrix;
use craft\db\Query;
use craft\db\Table;
use yii\db\Expression;
use craft\fields\Tags;
use brandindustry\editrix\helpers\Access;
use brandindustry\editrix\helpers\FieldValue;
use brandindustry\editrix\helpers\Regex;
use brandindustry\editrix\helpers\RegexException;
use brandindustry\editrix\helpers\HtmlText;
use brandindustry\editrix\helpers\Prefilter;
use brandindustry\editrix\models\SearchResult;
use brandindustry\editrix\services\ReplaceService;
use brandindustry\editrix\Editrix;

class SearchService extends Component
{
    /**
     * Hard ceiling on accumulated matches for a single search. Results are
     * built up as one in-memory array and serialized into one JSON blob at
     * the end (see SearchJob) - on a large site a broad query can produce
     * hundreds of thousands of matches, which can exhaust a queue worker's
     * memory long before the UI could ever usefully show them all. Past
     * this point we stop scanning and flag the search as truncated.
     */
    public const HARD_CEILING = 50000;

    /**
     * How many rows a search may return: the edition's limit, lowered by
     * the optional setting, and never above the hard ceiling (the JSON for
     * a search is stored in one database value and sent to the browser in
     * one response).
     */
    public static function resultLimit(int $editionLimit, ?int $setting = null): int
    {
        $limit = $editionLimit > 0 ? $editionLimit : self::HARD_CEILING;

        if ($setting !== null && $setting > 0) {
            $limit = min($limit, $setting);
        }

        return min($limit, self::HARD_CEILING);
    }

    /** The limit that applies to this site right now. */
    public function currentResultLimit(): int
    {
        return self::resultLimit(
            Editrix::$plugin->license->getLimit("searchResultLimit"),
            Editrix::$plugin->getSettings()->maxSearchResults
        );
    }

    /**
     * The limit Pro would give, if this site is not on Pro and that is
     * higher; null otherwise. Used to say what upgrading would change.
     */
    public function upgradeResultLimit(): ?int
    {
        if (Editrix::$plugin->edition === Editrix::EDITION_PRO) {
            return null;
        }

        $pro = self::resultLimit(
            Editrix::$plugin->license->getLimitFor(Editrix::EDITION_PRO, "searchResultLimit")
        );

        return $pro > $this->currentResultLimit() ? $pro : null;
    }

    /**
     * Share of PHP's memory_limit a scan may use before it stops and
     * returns what it has, instead of being killed with nothing to show.
     */
    private const MEMORY_STOP_RATIO = 0.8;

    /**
     * How many entry ids one window covers when scanning. Small enough that
     * MySQL answers from a short primary-key range, large enough that a
     * 3M-row table needs only a few hundred of them.
     */
    private const ID_WINDOW = 10000;

    /** Matches already found on earlier sites, so progress counts across sites. */
    private int $matchesBase = 0;

    /** Why the last search ended before covering everything, if it did. */
    private ?string $stopReason = null;

    /** Who the last search ran for; what they may not view is left out. */
    private ?int $actingUserId = null;

    /** Whether the last search also looked in link and image addresses of rich text. */
    private bool $includeLinks = false;

    public function search(
        string $query,
        ?int $siteId = null,
        bool $useRegex = false,
        bool $caseSensitive = true,
        array $options = [],
        ?callable $onProgress = null,
        ?callable $isCancelled = null,
        array &$meta = []
    ): array {
        $results = [];
        $truncated = false;
        $this->stopReason = null;
        $this->matchesBase = 0;
        $this->actingUserId = ($options["userId"] ?? null) ?: null;
        $this->includeLinks = (bool) ($options["includeLinks"] ?? false);
        $limit = $this->currentResultLimit();
        $maxResults = min($limit, (int) ($options["maxResults"] ?? $limit));
        $settings = Editrix::$plugin->getSettings();

        // Get search options
        $searchEntries = $options["searchEntries"] ?? $settings->searchEntries;
        $searchGlobals = $options["searchGlobals"] ?? $settings->searchGlobals;
        $searchMatrix = $options["searchMatrix"] ?? $settings->searchMatrix;
        $searchCategories =
            $options["searchCategories"] ?? $settings->searchCategories;
        $wholeWords = $options["wholeWords"] ?? false;

        $sectionFilter = $options["sections"] ?? [];
        $fieldFilter = $options["fields"] ?? [];
        $entryTypeFilter = $options["entryTypes"] ?? [];

        // Specific entries (a list from a spreadsheet, say). Only entries
        // are searched then, and the scan goes straight to those ids.
        $elementIds = array_values(array_unique(array_filter(
            array_map("intval", (array) ($options["elementIds"] ?? [])),
            fn($id) => $id > 0
        )));
        if ($elementIds !== []) {
            $searchGlobals = false;
            $searchCategories = false;
        }

        $sites = $this->getSitesToSearch($siteId, $options["siteIds"] ?? []);

        $tokens = ($options["sqlPrefilter"] ?? $settings->sqlPrefilter)
            ? $this->prefilterTokens($query, $useRegex)
            : null;
        $meta["prefiltered"] = $tokens !== null;

        foreach ($sites as $site) {
            if ($isCancelled && $isCancelled()) {
                break;
            }

            if ($searchEntries) {
                $this->matchesBase = count($results);
                $results = array_merge(
                    $results,
                    $this->searchInEntries(
                        $query,
                        $site->id,
                        $useRegex,
                        $caseSensitive,
                        $searchMatrix,
                        $wholeWords,
                        $sectionFilter,
                        $fieldFilter,
                        $entryTypeFilter,
                        $onProgress,
                        $isCancelled,
                        $maxResults,
                        $tokens,
                        $elementIds
                    )
                );

                if ($this->countRows($results) >= $maxResults) {
                    $truncated = true;
                    $this->stopReason ??= "results";
                } elseif ($this->stopReason !== null) {
                    $truncated = true;
                }
            }

            if (!$truncated && $searchGlobals) {
                $results = array_merge(
                    $results,
                    $this->searchInGlobals(
                        $query,
                        $site->id,
                        $useRegex,
                        $caseSensitive,
                        $wholeWords,
                        $fieldFilter
                    )
                );

                if ($this->countRows($results) >= $maxResults) {
                    $truncated = true;
                    $this->stopReason ??= "results";
                }
            }

            if (
                !$truncated &&
                $searchCategories &&
                Editrix::$plugin->hasFeature(
                    LicenseService::FEATURE_SEARCH_CATEGORIES
                )
            ) {
                $results = array_merge(
                    $results,
                    $this->searchInCategories(
                        $query,
                        $site->id,
                        $useRegex,
                        $caseSensitive,
                        $wholeWords,
                        $fieldFilter
                    )
                );

                if ($this->countRows($results) >= $maxResults) {
                    $truncated = true;
                    $this->stopReason ??= "results";
                }
            }

            if ($truncated) {
                break;
            }
        }

        if ($truncated) {
            $results = $this->capRows($results, $maxResults);
        }

        $meta["truncated"] = $truncated;
        $meta["stopReason"] = $truncated ? $this->stopReason ?? "results" : null;
        $meta["resultLimit"] = $limit;
        $meta["upgradeLimit"] = $truncated ? $this->upgradeResultLimit() : null;
        $meta["resultCount"] = $this->countRows($results);
        $meta["matchCount"] = count($results);

        return $results;
    }

    /**
     * The most contexts kept per row. A field can match thousands of times;
     * the row still reports the true count in "matchCount".
     */
    public const MAX_CONTEXTS_PER_ROW = 50;

    /**
     * What a row of results stands for: one field of one element on one
     * site, however many times it matches. Tag matches are the exception,
     * because each tag is its own value and can't be replaced anyway.
     */
    private function rowKey(SearchResult $result): string
    {
        if ($result->readOnlyReason === "tag") {
            return $result->getUniqueKey();
        }

        return ReplaceService::keyFor([
            "elementType" => $result->elementType,
            "elementId" => $result->elementId,
            "fieldHandle" => $result->fieldHandle,
            "siteId" => $result->siteId,
        ]);
    }

    private function countRows(array $results): int
    {
        $keys = [];
        foreach ($results as $result) {
            $keys[$this->rowKey($result)] = true;
        }

        return count($keys);
    }

    /** Keeps every match of the first $max rows, in order. */
    private function capRows(array $results, int $max): array
    {
        $keys = [];
        $kept = [];

        foreach ($results as $result) {
            $key = $this->rowKey($result);

            if (!isset($keys[$key])) {
                if (count($keys) >= $max) {
                    continue;
                }
                $keys[$key] = true;
            }

            $kept[] = $result;
        }

        return $kept;
    }

    /**
     * Groups a flat list of SearchResult objects by site and then by row
     * (see rowKey), in the exact shape the frontend expects - shared by the
     * synchronous search endpoint and SearchJob's background results, so
     * both produce identical output for the same query.
     *
     * One row per field means the field's value is stored once, however
     * many times it matches, and what the user selects is what Replace
     * actually acts on (it rewrites every occurrence in the field).
     */
    public function groupResultsBySite(array $results): array
    {
        $grouped = [];
        $rowIndex = [];

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

            $key = $this->rowKey($result);
            $match = [
                "start" => $result->matchStart,
                "end" => $result->matchEnd,
                "context" => $result->matchContext,
                "readOnly" => $result->readOnly,
                "readOnlyReason" => $result->readOnlyReason,
            ];

            if (isset($rowIndex[$siteHandle][$key])) {
                $row = &$grouped[$siteHandle]["results"][$rowIndex[$siteHandle][$key]];
                $row["matchCount"]++;
                if (count($row["matches"]) < self::MAX_CONTEXTS_PER_ROW) {
                    $row["matches"][] = $match;
                }
                // A row can be replaced as long as one match in it can.
                if (!$result->readOnly) {
                    $row["readOnly"] = false;
                    $row["readOnlyReason"] = null;
                }
                unset($row);
                continue;
            }

            $rowIndex[$siteHandle][$key] = count($grouped[$siteHandle]["results"]);
            $row = [
                "uniqueKey" => $key,
                "matches" => [$match],
                "matchCount" => 1,
                "elementType" => $result->elementType,
                "elementId" => $result->elementId,
                "elementTitle" => $result->elementTitle,
                "sectionName" => $result->sectionName,
                "sectionHandle" => $result->sectionHandle,
                "fieldName" => $result->fieldName,
                "fieldHandle" => $result->fieldHandle,
                "matchContext" => $result->matchContext,
                "matchStart" => $result->matchStart,
                "matchEnd" => $result->matchEnd,
                "isRichText" => $result->isRichText,
                // Whether the search also looked in link addresses, so the
                // replace that follows reads the field the same way.
                "includeLinks" => $result->isRichText && $this->includeLinks,
                "cpEditUrl" => $result->getCpEditUrl(),
                "parentId" => $result->parentId,
                "parentTitle" => $result->parentTitle,
                "blockTypeHandle" => $result->blockTypeHandle,
                "siteId" => $result->siteId,
                "siteHandle" => $result->siteHandle,
                "readOnly" => $result->readOnly,
                "readOnlyReason" => $result->readOnlyReason,
            ];

            // The field's text is not stored with the row: it can be long
            // and every row of a big search would carry its own copy. A
            // fingerprint is enough to know later whether it changed, and
            // the text is read again when it is needed. A tag's value is
            // just its title, so that stays.
            if (
                !$row["readOnly"] &&
                ReplaceService::isProtected($result->fieldHandle, $result->sectionHandle)
            ) {
                $row["readOnly"] = true;
                $row["readOnlyReason"] = "protected";
            }

            if ($result->readOnlyReason === "tag") {
                $row["fieldValue"] = $result->fieldValue;
            } else {
                $row["valueHash"] = $result->valueHash;
            }

            $grouped[$siteHandle]["results"][] = $row;
        }

        return $grouped;
    }

    private function getSitesToSearch(
        ?int $siteId,
        array $siteIds = []
    ): array {
        if (!empty($siteIds)) {
            return array_values(
                array_filter(
                    Craft::$app->getSites()->getAllSites(),
                    fn($site) => in_array($site->id, $siteIds)
                )
            );
        }

        if ($siteId !== null) {
            $site = Craft::$app->getSites()->getSiteById($siteId);
            return $site ? [$site] : [];
        }
        return Craft::$app->getSites()->getAllSites();
    }

    private function searchInEntries(
        string $query,
        int $siteId,
        bool $useRegex,
        bool $caseSensitive,
        bool $searchMatrix,
        bool $wholeWords,
        array $sectionFilter,
        array $fieldFilter,
        array $entryTypeFilter,
        ?callable $onProgress = null,
        ?callable $isCancelled = null,
        int $maxResults = self::HARD_CEILING,
        ?array $tokens = null,
        array $elementIds = []
    ): array {
        // A short list needs no prefilter and no scan of the id range.
        if ($elementIds !== []) {
            $tokens = null;
        }

        $results = [];
        $site = Craft::$app->getSites()->getSiteById($siteId);

        $entryQuery = Entry::find()
            ->siteId($siteId)
            ->status(null)
            ->drafts(false)
            ->revisions(false);

        if (!empty($sectionFilter)) {
            $entryQuery->section($sectionFilter);
        }

        if (!empty($entryTypeFilter)) {
            $entryQuery->type($entryTypeFilter);
        }

        $scoped = !empty($sectionFilter) || !empty($entryTypeFilter);

        // With a section or type chosen, its ids are listed once up front;
        // that list is also the total, so Craft's own count is not needed.
        $scopeIds = $tokens !== null && $scoped && $elementIds === []
            ? $this->scopeIds($entryQuery)
            : null;

        $total = 0;
        if ($onProgress) {
            $total = $elementIds !== []
                ? count($elementIds)
                : ($scopeIds !== null
                    ? count($scopeIds)
                    : (clone $entryQuery)->count());
        }
        $scanned = 0;
        $rows = [];

        // With the prefilter most windows hold no candidate at all, so the
        // per-entry checks below would almost never run. After each window
        // this reports progress by how much of the id range is behind us,
        // and honours cancel and the memory guard.
        $covered = 0.0;
        $checkpoint = function (float $fraction) use (&$results, &$covered, $total, $onProgress, $isCancelled): bool {
            $covered = $fraction;

            if ($onProgress) {
                $onProgress(
                    (int) round($total * $fraction),
                    $total,
                    $this->matchesBase + count($results)
                );
            }
            if ($isCancelled && $isCancelled()) {
                return false;
            }
            if ($this->memoryLow()) {
                $this->stopReason = "memory";
                return false;
            }

            return true;
        };

        $entries = $elementIds !== []
            ? $this->entriesById($entryQuery, $elementIds)
            : $this->eachEntry($entryQuery, $tokens, $siteId, $tokens !== null ? $checkpoint : null, $scoped, $scopeIds);

        foreach ($entries as $entry) {
            $scanned++;

            if ($scanned % 250 === 0) {
                if ($onProgress && $tokens === null) {
                    $onProgress($scanned, $total, $this->matchesBase + count($results));
                }
                if ($isCancelled && $isCancelled()) {
                    break;
                }
                if ($this->memoryLow()) {
                    $this->stopReason = "memory";
                    break;
                }
            }

            $before = count($results);

            try {
                $this->searchOneEntry(
                    $entry,
                    $query,
                    $siteId,
                    $site,
                    $useRegex,
                    $caseSensitive,
                    $searchMatrix,
                    $wholeWords,
                    $fieldFilter,
                    $results
                );
            } catch (RegexException $e) {
                // The pattern, not the entry, is the problem: every entry
                // would fail the same way, so say so instead of skipping.
                throw $e;
            } catch (\Throwable $e) {
                // One broken/orphaned entry (e.g. a nested Craft 5 entry
                // whose owner was deleted, tripping up another plugin's own
                // element-population listener) must not abort a search that
                // could otherwise cover hundreds of thousands of entries.
                Craft::error(
                    "Editrix: skipped entry #{$entry->id} during search - " .
                        $e->getMessage(),
                    __METHOD__
                );
            }

            // Craft's permissions apply to what a search shows. Checked only
            // for an entry that matched, so a scan of the whole site does not
            // pay for it on every entry.
            if (
                $this->actingUserId &&
                count($results) > $before &&
                !Access::canView($entry, $this->actingUserId)
            ) {
                array_splice($results, $before);
            }

            // The cap is on rows (fields), counted as they appear, so a field
            // that matches many times doesn't use it up.
            for ($i = $before, $n = count($results); $i < $n; $i++) {
                $rows[$this->rowKey($results[$i])] = true;
            }

            if (count($rows) >= $maxResults) {
                break;
            }
        }

        if ($onProgress) {
            $onProgress(
                $tokens !== null ? (int) round($total * $covered) : $scanned,
                $total,
                $this->matchesBase + count($results)
            );
        }

        return $results;
    }

    /**
     * Walks the query newest id first without holding more than one batch.
     *
     * Craft's own entry query pays for the whole joined table on every
     * LIMIT/OFFSET page (on a site with ~2M element rows that is seconds
     * per 100 entries), so this works in windows of ids instead: the ids
     * in one window are listed with a query MySQL can answer from a short
     * primary-key range, then hydrated 100 at a time. If hydrating a batch
     * throws (a bad row, or another plugin's populate listener), it is
     * retried one entry at a time so only the entries that actually fail
     * are skipped.
     */
    protected function eachEntry(
        $entryQuery,
        ?array $tokens = null,
        int $siteId = 0,
        ?callable $afterWindow = null,
        bool $scoped = false,
        ?array $scopeIds = null
    ): \Generator {
        $bounds = (new Query())
            ->select(["min" => "MIN([[id]])", "max" => "MAX([[id]])"])
            ->from(Table::ENTRIES)
            ->one();

        if (!$bounds || $bounds["max"] === null) {
            return;
        }

        $min = (int) $bounds["min"];
        $top = (int) $bounds["max"] + 1;
        $span = max(1, $top - $min);

        // Entries that match only through something they own (a Matrix
        // block, an embedded entry) or a tag they carry are found from the
        // owned row, so an owner can turn up in a window other than its
        // own. Each is loaded once.
        $seen = [];
        $tagOwners = [];
        if ($tokens !== null) {
            try {
                $tagOwners = $this->tagOwnerIds($tokens, $siteId);
            } catch (\Throwable $e) {
                // Without knowing which entries carry a matching tag the
                // prefilter could miss some, so scan everything instead.
                $tokens = null;
            }
        }

        // Skips what an earlier window already yielded, then hydrates the rest.
        $emit = function (array $ids) use (&$seen, $entryQuery, $tokens) {
            if ($tokens !== null) {
                $ids = array_values(array_filter($ids, fn($id) => !isset($seen[$id])));
                foreach ($ids as $id) {
                    $seen[$id] = true;
                }
                rsort($ids);
            }

            foreach (array_chunk($ids, 100) as $chunk) {
                yield from $this->loadEntries($entryQuery, $chunk);
            }
        };

        // A section or entry type is chosen: its ids are listed once (a
        // section of 200,000 entries is a few MB of ids), and the text check
        // only looks at those rows. Walking the whole id range instead would
        // pay a query for every window of a site's 3 million ids.
        if ($scopeIds === null && $tokens !== null && $scoped) {
            $scopeIds = $this->scopeIds($entryQuery);
        }

        if ($scopeIds !== null) {
            $count = max(1, count($scopeIds));
            $done = 0;

            foreach (array_chunk($scopeIds, self::ID_WINDOW) as $window) {
                $ids = $this->candidateIdsAmong($tokens, $window, $siteId, $tagOwners) ?? $window;
                yield from $emit($ids);

                $done += count($window);
                if ($afterWindow !== null && $afterWindow($done / $count) === false) {
                    return;
                }
            }

            return;
        }

        for ($hi = $top; $hi > $min; $hi -= self::ID_WINDOW) {
            $lo = max($min, $hi - self::ID_WINDOW);
            $ids = null;

            if ($tokens !== null) {
                $ids = $this->candidateIds($tokens, $lo, $hi, $siteId, $tagOwners);
            }

            if ($ids === null) {
                // No prefilter, or it failed for this window: list every id.
                // Never skip a window because the shortcut broke.
                try {
                    $ids = (clone $entryQuery)
                        ->id("and, >= {$lo}, < {$hi}")
                        ->orderBy("elements.id DESC")
                        ->ids();
                } catch (\Throwable $e) {
                    Craft::error(
                        "Editrix: could not list entries {$lo}-{$hi}, skipping that range - " .
                            $e->getMessage(),
                        __METHOD__
                    );
                    continue;
                }
            }

            yield from $emit($ids);

            if ($afterWindow !== null && $afterWindow(($top - $lo) / $span) === false) {
                return;
            }
        }
    }

    /**
     * Every id the query covers, newest first, or null when listing them
     * failed (the caller then walks the id range instead).
     *
     * @return int[]|null
     */
    protected function scopeIds($entryQuery): ?array
    {
        try {
            $ids = array_map("intval", (clone $entryQuery)->orderBy("elements.id")->ids());
            rsort($ids);

            return $ids;
        } catch (\Throwable $e) {
            Craft::error(
                "Editrix: could not list the chosen entries, walking the id range instead - " . $e->getMessage(),
                __METHOD__
            );

            return null;
        }
    }

    /** The entries with these ids that fit the scope, newest first. */
    private function entriesById($entryQuery, array $ids): \Generator
    {
        rsort($ids);

        foreach (array_chunk($ids, 100) as $chunk) {
            yield from $this->loadEntries($entryQuery, $chunk);
        }
    }

    /**
     * The words to prefilter on, or null to scan everything. A site that
     * has a field whose text lives outside the entry's own row and is not
     * covered by candidateIds() must not use the prefilter; at present
     * every searched kind is covered.
     */
    protected function prefilterTokens(string $query, bool $useRegex): ?array
    {
        return $useRegex ? Prefilter::forRegex($query) : Prefilter::forText($query);
    }

    /** A column as text that compares ignoring case, on either database. */
    private function textColumn(string $column): Expression
    {
        $db = Craft::$app->getDb();
        $quoted = $db->quoteColumnName($column);

        return new Expression(
            $db->getIsPgsql()
                ? "CAST({$quoted} AS TEXT)"
                : "CAST({$quoted} AS CHAR) COLLATE utf8mb4_unicode_ci"
        );
    }

    /** Every word must appear in the column; ignores case on either database. */
    private function likeAll(Expression|string $column, array $tokens): array
    {
        $operator = Craft::$app->getDb()->getIsPgsql() ? "ilike" : "like";
        $condition = ["and"];

        foreach ($tokens as $token) {
            $condition[] = [$operator, $column, $token];
        }

        return $condition;
    }

    /**
     * Ids of the entries in [$lo, $hi) that could contain a match, plus
     * those among $tagOwners. Null when the database could not answer, so
     * the caller scans the window instead.
     *
     * A row is a candidate when its title, or its content, contains every
     * word. Content is the field values of that one element; text inside a
     * nested entry is in the nested entry's own row, so those rows are
     * mapped up to the entry that owns them.
     */
    protected function candidateIds(
        array $tokens,
        int $lo,
        int $hi,
        int $siteId,
        array $tagOwners
    ): ?array {
        try {
            $matched = (new Query())
                ->select(["elements_sites.elementId"])
                ->distinct()
                ->from(["elements_sites" => Table::ELEMENTS_SITES])
                // Revisions are over half the rows on a site that keeps them,
                // and a search never reads them.
                ->innerJoin(
                    ["elements" => Table::ELEMENTS],
                    "[[elements.id]] = [[elements_sites.elementId]]"
                )
                ->where(["elements_sites.siteId" => $siteId])
                ->andWhere([
                    "elements.draftId" => null,
                    "elements.revisionId" => null,
                    "elements.dateDeleted" => null,
                ])
                ->andWhere(["between", "elements_sites.elementId", $lo, $hi - 1])
                ->andWhere([
                    "or",
                    $this->likeAll($this->textColumn("elements_sites.title"), $tokens),
                    $this->likeAll($this->textColumn("elements_sites.content"), $tokens),
                ])
                ->column();

            $ids = array_keys($this->topLevelIds(array_map("intval", $matched)));

            foreach ($tagOwners as $id) {
                if ($id >= $lo && $id < $hi) {
                    $ids[] = $id;
                }
            }

            return array_values(array_unique(array_map("intval", $ids)));
        } catch (\Throwable $e) {
            Craft::error(
                "Editrix: prefilter failed for {$lo}-{$hi}, scanning it instead - " . $e->getMessage(),
                __METHOD__
            );

            return null;
        }
    }

    /**
     * Of these entry ids (already limited to the chosen sections and types),
     * those that could contain a match: their own row contains every word,
     * or something they own does (a Matrix block, an embedded entry), or
     * they carry a matching tag. Null when the database could not answer.
     *
     * @param int[] $ids
     * @return int[]|null
     */
    protected function candidateIdsAmong(
        array $tokens,
        array $ids,
        int $siteId,
        array $tagOwners
    ): ?array {
        if ($ids === []) {
            return [];
        }

        try {
            $neoOwner = $this->neoOwnerColumn();
            $matched = [];
            $level = $ids;

            // The entries' own rows, then the rows of what they own, level
            // by level (a block inside a block).
            for ($depth = 0; $depth < 6 && $level !== []; $depth++) {
                foreach (array_chunk($level, 5000) as $chunk) {
                    foreach (
                        (new Query())
                            ->select(["elements_sites.elementId"])
                            ->from(["elements_sites" => Table::ELEMENTS_SITES])
                            ->where(["elements_sites.siteId" => $siteId])
                            ->andWhere(["elements_sites.elementId" => $chunk])
                            ->andWhere([
                                "or",
                                $this->likeAll($this->textColumn("elements_sites.title"), $tokens),
                                $this->likeAll($this->textColumn("elements_sites.content"), $tokens),
                            ])
                            ->column() as $id
                    ) {
                        $matched[(int) $id] = true;
                    }
                }

                $children = [];
                foreach (array_chunk($level, 5000) as $chunk) {
                    $children = array_merge(
                        $children,
                        (new Query())
                            ->select(["id"])
                            ->from(Table::ENTRIES)
                            ->where(["primaryOwnerId" => $chunk])
                            ->column()
                    );

                    if ($neoOwner !== null) {
                        $children = array_merge(
                            $children,
                            (new Query())
                                ->select(["id"])
                                ->from("{{%neoblocks}}")
                                ->where([$neoOwner => $chunk])
                                ->column()
                        );
                    }
                }

                $level = array_map("intval", array_unique($children));
            }

            $hits = array_keys($matched);
            $keep = $this->topLevelIds(array_map("intval", $hits));
            foreach ($tagOwners as $id) {
                $keep[$id] = true;
            }

            return array_values(array_filter($ids, fn($id) => isset($keep[$id])));
        } catch (\Throwable $e) {
            Craft::error(
                "Editrix: scoped prefilter failed, scanning the window instead - " . $e->getMessage(),
                __METHOD__
            );

            return null;
        }
    }

    /**
     * The entries that carry a tag whose title contains the words. A tag's
     * text is in the tag's row, not the entry's, so entries are found
     * through the relations that point at matching tags.
     *
     * @return int[]
     */
    private function tagOwnerIds(array $tokens, int $siteId): array
    {
        try {
            $tagIds = (new Query())
                ->select(["elements_sites.elementId"])
                ->from(["elements_sites" => Table::ELEMENTS_SITES])
                ->innerJoin(["tags" => Table::TAGS], "[[tags.id]] = [[elements_sites.elementId]]")
                ->where(["elements_sites.siteId" => $siteId])
                ->andWhere($this->likeAll($this->textColumn("elements_sites.title"), $tokens))
                ->column();

            if ($tagIds === []) {
                return [];
            }

            $sources = [];
            foreach (array_chunk($tagIds, 2000) as $chunk) {
                $sources = array_merge(
                    $sources,
                    (new Query())
                        ->select(["sourceId"])
                        ->distinct()
                        ->from(Table::RELATIONS)
                        ->where(["targetId" => $chunk])
                        ->column()
                );
            }

            return array_keys($this->topLevelIds(array_map("intval", $sources)));
        } catch (\Throwable $e) {
            Craft::error("Editrix: tag prefilter failed - " . $e->getMessage(), __METHOD__);

            // Unknown, so say "everything": the caller's windows then run
            // unfiltered because the exception also reaches candidateIds.
            throw $e;
        }
    }

    /**
     * The column of Neo's block table that names the owner: `primaryOwnerId`
     * from Neo 5, `ownerId` before. Null when Neo is not installed.
     */
    protected function neoOwnerColumn(): ?string
    {
        $table = Craft::$app->getDb()->getTableSchema("{{%neoblocks}}", true);

        if (!$table) {
            return null;
        }

        foreach (["primaryOwnerId", "ownerId"] as $column) {
            if ($table->getColumn($column)) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Maps element ids to the top-level entries that hold them: an entry
     * that is not nested stands for itself, a nested one (Matrix block,
     * embedded entry, Neo block) stands for its owner, followed up to the
     * top. Ids that are not entries are dropped.
     *
     * @param int[] $ids
     * @return array<int, true>
     */
    private function topLevelIds(array $ids): array
    {
        $top = [];
        $neoOwner = $this->neoOwnerColumn();

        for ($depth = 0; $depth < 6 && $ids !== []; $depth++) {
            $next = [];

            foreach (array_chunk($ids, 5000) as $chunk) {
                $rows = (new Query())
                    ->select(["id", "primaryOwnerId"])
                    ->from(Table::ENTRIES)
                    ->where(["id" => $chunk])
                    ->all();

                foreach ($rows as $row) {
                    if ($row["primaryOwnerId"] === null) {
                        $top[(int) $row["id"]] = true;
                    } else {
                        $next[(int) $row["primaryOwnerId"]] = true;
                    }
                }

                if ($neoOwner !== null) {
                    foreach (
                        (new Query())
                            ->select([$neoOwner])
                            ->from("{{%neoblocks}}")
                            ->where(["id" => $chunk])
                            ->column() as $owner
                    ) {
                        $next[(int) $owner] = true;
                    }
                }
            }

            $ids = array_keys($next);
        }

        return $top;
    }

    /**
     * Hydrates these ids, in the order given. A failing batch is retried
     * one entry at a time.
     */
    private function loadEntries($entryQuery, array $ids): array
    {
        try {
            $byId = [];
            foreach ((clone $entryQuery)->id($ids)->limit(null)->all() as $entry) {
                $byId[$entry->id] = $entry;
            }
        } catch (\Throwable $e) {
            Craft::error(
                "Editrix: could not load a batch of entries, retrying one by one - " .
                    $e->getMessage(),
                __METHOD__
            );
            $byId = [];

            foreach ($ids as $id) {
                try {
                    $one = (clone $entryQuery)->id($id)->one();
                    if ($one) {
                        $byId[$one->id] = $one;
                    }
                } catch (\Throwable $inner) {
                    Craft::error(
                        "Editrix: skipped entry #{$id} - " . $inner->getMessage(),
                        __METHOD__
                    );
                }
            }
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * True when this process has used most of its memory_limit. Overridable
     * so tests can trip it without allocating gigabytes.
     */
    protected function memoryLow(): bool
    {
        $limit = $this->memoryLimitBytes();

        return $limit > 0 &&
            memory_get_usage(true) > $limit * self::MEMORY_STOP_RATIO;
    }

    private function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get("memory_limit"));

        if ($raw === "" || $raw === "-1") {
            return 0;
        }

        $bytes = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            "g" => $bytes * 1024 ** 3,
            "m" => $bytes * 1024 ** 2,
            "k" => $bytes * 1024,
            default => $bytes,
        };
    }

    protected function searchOneEntry(
        Entry $entry,
        string $query,
        int $siteId,
        $site,
        bool $useRegex,
        bool $caseSensitive,
        bool $searchMatrix,
        bool $wholeWords,
        array $fieldFilter,
        array &$results
    ): void {
            $section = $entry->getSection();

            // On Craft 5, nested entries (e.g. Matrix blocks, which are now
            // regular Entry elements) don't belong to a section. They're
            // reached separately via searchInMatrixField() through their
            // owner, so skip them here to avoid duplicate/orphaned results.
            if (!$section) {
                return;
            }

            $fieldLayout = $entry->getFieldLayout();

            if (!$fieldLayout) {
                return;
            }

            if (
                (empty($fieldFilter) || in_array("title", $fieldFilter)) &&
                $entry->getType()->hasTitleField
            ) {
                $matches = $this->findMatches(
                    $entry->title,
                    $query,
                    $useRegex,
                    $caseSensitive,
                    $wholeWords
                );

                foreach ($matches as $match) {
                    $result = new SearchResult();
                    $result->elementType = "entry";
                    $result->elementId = $entry->id;
                    $result->elementTitle = $entry->title ?? "Untitled";
                    $result->sectionHandle = $section->handle;
                    $result->sectionName = $section->name;
                    $result->fieldHandle = "title";
                    $result->fieldName = Craft::t("editrix", "Title");
                    $result->siteId = $siteId;
                    $result->siteHandle = $site->handle;
                    $result->matchContext = $match["context"];
                    $result->valueHash = ReplaceService::hashValue((string) $entry->title);
                    $result->matchStart = $match["start"];
                    $result->matchEnd = $match["end"];
                    $results[] = $result;
                }
            }

            foreach ($fieldLayout->getCustomFields() as $field) {
                if (
                    !empty($fieldFilter) &&
                    !in_array($field->handle, $fieldFilter)
                ) {
                    continue;
                }

                if ($this->isSearchableField($field)) {
                    $value = $entry->getFieldValue($field->handle);
                    $isRich = $this->isRichTextField($field);
                    $matches = $isRich
                        ? $this->findMatchesInHtml(
                            $value,
                            $query,
                            $useRegex,
                            $caseSensitive,
                            $wholeWords
                        )
                        : $this->findMatches(
                            $value,
                            $query,
                            $useRegex,
                            $caseSensitive,
                            $wholeWords
                        );

                    foreach ($matches as $match) {
                        $result = new SearchResult();
                        $result->elementType = "entry";
                        $result->elementId = $entry->id;
                        $result->elementTitle = $entry->title ?? "Untitled";
                        $result->sectionHandle = $section->handle;
                        $result->sectionName = $section->name;
                        $result->fieldHandle = $field->handle;
                        $result->fieldName = $field->name;
                        $result->siteId = $siteId;
                        $result->siteHandle = $site->handle;
                        $result->matchContext = $match["context"];
                        $result->valueHash = ReplaceService::hashValue(FieldValue::toString($value));
                        $result->matchStart = $match["start"];
                        $result->matchEnd = $match["end"];
                        $result->isRichText = $isRich;
                        $this->applyCrossesTagReadOnly($result, $match);
                        $results[] = $result;
                    }
                }

                if (
                    $searchMatrix &&
                    $field instanceof Matrix &&
                    $this->matrixFieldMayMatch($field->handle, $fieldFilter)
                ) {
                    $matrixResults = $this->searchInMatrixField(
                        $entry,
                        $field,
                        $query,
                        $siteId,
                        $site->handle,
                        $section,
                        $useRegex,
                        $caseSensitive,
                        $wholeWords,
                        $fieldFilter
                    );
                    $results = array_merge($results, $matrixResults);
                }

                if ($searchMatrix && $this->isCkeditorField($field)) {
                    $nested = $this->ckeditorNestedEntries($entry, $field);

                    if ($nested) {
                        $results = array_merge(
                            $results,
                            $this->searchInMatrixField(
                                $entry,
                                $field,
                                $query,
                                $siteId,
                                $site->handle,
                                $section,
                                $useRegex,
                                $caseSensitive,
                                $wholeWords,
                                $fieldFilter,
                                $nested
                            )
                        );
                    }
                }

                if (
                    $searchMatrix &&
                    $this->isNeoField($field) &&
                    $this->matrixFieldMayMatch($field->handle, $fieldFilter)
                ) {
                    $neoResults = $this->searchInNeoField(
                        $entry,
                        $field,
                        $query,
                        $siteId,
                        $site->handle,
                        $section,
                        $useRegex,
                        $caseSensitive,
                        $wholeWords,
                        $fieldFilter
                    );
                    $results = array_merge($results, $neoResults);
                }

                if (
                    $field instanceof Tags &&
                    (empty($fieldFilter) || in_array($field->handle, $fieldFilter))
                ) {
                    $tagResults = $this->searchInTagsField(
                        $entry,
                        $field,
                        $query,
                        $siteId,
                        $site->handle,
                        $section,
                        $useRegex,
                        $caseSensitive,
                        $wholeWords
                    );
                    $results = array_merge($results, $tagResults);
                }
            }
    }

    /**
     * Tags fields link to Tag elements rather than storing text directly, so
     * matches here are shown for discovery only - see SearchResult::$readOnly.
     */
    private function searchInTagsField(
        Entry $entry,
        Tags $tagsField,
        string $query,
        int $siteId,
        string $siteHandle,
        $section,
        bool $useRegex,
        bool $caseSensitive,
        bool $wholeWords
    ): array {
        $results = [];

        $tagQuery = $entry->getFieldValue($tagsField->handle);
        if (!$tagQuery) {
            return $results;
        }

        foreach ($tagQuery->all() as $tag) {
            $matches = $this->findMatches(
                $tag->title,
                $query,
                $useRegex,
                $caseSensitive,
                $wholeWords
            );

            foreach ($matches as $match) {
                $result = new SearchResult();
                $result->elementType = "entry";
                $result->elementId = $entry->id;
                $result->elementTitle = $entry->title ?? "Untitled";
                $result->sectionHandle = $section->handle;
                $result->sectionName = $section->name;
                $result->fieldHandle = $tagsField->handle;
                $result->fieldName = $tagsField->name;
                $result->siteId = $siteId;
                $result->siteHandle = $siteHandle;
                $result->matchContext = $match["context"];
                $result->fieldValue = (string) $tag->title;
                $result->matchStart = $match["start"];
                $result->matchEnd = $match["end"];
                $result->readOnly = true;
                $result->readOnlyReason = "tag";
                $results[] = $result;
            }
        }

        return $results;
    }

    private function searchInMatrixField(
        Entry $entry,
        FieldInterface $matrixField,
        string $query,
        int $siteId,
        string $siteHandle,
        $section,
        bool $useRegex,
        bool $caseSensitive,
        bool $wholeWords,
        array $fieldFilter,
        ?iterable $blocks = null
    ): array {
        $results = [];

        if ($blocks === null) {
            $matrixQuery = $entry->getFieldValue($matrixField->handle);
            if (!$matrixQuery) {
                return $results;
            }

            $blocks = $matrixQuery->all();
        }

        foreach ($blocks as $block) {
            $blockType = $block->getType();
            $blockFields = $blockType->getCustomFields();

            foreach ($blockFields as $field) {
                if (
                    !$this->fieldInFilterScope(
                        $matrixField->handle,
                        $field->handle,
                        $fieldFilter
                    )
                ) {
                    continue;
                }

                if (!$this->isSearchableField($field)) {
                    continue;
                }

                $value = $block->getFieldValue($field->handle);
                $isRich = $this->isRichTextField($field);
                $matches = $isRich
                    ? $this->findMatchesInHtml(
                        $value,
                        $query,
                        $useRegex,
                        $caseSensitive,
                        $wholeWords
                    )
                    : $this->findMatches(
                        $value,
                        $query,
                        $useRegex,
                        $caseSensitive,
                        $wholeWords
                    );

                foreach ($matches as $match) {
                    $result = new SearchResult();
                    $result->elementType = "matrixBlock";
                    $result->elementId = $block->id;
                    $result->elementTitle = "Block #{$block->sortOrder}";
                    $result->sectionHandle = $section->handle;
                    $result->sectionName = $section->name;
                    $result->fieldHandle = $field->handle;
                    $result->fieldName = "{$matrixField->name} → {$field->name}";
                    $result->siteId = $siteId;
                    $result->siteHandle = $siteHandle;
                    $result->matchContext = $match["context"];
                    $result->valueHash = ReplaceService::hashValue(FieldValue::toString($value));
                    $result->matchStart = $match["start"];
                    $result->matchEnd = $match["end"];
                    $result->parentId = $entry->id;
                    $result->parentTitle = $entry->title ?? "Untitled";
                    $result->blockTypeHandle = $blockType->handle;
                    $result->isRichText = $isRich;
                    $this->applyCrossesTagReadOnly($result, $match);
                    $results[] = $result;
                }
            }
        }

        return $results;
    }

    private function isCkeditorField(FieldInterface $field): bool
    {
        return class_exists(\craft\ckeditor\Field::class) &&
            $field instanceof \craft\ckeditor\Field;
    }

    /**
     * Entries embedded in a CKEditor field are stored as their own elements;
     * the field itself only keeps a <craft-entry> marker for each. They are
     * searched like Matrix blocks, so text inside them is found and saved on
     * the embedded entry rather than flattened into the parent's HTML.
     * Returns null when the field embeds none, which is the usual case.
     */
    private function ckeditorNestedEntries(Entry $entry, FieldInterface $field): ?array
    {
        $value = $entry->getFieldValue($field->handle);

        if (
            !is_object($value) ||
            !method_exists($value, "getEntries") ||
            !str_contains(FieldValue::toString($value), "<craft-entry")
        ) {
            return null;
        }

        return $value->getEntries()->status(null)->all() ?: null;
    }

    /**
     * Whether the Neo plugin (an optional third-party dependency, not
     * required by this plugin) is installed and this field is one of its
     * Neo fields.
     */
    private function isNeoField(FieldInterface $field): bool
    {
        return class_exists(\benf\neo\Field::class) &&
            $field instanceof \benf\neo\Field;
    }

    /**
     * Neo fields work like Matrix (block types with their own field
     * layouts), just via a different plugin's classes - same traversal,
     * same compound "fieldHandle.subFieldHandle" filter matching.
     */
    private function searchInNeoField(
        Entry $entry,
        $neoField,
        string $query,
        int $siteId,
        string $siteHandle,
        $section,
        bool $useRegex,
        bool $caseSensitive,
        bool $wholeWords,
        array $fieldFilter
    ): array {
        $results = [];

        $blockQuery = $entry->getFieldValue($neoField->handle);
        if (!$blockQuery) {
            return $results;
        }

        $blocks = $blockQuery->all();

        foreach ($blocks as $block) {
            $blockType = $block->getType();
            $blockFields = $blockType->getCustomFields();

            foreach ($blockFields as $field) {
                if (
                    !$this->fieldInFilterScope(
                        $neoField->handle,
                        $field->handle,
                        $fieldFilter
                    )
                ) {
                    continue;
                }

                if (!$this->isSearchableField($field)) {
                    continue;
                }

                $value = $block->getFieldValue($field->handle);
                $isRich = $this->isRichTextField($field);
                $matches = $isRich
                    ? $this->findMatchesInHtml(
                        $value,
                        $query,
                        $useRegex,
                        $caseSensitive,
                        $wholeWords
                    )
                    : $this->findMatches(
                        $value,
                        $query,
                        $useRegex,
                        $caseSensitive,
                        $wholeWords
                    );

                foreach ($matches as $match) {
                    $result = new SearchResult();
                    $result->elementType = "neoBlock";
                    $result->elementId = $block->id;
                    $result->elementTitle = "Block #{$block->sortOrder}";
                    $result->sectionHandle = $section->handle;
                    $result->sectionName = $section->name;
                    $result->fieldHandle = $field->handle;
                    $result->fieldName = "{$neoField->name} → {$field->name}";
                    $result->siteId = $siteId;
                    $result->siteHandle = $siteHandle;
                    $result->matchContext = $match["context"];
                    $result->valueHash = ReplaceService::hashValue(FieldValue::toString($value));
                    $result->matchStart = $match["start"];
                    $result->matchEnd = $match["end"];
                    $result->parentId = $entry->id;
                    $result->parentTitle = $entry->title ?? "Untitled";
                    $result->blockTypeHandle = $blockType->handle;
                    $result->isRichText = $isRich;
                    $this->applyCrossesTagReadOnly($result, $match);
                    $results[] = $result;
                }
            }
        }

        return $results;
    }

    private function searchInGlobals(
        string $query,
        int $siteId,
        bool $useRegex,
        bool $caseSensitive,
        bool $wholeWords,
        array $fieldFilter
    ): array {
        $results = [];
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $globalSets = GlobalSet::find()->siteId($siteId)->all();

        // Global sets are few per site, so ->all() here is fine.
        foreach ($globalSets as $globalSet) {
            if (!Access::canView($globalSet, $this->actingUserId)) {
                continue;
            }

            $fieldLayout = $globalSet->getFieldLayout();

            if (!$fieldLayout) {
                continue;
            }

            foreach ($fieldLayout->getCustomFields() as $field) {
                if (
                    !empty($fieldFilter) &&
                    !in_array($field->handle, $fieldFilter)
                ) {
                    continue;
                }

                if (!$this->isSearchableField($field)) {
                    continue;
                }

                $value = $globalSet->getFieldValue($field->handle);
                $isRich = $this->isRichTextField($field);
                $matches = $isRich
                    ? $this->findMatchesInHtml(
                        $value,
                        $query,
                        $useRegex,
                        $caseSensitive,
                        $wholeWords
                    )
                    : $this->findMatches(
                        $value,
                        $query,
                        $useRegex,
                        $caseSensitive,
                        $wholeWords
                    );

                foreach ($matches as $match) {
                    $result = new SearchResult();
                    $result->elementType = "global";
                    $result->elementId = $globalSet->id;
                    $result->elementTitle = $globalSet->name;
                    $result->sectionHandle = $globalSet->handle;
                    $result->sectionName = $globalSet->name;
                    $result->fieldHandle = $field->handle;
                    $result->fieldName = $field->name;
                    $result->siteId = $siteId;
                    $result->siteHandle = $site->handle;
                    $result->matchContext = $match["context"];
                    $result->valueHash = ReplaceService::hashValue(FieldValue::toString($value));
                    $result->matchStart = $match["start"];
                    $result->matchEnd = $match["end"];
                    $result->isRichText = $isRich;
                    $this->applyCrossesTagReadOnly($result, $match);
                    $results[] = $result;
                }
            }
        }

        return $results;
    }

    private function searchInCategories(
        string $query,
        int $siteId,
        bool $useRegex,
        bool $caseSensitive,
        bool $wholeWords,
        array $fieldFilter
    ): array {
        $results = [];
        $site = Craft::$app->getSites()->getSiteById($siteId);

        $categoryQuery = Category::find()->siteId($siteId)->status(null);

        foreach ($categoryQuery->each() as $category) {
            if (!Access::canView($category, $this->actingUserId)) {
                continue;
            }

            $group = $category->getGroup();

            if (empty($fieldFilter) || in_array("title", $fieldFilter)) {
                $titleMatches = $this->findMatches(
                    $category->title,
                    $query,
                    $useRegex,
                    $caseSensitive,
                    $wholeWords
                );

                foreach ($titleMatches as $match) {
                    $result = new SearchResult();
                    $result->elementType = "category";
                    $result->elementId = $category->id;
                    $result->elementTitle = $category->title ?? "Untitled";
                    $result->sectionHandle = $group->handle;
                    $result->sectionName = $group->name;
                    $result->fieldHandle = "title";
                    $result->fieldName = Craft::t("editrix", "Title");
                    $result->siteId = $siteId;
                    $result->siteHandle = $site->handle;
                    $result->matchContext = $match["context"];
                    $result->valueHash = ReplaceService::hashValue((string) $category->title);
                    $result->matchStart = $match["start"];
                    $result->matchEnd = $match["end"];
                    $result->isRichText = false;
                    $results[] = $result;
                }
            }

            $fieldLayout = $category->getFieldLayout();

            if (!$fieldLayout) {
                continue;
            }

            foreach ($fieldLayout->getCustomFields() as $field) {
                if (
                    !empty($fieldFilter) &&
                    !in_array($field->handle, $fieldFilter)
                ) {
                    continue;
                }

                if (!$this->isSearchableField($field)) {
                    continue;
                }

                $value = $category->getFieldValue($field->handle);
                $isRich = $this->isRichTextField($field);
                $matches = $isRich
                    ? $this->findMatchesInHtml(
                        $value,
                        $query,
                        $useRegex,
                        $caseSensitive,
                        $wholeWords
                    )
                    : $this->findMatches(
                        $value,
                        $query,
                        $useRegex,
                        $caseSensitive,
                        $wholeWords
                    );

                foreach ($matches as $match) {
                    $result = new SearchResult();
                    $result->elementType = "category";
                    $result->elementId = $category->id;
                    $result->elementTitle = $category->title ?? "Untitled";
                    $result->sectionHandle = $group->handle;
                    $result->sectionName = $group->name;
                    $result->fieldHandle = $field->handle;
                    $result->fieldName = $field->name;
                    $result->siteId = $siteId;
                    $result->siteHandle = $site->handle;
                    $result->matchContext = $match["context"];
                    $result->valueHash = ReplaceService::hashValue(FieldValue::toString($value));
                    $result->matchStart = $match["start"];
                    $result->matchEnd = $match["end"];
                    $result->isRichText = $isRich;
                    $this->applyCrossesTagReadOnly($result, $match);
                    $results[] = $result;
                }
            }
        }

        return $results;
    }

    /**
     * Whether a field filter still leaves room for a match inside this
     * Matrix field, so we can skip loading its blocks entirely when not.
     */
    private function matrixFieldMayMatch(
        string $matrixHandle,
        array $fieldFilter
    ): bool {
        if (empty($fieldFilter)) {
            return true;
        }

        foreach ($fieldFilter as $filterHandle) {
            if (
                $filterHandle === $matrixHandle ||
                str_starts_with($filterHandle, "{$matrixHandle}.")
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a field nested inside a Matrix/Neo block (identified by its
     * own bare handle, e.g. "titleField") is in scope for $fieldFilter.
     * The Fields filter only ever offers one checkbox per Matrix/Neo field
     * (not one per nested sub-field - there'd be far too many, often with
     * duplicate names across block types), so selecting that checkbox
     * puts the bare PARENT handle in $fieldFilter, meaning "search every
     * field inside it." The compound "parent.field" form is also accepted
     * since ReplaceService/log history may carry it from an older scope.
     */
    private function fieldInFilterScope(
        string $parentHandle,
        string $fieldHandle,
        array $fieldFilter
    ): bool {
        if (empty($fieldFilter)) {
            return true;
        }

        return in_array($fieldHandle, $fieldFilter) ||
            in_array("{$parentHandle}.{$fieldHandle}", $fieldFilter) ||
            in_array($parentHandle, $fieldFilter);
    }

    /**
     * Check if field is searchable
     */
    private function isSearchableField(FieldInterface $field): bool
    {
        $settings = Editrix::$plugin->getSettings();
        $fieldClass = get_class($field);
        return in_array($fieldClass, $settings->searchableFieldTypes);
    }

    /**
     * Whether this field stores HTML (Redactor/CKEditor) rather than plain
     * text - these need tag-aware matching so a phrase isn't missed just
     * because a word in the middle happens to be wrapped in <i>/<a>/etc.
     */
    public function isRichTextField(FieldInterface $field): bool
    {
        return (class_exists(\craft\redactor\Field::class) &&
            $field instanceof \craft\redactor\Field) ||
            (class_exists(\craft\ckeditor\Field::class) &&
                $field instanceof \craft\ckeditor\Field);
    }

    /**
     * Like findMatches(), but for HTML field values: matches are found
     * against the tag-stripped text (so a phrase split by an inline tag,
     * e.g. "Our <i>Featured</i> Offers", still matches "Our Featured
     * Offers"), then mapped back to their real position in the raw HTML for
     * display/highlighting. A match is flagged "crossesTag" when its raw
     * HTML span contains a tag boundary - Replace intentionally treats
     * those as read-only, since blindly overwriting that span would corrupt
     * the markup rather than just the text.
     */
    public function findMatchesInHtml(
        mixed $value,
        string $query,
        bool $useRegex,
        bool $caseSensitive,
        bool $wholeWords
    ): array {
        return $this->htmlOccurrences(
            $value,
            $query,
            $useRegex,
            $caseSensitive,
            $wholeWords,
            $this->includeLinks
        );
    }

    /**
     * Every occurrence in a rich text field, in the order they appear in
     * its HTML. Replace reads the same list, so the number a person sees
     * next to an occurrence is the one it is replaced by.
     *
     * Most are in the visible text, found in the text with the tags
     * stripped. With $includeLinks, the address of a link (href) or an
     * image (src) is searched too, which is where a changed domain lives;
     * those are marked "attr" and matched as they are written in the HTML.
     *
     * Offsets are character offsets in $value. A text occurrence also
     * carries "plainStart" and "plainEnd" in the stripped text.
     */
    public function htmlOccurrences(
        mixed $value,
        string $query,
        bool $useRegex,
        bool $caseSensitive,
        bool $wholeWords,
        bool $includeLinks = false
    ): array {
        if (is_object($value)) {
            $value = FieldValue::toString($value);
        }

        if (!is_string($value) || $value === "" || $query === "") {
            return [];
        }

        $results = [];
        [$plain, $charMap] = HtmlText::stripTagsWithCharMap($value);

        if ($plain !== "") {
            $plainMatches = $this->findMatches(
                $plain,
                $query,
                $useRegex,
                $caseSensitive,
                $wholeWords
            );

            // findMatches() always reports CHARACTER offsets, regardless of
            // which internal branch handles the search - the same convention
            // getMatchContext() and the frontend's JS string slicing both
            // expect. $charMap is keyed by character position, so no unit
            // conversion is needed here.
            $plainCharLen = count($charMap);

            foreach ($plainMatches as $match) {
                $startChar = $match["start"];
                $endChar = $match["end"];

                if (
                    !isset($charMap[$startChar]) ||
                    $endChar <= $startChar ||
                    $endChar > $plainCharLen
                ) {
                    continue;
                }

                $rawStart = $charMap[$startChar][0];
                $rawEnd = isset($charMap[$endChar - 1])
                    ? $charMap[$endChar - 1][1]
                    : $rawStart;

                $crossesTag = str_contains(
                    mb_substr($value, $rawStart, $rawEnd - $rawStart),
                    "<"
                );

                $results[] = [
                    "start" => $rawStart,
                    "end" => $rawEnd,
                    "match" => mb_substr($value, $rawStart, $rawEnd - $rawStart),
                    "context" => $this->getMatchContext(
                        $value,
                        $rawStart,
                        $rawEnd,
                        50
                    ),
                    "crossesTag" => $crossesTag,
                    "attr" => false,
                    "plainStart" => $startChar,
                    "plainEnd" => $endChar,
                ];
            }
        }

        if ($includeLinks) {
            $results = array_merge(
                $results,
                $this->linkOccurrences($value, $query, $useRegex, $caseSensitive, $wholeWords)
            );
            usort($results, fn(array $a, array $b) => $a["start"] <=> $b["start"]);
        }

        return $results;
    }

    /**
     * The matches inside the href and src attributes of the tags in $value.
     * Only the text between the quotes is looked at, never the markup
     * around it.
     */
    private function linkOccurrences(
        string $value,
        string $query,
        bool $useRegex,
        bool $caseSensitive,
        bool $wholeWords
    ): array {
        if (!preg_match_all('/<[a-zA-Z][^>]*>/u', $value, $tags, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $found = [];

        foreach ($tags[0] as [$tag, $tagByte]) {
            if (!preg_match_all(
                '/\b(?:href|src)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/iu',
                $tag,
                $attrs,
                PREG_OFFSET_CAPTURE | PREG_SET_ORDER
            )) {
                continue;
            }

            foreach ($attrs as $attr) {
                [$text, $byteInTag] = ($attr[1][1] ?? -1) !== -1 ? $attr[1] : ($attr[2] ?? ["", -1]);

                if ($text === "" || $byteInTag < 0) {
                    continue;
                }

                $base = mb_strlen(substr($value, 0, $tagByte + $byteInTag));

                foreach ($this->findMatches($text, $query, $useRegex, $caseSensitive, $wholeWords) as $match) {
                    $start = $base + $match["start"];
                    $end = $base + $match["end"];

                    $found[] = [
                        "start" => $start,
                        "end" => $end,
                        "match" => $match["match"],
                        "context" => $this->getMatchContext($value, $start, $end, 50),
                        "crossesTag" => false,
                        "attr" => true,
                    ];
                }
            }
        }

        return $found;
    }

    public function findMatches(
        mixed $value,
        string $query,
        bool $useRegex,
        bool $caseSensitive,
        bool $wholeWords
    ): array {
        // Rich text fields (Redactor, CKEditor, Matrix/relation values, etc.)
        // return a FieldData/Markup wrapper object, not a plain string.
        if (is_object($value)) {
            $value = FieldValue::toString($value);
        }

        if (!is_string($value) || $value === "" || $query === "") {
            return [];
        }

        $matches = [];
        $contextLength = 50;

        if ($useRegex) {
            $regexMatches = [Regex::matchAll(
                Regex::pattern($query, $caseSensitive),
                $value
            )];

            foreach ($regexMatches[0] as $match) {
                $matchText = $match[0];
                $start = $this->bytePosToCharPos($value, $match[1]);
                $end = $start + mb_strlen($matchText);
                $matches[] = [
                    "start" => $start,
                    "end" => $end,
                    "match" => $matchText,
                    "context" => $this->getMatchContext(
                        $value,
                        $start,
                        $end,
                        $contextLength
                    ),
                ];
            }
        } else {
            $searchValue = $caseSensitive ? $value : mb_strtolower($value);
            $searchQuery = $caseSensitive ? $query : mb_strtolower($query);

            if ($wholeWords) {
                $escapedQuery = preg_quote($searchQuery, "/");
                $flags = $caseSensitive ? "" : "i";
                $pattern = "/\\b{$escapedQuery}\\b/{$flags}u";

                if (
                    @preg_match_all(
                        $pattern,
                        $value,
                        $regexMatches,
                        PREG_OFFSET_CAPTURE
                    ) === false
                ) {
                    return [];
                }

                foreach ($regexMatches[0] as $match) {
                    $matchText = $match[0];
                    $start = $this->bytePosToCharPos($value, $match[1]);
                    $end = $start + mb_strlen($matchText);
                    $matches[] = [
                        "start" => $start,
                        "end" => $end,
                        "match" => $matchText,
                        "context" => $this->getMatchContext(
                            $value,
                            $start,
                            $end,
                            $contextLength
                        ),
                    ];
                }
            } else {
                if (mb_strpos($query, " ") !== false) {
                    $escapedQuery = preg_quote($query, "/");
                    $flags = $caseSensitive ? "" : "i";
                    $pattern =
                        "/" .
                        str_replace("\\ ", "\\s+", $escapedQuery) .
                        "/" .
                        $flags .
                        "u";
                    if (
                        @preg_match_all(
                            $pattern,
                            $value,
                            $regexMatches,
                            PREG_OFFSET_CAPTURE
                        ) === false
                    ) {
                        return [];
                    }
                    foreach ($regexMatches[0] as $match) {
                        $matchText = $match[0];
                        $start = $this->bytePosToCharPos($value, $match[1]);
                        $end = $start + mb_strlen($matchText);
                        $matches[] = [
                            "start" => $start,
                            "end" => $end,
                            "match" => $matchText,
                            "context" => $this->getMatchContext(
                                $value,
                                $start,
                                $end,
                                $contextLength
                            ),
                        ];
                    }
                } else {
                    $offset = 0;
                    while (
                        ($pos = mb_strpos(
                            $searchValue,
                            $searchQuery,
                            $offset
                        )) !== false
                    ) {
                        $end = $pos + mb_strlen($query);
                        $matchText = mb_substr($value, $pos, mb_strlen($query));
                        $matches[] = [
                            "start" => $pos,
                            "end" => $end,
                            "match" => $matchText,
                            "context" => $this->getMatchContext(
                                $value,
                                $pos,
                                $end,
                                $contextLength
                            ),
                        ];
                        $offset = $pos + 1;
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * Converts a byte offset (what PREG_OFFSET_CAPTURE always returns, even
     * with the /u flag) into a character offset (what getMatchContext() and
     * the char-position "start"/"end" this class returns elsewhere expect).
     */
    private function bytePosToCharPos(string $value, int $bytePos): int
    {
        return mb_strlen(substr($value, 0, $bytePos));
    }

    /**
     * A rich-text match whose span crosses an HTML tag boundary is found
     * for discovery but can't be safely auto-replaced (see
     * ReplaceService::performReplacementInHtml()'s tag guard) - flag it
     * read-only so the UI doesn't offer a Replace that will silently no-op.
     */
    private function applyCrossesTagReadOnly(
        SearchResult $result,
        array $match
    ): void {
        if (!empty($match["crossesTag"])) {
            $result->readOnly = true;
            $result->readOnlyReason = "formatting";
        }
    }

    private function getMatchContext(
        string $value,
        int $start,
        int $end,
        int $contextLength
    ): string {
        $prefix = "";
        $suffix = "";

        $prefixStart = max(0, $start - $contextLength);
        if ($prefixStart > 0) {
            $prefix = "...";
        }
        $prefix .= mb_substr($value, $prefixStart, $start - $prefixStart);

        $match = mb_substr($value, $start, $end - $start);

        $suffixEnd = min(mb_strlen($value), $end + $contextLength);
        $suffix = mb_substr($value, $end, $suffixEnd - $end);
        if ($suffixEnd < mb_strlen($value)) {
            $suffix .= "...";
        }

        return $prefix . "[[MATCH]]" . $match . "[[/MATCH]]" . $suffix;
    }
}
