<?php

namespace brandindustry\editrix\tests;

use PHPUnit\Framework\TestCase;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\helpers\Regex;
use brandindustry\editrix\helpers\RegexException;
use brandindustry\editrix\services\SearchService;

/**
 * Patterns people type: a "/" in one, and one that never finishes.
 */
class RegexGuardTest extends TestCase
{
    public function testASlashInThePatternIsNotTheEndOfIt(): void
    {
        $matches = Editrix::$plugin->search->findMatches(
            'See http://acme.test/a and https://beta.test/b',
            'https?://\w+\.test',
            true,
            true,
            false
        );

        $this->assertCount(2, $matches);
        $this->assertSame('https://beta.test', $matches[1]['match']);
    }

    public function testAnEscapedSlashStaysEscaped(): void
    {
        $this->assertSame('/a\/b/u', Regex::pattern('a\/b'));
        $this->assertSame('/a\/b/u', Regex::pattern('a/b'));
        $this->assertSame('/[\/]x/iu', Regex::pattern('[/]x', false));
    }

    public function testAPatternThatDoesNotCompileIsReportedAsInvalid(): void
    {
        $this->assertNotNull(Regex::validate('(abc'));
        $this->assertNull(Regex::validate('https?://x'));
    }

    public function testAPatternThatNeverFinishesFailsLoudly(): void
    {
        $limit = ini_get('pcre.backtrack_limit');

        try {
            Editrix::$plugin->search->findMatches(str_repeat('a', 40) . 'b', '(a+)+$', true, true, false);
            $this->fail('A runaway pattern must not be read as "no match".');
        } catch (RegexException $e) {
            $this->assertStringContainsString('too complex', $e->getMessage());
        }

        $this->assertSame($limit, ini_get('pcre.backtrack_limit'), 'The limit is put back.');
    }

    public function testReplacingWithASlashInThePattern(): void
    {
        $this->assertSame(
            'go to https://x.test',
            Regex::replace(Regex::pattern('http://'), 'https://', 'go to http://x.test')
        );
    }

    public function testTextThatIsNotUtf8HasNoMatchesAndIsNotChanged(): void
    {
        $this->assertSame([], Regex::matchAll(Regex::pattern('a'), "a\xff"));
        $this->assertSame("a\xff", Regex::replace(Regex::pattern('a'), 'b', "a\xff"));
    }

    public function testASearchDoesNotSwallowARunawayPattern(): void
    {
        $service = new class extends SearchService {
            protected function searchOneEntry(
                \craft\elements\Entry $entry,
                string $query,
                int $siteId,
                $site,
                bool $useRegex,
                bool $caseSensitive,
                bool $searchMatrix,
                bool $wholeWords,
                array $fieldFilter,
                array &$results
            ): void
            {
                throw new RegexException('too complex');
            }
        };

        $section = null;
        foreach (\Craft::$app->getEntries()->getAllSections() as $candidate) {
            if (\craft\elements\Entry::find()->section($candidate->handle)->status(null)->drafts(false)->revisions(false)->exists()) {
                $section = $candidate->handle;
                break;
            }
        }
        if (!$section) {
            $this->markTestSkipped('The site has no entries.');
        }

        $this->expectException(RegexException::class);
        $service->search('x', \Craft::$app->getSites()->getPrimarySite()->id, true, true, [
            'searchEntries' => true,
            'searchGlobals' => false,
            'sections' => [$section],
            'sqlPrefilter' => false,
            'maxResults' => 5,
        ]);
    }
}
