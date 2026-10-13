<?php

namespace brandindustry\editrix\migrations;

use craft\db\Migration;

/**
 * Remembers which matches of a saved search were already replaced, so
 * reopening it later shows them as done instead of as live matches.
 */
class m261006_000000_add_search_replaced extends Migration
{
    public const TABLE_SEARCHES = '{{%editrix_searches}}';

    public function safeUp(): bool
    {
        $table = $this->db->getTableSchema(self::TABLE_SEARCHES, true);

        if ($table !== null && !isset($table->columns['replacedJson'])) {
            $this->addColumn(self::TABLE_SEARCHES, 'replacedJson', $this->longText()->after('resultsJson'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
