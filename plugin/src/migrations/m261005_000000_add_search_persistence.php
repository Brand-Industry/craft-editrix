<?php

namespace brandindustry\editrix\migrations;

use craft\db\Migration;

/**
 * Lets a finished or running search be found again after the user leaves
 * the page: what was searched (to restore the form) and how many results
 * it has (to list it without loading the results themselves).
 */
class m261005_000000_add_search_persistence extends Migration
{
    public const TABLE_SEARCHES = '{{%editrix_searches}}';

    public function safeUp(): bool
    {
        $table = $this->db->getTableSchema(self::TABLE_SEARCHES, true);

        if ($table === null) {
            return true;
        }

        if (!isset($table->columns['resultCount'])) {
            $this->addColumn(self::TABLE_SEARCHES, 'resultCount', $this->integer()->after('totalCount'));
        }

        if (!isset($table->columns['paramsJson'])) {
            $this->addColumn(self::TABLE_SEARCHES, 'paramsJson', $this->text()->after('resultCount'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
