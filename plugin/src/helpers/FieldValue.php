<?php

namespace brandindustry\editrix\helpers;

class FieldValue
{
    /**
     * The value as it is stored, as a string.
     *
     * Rich text fields (CKEditor, Redactor) return a FieldData object whose
     * __toString() renders the content for the front end. For CKEditor that
     * expands every nested entry through its Twig template, so the string no
     * longer holds the <craft-entry> markers and writing it back would
     * flatten those entries. getRawContent() is the stored HTML.
     */
    public static function toString(mixed $value): string
    {
        if (is_object($value) && method_exists($value, "getRawContent")) {
            return (string) $value->getRawContent();
        }

        if ($value instanceof \Stringable || is_scalar($value)) {
            return (string) $value;
        }

        return "";
    }

    /**
     * Drops the fallback URL from reference tags, so
     * {asset:12:url||https://cdn/x.jpg} compares equal to {asset:12:url}.
     * Craft rewrites that fallback from the asset's current URL on every
     * save, so two copies of the same content can differ only there.
     */
    public static function withoutRefFallbacks(string $html): string
    {
        return preg_replace('/\{(\w+:\d+(?::\w+)?)\|\|[^}]*\}/', '{$1}', $html) ?? $html;
    }
}
