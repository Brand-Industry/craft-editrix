<?php

namespace brandindustry\editrix\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public const TABLE_LOGS = '{{%editrix_logs}}';
    public const TABLE_PRESETS = '{{%editrix_presets}}';
    public const TABLE_SEARCHES = '{{%editrix_searches}}';
    public const TABLE_LOG_ITEMS = '{{%editrix_log_items}}';

    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE_LOG_ITEMS);
        $this->dropTableIfExists(self::TABLE_SEARCHES);
        $this->dropTableIfExists(self::TABLE_PRESETS);
        $this->dropTableIfExists(self::TABLE_LOGS);
        return true;
    }

    protected function createTables(): void
    {
        // Logs table
        $this->createTable(self::TABLE_LOGS, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'userId' => $this->integer()->notNull(),
            'type' => $this->string(20)->notNull()->defaultValue('replace'), // search, replace
            'searchQuery' => $this->text()->notNull(),
            'replaceWith' => $this->text()->notNull(),
            'useRegex' => $this->boolean()->defaultValue(false),
            'caseSensitive' => $this->boolean()->defaultValue(true),
            'wholeWords' => $this->boolean()->defaultValue(false),
            'scope' => $this->text(), // JSON: sections, fields, entryTypes, search toggles
            'replacementCount' => $this->integer()->defaultValue(0), // result count for search logs
            'replacements' => $this->longText(),
            'status' => $this->string(20)->defaultValue('success'), // success, reverted, partial
            'revertedAt' => $this->dateTime(),
            'revertedBy' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Presets table (Standard+)
        $this->createTable(self::TABLE_PRESETS, [
            'id' => $this->primaryKey(),
            'userId' => $this->integer()->notNull(),
            'name' => $this->string(255)->notNull(),
            'description' => $this->text(),
            'searchQuery' => $this->text()->notNull(),
            'replaceWith' => $this->text()->notNull(),
            'useRegex' => $this->boolean()->defaultValue(false),
            'caseSensitive' => $this->boolean()->defaultValue(true),
            'wholeWords' => $this->boolean()->defaultValue(false),
            'scope' => $this->text(), // JSON: sections, sites, fields, etc.
            'isShared' => $this->boolean()->defaultValue(false), // Pro only
            'sortOrder' => $this->integer()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // One row per value a replace changed
        $this->createTable(self::TABLE_LOG_ITEMS, self::logItemColumns($this));

        // Background searches (SearchJob): progress while running, results
        // once done, kept for a day so a user can leave and come back.
        $this->createTable(self::TABLE_SEARCHES, [
            'id' => $this->primaryKey(),
            'searchId' => $this->string(36)->notNull(),
            'userId' => $this->integer()->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('running'), // queued, running, completed, cancelled, error
            'scannedCount' => $this->integer()->defaultValue(0),
            'totalCount' => $this->integer()->defaultValue(0),
            'resultCount' => $this->integer(),
            'paramsJson' => $this->text(), // what was searched, to restore the form
            'resultsJson' => $this->longText(),
            'replacedJson' => $this->longText(), // keys of matches already replaced
            'errorMessage' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    /**
     * Columns of the log items table, shared with the migration that adds
     * it to existing installs so the two cannot drift apart.
     */
    public static function logItemColumns(Migration $m): array
    {
        return [
            'id' => $m->primaryKey(),
            'logId' => $m->integer()->notNull(),
            'position' => $m->integer()->notNull()->defaultValue(0),
            'elementType' => $m->string(20)->notNull(),
            'elementId' => $m->integer()->notNull(),
            'siteId' => $m->integer()->notNull(),
            'fieldHandle' => $m->string(255)->notNull(),
            'fieldName' => $m->string(255),
            'elementTitle' => $m->text(),
            'sectionHandle' => $m->string(255),
            'parentId' => $m->integer(),
            'oldValue' => $m->longText(),
            'newValue' => $m->longText(),
            // occurrences changed inside this value, for the summary
            'matches' => $m->integer(),
            // pending (written before the entry is saved), applied,
            // reverted, skipped, failed
            'state' => $m->string(20)->notNull()->defaultValue('applied'),
            'message' => $m->text(),
            'dateCreated' => $m->dateTime()->notNull(),
            'dateUpdated' => $m->dateTime()->notNull(),
            'uid' => $m->uid(),
        ];
    }

    protected function createIndexes(): void
    {
        // Logs indexes
        $this->createIndex(null, self::TABLE_LOGS, ['siteId']);
        $this->createIndex(null, self::TABLE_LOGS, ['userId']);
        $this->createIndex(null, self::TABLE_LOGS, ['type']);
        $this->createIndex(null, self::TABLE_LOGS, ['status']);
        $this->createIndex(null, self::TABLE_LOGS, ['dateCreated']);

        // Presets indexes
        $this->createIndex(null, self::TABLE_PRESETS, ['userId']);
        $this->createIndex(null, self::TABLE_PRESETS, ['isShared']);
        $this->createIndex(null, self::TABLE_PRESETS, ['sortOrder']);

        // Log items indexes
        $this->createIndex(null, self::TABLE_LOG_ITEMS, ['logId', 'position']);
        $this->createIndex(null, self::TABLE_LOG_ITEMS, ['logId', 'state']);

        // Searches indexes
        $this->createIndex(null, self::TABLE_SEARCHES, ['searchId'], true);
        $this->createIndex(null, self::TABLE_SEARCHES, ['userId']);
        $this->createIndex(null, self::TABLE_SEARCHES, ['status']);
        $this->createIndex(null, self::TABLE_SEARCHES, ['dateCreated']);
    }

    protected function addForeignKeys(): void
    {
        // Logs foreign keys
        $this->addForeignKey(
            null,
            self::TABLE_LOGS,
            ['siteId'],
            '{{%sites}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            self::TABLE_LOGS,
            ['userId'],
            '{{%users}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            self::TABLE_LOGS,
            ['revertedBy'],
            '{{%users}}',
            ['id'],
            'SET NULL',
            'CASCADE'
        );

        // Presets foreign keys
        $this->addForeignKey(
            null,
            self::TABLE_PRESETS,
            ['userId'],
            '{{%users}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // Log items foreign keys
        $this->addForeignKey(
            null,
            self::TABLE_LOG_ITEMS,
            ['logId'],
            self::TABLE_LOGS,
            ['id'],
            'CASCADE',
            'CASCADE'
        );

        // Searches foreign keys
        $this->addForeignKey(
            null,
            self::TABLE_SEARCHES,
            ['userId'],
            '{{%users}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );
    }
}
