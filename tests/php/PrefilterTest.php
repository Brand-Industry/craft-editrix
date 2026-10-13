<?php

namespace brandindustry\editrix\tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use brandindustry\editrix\helpers\Prefilter;

/**
 * What the database prefilter may require of a match. The rule these
 * protect: a word that is asked for must be one that any match contains,
 * stored exactly like that.
 */
class PrefilterTest extends TestCase
{
    public function testAPlainWordIsAskedFor(): void
    {
        $this->assertSame(['hotel'], Prefilter::forText('hotel'));
    }

    public function testEachWordOfAPhraseIsAskedForLongestFirst(): void
    {
        $this->assertSame(['available', 'tools', 'big'], Prefilter::forText('big available tools'));
    }

    public function testPunctuationAndEntitiesNeverBecomePartOfAWord(): void
    {
        // "&" is stored as &amp;, so it must not be part of what is asked for.
        $this->assertSame(['Chips', 'Fish'], Prefilter::forText('Fish & Chips'));
        $this->assertSame(['stop', 'don'], Prefilter::forText("don't stop"));
    }

    public function testAccentedLettersEndAWordBecauseTheyMayBeStoredAsEntities(): void
    {
        // "café" may be stored as caf&eacute;, so only "caf" is certain.
        $this->assertSame(['resort', 'caf'], Prefilter::forText('café resort'));
    }

    public function testShortWordsAreNotWorthAskingFor(): void
    {
        $this->assertNull(Prefilter::forText('a is'));
        $this->assertNull(Prefilter::forText('e'));
        $this->assertNull(Prefilter::forText(''));
        $this->assertNull(Prefilter::forText('é ü'));
    }

    public function testWordsAreUniqueAndCapped(): void
    {
        $this->assertSame(['hotel'], Prefilter::forText('hotel Hotel HOTEL'));
        $this->assertCount(Prefilter::MAX_TOKENS, Prefilter::forText('aaa bbb ccc ddd eee fff'));
    }

    #[DataProvider('regexes')]
    public function testRegexesOnlyAskForWhatTheyMustContain(string $pattern, ?array $expected): void
    {
        $this->assertSame($expected, Prefilter::forRegex($pattern));
    }

    public static function regexes(): array
    {
        return [
            'a literal with a class after it' => ['hotel\\d+', ['hotel']],
            'an optional letter is not required' => ['colou?r', ['colo']],
            'a letter that may repeat zero times is not required' => ['abcd{0,2}', ['abc']],
            'a letter that repeats at least once is' => ['abc{2,}', ['abc']],
            'an anchor ends a word' => ['\\bACME\\b', ['ACME']],
            'punctuation ends a word' => ['old-docs/[\\w-]+', ['docs', 'old']],
            'alternation gives up' => ['foo|bar', null],
            'a group gives up' => ['(foo)bar', null],
            'a lookahead gives up, since its text is forbidden not required' => ['foo(?!bar)', null],
            'an inline flag gives up' => ['(?i)hotel', null],
            'nothing literal long enough' => ['\\d+\\s\\w', null],
            'a class is not required' => ['[abc]def', ['def']],
            'a star makes the letter before it optional' => ['abcd*efgh', ['efgh', 'abc']],
            'a word too short after dropping the optional letter' => ['abc?', null],
            'an escaped dot is punctuation' => ['hotel\\.com', ['hotel', 'com']],
        ];
    }
}
