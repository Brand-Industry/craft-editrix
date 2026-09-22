<?php

namespace brandindustry\editrix\services;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Category;
use craft\fields\Matrix;
use craft\fields\Tags;
use brandindustry\editrix\helpers\HtmlText;
use brandindustry\editrix\models\SearchResult;
use brandindustry\editrix\Editrix;

class SearchService extends Component
{
    public function search(
        string $query,
        ?int $siteId = null,
        bool $useRegex = false,
        bool $caseSensitive = true,
        array $options = []
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

        $sectionFilter = $options["sections"] ?? [];
        $fieldFilter = $options["fields"] ?? [];
        $entryTypeFilter = $options["entryTypes"] ?? [];

        $sites = $this->getSitesToSearch($siteId, $options["siteIds"] ?? []);

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
                        $entryTypeFilter
                    )
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
                        $fieldFilter
                    )
                );
            }

            if (
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
            }
        }

        return $results;
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
        array $entryTypeFilter
    ): array {
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

        // Batch instead of ->all() so we never hold every entry (and every
        // custom field value) on the site in memory at the same time.
        foreach ($entryQuery->each() as $entry) {
            $section = $entry->getSection();

            // On Craft 5, nested entries (e.g. Matrix blocks, which are now
            // regular Entry elements) don't belong to a section. They're
            // reached separately via searchInMatrixField() through their
            // owner, so skip them here to avoid duplicate/orphaned results.
            if (!$section) {
                continue;
            }

            $fieldLayout = $entry->getFieldLayout();

            if (!$fieldLayout) {
                continue;
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
                    $result->fieldValue = (string) $entry->title;
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
                        $result->fieldValue = (string) $value;
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

        return $results;
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
        Matrix $matrixField,
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

        $matrixQuery = $entry->getFieldValue($matrixField->handle);
        if (!$matrixQuery) {
            return $results;
        }

        $blocks = $matrixQuery->all();

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
                    $result->fieldValue = (string) $value;
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
                    $result->fieldValue = (string) $value;
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
                    $result->fieldValue = (string) $value;
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
                    $result->fieldValue = (string) $value;
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
        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }

        if (!is_string($value) || $value === "" || $query === "") {
            return [];
        }

        [$plain, $charMap] = HtmlText::stripTagsWithCharMap($value);

        if ($plain === "") {
            return [];
        }

        $plainMatches = $this->findMatches(
            $plain,
            $query,
            $useRegex,
            $caseSensitive,
            $wholeWords
        );

        if (empty($plainMatches)) {
            return [];
        }

        // findMatches() always reports CHARACTER offsets, regardless of
        // which internal branch handles the search - the same convention
        // getMatchContext() and the frontend's JS string slicing both
        // expect. $charMap is keyed by character position, so no unit
        // conversion is needed here.
        $plainCharLen = count($charMap);
        $results = [];

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
            ];
        }

        return $results;
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
        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }

        if (!is_string($value) || $value === "" || $query === "") {
            return [];
        }

        $matches = [];
        $contextLength = 50;

        if ($useRegex) {
            $flags = $caseSensitive ? "u" : "iu";
            $pattern = "/{$query}/{$flags}";

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
