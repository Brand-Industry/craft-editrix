<?php

namespace brandindustry\editrix\tests;

use PHPUnit\Framework\TestCase;
use brandindustry\editrix\Editrix;

/**
 * Leaving single occurrences out of a replace, and replacing the address of
 * a link or an image. Works on text alone, so it needs no entries.
 */
class OccurrenceTest extends TestCase
{
    private function compute(array $row, string $value, string $find, string $with, bool $regex = false): string
    {
        return Editrix::$plugin->replace->computeNewValue($row, $value, $find, $with, $regex, true, false);
    }

    public function testAPlainFieldKeepsTheOccurrencesTurnedOff(): void
    {
        $this->assertSame(
            'dog cat dog',
            $this->compute(['excludedMatches' => [1]], 'cat cat cat', 'cat', 'dog')
        );
    }

    public function testNothingTurnedOffReplacesEverything(): void
    {
        $this->assertSame('dog dog dog', $this->compute([], 'cat cat cat', 'cat', 'dog'));
    }

    public function testARegexKeepsItsGroupsWhenSomeOccurrencesAreLeftOut(): void
    {
        $this->assertSame(
            'a1 b2 b3',
            $this->compute(['excludedMatches' => [0]], 'a1 a2 a3', 'a(\d)', 'b$1', true)
        );
    }

    public function testARegexThatLooksAtWhatSurroundsAMatchStillWorks(): void
    {
        // Only the "a" that is followed by a digit counts; it needs the text after it.
        $this->assertSame(
            'a x b1 b2',
            $this->compute(['excludedMatches' => []], 'a x a1 a2', 'a(?=\d)', 'b', true)
        );
        $this->assertSame(
            'a x a1 b2',
            $this->compute(['excludedMatches' => [0]], 'a x a1 a2', 'a(?=\d)', 'b', true)
        );
    }

    public function testARichTextFieldKeepsTheOccurrencesTurnedOff(): void
    {
        $this->assertSame(
            '<p>dog <b>cat</b> dog</p>',
            $this->compute(['isRichText' => true, 'excludedMatches' => [1]], '<p>cat <b>cat</b> cat</p>', 'cat', 'dog')
        );
    }

    public function testLinkAddressesAreLeftAloneUnlessAsked(): void
    {
        $html = '<p>See <a href="http://old.test/x">old.test</a></p>';

        $this->assertSame(
            '<p>See <a href="http://old.test/x">new.test</a></p>',
            $this->compute(['isRichText' => true], $html, 'old.test', 'new.test')
        );
    }

    public function testLinkAndImageAddressesAreReplacedWhenAsked(): void
    {
        $html = '<p>See <a href="http://old.test/x">old.test</a> <img src="http://old.test/i.png"></p>';

        $this->assertSame(
            '<p>See <a href="http://new.test/x">new.test</a> <img src="http://new.test/i.png"></p>',
            $this->compute(['isRichText' => true, 'includeLinks' => true], $html, 'old.test', 'new.test')
        );
    }

    public function testOnlyTheAddressBetweenTheQuotesIsTouched(): void
    {
        $html = '<a class="old.test" href="http://old.test/x">go</a>';

        $this->assertSame(
            '<a class="old.test" href="http://new.test/x">go</a>',
            $this->compute(['isRichText' => true, 'includeLinks' => true], $html, 'old.test', 'new.test')
        );
    }

    public function testOccurrencesAreListedInTheOrderTheyAppear(): void
    {
        $html = '<p>old.test <a href="http://old.test/x">old.test</a></p>';

        $list = Editrix::$plugin->search->htmlOccurrences($html, 'old.test', false, true, false, true);

        $this->assertCount(3, $list);
        $this->assertSame([false, true, false], array_column($list, 'attr'));
        $this->assertSame(array_column($list, 'start'), array_values(array_column($list, 'start')));
        $this->assertSame(
            array_column($list, 'start'),
            (function ($starts) { sort($starts); return $starts; })(array_column($list, 'start'))
        );
    }

    public function testAnAddressCanBeLeftOutLikeAnyOtherOccurrence(): void
    {
        $html = '<p>old.test <a href="http://old.test/x">old.test</a></p>';

        // 0 is the first word, 1 the address, 2 the link text.
        $this->assertSame(
            '<p>new.test <a href="http://old.test/x">new.test</a></p>',
            $this->compute(['isRichText' => true, 'includeLinks' => true, 'excludedMatches' => [1]], $html, 'old.test', 'new.test')
        );
    }

    public function testASingleQuotedAddressIsFound(): void
    {
        $html = "<a href='http://old.test/x'>go</a>";

        $this->assertSame(
            "<a href='http://new.test/x'>go</a>",
            $this->compute(['isRichText' => true, 'includeLinks' => true], $html, 'old.test', 'new.test')
        );
    }

    public function testAccentedTextBeforeAnAddressDoesNotShiftIt(): void
    {
        $html = '<p>Ñandú café <a href="http://old.test/ñ">old.test</a></p>';

        $this->assertSame(
            '<p>Ñandú café <a href="http://new.test/ñ">new.test</a></p>',
            $this->compute(['isRichText' => true, 'includeLinks' => true], $html, 'old.test', 'new.test')
        );
    }
}
