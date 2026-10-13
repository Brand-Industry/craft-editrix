<?php

namespace brandindustry\editrix\jobs;

use Craft;
use craft\db\Query;
use craft\queue\BaseJob;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\migrations\Install;

/**
 * Runs a replace in the background. Saving an entry takes about half a
 * second, so a replace of hundreds of rows would outlast a web request and
 * be cut off partway. Every value goes into the log before its entry is
 * saved (see ReplaceService::run), so this can be stopped, or killed, at
 * any point and what it did can still be reverted.
 *
 * The rows are carried as arrays: a queued job may be run by a worker on a
 * different version of the plugin than the one that queued it.
 */
class ReplaceJob extends BaseJob
{
    public int $logId = 0;
    public array $rows = [];
    public string $query = "";
    public string $replaceWith = "";
    public bool $useRegex = false;
    public bool $caseSensitive = true;
    public bool $wholeWords = false;
    public bool $preserveCase = false;
    public ?int $userId = null;
    public string $searchId = "";

    public function execute($queue): void
    {
        $service = Editrix::$plugin->replace;
        $total = max(1, count($this->rows));

        $service->run(
            $this->logId,
            $this->rows,
            $this->query,
            $this->replaceWith,
            $this->useRegex,
            $this->caseSensitive,
            $this->wholeWords,
            $this->userId,
            $this->searchId,
            function (int $done, int $of) use ($queue, $total) {
                $this->setProgress($queue, min(1, $done / $total));
            },
            fn() => $this->isCancelling(),
            true,
            $this->preserveCase
        );
    }

    private function isCancelling(): bool
    {
        return (new Query())
            ->select(["status"])
            ->from(Install::TABLE_LOGS)
            ->where(["id" => $this->logId])
            ->scalar() === "cancelling";
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t("editrix", "Replacing \"{query}\" with \"{replaceWith}\"", [
            "query" => $this->query,
            "replaceWith" => $this->replaceWith,
        ]);
    }
}
