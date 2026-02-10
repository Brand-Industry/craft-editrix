<?php

namespace brandindustry\editrix\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public const TABLE_LOGS = '{{%editrix_logs}}';
    public const TABLE_PRESETS = '{{%editrix_presets}}';

    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();
        return true;
    }

    public function safeDown(): bool
    {
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
            'searchQuery' => $this->text()->notNull(),
            'replaceWith' => $this->text()->notNull(),
            'useRegex' => $this->boolean()->defaultValue(false),
            'caseSensitive' => $this->boolean()->defaultValue(true),
            'wholeWords' => $this->boolean()->defaultValue(false),
            'replacementCount' => $this->integer()->defaultValue(0),
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
    }

    protected function createIndexes(): void
    {
        // Logs indexes
        $this->createIndex(null, self::TABLE_LOGS, ['siteId']);
        $this->createIndex(null, self::TABLE_LOGS, ['userId']);
        $this->createIndex(null, self::TABLE_LOGS, ['status']);
        $this->createIndex(null, self::TABLE_LOGS, ['dateCreated']);

        // Presets indexes
        $this->createIndex(null, self::TABLE_PRESETS, ['userId']);
        $this->createIndex(null, self::TABLE_PRESETS, ['isShared']);
        $this->createIndex(null, self::TABLE_PRESETS, ['sortOrder']);
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
    }
}
