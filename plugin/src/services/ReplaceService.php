<?php

namespace brandindustry\editrix\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Category;
use craft\elements\MatrixBlock;
use benf\neo\elements\Block as NeoBlock;
use brandindustry\editrix\helpers\HtmlText;
use brandindustry\editrix\models\SearchResult;
use brandindustry\editrix\Editrix;

class ReplaceService extends Component
{
    public function replace(
        array $results,
        string $searchQuery,
        string $replaceWith,
        bool $useRegex = false,
        bool $caseSensitive = true,
        bool $wholeWords = false
    ): array {
        $replacements = [];
        $processedElements = [];

        foreach ($results as $result) {
            $key = "{$result["elementType"]}_{$result["elementId"]}_{$result["fieldHandle"]}";

            if (!isset($processedElements[$key])) {
                $processedElements[$key] = [
                    "result" => $result,
                    "oldValue" => $result["fieldValue"],
                ];
            }
        }

        foreach ($processedElements as $data) {
            $result = $data["result"];
            $oldValue = $data["oldValue"];

            $newValue = $this->computeNewValue(
                $result,
                $oldValue,
                $searchQuery,
                $replaceWith,
                $useRegex,
                $caseSensitive,
                $wholeWords
            );

            if ($newValue === $oldValue) {
                continue;
            }

            $saved = $this->saveElementField($result, $newValue);

            if ($saved) {
                $replacements[] = [
                    "elementType" => $result["elementType"],
                    "elementId" => $result["elementId"],
                    "elementTitle" => $result["elementTitle"],
                    "sectionHandle" => $result["sectionHandle"],
                    "fieldHandle" => $result["fieldHandle"],
                    "fieldName" => $result["fieldName"] ?? "",
                    "oldValue" => $oldValue,
                    "newValue" => $newValue,
                    "siteId" => $result["siteId"],
                    "parentId" => $result["parentId"] ?? null,
                ];
            }
        }

        return $replacements;
    }

    /**
     * The single place that decides which algorithm applies a replacement -
     * shared by the real save (replace()) and ReplaceController's preview
     * endpoint, so what a user is shown before confirming is guaranteed to
     * be what actually gets written. $result only needs an "isRichText"
     * key; the full result array works, but so does a minimal one built
     * just for a preview call.
     */
    public function computeNewValue(
        array $result,
        string $value,
        string $query,
        string $replaceWith,
        bool $useRegex = false,
        bool $caseSensitive = true,
        bool $wholeWords = false
    ): string {
        return !empty($result["isRichText"])
            ? $this->performReplacementInHtml(
                $value,
                $query,
                $replaceWith,
                $useRegex,
                $caseSensitive,
                $wholeWords
            )
            : $this->performReplacement(
                $value,
                $query,
                $replaceWith,
                $useRegex,
                $caseSensitive,
                $wholeWords
            );
    }

    private function performReplacement(
        string $value,
        string $query,
        string $replaceWith,
        bool $useRegex,
        bool $caseSensitive,
        bool $wholeWords
    ): string {
        if ($useRegex) {
            $flags = $caseSensitive ? "u" : "iu";
            $pattern = "/{$query}/{$flags}";
            return @preg_replace($pattern, $replaceWith, $value) ?? $value;
        }

        // Not regex mode - $replaceWith is literal user text, not a regex
        // replacement template, so preg_replace_callback() (which always
        // inserts it as-is) is used instead of preg_replace() (which would
        // treat a literal "$1"/"$100"/backslash in $replaceWith as a
        // backreference and corrupt the saved content).
        if ($wholeWords) {
            $escapedQuery = preg_quote($query, "/");
            $flags = $caseSensitive ? "" : "i";
            $pattern = "/\\b{$escapedQuery}\\b/{$flags}u";
            return preg_replace_callback(
                $pattern,
                fn() => $replaceWith,
                $value
            );
        }

        if ($caseSensitive) {
            return str_replace($query, $replaceWith, $value);
        }

        $pattern = "/" . preg_quote($query, "/") . "/iu";
        return preg_replace_callback($pattern, fn() => $replaceWith, $value);
    }

    /**
     * Like performReplacement(), but for HTML field values (Redactor/
     * CKEditor): finds every occurrence in the tag-stripped plain text
     * (so a phrase split by an inline tag, e.g. "Our <i>Featured</i>
     * Offers", is still matched), then for each one rewrites only the
     * part of the raw HTML that actually differs between the old and new
     * text (a common-prefix/common-suffix diff). Formatting untouched by
     * the edit - like an <i> earlier in the same phrase - is left
     * byte-for-byte intact; only markup that falls inside the span that
     * actually changed can be affected, which is unavoidable.
     */
    private function performReplacementInHtml(
        string $value,
        string $query,
        string $replaceWith,
        bool $useRegex,
        bool $caseSensitive,
        bool $wholeWords
    ): string {
        [$plain, $charMap] = HtmlText::stripTagsWithCharMap($value);

        if ($plain === "") {
            return $value;
        }

        $plainMatches = Editrix::$plugin->search->findMatches(
            $plain,
            $query,
            $useRegex,
            $caseSensitive,
            $wholeWords
        );

        if (empty($plainMatches)) {
            return $value;
        }

        // findMatches() always reports CHARACTER offsets, so no unit
        // conversion is needed here - $charMap is keyed by character
        // position too.
        $charMapLen = count($charMap);

        // Process back-to-front so each raw-string edit doesn't invalidate
        // the positions of matches still to be processed.
        for ($i = count($plainMatches) - 1; $i >= 0; $i--) {
            $match = $plainMatches[$i];

            $startChar = $match["start"];
            $endChar = $match["end"];

            if ($endChar <= $startChar || $endChar > $charMapLen) {
                continue;
            }

            $oldPlainMatch = mb_substr(
                $plain,
                $startChar,
                $endChar - $startChar
            );
            $newPlainMatch = $this->performReplacement(
                $oldPlainMatch,
                $query,
                $replaceWith,
                $useRegex,
                $caseSensitive,
                $wholeWords
            );

            if ($newPlainMatch === $oldPlainMatch) {
                continue;
            }

            [$innerPlainStart, $innerPlainEnd, $newMiddle] = $this->diffSpan(
                $oldPlainMatch,
                $newPlainMatch,
                $startChar,
                $endChar
            );

            $innerRawStart = $this->rawStartForPlainIndex(
                $innerPlainStart,
                $charMap
            );
            $innerRawEnd = $this->rawEndForPlainIndex(
                $innerPlainEnd,
                $charMap,
                $innerRawStart
            );

            // The matched PHRASE can span a tag (or now, a decoded entity)
            // and still be safe to edit, as long as the part that actually
            // CHANGED doesn't itself include a tag - inserting text next to
            // an <i> is fine, overwriting a span that contains the <i>
            // itself would delete only one side of it and corrupt the
            // markup. When the diff span does include a tag, leave this
            // occurrence untouched rather than risk that - same as any
            // other no-op, it's just excluded from the result below.
            $innerRawSlice = mb_substr(
                $value,
                $innerRawStart,
                $innerRawEnd - $innerRawStart
            );
            if (str_contains($innerRawSlice, "<")) {
                continue;
            }

            $value =
                mb_substr($value, 0, $innerRawStart) .
                $newMiddle .
                mb_substr($value, $innerRawEnd);
        }

        return $value;
    }

    /**
     * Raw position where plain-text character $plainIndex begins - the
     * start of a span. Direct lookup, except one-past-the-end (inserting
     * after the very last plain character), which must resolve to right
     * after that last character, not mb_strlen($value) - there's typically
     * trailing markup (e.g. a closing </p>) after it that isn't part of
     * any plain-text run.
     */
    private function rawStartForPlainIndex(
        int $plainIndex,
        array $charMap
    ): int {
        $len = count($charMap);

        if ($plainIndex < $len) {
            return $charMap[$plainIndex][0];
        }

        return $len > 0 ? $charMap[$len - 1][1] : 0;
    }

    /**
     * Raw position marking the END of a span (exclusive) that runs up to
     * plain-text character $plainIndex - NOT $charMap[$plainIndex][0]
     * itself, which is the START of the NEXT plain character's span and
     * would skip over (and silently delete) any tag sitting between the
     * two, e.g. a closing </i> right after the matched word, or leave a
     * partially-consumed entity behind. The correct boundary is always
     * "right after the last INCLUDED character's span", i.e.
     * $charMap[$plainIndex - 1][1]. $fallback covers a zero-width span at
     * the very start (nothing precedes it to measure from).
     */
    private function rawEndForPlainIndex(
        int $plainIndex,
        array $charMap,
        int $fallback
    ): int {
        if ($plainIndex > 0 && isset($charMap[$plainIndex - 1])) {
            return $charMap[$plainIndex - 1][1];
        }

        return $fallback;
    }

    /**
     * Trims the common prefix/suffix between $old and $new (both relative
     * to one matched occurrence), returning [innerStart, innerEnd,
     * replacementText]: the smallest absolute character span that actually
     * differs, and the slice of $new that belongs there. innerStart ==
     * innerEnd means a pure insertion at that point (nothing to remove).
     */
    private function diffSpan(
        string $old,
        string $new,
        int $matchStart,
        int $matchEnd
    ): array {
        $oldChars = mb_str_split($old);
        $newChars = mb_str_split($new);
        $oldLen = count($oldChars);
        $newLen = count($newChars);

        $prefixLen = 0;
        $maxPrefix = min($oldLen, $newLen);
        while (
            $prefixLen < $maxPrefix &&
            $oldChars[$prefixLen] === $newChars[$prefixLen]
        ) {
            $prefixLen++;
        }

        $suffixLen = 0;
        $maxSuffix = min($oldLen, $newLen) - $prefixLen;
        while (
            $suffixLen < $maxSuffix &&
            $oldChars[$oldLen - 1 - $suffixLen] ===
                $newChars[$newLen - 1 - $suffixLen]
        ) {
            $suffixLen++;
        }

        $innerStart = $matchStart + $prefixLen;
        $innerEnd = $matchEnd - $suffixLen;
        $newMiddle = mb_substr(
            $new,
            $prefixLen,
            $newLen - $prefixLen - $suffixLen
        );

        return [$innerStart, $innerEnd, $newMiddle];
    }

    /**
     * Fields whose values aren't a plain string we can safely overwrite (e.g.
     * a Tags field, where the "value" shown is a related Tag element's
     * title, not something stored on this element) must never be written to
     * here - the frontend already hides Replace for these, this is the
     * server-side backstop, for every element type this class can write to
     * (entries, Matrix/Neo blocks, categories, globals).
     */
    private function isReplaceableField(array $result): bool
    {
        if ($result["fieldHandle"] === "title") {
            return true;
        }

        $field = Craft::$app
            ->getFields()
            ->getFieldByHandle($result["fieldHandle"]);

        if (!$field) {
            return false;
        }

        $settings = Editrix::$plugin->getSettings();
        return in_array(get_class($field), $settings->searchableFieldTypes);
    }

    private function saveElementField(array $result, string $newValue): bool
    {
        if (!$this->isReplaceableField($result)) {
            Craft::warning(
                "Editrix: Refused to replace non-replaceable field '{$result["fieldHandle"]}'",
                __METHOD__
            );
            return false;
        }

        try {
            $element = $this->getElement($result);

            if (!$element) {
                return false;
            }

            if ($result["fieldHandle"] === "title") {
                $element->title = $newValue;
            } else {
                $element->setFieldValue($result["fieldHandle"], $newValue);
            }

            // A MatrixBlock is a first-class element - save it directly.
            // (Re-fetching and saving the owner entry here would silently
            // discard this change, since that's a separate, unmodified
            // instance of the same content.)
            return Craft::$app->getElements()->saveElement($element);
        } catch (\Throwable $e) {
            Craft::error(
                "Editrix: Failed to save element - " . $e->getMessage(),
                __METHOD__
            );
            return false;
        }
    }

    private function getElement(array $result): mixed
    {
        return match ($result["elementType"]) {
            "entry" => Entry::find()
                ->id($result["elementId"])
                ->siteId($result["siteId"])
                ->status(null)
                ->drafts(false)
                ->revisions(false)
                ->one(),
            "global" => GlobalSet::find()
                ->id($result["elementId"])
                ->siteId($result["siteId"])
                ->one(),
            "matrixBlock" => MatrixBlock::find()
                ->id($result["elementId"])
                ->siteId($result["siteId"])
                ->status(null)
                ->one(),
            "neoBlock" => NeoBlock::find()
                ->id($result["elementId"])
                ->siteId($result["siteId"])
                ->status(null)
                ->one(),
            "category" => Category::find()
                ->id($result["elementId"])
                ->siteId($result["siteId"])
                ->status(null)
                ->one(),
            default => null,
        };
    }

    /**
     * Reads a field value the same way saveElementField()/revert() write it,
     * so title-based replacements can be compared without hitting
     * getFieldValue('title'), which isn't a real custom field.
     */
    private function getElementValue($element, string $fieldHandle): mixed
    {
        return $fieldHandle === "title"
            ? $element->title
            : $element->getFieldValue($fieldHandle);
    }

    /**
     * Validate whether a revert is safe (content hasn't changed since replacement)
     *
     * Returns an array of discrepancies for each replacement:
     * - 'safe' = current value matches newValue, revert is clean
     * - 'modified' = current value was changed after the replacement
     * - 'missing' = element no longer exists
     */
    public function validateRevert(array $replacements): array
    {
        $results = [];

        foreach ($replacements as $i => $replacement) {
            $element = $this->getElement($replacement);

            if (!$element) {
                $results[] = [
                    "index" => $i,
                    "status" => "missing",
                    "elementId" => $replacement["elementId"],
                    "elementTitle" =>
                        $replacement["elementTitle"] ?? "(unknown)",
                    "fieldHandle" => $replacement["fieldHandle"],
                ];
                continue;
            }

            $currentValue = (string) $this->getElementValue(
                $element,
                $replacement["fieldHandle"]
            );
            $expectedValue = (string) ($replacement["newValue"] ?? "");

            if ($currentValue === $expectedValue) {
                $results[] = [
                    "index" => $i,
                    "status" => "safe",
                    "elementId" => $replacement["elementId"],
                    "elementTitle" =>
                        $replacement["elementTitle"] ??
                        ($element->title ?? "(unknown)"),
                    "fieldHandle" => $replacement["fieldHandle"],
                ];
            } else {
                $results[] = [
                    "index" => $i,
                    "status" => "modified",
                    "elementId" => $replacement["elementId"],
                    "elementTitle" =>
                        $replacement["elementTitle"] ??
                        ($element->title ?? "(unknown)"),
                    "fieldHandle" => $replacement["fieldHandle"],
                    "expectedValue" => mb_substr($expectedValue, 0, 100),
                    "currentValue" => mb_substr($currentValue, 0, 100),
                ];
            }
        }

        return $results;
    }

    public function revert(array $replacement): bool
    {
        if (!$this->isReplaceableField($replacement)) {
            return false;
        }

        try {
            $element = $this->getElement($replacement);

            if (!$element) {
                return false;
            }

            if ($replacement["fieldHandle"] === "title") {
                $element->title = $replacement["oldValue"];
            } else {
                $element->setFieldValue(
                    $replacement["fieldHandle"],
                    $replacement["oldValue"]
                );
            }

            // See saveElementField() - a MatrixBlock saves directly, not via
            // a freshly-queried (and therefore unmodified) owner entry.
            return Craft::$app->getElements()->saveElement($element);
        } catch (\Throwable $e) {
            Craft::error(
                "Editrix: Failed to revert - " . $e->getMessage(),
                __METHOD__
            );
            return false;
        }
    }
}
