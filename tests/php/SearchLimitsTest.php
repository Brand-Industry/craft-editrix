<?php

namespace brandindustry\editrix\tests;

use brandindustry\editrix\Editrix;
use brandindustry\editrix\models\Settings;
use brandindustry\editrix\services\SearchService;

/**
 * How many rows a search may return, by edition.
 */
class SearchLimitsTest extends EditrixTestCase
{
    public function testEachEditionHasItsOwnLimit(): void
    {
        $license = Editrix::$plugin->license;

        $this->assertSame(3000, $license->getLimitFor(Editrix::EDITION_STANDARD, 'searchResultLimit'));
        $this->assertSame(25000, $license->getLimitFor(Editrix::EDITION_PRO, 'searchResultLimit'));
    }

    public function testTheLimitIsTheEditionsOwn(): void
    {
        $this->assertSame(3000, SearchService::resultLimit(3000));
        $this->assertSame(25000, SearchService::resultLimit(25000));
    }

    public function testASettingCanLowerTheLimitButNeverRaiseIt(): void
    {
        $this->assertSame(1000, SearchService::resultLimit(3000, 1000));
        $this->assertSame(3000, SearchService::resultLimit(3000, 10000), 'Standard cannot be configured up to Pro.');
        $this->assertSame(3000, SearchService::resultLimit(3000, null));
        $this->assertSame(3000, SearchService::resultLimit(3000, 0));
    }

    public function testNothingGoesPastTheHardCeiling(): void
    {
        $this->assertSame(SearchService::HARD_CEILING, SearchService::resultLimit(1000000));
        $this->assertSame(SearchService::HARD_CEILING, SearchService::resultLimit(0), 'An unknown limit falls back to the ceiling, not to unlimited.');
    }

    public function testTheSiteCurrentLimitFollowsItsEdition(): void
    {
        $expected = Editrix::$plugin->edition === Editrix::EDITION_PRO ? 25000 : 3000;
        $settings = Editrix::$plugin->getSettings();

        if ($settings->maxSearchResults !== null) {
            $this->markTestSkipped('This site lowers the limit with the maxSearchResults setting.');
        }

        $this->assertSame($expected, Editrix::$plugin->search->currentResultLimit());
    }

    public function testOnlyAStandardSiteIsOfferedTheProLimit(): void
    {
        $upgrade = Editrix::$plugin->search->upgradeResultLimit();

        if (Editrix::$plugin->edition === Editrix::EDITION_PRO) {
            $this->assertNull($upgrade);
        } else {
            $this->assertSame(25000, $upgrade);
        }
    }

    public function testASearchReportsTheLimitItRanUnder(): void
    {
        $section = $this->smallestSection(1);
        if (!$section) {
            $this->markTestSkipped('The site has no entries.');
        }

        $meta = [];
        Editrix::$plugin->search->search(
            'zzq-editrix-no-such-text-zzq',
            $this->siteId(),
            false,
            false,
            $this->sectionOptions($section[0]),
            null,
            null,
            $meta
        );

        $this->assertSame(Editrix::$plugin->search->currentResultLimit(), $meta['resultLimit']);
        $this->assertNull($meta['upgradeLimit'], 'Nothing to upsell when the search was not cut off.');
    }

    public function testTheReplaceLimitIsConservativeByDefault(): void
    {
        $settings = new Settings();

        $this->assertSame(150, $settings->maxRowsPerReplace);
        $this->assertNull($settings->maxSearchResults);
        $this->assertTrue($settings->validate(['maxRowsPerReplace', 'maxSearchResults']));

        $settings->maxRowsPerReplace = 0;
        $this->assertFalse($settings->validate(['maxRowsPerReplace']));
    }

    /**
     * Runs $fn as if the site were on $edition, then restores the real one.
     */
    private function asEdition(string $edition, callable $fn): void
    {
        $real = Editrix::$plugin->edition;
        Editrix::$plugin->edition = $edition;

        try {
            $fn();
        } finally {
            Editrix::$plugin->edition = $real;
        }
    }

    public function testAStandardSiteIsLimitedAndToldWhatProWouldGive(): void
    {
        $this->asEdition(Editrix::EDITION_STANDARD, function () {
            $search = Editrix::$plugin->search;

            $this->assertSame(3000, $search->currentResultLimit());
            $this->assertSame(25000, $search->upgradeResultLimit());
        });
    }

    public function testAProSiteHasTheLargerLimitAndNothingToUpgradeTo(): void
    {
        $this->asEdition(Editrix::EDITION_PRO, function () {
            $search = Editrix::$plugin->search;

            $this->assertSame(25000, $search->currentResultLimit());
            $this->assertNull($search->upgradeResultLimit());
        });
    }

    public function testACutOffStandardSearchCarriesTheUpgradeLimit(): void
    {
        $section = $this->smallestSection(20);
        if (!$section) {
            $this->markTestSkipped('No section has enough entries.');
        }

        $this->asEdition(Editrix::EDITION_STANDARD, function () use ($section) {
            $meta = [];
            $results = Editrix::$plugin->search->search(
                'e',
                $this->siteId(),
                false,
                false,
                $this->sectionOptions($section[0], ['maxResults' => 2]),
                null,
                null,
                $meta
            );

            if ($meta['resultCount'] < 2) {
                $this->markTestSkipped('Fewer than 2 matches for "e".');
            }

            $this->assertTrue($meta['truncated']);
            $this->assertSame(3000, $meta['resultLimit']);
            $this->assertSame(25000, $meta['upgradeLimit']);
        });
    }
}
