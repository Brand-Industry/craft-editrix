<?php

namespace brandindustry\editrix\helpers;

/**
 * Shared HTML/plain-text mapping used by both SearchService (to find
 * matches that span an inline tag, e.g. "Our <i>Featured</i> Offers", or
 * an entity, e.g. searching "Terms & Conditions" against source that
 * stores "Terms &amp; Conditions") and ReplaceService (to rewrite only
 * the part of a match that actually changed, leaving untouched tags and
 * entities intact).
 */
class HtmlText
{
    /**
     * Strips HTML tags from $html and decodes HTML entities (&amp;, &lt;,
     * &nbsp;, &#39;, ...) so plain-text matching works against what an
     * editor actually sees, not the raw markup. Returns the plain text
     * plus a map from each CHARACTER offset in that plain text back to
     * the [start, end) character span in $html it came from - a span,
     * not a single offset, because a decoded entity like "&amp;" (5 raw
     * characters) collapses to one plain character ("&"). Units match
     * mb_substr/mb_strlen (not bytes) - multi-byte content would
     * otherwise drift the mapping the deeper into $html a match is.
     */
    public static function stripTagsWithCharMap(string $html): array
    {
        $plain = "";
        $map = [];
        $inTag = false;

        $chars = mb_str_split($html);
        $len = count($chars);
        $i = 0;

        while ($i < $len) {
            $char = $chars[$i];

            if ($char === "<") {
                $inTag = true;
                $i++;
                continue;
            }
            if ($char === ">") {
                $inTag = false;
                $i++;
                continue;
            }
            if ($inTag) {
                $i++;
                continue;
            }

            if ($char === "&") {
                $entityLen = self::matchEntityLength($chars, $i, $len);
                if ($entityLen !== null) {
                    $raw = implode(
                        "",
                        array_slice($chars, $i, $entityLen)
                    );
                    $decoded = html_entity_decode(
                        $raw,
                        ENT_QUOTES | ENT_HTML5,
                        "UTF-8"
                    );
                    foreach (mb_str_split($decoded) as $decodedChar) {
                        $plain .= $decodedChar;
                        $map[] = [$i, $i + $entityLen];
                    }
                    $i += $entityLen;
                    continue;
                }
            }

            $plain .= $char;
            $map[] = [$i, $i + 1];
            $i++;
        }

        return [$plain, $map];
    }

    /**
     * Character length of a well-formed HTML entity starting at
     * $chars[$start] (which must be "&"), or null if what follows isn't
     * one - e.g. a bare "&" in running text ("Smith & Sons"), which
     * should stay a literal "&" rather than being treated as the start
     * of a malformed entity.
     */
    private static function matchEntityLength(
        array $chars,
        int $start,
        int $len
    ): ?int {
        // The longest standard HTML5 named entity is 34 characters
        // (&CounterClockwiseContourIntegral;); this window comfortably
        // covers it plus named/numeric references.
        $windowEnd = min($start + 40, $len);
        $window = implode("", array_slice($chars, $start, $windowEnd - $start));

        if (
            preg_match(
                '/^&(#x[0-9a-fA-F]+|#[0-9]+|[a-zA-Z][a-zA-Z0-9]*);/',
                $window,
                $m
            )
        ) {
            return mb_strlen($m[0]);
        }

        return null;
    }
}
