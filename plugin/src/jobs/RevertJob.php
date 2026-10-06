<?php

namespace brandindustry\editrix\jobs;

use Craft;
use craft\queue\BaseJob;
use brandindustry\editrix\Editrix;

/**
 * Reverts a replace in the background, for the same reason a large
 * replace runs there. Values are restored one by one and their state is
 * kept in the log, so a revert that is interrupted can simply be run
 * again: what is already back counts as done.
 */
class RevertJob extends BaseJob
{
    public int $logId = 0;
    public bool $force = false;
    public ?int $userId = null;

    public function execute($queue): void
    {
        $service = Editrix::$plugin->replace;
        $log = Editrix::$plugin->log->getLogById($this->logId);

        if (!$log) {
            return;
        }

        $result = $service->revertEntries(
            Editrix::$plugin->log->replacementsFor($log),
            $this->force
        );

        $service->finishRevert($this->logId, $result, $this->userId);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t("editrix", "Reverting replacement #{id}", ["id" => $this->logId]);
    }
}
