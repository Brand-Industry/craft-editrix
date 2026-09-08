<?php

namespace brandindustry\editrix\migrations;

use craft\db\Migration;

/**
 * Lets a log entry represent a plain search (no replacement made) alongside
 * the existing replace entries, so History can show both in one place.
 */
class m260903_000000_add_search_log_support extends Migration
{
    public const TABLE_LOGS = '{{%editrix_logs}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::TABLE_LOGS, 'type')) {
            $this->addColumn(
                self::TABLE_LOGS,
                'type',
                $this->string(20)->notNull()->defaultValue('replace')->after('userId')
            );
            $this->createIndex(null, self::TABLE_LOGS, ['type']);
        }

        if (!$this->db->columnExists(self::TABLE_LOGS, 'scope')) {
            // JSON: sections, fields, entryTypes, and the searchEntries/
            // searchGlobals/etc. toggles - lets a search log be re-run later
            // with the exact same scope it was originally run with.
            $this->addColumn(
                self::TABLE_LOGS,
                'scope',
                $this->text()->after('wholeWords')
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::TABLE_LOGS, 'scope')) {
            $this->dropColumn(self::TABLE_LOGS, 'scope');
        }

        if ($this->db->columnExists(self::TABLE_LOGS, 'type')) {
            $this->dropColumn(self::TABLE_LOGS, 'type');
        }

        return true;
    }
}
