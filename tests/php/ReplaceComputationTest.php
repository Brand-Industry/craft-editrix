<?php

namespace brandindustry\editrix\tests;

use brandindustry\editrix\Editrix;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The text-level replacement rules, with no entries involved. These guard
 * the corruption bugs found in the audit (literal "$1" and backslashes in
 * the replacement text) and the HTML handling.
 */
class ReplaceComputationTest extends TestCase
{
    private function plain(
        string $value,
        string $query,
        string $with,
        bool $regex = false,
        bool $caseSensitive = true,
        bool $wholeWords = false
    ): string {
        return Editrix::$plugin->replace->computeNewValue(
            ['isRichText' => false],
            $value,
            $query,
            $with,
            $regex,
            $caseSensitive,
            $wholeWords
        );
    }

    private function html(string $value, string $query, string $with, bool $caseSensitive = true): string
    {
        return Editrix::$plugin->replace->computeNewValue(
            ['isRichText' => true],
            $value,
            $query,
            $with,
            false,
            $caseSensitive,
            false
        );
    }

    public function testCaseSensitiveReplaceLeavesOtherCasesAlone(): void
    {
        $this->assertSame('Inn and Hotel', $this->plain('hotel and Hotel', 'hotel', 'Inn'));
    }

    public function testCaseInsensitiveReplaceHitsEveryCase(): void
    {
        $this->assertSame('Inn and Inn', $this->plain('hotel and HOTEL', 'hotel', 'Inn', false, false));
    }

    public function testWholeWordsSkipsLongerWords(): void
    {
        $this->assertSame(
            'a dog and concatenate',
            $this->plain('a cat and concatenate', 'cat', 'dog', false, true, true)
        );
    }

    #[DataProvider('literalReplacements')]
    public function testReplacementTextIsInsertedLiterallyOutsideRegexMode(string $with): void
    {
        $this->assertSame("Price: {$with}", $this->plain('Price: old', 'old', $with));
        $this->assertSame("Price: {$with}", $this->plain('Price: OLD', 'old', $with, false, false));
        $this->assertSame("Price: {$with}", $this->plain('Price: old', 'old', $with, false, true, true));
    }

    public static function literalReplacements(): array
    {
        return [
            'dollar and digits' => ['$100'],
            'backreference lookalike' => ['$1'],
            'backslash' => ['C:\\temp\\new'],
            'braces' => ['${1}'],
        ];
    }

    public function testRegexModeStillSupportsCaptureGroups(): void
    {
        $this->assertSame('Smith, John', $this->plain('John Smith', '(\w+) (\w+)', '$2, $1', true));
    }

    public function testAnInvalidRegexIsRefusedInsteadOfBeingReadAsNoMatch(): void
    {
        $this->expectException(\brandindustry\editrix\helpers\RegexException::class);
        $this->plain('keep me', '(unclosed', 'x', true);
    }

    public function testMultibyteTextIsReplacedWithoutCorruption(): void
    {
        $this->assertSame('Un Hôtel à Zürich', $this->plain('Un Café à Zürich', 'Café', 'Hôtel'));
        $this->assertSame('Hôtel ÉTOILE', $this->plain('Café ÉTOILE', 'café', 'Hôtel', false, false));
    }

    public function testHtmlReplaceChangesTextAndKeepsMarkup(): void
    {
        $this->assertSame('<p>Hello there</p>', $this->html('<p>Hello world</p>', 'world', 'there'));
        $this->assertSame(
            'Our <i>Featured</i> Deals',
            $this->html('Our <i>Featured</i> Offers', 'Offers', 'Deals')
        );
    }

    public function testHtmlReplaceKeepsEntitiesIntact(): void
    {
        $this->assertSame(
            '<p>Tom &amp; Spike</p>',
            $this->html('<p>Tom &amp; Jerry</p>', 'Jerry', 'Spike')
        );
    }

    public function testHtmlReplaceNeverMatchesInsideTagAttributes(): void
    {
        $value = '<a href="/hotel">Book</a>';
        $this->assertSame($value, $this->html($value, 'hotel', 'inn'));
    }
}
