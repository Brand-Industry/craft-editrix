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

        foreach (['searchId', 'userId', 'status', 'scannedCount', 'totalCount', 'resultCount', 'paramsJson', 'resultsJson', 'replacedJson', 'errorMessage'] as $column) {
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

    /**
     * A fresh install runs Install and then marks every other migration as
     * applied, so a table that only a migration creates never exists on a
     * new site. Records what Install would create without touching the
     * database.
     */
    public function testAFreshInstallCreatesTheSearchSessionsTable(): void
    {
        $install = new class extends \brandindustry\editrix\migrations\Install {
            public array $created = [];

            public function createTable($table, $columns, $options = null): void
            {
                $this->created[$table] = array_keys($columns);
            }

            public function createTables(): void
            {
                parent::createTables();
            }
        };

        $install->createTables();

        $this->assertArrayHasKey('{{%editrix_searches}}', $install->created);
        foreach (['searchId', 'userId', 'status', 'resultCount', 'paramsJson', 'resultsJson', 'replacedJson'] as $column) {
            $this->assertContains($column, $install->created['{{%editrix_searches}}']);
        }
    }
}
