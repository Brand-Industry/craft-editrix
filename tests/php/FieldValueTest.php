<?php

namespace brandindustry\editrix\tests;

use brandindustry\editrix\helpers\FieldValue;
use PHPUnit\Framework\TestCase;

/**
 * Craft rewrites the fallback URL of a reference tag from the current
 * domain on every save, so a value saved on another host (a copy of
 * production run locally) differs from what Editrix logged only there.
 */
class FieldValueTest extends TestCase
{
    public function testTheFallbackOfAnAssetReferenceIsDropped(): void
    {
        $this->assertSame('<img src="{asset:12:url}">', FieldValue::withoutRefFallbacks('<img src="{asset:12:url||https://cdn.test/x.jpg}">'));
    }

    public function testTheFallbackOfAReferenceWithASiteIsDropped(): void
    {
        $this->assertSame(
            '<a href="{entry:16090@1:url}">x</a>',
            FieldValue::withoutRefFallbacks('<a href="{entry:16090@1:url||https://www.example.com/blog/post}">x</a>')
        );
    }

    public function testTheFallbackOfAReferenceByHandleIsDropped(): void
    {
        $this->assertSame('{entry:my-post:url}', FieldValue::withoutRefFallbacks('{entry:my-post:url||https://example.com/my-post}'));
    }

    public function testTwoHostsOfTheSameContentCompareEqual(): void
    {
        $production = '<p><a href="{entry:7@1:url||https://www.example.com/a}">a</a></p>';
        $local = '<p><a href="{entry:7@1:url||https://site.test/a}">a</a></p>';

        $this->assertNotSame($production, $local);
        $this->assertSame(FieldValue::withoutRefFallbacks($production), FieldValue::withoutRefFallbacks($local));
    }

    public function testOrdinaryTextAndReferencesWithoutAFallbackAreLeftAlone(): void
    {
        foreach (['{globalset:3}', 'a {b||c} d', '<p>plain</p>', '{entry:7@1:url}'] as $text) {
            $this->assertSame($text, FieldValue::withoutRefFallbacks($text));
        }
    }

    public function testADifferentEditStillShows(): void
    {
        $this->assertNotSame(
            FieldValue::withoutRefFallbacks('{entry:7@1:url||https://a.test/x} one'),
            FieldValue::withoutRefFallbacks('{entry:7@1:url||https://a.test/x} two')
        );
    }
}
