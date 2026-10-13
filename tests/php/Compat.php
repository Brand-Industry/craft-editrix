<?php

namespace brandindustry\editrix\tests;

use Craft;

/**
 * What differs between Craft 4 and Craft 5 for the tests: the service that
 * holds sections and entry types, and the Craft 5 only features some tests
 * need.
 */
final class Compat
{
    public static function isCraft4(): bool
    {
        return version_compare(Craft::$app->getVersion(), '5.0.0-alpha', '<');
    }

    /** The service that has getAllSections(), getSectionByHandle() and the entry type lookups. */
    public static function entries(): object
    {
        return self::isCraft4() ? Craft::$app->getSections() : Craft::$app->getEntries();
    }
}
