<?php

namespace brandindustry\editrix\services;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Category;
use craft\fields\Matrix;
use brandindustry\editrix\models\SearchResult;
use brandindustry\editrix\Editrix;

class SearchService extends Component
{
    /**
     * Perform search across content
     */
    public function search(
        string $query,
        ?int $siteId = null,
        bool $useRegex = false,
        bool $caseSensitive = true,
        array $options = [],
    ): array {
        $results = [];
        $settings = Editrix::$plugin->getSettings();

        // Get search options
        $searchEntries = $options["searchEntries"] ?? $settings->searchEntries;
        $searchGlobals = $options["searchGlobals"] ?? $settings->searchGlobals;
        $searchMatrix = $options["searchMatrix"] ?? $settings->searchMatrix;
        $searchCategories =
            $options["searchCategories"] ?? $settings->searchCategories;
        $wholeWords = $options["wholeWords"] ?? false;

        // Scope filters
        $sectionFilter = $options["sections"] ?? [];
        $fieldFilter = $options["fields"] ?? [];
        $entryTypeFilter = $options["entryTypes"] ?? [];

        // Get sites to search
        $sites = $this->getSitesToSearch($siteId);

        foreach ($sites as $site) {
            if ($searchEntries) {
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
                    ),
                );
            }

            if ($searchGlobals) {
                $results = array_merge(
                    $results,
                    $this->searchInGlobals(
                        $query,
                        $site->id,
                        $useRegex,
                        $caseSensitive,
                        $wholeWords,
                        $fieldFilter,
                    ),
                );
            }

            if (
                $searchCategories &&
                Editrix::$plugin->hasFeature(
                    LicenseService::FEATURE_SEARCH_CATEGORIES,
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
                        $fieldFilter,
                    ),
                );
            }
        }

        return $results;
    }

    /**
     * Get sites to search
     */
    private function getSitesToSearch(?int $siteId): array
    {
        if ($siteId !== null) {
            $site = Craft::$app->getSites()->getSiteById($siteId);
            return $site ? [$site] : [];
        }
        return Craft::$app->getSites()->getAllSites();
    }

    /**
     * Search in entries
     */
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
    ): array {
        $results = [];
        $site = Craft::$app->getSites()->getSiteById($siteId);

        $entryQuery = Entry::find()
            ->siteId($siteId)
            ->status(null)
            ->drafts(false)
            ->revisions(false);

        // Apply section filter
        if (!empty($sectionFilter)) {
            $entryQuery->section($sectionFilter);
        }

        // Apply entry type filter
        if (!empty($entryTypeFilter)) {
            $entryQuery->type($entryTypeFilter);
        }

        $entries = $entryQuery->all();

        foreach ($entries as $entry) {
            $section = $entry->getSection();
            $fieldLayout = $entry->getFieldLayout();

            if (!$fieldLayout) {
                continue;
            }

            foreach ($fieldLayout->getCustomFields() as $field) {
                // Apply field filter
                if (
                    !empty($fieldFilter) &&
                    !in_array($field->handle, $fieldFilter)
                ) {
                    continue;
                }

                if ($this->isSearchableField($field)) {
                    $value = $entry->getFieldValue($field->handle);
                    $matches = $this->findMatches(
                        $value,
                        $query,
                        $useRegex,
                        $caseSensitive,
                        $wholeWords,
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
                        $result->fieldValue = (string) $value;
                        $result->matchStart = $match["start"];
                        $result->matchEnd = $match["end"];
                        $results[] = $result;
                    }
                }

                // Search in Matrix fields
                if ($searchMatrix && $field instanceof Matrix) {
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
                        $fieldFilter,
                    );
                    $results = array_merge($results, $matrixResults);
                }
            }
        }

        return $results;
    }

    /**
     * Search in Matrix field blocks
     */
    private function searchInMatrixField(
        Entry $entry,
        Matrix $matrixField,
        string $query,
        int $siteId,
        string $siteHandle,
        $section,
        bool $useRegex,
        bool $caseSensitive,
        bool $wholeWords,
        array $fieldFilter,
    ): array {
        $results = [];

        $matrixQuery = $entry->getFieldValue($matrixField->handle);
        if (!$matrixQuery) {
            return $results;
        }

        $blocks = $matrixQuery->all();

        foreach ($blocks as $block) {
            $blockType = $block->getType();
            $blockFields = $blockType->getCustomFields();

            foreach ($blockFields as $field) {
                // Apply field filter (check both the matrix field handle and sub-field handle)
                if (!empty($fieldFilter)) {
                    $matrixFieldHandle = "{$matrixField->handle}.{$field->handle}";
                    if (
                        !in_array($field->handle, $fieldFilter) &&
                        !in_array($matrixFieldHandle, $fieldFilter)
                    ) {
                        continue;
                    }
                }

                if (!$this->isSearchableField($field)) {
                    continue;
                }

                $value = $block->getFieldValue($field->handle);
                $matches = $this->findMatches(
                    $value,
                    $query,
                    $useRegex,
                    $caseSensitive,
                    $wholeWords,
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
                    $result->fieldValue = (string) $value;
                    $result->matchStart = $match["start"];
                    $result->matchEnd = $match["end"];
                    $result->parentId = $entry->id;
                    $result->parentTitle = $entry->title ?? "Untitled";
                    $result->blockTypeHandle = $blockType->handle;
                    $results[] = $result;
                }
            }
        }

        return $results;
    }

    /**
     * Search in globals
     */
    private function searchInGlobals(
        string $query,
        int $siteId,
        bool $useRegex,
        bool $caseSensitive,
        bool $wholeWords,
        array $fieldFilter,
    ): array {
        $results = [];
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $globalSets = GlobalSet::find()->siteId($siteId)->all();

        foreach ($globalSets as $globalSet) {
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
                $matches = $this->findMatches(
                    $value,
                    $query,
                    $useRegex,
                    $caseSensitive,
                    $wholeWords,
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
                    $result->fieldValue = (string) $value;
                    $result->matchStart = $match["start"];
                    $result->matchEnd = $match["end"];
                    $results[] = $result;
                }
            }
        }

        return $results;
    }

    /**
     * Search in categories
     */
    private function searchInCategories(
        string $query,
        int $siteId,
        bool $useRegex,
        bool $caseSensitive,
        bool $wholeWords,
        array $fieldFilter,
    ): array {
        $results = [];
        $site = Craft::$app->getSites()->getSiteById($siteId);

        $categories = Category::find()->siteId($siteId)->status(null)->all();

        foreach ($categories as $category) {
            $group = $category->getGroup();
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
                $matches = $this->findMatches(
                    $value,
                    $query,
                    $useRegex,
                    $caseSensitive,
                    $wholeWords,
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
                    $result->fieldValue = (string) $value;
                    $result->matchStart = $match["start"];
                    $result->matchEnd = $match["end"];
                    $results[] = $result;
                }
            }
        }

        return $results;
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
     * Find matches in text
     */
    private function findMatches(
        mixed $value,
        string $query,
        bool $useRegex,
        bool $caseSensitive,
        bool $wholeWords,
    ): array {
        if (!is_string($value) || empty($value) || empty($query)) {
            return [];
        }

        // DEBUG - Ver el contenido real
        if (strlen($value) > 50) {
            \Craft::info(
                "Editrix - Checking value (first 100 chars): " .
                    substr($value, 0, 100),
                __METHOD__,
            );
        }

        // DEBUG - Ver si contiene la palabra
        $containsQuery = stripos($value, $query) !== false;
        \Craft::info(
            "Editrix - Contains '{$query}'? " .
                ($containsQuery ? "YES" : "NO") .
                ", CaseSensitive: " .
                ($caseSensitive ? "yes" : "no"),
            __METHOD__,
        );

        if (!is_string($value) || empty($value) || empty($query)) {
            return [];
        }

        $matches = [];
        $contextLength = 50;

        if ($useRegex) {
            $flags = $caseSensitive ? "" : "i";
            $pattern = "/{$query}/{$flags}";

            if (
                @preg_match_all(
                    $pattern,
                    $value,
                    $regexMatches,
                    PREG_OFFSET_CAPTURE,
                ) === false
            ) {
                return [];
            }

            foreach ($regexMatches[0] as $match) {
                $matchText = $match[0];
                $start = $match[1];
                $end = $start + strlen($matchText);
                $matches[] = [
                    "start" => $start,
                    "end" => $end,
                    "match" => $matchText,
                    "context" => $this->getMatchContext(
                        $value,
                        $start,
                        $end,
                        $contextLength,
                    ),
                ];
            }
        } else {
            $searchValue = $caseSensitive ? $value : mb_strtolower($value);
            $searchQuery = $caseSensitive ? $query : mb_strtolower($query);

            // Build regex for whole words if needed
            if ($wholeWords) {
                $escapedQuery = preg_quote($searchQuery, "/");
                $flags = $caseSensitive ? "" : "i";
                $pattern = "/\\b{$escapedQuery}\\b/{$flags}u";

                if (
                    @preg_match_all(
                        $pattern,
                        $value,
                        $regexMatches,
                        PREG_OFFSET_CAPTURE,
                    ) === false
                ) {
                    return [];
                }

                foreach ($regexMatches[0] as $match) {
                    $matchText = $match[0];
                    $start = $match[1];
                    $end = $start + strlen($matchText);
                    $matches[] = [
                        "start" => $start,
                        "end" => $end,
                        "match" => $matchText,
                        "context" => $this->getMatchContext(
                            $value,
                            $start,
                            $end,
                            $contextLength,
                        ),
                    ];
                }
            } else {
                // Whitespace-flexible matching: allow variations in spaces only when query contains spaces
                if (mb_strpos($query, ' ') !== false) {
                    $escapedQuery = preg_quote($searchQuery, '/');
                    $pattern = '/' . str_replace(' ', '\\s+', $escapedQuery) . '/u';
                    if (@preg_match_all($pattern, $value, $regexMatches, PREG_OFFSET_CAPTURE) === false) {
                        return [];
                    }
                    foreach ($regexMatches[0] as $match) {
                        $start = $match[1];
                        $matchText = $match[0];
                        $end = $start + mb_strlen($matchText);
                        $matches[] = [
                            "start" => $start,
                            "end" => $end,
                            "match" => $matchText,
                            "context" => $this->getMatchContext(
                                $value,
                                $start,
                                $end,
                                $contextLength,
                            ),
                        ];
                    }
                } else {
                    $offset = 0;
                    while (
                        ($pos = mb_strpos($searchValue, $searchQuery, $offset)) !==
                        false
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
                                $contextLength,
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
     * Get context around match
     */
    private function getMatchContext(
        string $value,
        int $start,
        int $end,
        int $contextLength,
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

        // Use markers for highlighting
        return $prefix . "[[MATCH]]" . $match . "[[/MATCH]]" . $suffix;
    }
}
