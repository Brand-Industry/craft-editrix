<?php

namespace brandindustry\editrix\tests;

use PHPUnit\Framework\TestCase;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\models\SearchResult;
use brandindustry\editrix\services\ReplaceService;
use brandindustry\editrix\services\SearchService;

/**
 * One row per field, however many times it matches.
 */
class SearchGroupingTest extends TestCase
{
    private function match(array $overrides = []): SearchResult
    {
        $site = \Craft::$app->getSites()->getPrimarySite();
        $result = new SearchResult();
        $result->elementType = 'entry';
        $result->elementId = 10;
        $result->elementTitle = 'An article';
        $result->sectionHandle = 'articles';
        $result->sectionName = 'Articles';
        $result->fieldHandle = 'body';
        $result->fieldName = 'Body';
        $result->siteId = $site->id;
        $result->siteHandle = $site->handle;
        $result->matchContext = 'a [[MATCH]]hotel[[/MATCH]] b';
        $result->valueHash = ReplaceService::hashValue(str_repeat('x', 100));
        $result->matchStart = 0;
        $result->matchEnd = 5;
        $result->isRichText = true;

        foreach ($overrides as $property => $value) {
            $result->$property = $value;
        }

        return $result;
    }

    private function rows(array $results): array
    {
        $out = [];
        foreach (Editrix::$plugin->search->groupResultsBySite($results) as $site) {
            array_push($out, ...$site['results']);
        }

        return $out;
    }

    public function testSeveralMatchesInOneFieldAreOneRow(): void
    {
        $rows = $this->rows([
            $this->match(['matchStart' => 0, 'matchEnd' => 5]),
            $this->match(['matchStart' => 20, 'matchEnd' => 25]),
            $this->match(['matchStart' => 40, 'matchEnd' => 45]),
            $this->match(['matchStart' => 60, 'matchEnd' => 65]),
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(4, $rows[0]['matchCount']);
        $this->assertCount(4, $rows[0]['matches']);
        $this->assertSame([0, 20, 40, 60], array_column($rows[0]['matches'], 'start'));
    }

    public function testARowCarriesAFingerprintNotTheFieldText(): void
    {
        $rows = $this->rows([
            $this->match(['matchStart' => 0]),
            $this->match(['matchStart' => 20]),
        ]);

        $this->assertArrayNotHasKey('fieldValue', $rows[0]);
        $this->assertArrayNotHasKey('fieldValue', $rows[0]['matches'][0]);
        $this->assertSame(ReplaceService::hashValue(str_repeat('x', 100)), $rows[0]['valueHash']);
    }

    public function testTheRowStaysSmallWhateverTheFieldLength(): void
    {
        $short = $this->rows([$this->match(['valueHash' => ReplaceService::hashValue('short')])])[0];
        $long = $this->rows([$this->match(['valueHash' => ReplaceService::hashValue(str_repeat('long ', 50000))])])[0];

        $this->assertSame(strlen(json_encode($short)), strlen(json_encode($long)));
    }

    public function testATagRowKeepsItsShortValue(): void
    {
        $row = $this->rows([$this->match([
            'fieldHandle' => 'tags',
            'fieldValue' => 'Hotels',
            'readOnly' => true,
            'readOnlyReason' => 'tag',
        ])])[0];

        $this->assertSame('Hotels', $row['fieldValue']);
        $this->assertArrayNotHasKey('valueHash', $row);
    }

    public function testDifferentFieldsAreDifferentRows(): void
    {
        $rows = $this->rows([
            $this->match(['fieldHandle' => 'body']),
            $this->match(['fieldHandle' => 'summary']),
            $this->match(['elementId' => 11]),
        ]);

        $this->assertCount(3, $rows);
    }

    public function testTheRowKeyIsTheOneReplaceAndTheReplacedMarksUse(): void
    {
        $match = $this->match();
        $row = $this->rows([$match])[0];

        $this->assertSame(ReplaceService::keyFor($row), $row['uniqueKey']);
    }

    public function testTagMatchesStaySeparate(): void
    {
        $tag = fn(int $start) => $this->match([
            'fieldHandle' => 'tags',
            'readOnly' => true,
            'readOnlyReason' => 'tag',
            'matchStart' => $start,
        ]);

        $this->assertCount(2, $this->rows([$tag(0), $tag(1)]));
    }

    public function testARowIsReadOnlyOnlyWhenEveryMatchIsAndStaysReplaceableOtherwise(): void
    {
        $formatting = fn(int $start) => $this->match([
            'readOnly' => true,
            'readOnlyReason' => 'formatting',
            'matchStart' => $start,
        ]);

        $allFormatting = $this->rows([$formatting(0), $formatting(20)])[0];
        $this->assertTrue($allFormatting['readOnly']);
        $this->assertSame('formatting', $allFormatting['readOnlyReason']);

        $mixed = $this->rows([$formatting(0), $this->match(['matchStart' => 20])])[0];
        $this->assertFalse($mixed['readOnly']);
        $this->assertNull($mixed['readOnlyReason']);

        $first = $this->rows([$this->match(['matchStart' => 20]), $formatting(0)])[0];
        $this->assertFalse($first['readOnly'], 'Order must not matter.');
    }

    public function testContextsAreCappedButTheCountIsTrue(): void
    {
        $many = [];
        for ($i = 0; $i < SearchService::MAX_CONTEXTS_PER_ROW + 30; $i++) {
            $many[] = $this->match(['matchStart' => $i]);
        }

        $row = $this->rows($many)[0];

        $this->assertSame(SearchService::MAX_CONTEXTS_PER_ROW + 30, $row['matchCount']);
        $this->assertCount(SearchService::MAX_CONTEXTS_PER_ROW, $row['matches']);
    }

    public function testRowsKeepTheOrderOfTheirFirstMatch(): void
    {
        $rows = $this->rows([
            $this->match(['elementId' => 1]),
            $this->match(['elementId' => 2]),
            $this->match(['elementId' => 1, 'matchStart' => 30]),
        ]);

        $this->assertSame([1, 2], array_column($rows, 'elementId'));
        $this->assertSame(2, $rows[0]['matchCount']);
    }
}
