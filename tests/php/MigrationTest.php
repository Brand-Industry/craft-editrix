<?php

namespace brandindustry\editrix\tests;

use Craft;
use PHPUnit\Framework\TestCase;

class MigrationTest extends TestCase
{
    public function testTheSearchSessionsTableHasWhatSearchJobReadsAndWrites(): void
    {
        $schema = Craft::$app->getDb()->getSchema()->getTableSchema('{{%editrix_searches}}', true);

        $this->assertNotNull($schema, 'editrix_searches is missing; run the plugin migrations.');

        foreach (['searchId', 'userId', 'status', 'scannedCount', 'totalCount', 'resultsJson', 'errorMessage'] as $column) {
            $this->assertArrayHasKey($column, $schema->columns, "Missing column {$column}");
        }

        $this->assertSame(36, $schema->columns['searchId']->size);
    }

    public function testSearchIdIsUnique(): void
    {
        $uniques = Craft::$app->getDb()->getSchema()->findUniqueIndexes(
            Craft::$app->getDb()->getSchema()->getTableSchema('{{%editrix_searches}}', true)
        );

        $this->assertContains(['searchId'], array_values($uniques));
    }
}
