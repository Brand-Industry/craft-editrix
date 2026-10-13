<?php

namespace brandindustry\editrix\migrations;

use craft\db\Migration;

/**
 * One row per value a replace changed, instead of one JSON blob holding
 * all of them. A large replace is written value by value as it runs (each
 * row before the entry is saved), so a request that is cut off partway
 * still leaves a log that can be reverted.
 */
class m261007_000000_add_log_items extends Migration
{
    public const TABLE_ITEMS = '{{%editrix_log_items}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE_ITEMS)) {
            return true;
        }

        $this->createTable(self::TABLE_ITEMS, Install::logItemColumns($this));
        $this->createIndex(null, self::TABLE_ITEMS, ['logId', 'position']);
        $this->createIndex(null, self::TABLE_ITEMS, ['logId', 'state']);
        $this->addForeignKey(null, self::TABLE_ITEMS, ['logId'], '{{%editrix_logs}}', ['id'], 'CASCADE', 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE_ITEMS);

        return true;
    }
}
