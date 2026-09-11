<?php

namespace brandindustry\editrix\helpers;

/**
 * Shared HTML/plain-text mapping used by both SearchService (to find
 * matches that span an inline tag, e.g. "Our <i>Featured</i> Offers") and
 * ReplaceService (to rewrite only the part of a match that actually
 * changed, leaving untouched tags intact).
 */
class HtmlText
{
    /**
     * Strips HTML tags from $html, returning the plain text plus a map from
     * each CHARACTER offset in that plain text back to its character
     * offset in $html (matching mb_substr/mb_strlen's units, not byte
     * offsets - multi-byte content like curly quotes or accented letters
     * would otherwise drift the mapping the deeper into $html a match is).
     */
    public static function stripTagsWithCharMap(string $html): array
    {
        $plain = "";
        $map = [];
        $inTag = false;

        foreach (mb_str_split($html) as $i => $char) {
            if ($char === "<") {
                $inTag = true;
                continue;
            }
            if ($char === ">") {
                $inTag = false;
                continue;
            }
            if ($inTag) {
                continue;
            }
            $plain .= $char;
            $map[] = $i;
        }

        return [$plain, $map];
    }
}
