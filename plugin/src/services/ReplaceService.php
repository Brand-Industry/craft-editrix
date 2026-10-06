<?php

namespace brandindustry\editrix\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Category;
use craft\elements\MatrixBlock;
use benf\neo\elements\Block as NeoBlock;
use brandindustry\editrix\helpers\FieldValue;
use brandindustry\editrix\helpers\HtmlText;
use brandindustry\editrix\migrations\Install;
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
        bool $wholeWords = false,
        array &$skipped = [],
        array &$failed = [],
        array $hooks = []
    ): array {
        // $hooks: "logId" writes every value to that log before its entry
        // is saved; "onRow" is called as fn(int $done, int $total);
        // "isCancelled" is polled between rows.
        $logId = $hooks["logId"] ?? null;
        $log = Editrix::$plugin->log;
        $position = 0;
        $done = 0;
        $replacements = [];
        $processedElements = [];

        foreach ($results as $result) {
            $key = self::keyFor($result);

            if (!isset($processedElements[$key])) {
                $processedElements[$key] = ["result" => $result];
            }
        }

        $total = count($processedElements);

        foreach ($processedElements as $data) {
            if (isset($hooks["isCancelled"]) && $hooks["isCancelled"]()) {
                break;
            }

            if (isset($hooks["onRow"])) {
                $hooks["onRow"]($done++, $total);
            }

            $result = $data["result"];

            // Rows don't carry the field's text, only a fingerprint of it
            // from when the search ran. The text is read now, and used only
            // if it still matches that fingerprint: if the field was edited
            // since (by someone else, or by an earlier replace), the user
            // never saw this content and rewriting it would be a guess.
            if (self::isProtected($result["fieldHandle"], $result["sectionHandle"] ?? null)) {
                $skipped[] = [
                    "key" => self::keyFor($result),
                    "elementId" => $result["elementId"],
                    "elementTitle" => $result["elementTitle"] ?? "",
                    "fieldHandle" => $result["fieldHandle"],
                    "reason" => "protected",
                ];

                if ($logId) {
                    $log->addItem($logId, $this->entryFor($result, null, null, "protected"), $position++, "skipped");
                }

                continue;
            }

            $current = $this->readCurrent($result);
            $element = $current[0] ?? null;
            $oldValue = $current[1] ?? null;
            $reason = $oldValue === null
                ? "missing"
                : ($this->matchesSearchSnapshot($result, $oldValue) ? null : "changed");
            if ($reason !== null) {
                $skipped[] = [
                    "key" => self::keyFor($result),
                    "elementId" => $result["elementId"],
                    "elementTitle" => $result["elementTitle"] ?? "",
                    "fieldHandle" => $result["fieldHandle"],
                    "reason" => $reason,
                ];

                if ($logId) {
                    $log->addItem($logId, $this->entryFor($result, null, null, $reason), $position++, "skipped");
                }

                continue;
            }

            $newValue = $this->computeNewValue(
                $result,
                $oldValue,
                $searchQuery,
                $replaceWith,
                $useRegex,
                $caseSensitive,
                $wholeWords,
                (bool) ($hooks["preserveCase"] ?? false)
            );

            if ($newValue === $oldValue) {
                continue;
            }

            // Write-ahead: the value is in the log before the entry is
            // saved, so a request cut off in the middle of the save still
            // leaves something to revert from. Revert reads "pending" as
            // "may or may not have been saved" and checks which.
            $itemId = $logId
                ? $log->addItem($logId, $this->entryFor($result, $oldValue, $newValue), $position++, "pending")
                : null;

            $error = null;
            $saved = $this->saveElementField($result, $newValue, $element, $error);

            if ($itemId) {
                $log->setItemState($itemId, $saved ? "applied" : "failed", $saved ? null : $error);
            }

            if (!$saved) {
                // Said out loud, with the reason: a replace that quietly
                // leaves some entries unchanged looks like a bug.
                $failed[] = [
                    "key" => self::keyFor($result),
                    "elementId" => $result["elementId"],
                    "elementTitle" => $result["elementTitle"] ?? "",
                    "fieldHandle" => $result["fieldHandle"],
                    "reason" => $error ?? "could not be saved",
                ];
            }

            if ($saved) {
                $replacements[] = $this->entryFor($result, $oldValue, $newValue) + ["itemId" => $itemId];

                if ($logId && count($replacements) % 10 === 0) {
                    $log->touchReplaceLog($logId, count($replacements));
                }
            }
        }

        if (isset($hooks["onRow"])) {
            $hooks["onRow"]($total, $total);
        }

        return $replacements;
    }

    /**
     * Runs a replace into an open log, whether inline or from the queue.
     * Every value is written to the log before its entry is saved; at the
     * end the log is closed with what happened, or removed if nothing
     * changed.
     *
     * @param array[] $rows the selected rows
     * @return array summary, as summaryFromLog() reads it back
     */
    public function run(
        int $logId,
        array $rows,
        string $query,
        string $replaceWith,
        bool $useRegex,
        bool $caseSensitive,
        bool $wholeWords,
        ?int $userId = null,
        string $searchId = "",
        ?callable $onRow = null,
        ?callable $isCancelled = null,
        bool $keepEmptyLog = false,
        bool $preserveCase = false
    ): array {
        $log = Editrix::$plugin->log;
        $cancelled = false;
        $skipped = [];
        $failed = [];

        try {
            $replacements = $this->replace(
                $rows,
                $query,
                $replaceWith,
                $useRegex,
                $caseSensitive,
                $wholeWords,
                $skipped,
                $failed,
                [
                    "logId" => $logId,
                    "preserveCase" => $preserveCase,
                    "onRow" => $onRow,
                    "isCancelled" => function () use ($isCancelled, &$cancelled) {
                        return $cancelled = (bool) ($isCancelled && $isCancelled());
                    },
                ]
            );
        } catch (\Throwable $e) {
            $count = (int) ($log->itemCounts($logId)["applied"] ?? 0);
            $log->finishReplaceLog($logId, $count > 0 ? "partial" : "failed", $count);
            throw $e;
        }

        $count = count($replacements);

        $dropped = $count === 0 && !$cancelled && !$keepEmptyLog;

        if ($dropped) {
            // Nothing changed: no log worth keeping.
            $log->deleteLog($logId);
        } else {
            $log->finishReplaceLog($logId, $cancelled ? "cancelled" : "success", $count);
        }

        $this->writeRunLog($logId, $query, $replaceWith, $userId, $count, count($skipped), count($failed), $cancelled);

        if ($userId !== null && $searchId !== "") {
            $this->markReplaced(
                $searchId,
                $userId,
                array_map(fn(array $r) => self::keyFor($r), $replacements)
            );
        }

        return [
            "logId" => $dropped ? null : $logId,
            "cancelled" => $cancelled,
            "replacedCount" => $count,
            "replacedMatches" => array_sum(array_map(fn(array $r) => (int) ($r["matches"] ?? 1), $replacements)),
            "replacedKeys" => array_map(fn(array $r) => self::keyFor($r), $replacements),
            "skipped" => $skipped,
            "failed" => $failed,
        ];
    }

    /**
     * One line per run, and one per value changed, in the plugin's log
     * file. Ids and field handles, not the text: enough to know what was
     * touched even if the database is restored from a backup.
     */
    private function writeRunLog(int $logId, string $query, string $replaceWith, ?int $userId, int $replaced, int $skipped, int $failed, bool $cancelled): void
    {
        $clip = fn(string $s) => mb_strimwidth(preg_replace('/\s+/', ' ', $s), 0, 80, "…");

        Craft::info(
            sprintf(
                'Replace #%d by user %s: "%s" -> "%s": %d changed, %d skipped, %d failed%s',
                $logId,
                $userId ?? "?",
                $clip($query),
                $clip($replaceWith),
                $replaced,
                $skipped,
                $failed,
                $cancelled ? " (cancelled)" : ""
            ),
            Editrix::LOG_CATEGORY
        );

        foreach (
            (new \craft\db\Query())
                ->select(["elementType", "elementId", "siteId", "fieldHandle", "state"])
                ->from(Install::TABLE_LOG_ITEMS)
                ->where(["logId" => $logId])
                ->orderBy(["id" => SORT_ASC])
                ->each(200) as $item
        ) {
            Craft::info(
                sprintf(
                    "Replace #%d %s %s #%d site %d field %s",
                    $logId,
                    $item["state"],
                    $item["elementType"],
                    $item["elementId"],
                    $item["siteId"],
                    $item["fieldHandle"]
                ),
                Editrix::LOG_CATEGORY
            );
        }
    }

    /**
     * What a replace did, read back from its log: the same summary run()
     * returns, for a replace that ran in the queue.
     */
    public function summaryFromLog(int $logId): array
    {
        $table = Install::TABLE_LOG_ITEMS;
        $key = fn(array $row) => self::keyFor($row);
        $rows = fn(string $state) => (new \craft\db\Query())
            ->select(["elementType", "elementId", "fieldHandle", "siteId", "elementTitle", "message", "matches"])
            ->from($table)
            ->where(["logId" => $logId, "state" => $state])
            ->orderBy(["id" => SORT_ASC])
            ->all();

        $applied = $rows("applied");
        $detail = fn(array $row) => [
            "key" => $key($row),
            "elementId" => (int) $row["elementId"],
            "elementTitle" => $row["elementTitle"] ?? "",
            "fieldHandle" => $row["fieldHandle"],
            "reason" => $row["message"],
        ];

        return [
            "replacedCount" => count($applied),
            "replacedMatches" => array_sum(array_map(fn($r) => (int) ($r["matches"] ?? 1), $applied)),
            "replacedKeys" => array_map($key, $applied),
            "skipped" => array_map($detail, $rows("skipped")),
            "failed" => array_map($detail, $rows("failed")),
        ];
    }

    /** The shape a log keeps for one changed value. */
    private function entryFor(array $result, ?string $old, ?string $new, ?string $message = null): array
    {
        return [
            "elementType" => $result["elementType"],
            "elementId" => $result["elementId"],
            "elementTitle" => $result["elementTitle"] ?? "",
            "sectionHandle" => $result["sectionHandle"] ?? null,
            "fieldHandle" => $result["fieldHandle"],
            "fieldName" => $result["fieldName"] ?? "",
            "oldValue" => $old,
            "newValue" => $new,
            "siteId" => $result["siteId"],
            "parentId" => $result["parentId"] ?? null,
            "message" => $message,
            // Occurrences in the field minus any left alone for crossing formatting
            "matches" => max(1, (int) ($result["matchCount"] ?? 1) - (int) ($result["readOnlyMatchCount"] ?? 0)),
        ];
    }

    /**
     * Whether a row is in a protected field or section (see Settings).
     * Checked when a search is shown and again when a replace runs.
     */
    public static function isProtected(string $fieldHandle, ?string $sectionHandle): bool
    {
        $settings = Editrix::$plugin->getSettings();

        return in_array($fieldHandle, $settings->protectedFields, true) ||
            ($sectionHandle !== null && in_array($sectionHandle, $settings->protectedSections, true));
    }

    /**
     * How many rows one replace may change: the edition's limit, lowered by
     * the optional setting, never raised by it.
     */
    public static function rowLimit(int $editionLimit, ?int $setting = null): int
    {
        $limit = $editionLimit > 0 ? $editionLimit : 1;

        return $setting !== null && $setting > 0 ? min($limit, $setting) : $limit;
    }

    public function currentRowLimit(): int
    {
        return self::rowLimit(
            Editrix::$plugin->license->getLimit("replaceRowLimit"),
            Editrix::$plugin->getSettings()->maxRowsPerReplace
        );
    }

    /**
     * Identifies one field of one element on one site. Results, replaced
     * marks and skipped items all use it, in the frontend too.
     */
    public static function keyFor(array $result): string
    {
        return "{$result["elementType"]}_{$result["elementId"]}_{$result["fieldHandle"]}_{$result["siteId"]}";
    }

    /**
     * How many occurrences a replace changed. A row stands for a whole
     * field, so this adds up the occurrences in the fields that were
     * rewritten, minus the ones left alone because they cross formatting.
     *
     * @param array[] $selected the rows the user selected
     * @param string[] $replacedKeys keys of the rows that were saved
     */
    public static function countOccurrences(array $selected, array $replacedKeys): int
    {
        $byKey = [];
        foreach ($selected as $row) {
            $byKey[self::keyFor($row)] = $row;
        }

        $total = 0;
        foreach ($replacedKeys as $key) {
            $row = $byKey[$key] ?? [];
            $skipped = isset($row["readOnlyMatchCount"])
                ? (int) $row["readOnlyMatchCount"]
                : count(array_filter(
                    $row["matches"] ?? [],
                    fn($m) => !empty($m["readOnly"])
                ));
            $total += max(1, (int) ($row["matchCount"] ?? 1) - $skipped);
        }

        return $total;
    }

    /**
     * A short fingerprint of a field's content, compared ignoring the
     * fallback URL Craft rewrites in reference tags. Not for security.
     */
    public static function hashValue(string $value): string
    {
        return md5(FieldValue::withoutRefFallbacks($value));
    }

    /**
     * The field's stored text right now, or null if the element is gone.
     * Same reader the search used, so the two are comparable.
     */
    public function currentValueFor(array $result): ?string
    {
        return $this->readCurrent($result)[1] ?? null;
    }

    /** @return array{0: mixed, 1: string}|null the element and the field's text */
    private function readCurrent(array $result): ?array
    {
        $element = $this->getElement($result);

        if (!$element) {
            return null;
        }

        return [
            $element,
            FieldValue::toString(
                $this->getElementValue($element, $result["fieldHandle"])
            ),
        ];
    }

    /**
     * Whether $current is what the search saw. Rows carry a "valueHash";
     * older saved searches carry the text itself. A row with neither
     * can't be verified, so it counts as changed.
     */
    public function matchesSearchSnapshot(array $result, string $current): bool
    {
        if (isset($result["valueHash"])) {
            $expected = $result["valueHash"];
        } elseif (isset($result["fieldValue"])) {
            $expected = self::hashValue((string) $result["fieldValue"]);
        } else {
            return false;
        }

        return self::hashValue($current) === $expected;
    }

    /**
     * Gives the replacement the capitalisation of the text it replaces:
     * ALL CAPS stays all caps, Title Case stays title case, anything else
     * (lowercase or mixed) takes the replacement as it was typed.
     */
    public static function wearCase(string $matched, string $replacement): string
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $matched) ?? '';

        if ($letters === '') {
            return $replacement;
        }

        if (mb_strlen($letters) > 1 && $letters === mb_strtoupper($letters)) {
            return mb_strtoupper($replacement);
        }

        if ($letters === mb_strtolower($letters)) {
            return $replacement;
        }

        // Title case: every word starts with a capital and has none after it.
        foreach (preg_split('/\s+/u', trim($matched), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            // Punctuation around a word ("(Widget)") is not part of its case.
            $wordLetters = preg_replace('/[^\p{L}]/u', '', $word) ?? '';

            if ($wordLetters === '') {
                continue;
            }

            $first = mb_substr($wordLetters, 0, 1);
            $rest = mb_substr($wordLetters, 1);

            if ($first === mb_strtolower($first) || $rest !== mb_strtolower($rest)) {
                return $replacement;
            }
        }

        return preg_replace_callback(
            '/(?<![\p{L}\p{N}])\p{Ll}/u',
            fn(array $m) => mb_strtoupper($m[0]),
            $replacement
        ) ?? $replacement;
    }

    /**
     * Records replaced matches on the saved search they came from, so
     * reopening it later shows them as done.
     */
    public function markReplaced(string $searchId, int $userId, array $keys): void
    {
        if ($searchId === "" || empty($keys)) {
            return;
        }

        $table = "{{%editrix_searches}}";
        $existing = (new \craft\db\Query())
            ->select(["replacedJson"])
            ->from($table)
            ->where(["searchId" => $searchId, "userId" => $userId])
            ->scalar();

        if ($existing === false) {
            return;
        }

        $merged = array_values(array_unique(array_merge(
            $existing ? \craft\helpers\Json::decode($existing) : [],
            $keys
        )));

        Craft::$app->getDb()->createCommand()
            ->update(
                $table,
                ["replacedJson" => \craft\helpers\Json::encode($merged)],
                ["searchId" => $searchId, "userId" => $userId]
            )
            ->execute();
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
        bool $wholeWords = false,
        bool $preserveCase = false
    ): string {
        return !empty($result["isRichText"])
            ? $this->performReplacementInHtml(
                $value,
                $query,
                $replaceWith,
                $useRegex,
                $caseSensitive,
                $wholeWords,
                $preserveCase
            )
            : $this->performReplacement(
                $value,
                $query,
                $replaceWith,
                $useRegex,
                $caseSensitive,
                $wholeWords,
                $preserveCase
            );
    }

    private function performReplacement(
        string $value,
        string $query,
        string $replaceWith,
        bool $useRegex,
        bool $caseSensitive,
        bool $wholeWords,
        bool $preserveCase = false
    ): string {
        // "widget" -> "gadget" also turns "Widget" into "Gadget" and
        // "WIDGET" into "GADGET". Only for plain and whole-word searches:
        // a regex says exactly what it wants.
        if ($preserveCase && !$useRegex) {
            $quoted = preg_quote($query, "/");
            $pattern = $wholeWords
                ? "/\\b{$quoted}\\b/iu"
                : "/{$quoted}/iu";

            return preg_replace_callback(
                $pattern,
                fn(array $m) => self::wearCase($m[0], $replaceWith),
                $value
            ) ?? $value;
        }

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
        bool $wholeWords,
        bool $preserveCase = false
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
                $wholeWords,
                $preserveCase
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
    public function isReplaceableField(array $result): bool
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

    /**
     * Writes one field and saves the element.
     *
     * Validation and propagation are off on purpose. Validation, because
     * content that predates a field's "required" flag would otherwise
     * refuse a typo fix (the validateOnReplace setting turns it on).
     * Propagation, because every site's row is its own result and is
     * replaced on its own: propagating would copy this site's text over
     * the next site's row and make that row look edited.
     *
     * @param string|null $error set to why it could not be saved
     */
    private function saveElementField(
        array $result,
        string $newValue,
        mixed $element = null,
        ?string &$error = null,
        bool $guardRequired = true
    ): bool {
        if (!$this->isReplaceableField($result)) {
            Craft::warning(
                "Editrix: Refused to replace non-replaceable field '{$result["fieldHandle"]}'",
                __METHOD__
            );
            $error = "this field cannot be replaced";
            return false;
        }

        try {
            $element ??= $this->getElement($result);

            if (!$element) {
                $error = "it no longer exists";
                return false;
            }

            // With validation off, nothing else would stop a replace from
            // blanking a field that must not be empty.
            if ($guardRequired && trim($newValue) === "" && $this->isRequired($element, $result["fieldHandle"])) {
                $error = "it would leave a required field empty";
                return false;
            }

            if ($result["fieldHandle"] === "title") {
                $element->title = $newValue;
            } else {
                $element->setFieldValue($result["fieldHandle"], $newValue);
            }

            $settings = Editrix::$plugin->getSettings();
            // A revision is how an editor sees, in the screen they already
            // use, that an entry changed and what it changed from.
            $element->resaving = !$settings->createRevisionsOnReplace;

            // A nested entry or block is a first-class element: save it
            // directly. (Re-fetching and saving its owner would discard
            // this change, since that is a separate, unmodified instance.)
            if (
                Craft::$app->getElements()->saveElement(
                    $element,
                    runValidation: $settings->validateOnReplace,
                    propagate: false,
                    updateSearchIndex: true
                )
            ) {
                return true;
            }

            $errors = [];
            foreach ($element->getErrors() as $messages) {
                $errors = array_merge($errors, (array) $messages);
            }
            $error = $errors ? implode("; ", $errors) : "Craft refused to save it";

            return false;
        } catch (\Throwable $e) {
            Craft::error(
                "Editrix: Failed to save element - " . $e->getMessage(),
                __METHOD__
            );
            $error = $e->getMessage();
            return false;
        }
    }

    /** Whether a field must not be left empty on this element. */
    private function isRequired(mixed $element, string $handle): bool
    {
        if ($handle === "title") {
            return true;
        }

        foreach ($element->getFieldLayout()?->getCustomFieldElements() ?? [] as $layoutElement) {
            if ($layoutElement->getField()->handle === $handle) {
                return (bool) $layoutElement->required;
            }
        }

        return false;
    }

    /**
     * Things wrong with a replace that can be known before reading a row.
     * They turn "nothing happened" into a sentence saying why.
     *
     * @return string[]
     */
    public static function objections(
        string $find,
        string $replace
    ): array {
        $objections = [];

        if (trim($find) === "") {
            $objections[] = Craft::t("editrix", "There is nothing to find.");
        } elseif ($find === $replace) {
            $objections[] = Craft::t(
                "editrix",
                "The replacement is the same as the search, so nothing would change."
            );
        }

        return $objections;
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
            // Craft 5 has no MatrixBlock class: Matrix blocks are Entries.
            "matrixBlock" => (class_exists(MatrixBlock::class)
                ? MatrixBlock::find()
                : Entry::find())
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
     * Validate whether a revert is safe (content hasn't changed since the replacement)
     *
     * Returns an array of discrepancies for each replacement:
     * - 'safe' = current value matches newValue, revert is clean
     * - 'unchanged' = it already holds the original text (a replace cut
     *   off before this entry was saved, or already reverted): nothing to do
     * - 'modified' = current value was changed after the replacement
     * - 'missing' = element no longer exists
     *
     * @param iterable $replacements entries as logs keep them
     */
    public function validateRevert(iterable $replacements): array
    {
        $results = [];

        foreach ($replacements as $i => $replacement) {
            $base = [
                "index" => $i,
                "elementId" => $replacement["elementId"],
                "elementTitle" => $replacement["elementTitle"] ?? "(unknown)",
                "fieldHandle" => $replacement["fieldHandle"],
            ];

            $status = $this->revertStatus($replacement, $current);

            if ($status === "modified") {
                $expected = (string) ($replacement["newValue"] ?? "");
                $base["expectedValue"] = mb_substr($expected, 0, 100);
                $base["currentValue"] = mb_substr((string) $current, 0, 100);
            }

            $results[] = ["status" => $status] + $base;
        }

        return $results;
    }

    /**
     * Where one logged value stands now: safe, unchanged, modified or
     * missing. $current is set to the text it holds, if it exists.
     */
    private function revertStatus(array $replacement, ?string &$current = null, mixed &$element = null): string
    {
        $read = $this->readCurrent($replacement);

        if ($read === null) {
            $current = null;
            return "missing";
        }

        [$element, $current] = $read;

        $same = fn(string $a, string $b) => FieldValue::withoutRefFallbacks($a)
            === FieldValue::withoutRefFallbacks($b);

        if ($same($current, (string) ($replacement["newValue"] ?? ""))) {
            return "safe";
        }

        if ($same($current, (string) ($replacement["oldValue"] ?? ""))) {
            return "unchanged";
        }

        return "modified";
    }

    /**
     * Puts logged values back, one by one, and says what happened to each.
     *
     * A value is restored only if it still holds exactly what the replace
     * wrote. One that was edited since is left alone and reported, because
     * putting the old text back would silently destroy that edit; $force
     * overrides this for the user who has decided to. A value that already
     * holds the original text counts as done, so reverting twice, or after
     * a replace that was cut off, is safe.
     *
     * @param iterable $entries entries as logs keep them (from LogService::replacementsFor)
     * @return array{reverted: int, unchanged: int, conflicts: array, missing: array, failed: array}
     */
    public function revertEntries(iterable $entries, bool $force = false, ?callable $onEach = null): array
    {
        $out = ["reverted" => 0, "unchanged" => 0, "conflicts" => [], "missing" => [], "failed" => []];
        $log = Editrix::$plugin->log;

        foreach ($entries as $entry) {
            $status = $this->revertStatus($entry, $current, $element);
            $itemId = $entry["itemId"] ?? null;
            $about = [
                "elementId" => $entry["elementId"],
                "elementTitle" => $entry["elementTitle"] ?? "",
                "fieldHandle" => $entry["fieldHandle"],
            ];

            if ($status === "missing") {
                $out["missing"][] = $about;
            } elseif ($status === "unchanged") {
                $out["unchanged"]++;
                $itemId && $log->setItemState($itemId, "reverted", "already held the original text");
            } elseif ($status === "modified" && !$force) {
                $out["conflicts"][] = $about;
            } else {
                $error = null;

                // The text being restored was valid before, so the guard
                // against emptying a required field does not apply.
                if ($this->saveElementField($entry, (string) $entry["oldValue"], $element, $error, false)) {
                    $out["reverted"]++;
                    $itemId && $log->setItemState($itemId, "reverted");
                } else {
                    $out["failed"][] = $about + ["reason" => $error];
                }
            }

            $onEach && $onEach($out);
        }

        return $out;
    }

    /** Closes a revert: reverted when nothing was left alone, otherwise partial. */
    public function finishRevert(int $logId, array $result, ?int $userId = null): void
    {
        Craft::info(
            sprintf(
                "Revert of replace #%d by user %s: %d restored, %d already original, %d left edited, %d missing, %d failed",
                $logId,
                $userId ?? "?",
                $result["reverted"],
                $result["unchanged"],
                count($result["conflicts"]),
                count($result["missing"]),
                count($result["failed"])
            ),
            Editrix::LOG_CATEGORY
        );

        $left = count($result["conflicts"]) + count($result["missing"]) + count($result["failed"]);
        $log = Editrix::$plugin->log;

        if ($left === 0) {
            $log->markAsReverted($logId, $userId);
        } elseif ($result["reverted"] + $result["unchanged"] > 0) {
            $log->markAsPartial($logId);
        } else {
            // Nothing could be restored: back to what it was before the attempt.
            $log->finishReplaceLog($logId, "success", (int) ($log->itemCounts($logId)["applied"] ?? 0));
        }
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

            return $this->saveElementField($replacement, (string) $replacement["oldValue"], $element, $error, false);
        } catch (\Throwable $e) {
            Craft::error(
                "Editrix: Failed to revert - " . $e->getMessage(),
                __METHOD__
            );
            return false;
        }
    }
}
