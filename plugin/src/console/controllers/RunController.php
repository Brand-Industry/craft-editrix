<?php

namespace brandindustry\editrix\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\helpers\Console;
use yii\console\ExitCode;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\services\LicenseService;
use brandindustry\editrix\services\ReplaceService;

/**
 * `craft editrix/run/search`, `replace` and `revert`.
 *
 * For a replacement that belongs in a deployment, and for scheduled work
 * in cron. Everything defaults to a dry run: --dry-run=0 is the only way
 * to change anything, and it has to be typed. A command that writes by
 * default is one shell-history keystroke away from a bad day.
 *
 *   craft editrix/run/search --find="old name" --sections=articles
 *   craft editrix/run/replace --find="old name" --replace="new name" --sections=articles
 *   craft editrix/run/replace --find="old name" --replace="new name" --dry-run=0
 *   craft editrix/run/revert 12
 */
class RunController extends Controller
{
    public $defaultAction = "search";

    /** @var string What to find. */
    public string $find = "";

    /** @var string What to replace it with. Empty deletes the text. */
    public string $replace = "";

    /** @var string Comma-separated section handles. */
    public string $sections = "";

    /** @var string Comma-separated entry type handles. */
    public string $entryTypes = "";

    /** @var string Comma-separated field handles; `title` counts. */
    public string $fields = "";

    /** @var string Comma-separated site ids, or `all`. Defaults to the primary site. */
    public string $sites = "";

    /** @var string Comma-separated entry ids, to search only those. */
    public string $elements = "";

    /** @var bool Treat --find as a regular expression. */
    public bool $regex = false;

    /** @var bool Ignore case when matching. */
    public bool $caseInsensitive = false;

    /** @var bool Match whole words only. */
    public bool $wholeWords = false;

    /** @var bool Also search the addresses of links and images in rich text. */
    public bool $links = false;

    /** @var bool Give the replacement the capitalisation of what it replaces. */
    public bool $preserveCase = false;

    /** @var bool Only report what would change. The default. */
    public bool $dryRun = true;

    /** @var string The user a replace is logged under: id, username or email. Defaults to the first admin. */
    public string $user = "";

    /** @var int How many matches to list. */
    public int $limit = 20;

    /** @var bool On revert, also overwrite values edited since. */
    public bool $force = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $scope = ["find", "sections", "entryTypes", "fields", "sites", "elements", "regex", "caseInsensitive", "wholeWords", "links", "limit"];

        return match ($actionID) {
            "search" => array_merge($options, $scope),
            "replace" => array_merge($options, $scope, ["replace", "preserveCase", "dryRun", "user"]),
            "revert" => array_merge($options, ["force", "user"]),
            default => $options,
        };
    }

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Editrix::$plugin->license->hasFeature(LicenseService::FEATURE_CLI)) {
            $this->stderr("The command line needs Editrix Pro.\n", Console::FG_RED);
            return false;
        }

        return true;
    }

    // -----------------------------------------------------------------------------------------

    public function actionSearch(): int
    {
        $plan = $this->plan(Editrix::$plugin->search->currentResultLimit());

        if (isset($plan["error"])) {
            return $this->fail($plan["error"]);
        }

        $this->printMatches($plan);
        $this->stdout(sprintf(
            "%d row(s), %d match(es)%s.\n",
            $plan["rowCount"],
            $plan["matchCount"],
            $plan["truncated"] ? ", stopped at the limit" : ""
        ));

        return ExitCode::OK;
    }

    public function actionReplace(): int
    {
        $limit = Editrix::$plugin->replace->currentRowLimit();
        $plan = $this->plan($limit + 1);

        if (isset($plan["error"])) {
            return $this->fail($plan["error"]);
        }

        $objections = ReplaceService::objections($this->find, $this->replace);
        if ($objections !== []) {
            return $this->fail(implode(" ", $objections));
        }

        if ($plan["rowCount"] > $limit) {
            return $this->fail(sprintf(
                "More than %d rows match, which is the most one replace may change. Narrow the scope.",
                $limit
            ));
        }

        $this->printMatches($plan);

        if ($this->dryRun) {
            $this->stdout(sprintf(
                "Dry run: %d row(s), %d match(es) would change. Nothing was written. Add --dry-run=0 to apply.\n",
                $plan["replaceable"],
                $plan["matchCount"]
            ));

            return ExitCode::OK;
        }

        $summary = $this->applyPlan($plan, function (int $done, int $total) {
            if ($done % 25 === 0 && $done > 0) {
                $this->stdout("  {$done} of {$total}\n");
            }
        });

        if (isset($summary["error"])) {
            return $this->fail($summary["error"]);
        }

        $this->stdout(sprintf(
            "Replaced %d row(s) (%d occurrence(s)); %d skipped, %d failed. Log #%s; revert with: craft editrix/run/revert %s\n",
            $summary["replacedCount"],
            $summary["replacedMatches"],
            count($summary["skipped"]),
            count($summary["failed"]),
            $summary["logId"] ?? "-",
            $summary["logId"] ?? "-"
        ));

        foreach (array_merge($summary["skipped"], $summary["failed"]) as $item) {
            $this->stdout(sprintf("  left unchanged: #%d %s: %s\n", $item["elementId"], $item["fieldHandle"], $item["reason"]), Console::FG_YELLOW);
        }

        return $summary["failed"] === [] ? ExitCode::OK : 2;
    }

    public function actionRevert(int $id): int
    {
        $result = $this->revertLog($id, $this->force);

        if (isset($result["error"])) {
            return $this->fail($result["error"]);
        }

        $this->stdout(sprintf(
            "Reverted log #%d: %d restored, %d already original, %d left because they were edited since, %d missing, %d failed.\n",
            $id,
            $result["reverted"],
            $result["unchanged"],
            count($result["conflicts"]),
            count($result["missing"]),
            count($result["failed"])
        ));

        if ($result["conflicts"] !== []) {
            $this->stdout("Add --force to overwrite those edits.\n", Console::FG_YELLOW);
        }

        return $result["conflicts"] === [] && $result["failed"] === [] ? ExitCode::OK : 2;
    }

    // -----------------------------------------------------------------------------------------
    // The work, separate from printing so it can be tested
    // -----------------------------------------------------------------------------------------

    /** @return string[] */
    public static function parseList(string $list): array
    {
        return array_values(array_filter(array_map("trim", explode(",", $list)), fn($s) => $s !== ""));
    }

    /**
     * Runs the search this command was asked for.
     *
     * @return array{rows?: array, rowCount?: int, replaceable?: int, matchCount?: int, truncated?: bool, siteId?: int, error?: string}
     */
    public function plan(int $maxRows): array
    {
        if (trim($this->find) === "") {
            return ["error" => Craft::t("editrix", "There is nothing to find.")];
        }

        $siteIds = $this->sites === "all"
            ? array_map(fn($s) => $s->id, Craft::$app->getSites()->getAllSites())
            : array_map("intval", self::parseList($this->sites));
        $siteId = $siteIds[0] ?? Craft::$app->getSites()->getPrimarySite()->id;

        $meta = [];
        $results = Editrix::$plugin->search->search(
            $this->find,
            $siteId,
            $this->regex,
            !($this->caseInsensitive || $this->preserveCase),
            [
                "searchEntries" => true,
                "searchGlobals" => $this->elements === "",
                "sections" => self::parseList($this->sections),
                "entryTypes" => self::parseList($this->entryTypes),
                "fields" => self::parseList($this->fields),
                "siteIds" => count($siteIds) > 1 ? $siteIds : [],
                "elementIds" => array_map("intval", self::parseList($this->elements)),
                "wholeWords" => $this->wholeWords,
                "includeLinks" => $this->links,
                "maxResults" => $maxRows,
                // The user the command acts as is held to their Craft
                // permissions, so the default admin sees everything.
                "userId" => $this->resolveUser(),
            ],
            null,
            null,
            $meta
        );

        $rows = [];
        foreach (Editrix::$plugin->search->groupResultsBySite($results) as $site) {
            array_push($rows, ...$site["results"]);
        }

        // Rows as a replace needs them: how many of their matches it will leave alone.
        $replaceable = array_values(array_map(
            fn($r) => $r + [
                "readOnlyMatchCount" => count(array_filter($r["matches"] ?? [], fn($m) => !empty($m["readOnly"]))),
            ],
            array_filter($rows, fn($r) => empty($r["readOnly"]))
        ));

        return [
            "rows" => $rows,
            "replaceable" => count($replaceable),
            "replaceRows" => $replaceable,
            "rowCount" => count($rows),
            "matchCount" => array_sum(array_map(fn($r) => (int) ($r["matchCount"] ?? 1), $rows)),
            "truncated" => (bool) ($meta["truncated"] ?? false),
            "siteId" => $siteId,
        ];
    }

    /**
     * Applies a plan from plan(): opens the log, replaces every
     * replaceable row, closes the log. No queue, since a console command
     * has no request to outlast.
     */
    public function applyPlan(array $plan, ?callable $progress = null): array
    {
        $userId = $this->resolveUser();
        if ($userId === null) {
            return ["error" => "There is no user to log this replace under. Pass --user=<id>."];
        }

        $logId = Editrix::$plugin->log->beginReplaceLog(
            $plan["siteId"],
            $this->find,
            $this->replace,
            $this->regex,
            !($this->caseInsensitive || $this->preserveCase),
            $this->wholeWords,
            $userId
        );

        return Editrix::$plugin->replace->run(
            $logId,
            $plan["replaceRows"],
            $this->find,
            $this->replace,
            $this->regex,
            !($this->caseInsensitive || $this->preserveCase),
            $this->wholeWords,
            $userId,
            "",
            $progress,
            null,
            false,
            $this->preserveCase
        );
    }

    /** @return array reverted, unchanged, conflicts, missing, failed; or error */
    public function revertLog(int $id, bool $force = false): array
    {
        $log = Editrix::$plugin->log->getLogById($id);

        if (!$log || $log["type"] !== "replace") {
            return ["error" => "There is no replace with log id {$id}."];
        }

        if (in_array($log["status"], ["running", "reverting"], true)) {
            return ["error" => "That replace is still running."];
        }

        $userId = $this->resolveUser();
        $result = Editrix::$plugin->replace->revertEntries(
            Editrix::$plugin->log->replacementsFor($log),
            $force,
            userId: $userId,
            logId: $id
        );

        Editrix::$plugin->replace->finishRevert($id, $result, $userId);

        return $result;
    }

    private function resolveUser(): ?int
    {
        $query = (new Query())->select(["id"])->from("{{%users}}");

        if ($this->user !== "") {
            $query->where(["or", ["id" => ctype_digit($this->user) ? (int) $this->user : 0], ["username" => $this->user], ["email" => $this->user]]);
        } else {
            $query->orderBy(["admin" => SORT_DESC, "id" => SORT_ASC]);
        }

        $id = $query->scalar();

        return $id ? (int) $id : null;
    }

    private function printMatches(array $plan): void
    {
        foreach (array_slice($plan["rows"], 0, max(0, $this->limit)) as $row) {
            $context = preg_replace("/\s+/", " ", $row["matches"][0]["context"] ?? $row["matchContext"] ?? "");
            $context = str_replace(["[[MATCH]]", "[[/MATCH]]"], ["«", "»"], $context);

            $this->stdout(sprintf(
                "#%d %s %s%s: %s\n",
                $row["elementId"],
                $row["sectionName"] ?? "",
                $row["fieldHandle"],
                ($row["matchCount"] ?? 1) > 1 ? " (" . $row["matchCount"] . " matches)" : "",
                $context
            ));
        }

        if (count($plan["rows"]) > $this->limit) {
            $this->stdout(sprintf("  … and %d more\n", count($plan["rows"]) - $this->limit));
        }
    }

    private function fail(string $message): int
    {
        $this->stderr($message . "\n", Console::FG_RED);

        return ExitCode::UNSPECIFIED_ERROR;
    }
}
