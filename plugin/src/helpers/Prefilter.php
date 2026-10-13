<?php

namespace brandindustry\editrix\helpers;

/**
 * Works out which words a database prefilter may require.
 *
 * A search scans entries, and on a large site almost all of them cannot
 * match. The prefilter asks the database for the rows whose stored text
 * contains the words a match must contain, so only those are loaded.
 *
 * Its one rule: it may return rows that don't match, it must never leave
 * out one that does. So it only ever asks for runs of plain letters and
 * digits (A-Z, 0-9) of at least MIN_LENGTH. Those are stored as they are,
 * unlike punctuation and accented letters, which HTML may store as
 * entities (&amp;, &eacute;) and which Editrix reads decoded. Anything it
 * is not certain about returns null, meaning "don't filter".
 *
 * Known gap: a word that formatting splits in the middle
 * (Fea<b>tured</b>) is found by Editrix but not by the prefilter. The
 * `sqlPrefilter` setting turns the prefilter off for sites where that
 * matters more than speed.
 */
final class Prefilter
{
    /** Shorter words match too much of the table to be worth asking for. */
    public const MIN_LENGTH = 3;

    /** More than this adds cost without narrowing much. */
    public const MAX_TOKENS = 4;

    /**
     * Words a plain or whole-word search requires, longest first.
     *
     * @return string[]|null null when no word is long enough to filter on
     */
    public static function forText(string $query): ?array
    {
        preg_match_all('/[A-Za-z0-9]+/', $query, $m);

        return self::pick($m[0]);
    }

    /**
     * Words a regular expression requires, or null when that can't be
     * known for certain. Deliberately timid: alternation, groups,
     * assertions and inline flags all give up, because each can make a
     * literal optional or even forbidden.
     *
     * @return string[]|null
     */
    public static function forRegex(string $pattern): ?array
    {
        if (str_contains($pattern, '|') || str_contains($pattern, '(')) {
            return null;
        }

        $runs = [];
        $run = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '\\') {
                // An escape is a class (\d), an anchor (\b) or a literal
                // punctuation mark. None of them is a letter or digit that
                // must appear, so it ends the run.
                $i++;
                $runs[] = $run;
                $run = '';
                continue;
            }

            if ($char === '[') {
                $i = self::endOfClass($pattern, $i);
                $runs[] = $run;
                $run = '';
                continue;
            }

            if ($char === '?' || $char === '*') {
                // The character before it is optional.
                $run = substr($run, 0, -1);
                $runs[] = $run;
                $run = '';
                continue;
            }

            if ($char === '{') {
                $close = strpos($pattern, '}', $i);
                $range = $close === false ? '' : substr($pattern, $i + 1, $close - $i - 1);

                // {0,3} makes the character before it optional; {2,} does not.
                if ($close === false || str_starts_with($range, '0') || $range === '' || $range[0] === ',') {
                    $run = substr($run, 0, -1);
                }

                $runs[] = $run;
                $run = '';
                $i = $close === false ? $length : $close;
                continue;
            }

            if (ctype_alnum($char)) {
                $run .= $char;
                continue;
            }

            // + . ^ $ ) and anything else that is not a plain letter or digit
            $runs[] = $run;
            $run = '';
        }

        $runs[] = $run;

        return self::pick($runs);
    }

    /**
     * The words to ask the database for, from candidate runs: unique,
     * long enough, longest first, at most MAX_TOKENS.
     *
     * @param string[] $runs
     * @return string[]|null
     */
    private static function pick(array $runs): ?array
    {
        // Unique ignoring case: the database compares ignoring case too.
        $unique = [];
        foreach ($runs as $run) {
            if (strlen($run) >= self::MIN_LENGTH) {
                $unique[strtolower($run)] ??= $run;
            }
        }
        $runs = array_values($unique);

        if ($runs === []) {
            return null;
        }

        usort($runs, fn(string $a, string $b) => strlen($b) <=> strlen($a));

        return array_slice($runs, 0, self::MAX_TOKENS);
    }

    /** Index of the `]` that closes a class opened at $start. */
    private static function endOfClass(string $pattern, int $start): int
    {
        $length = strlen($pattern);
        $i = $start + 1;

        if (($pattern[$i] ?? '') === '^') {
            $i++;
        }

        // A ] right after the opening bracket is a literal ].
        if (($pattern[$i] ?? '') === ']') {
            $i++;
        }

        for (; $i < $length; $i++) {
            if ($pattern[$i] === '\\') {
                $i++;
                continue;
            }

            if ($pattern[$i] === ']') {
                return $i;
            }
        }

        return $length;
    }
}
