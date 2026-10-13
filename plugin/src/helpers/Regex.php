<?php

namespace brandindustry\editrix\helpers;

use Craft;

/**
 * Every pattern a user types goes through here.
 *
 * Two problems it removes. A "/" typed in a pattern (https?://) ended it
 * early and was reported as invalid. And a pattern that backtracks without
 * end made PHP return false, which the callers read as "no match", so a
 * search quietly missed entries; with PHP's default limit of a million
 * steps it could also keep a queue job busy for a long time.
 */
class Regex
{
    /** Steps one pattern may take on one text before it is given up on. */
    public const BACKTRACK_LIMIT = 100000;

    /**
     * The pattern as PHP wants it: delimited, Unicode, and with any "/" the
     * user did not escape escaped for them.
     */
    public static function pattern(string $query, bool $caseSensitive = true): string
    {
        // An unescaped "/" is one preceded by an even number of backslashes.
        $escaped = preg_replace('~(?<!\\\\)((?:\\\\\\\\)*)/~', '$1\\/', $query) ?? $query;

        return "/{$escaped}/" . ($caseSensitive ? "u" : "iu");
    }

    /** Null when the pattern compiles, otherwise what to tell the user. */
    public static function validate(string $query): ?string
    {
        if (@preg_match(self::pattern($query), "") === false) {
            return Craft::t("editrix", "Invalid regular expression");
        }

        return null;
    }

    /**
     * Every match of the pattern in the text, as preg_match_all reports
     * them with PREG_OFFSET_CAPTURE. Text that is not valid UTF-8 has no
     * matches.
     *
     * @return array<int, array{0: string, 1: int}>
     * @throws RegexException
     */
    public static function matchAll(string $pattern, string $subject): array
    {
        [$found, $error] = self::guarded(
            fn() => preg_match_all($pattern, $subject, $matches, PREG_OFFSET_CAPTURE) === false
                ? false
                : $matches[0]
        );

        if ($found === false) {
            return self::failed($error, []);
        }

        return $found;
    }

    /**
     * preg_replace with the same limit. Text that is not valid UTF-8 is
     * returned as it was.
     *
     * @throws RegexException
     */
    public static function replace(string $pattern, string $replacement, string $subject): string
    {
        [$done, $error] = self::guarded(fn() => preg_replace($pattern, $replacement, $subject));

        if ($done === null) {
            return self::failed($error, $subject);
        }

        return $done;
    }

    /**
     * What the replacement becomes for the one match that starts at the
     * character offset $charStart of $subject: $1, ${1} and \1 stand for the
     * groups of that match. Used when only some matches are replaced, where
     * preg_replace cannot be told to skip the others.
     *
     * @throws RegexException
     */
    public static function expand(string $pattern, string $replacement, string $subject, int $charStart): string
    {
        $byteStart = strlen(mb_substr($subject, 0, $charStart));

        [$found, $error] = self::guarded(
            fn() => preg_match($pattern, $subject, $groups, 0, $byteStart) === 1 ? $groups : false
        );

        if ($found === false) {
            return self::failed($error, $replacement);
        }

        return preg_replace_callback(
            '/\\\\(\d{1,2})|\$\{(\d{1,2})\}|\$(\d{1,2})/',
            function (array $ref) use ($found): string {
                $n = (int) ($ref[1] !== "" ? $ref[1] : ($ref[2] !== "" ? $ref[2] : $ref[3]));
                return (string) ($found[$n] ?? "");
            },
            $replacement
        ) ?? $replacement;
    }

    /** @return array{0: mixed, 1: int} what the call returned, and preg_last_error() after it */
    private static function guarded(callable $call): array
    {
        $previous = ini_get("pcre.backtrack_limit");
        ini_set("pcre.backtrack_limit", (string) self::BACKTRACK_LIMIT);

        try {
            $result = @$call();
            $error = preg_last_error();
        } finally {
            ini_set("pcre.backtrack_limit", $previous === false ? "1000000" : $previous);
        }

        return [$result, $error];
    }

    /**
     * What to do with a call that failed: nothing for text PHP cannot read
     * as UTF-8, a clear error for anything else.
     *
     * @throws RegexException
     */
    private static function failed(int $error, mixed $whenUnreadable): mixed
    {
        if ($error === PREG_BAD_UTF8_ERROR || $error === PREG_BAD_UTF8_OFFSET_ERROR) {
            return $whenUnreadable;
        }

        // PHP 8 reports a pattern that does not compile as an internal error.
        if ($error === PREG_NO_ERROR || $error === PREG_INTERNAL_ERROR) {
            throw new RegexException(Craft::t("editrix", "Invalid regular expression"));
        }

        throw new RegexException(Craft::t(
            "editrix",
            "This regular expression is too complex for some of the text it meets. Make it more specific, for example by anchoring it or by avoiding nested repeats such as (a+)+."
        ));
    }
}
