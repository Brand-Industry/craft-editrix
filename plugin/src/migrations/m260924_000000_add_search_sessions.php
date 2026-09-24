<?php

namespace brandindustry\editrix\migrations;

use craft\db\Migration;

/**
 * Backs the queued/background search flow (SearchJob): a row per in-progress
 * or finished search, polled by the frontend for progress and read for the
 * final results once done, instead of one synchronous request scanning
 * every entry.
 */
class m260924_000000_add_search_sessions extends Migration
{
    public const TABLE_SEARCHES = '{{%editrix_searches}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE_SEARCHES)) {
            $this->createTable(self::TABLE_SEARCHES, [
                'id' => $this->primaryKey(),
                'searchId' => $this->string(36)->notNull(),
                'userId' => $this->integer()->notNull(),
                'status' => $this->string(20)->notNull()->defaultValue('running'), // running, completed, cancelled, error
                'scannedCount' => $this->integer()->defaultValue(0),
                'totalCount' => $this->integer()->defaultValue(0),
                'resultsJson' => $this->longText(),
                'errorMessage' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, self::TABLE_SEARCHES, ['searchId'], true);
            $this->createIndex(null, self::TABLE_SEARCHES, ['userId']);
            $this->createIndex(null, self::TABLE_SEARCHES, ['status']);
            $this->createIndex(null, self::TABLE_SEARCHES, ['dateCreated']);

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

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE_SEARCHES);
        return true;
    }
}
