<?php

namespace brandindustry\editrix\tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\services\LicenseService;
use brandindustry\editrix\services\ReplaceService;

/**
 * "widget" -> "gadget" also turns "Widget" into "Gadget" and "WIDGET" into
 * "GADGET".
 */
class PreserveCaseTest extends TestCase
{
    #[DataProvider('cases')]
    public function testTheReplacementWearsTheCaseOfWhatItReplaces(string $matched, string $replacement, string $expected): void
    {
        $this->assertSame($expected, ReplaceService::wearCase($matched, $replacement));
    }

    public static function cases(): array
    {
        return [
            'lowercase takes it as typed' => ['widget', 'gadget', 'gadget'],
            'lowercase keeps typed capitals' => ['widget', 'NASA', 'NASA'],
            'title case' => ['Widget', 'gadget', 'Gadget'],
            'all caps' => ['WIDGET', 'gadget', 'GADGET'],
            'mixed case takes it as typed' => ['wIdGeT', 'gadget', 'gadget'],
            'title case over several words' => ['Big Widget', 'small gadget', 'Small Gadget'],
            'one capital letter is title case, not all caps' => ['A', 'an', 'An'],
            'accented title case' => ['Écran', 'screen', 'Screen'],
            'accented all caps' => ['ÉCOLE', 'school', 'SCHOOL'],
            'no letters at all' => ['123', 'abc', 'abc'],
            'a title word with a capital inside is not title case' => ['WiDget', 'gadget', 'gadget'],
            'punctuation around a title word is not part of its case' => ['(Widget)', 'gadget', 'Gadget'],
        ];
    }

    public function testEveryCapitalisationInAPlainFieldIsReplaced(): void
    {
        $out = Editrix::$plugin->replace->computeNewValue(
            ['isRichText' => false],
            'Widget, widget and WIDGET',
            'widget',
            'gadget',
            false,
            false,
            false,
            true
        );

        $this->assertSame('Gadget, gadget and GADGET', $out);
    }

    public function testWithoutItEveryVariantBecomesTheTypedText(): void
    {
        $out = Editrix::$plugin->replace->computeNewValue(
            ['isRichText' => false],
            'Widget, widget and WIDGET',
            'widget',
            'gadget',
            false,
            false,
            false,
            false
        );

        $this->assertSame('gadget, gadget and gadget', $out);
    }

    public function testRichTextKeepsItsMarkupAndStillPreservesCase(): void
    {
        $out = Editrix::$plugin->replace->computeNewValue(
            ['isRichText' => true],
            '<p>The <b>Widget</b> and WIDGET and widget</p>',
            'widget',
            'gadget',
            false,
            false,
            false,
            true
        );

        $this->assertSame('<p>The <b>Gadget</b> and GADGET and gadget</p>', $out);
    }

    public function testWholeWordsStillLeavesLongerWordsAlone(): void
    {
        $out = Editrix::$plugin->replace->computeNewValue(
            ['isRichText' => false],
            'widgets and Widget',
            'widget',
            'gadget',
            false,
            false,
            true,
            true
        );

        $this->assertSame('widgets and Gadget', $out);
    }

    public function testARegexSaysExactlyWhatItWantsSoCaseIsNotPreserved(): void
    {
        $out = Editrix::$plugin->replace->computeNewValue(
            ['isRichText' => false],
            'Widget',
            'widget',
            'gadget',
            true,
            false,
            false,
            true
        );

        $this->assertSame('gadget', $out);
    }

    public function testRegexCharactersInThePlainSearchAreLiteral(): void
    {
        $out = Editrix::$plugin->replace->computeNewValue(
            ['isRichText' => false],
            'Price (usd) and price (usd)',
            'price (usd)',
            'cost (eur)',
            false,
            false,
            false,
            true
        );

        // Found and replaced as plain text, brackets and all. "Price (usd)"
        // is not in title case ("(usd)" is lower), so the typed text is used.
        $this->assertSame('cost (eur) and cost (eur)', $out);
    }

    public function testOnlyProCanPreserveCase(): void
    {
        $license = Editrix::$plugin->license;
        $real = Editrix::$plugin->edition;

        try {
            Editrix::$plugin->edition = Editrix::EDITION_STANDARD;
            $this->assertFalse($license->hasFeature(LicenseService::FEATURE_PRESERVE_CASE));

            Editrix::$plugin->edition = Editrix::EDITION_PRO;
            $this->assertTrue($license->hasFeature(LicenseService::FEATURE_PRESERVE_CASE));
        } finally {
            Editrix::$plugin->edition = $real;
        }
    }
}
