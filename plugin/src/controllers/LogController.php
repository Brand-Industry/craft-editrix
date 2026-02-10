<?php

namespace brandindustry\editrix\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use yii\web\Response;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\assetbundles\EditrixAsset;
use brandindustry\editrix\services\LicenseService;

class LogController extends Controller
{
    /**
     * Logs index page
     */
    public function actionIndex(): Response
    {
        try {
            Editrix::requireEdition(Editrix::EDITION_PRO);
        } catch (\Throwable $e) {
            throw new \yii\web\ForbiddenHttpException($e->getMessage());
        }
        if (!Editrix::$plugin->userCan('editrix:search')) {
            throw new \yii\web\ForbiddenHttpException('You do not have permission to view logs.');
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(EditrixAsset::class);

        $config = $this->buildJsConfig();
        $view->registerJs(
            'window.EditrixConfig = ' . json_encode($config) . ';',
            View::POS_HEAD
        );

        return $this->renderTemplate('editrix/_logs/index');
    }

    /**
     * Get logs list (API)
     */
    public function actionList(): Response
    {
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan('editrix:search')) {
            return $this->asJson([
                'success' => false,
                'error' => 'Permission denied',
            ]);
        }

        $request = Craft::$app->getRequest();

        $filters = [
            'siteId' => $request->getParam('siteId'),
            'userId' => $request->getParam('userId'),
            'status' => $request->getParam('status'),
            'limit' => (int)($request->getParam('limit', 20)),
            'offset' => (int)($request->getParam('offset', 0)),
        ];

        $logs = Editrix::$plugin->log->getLogs($filters);
        $total = Editrix::$plugin->log->getLogsCount($filters);

        // Format logs for display
        $formattedLogs = array_map(function ($log) {
            return [
                'id' => $log['id'],
                'date' => $log['dateCreated'],
                'relativeDate' => $this->getRelativeDate($log['dateCreated']),
                'userId' => $log['userId'],
                'username' => $log['username'] ?? 'Unknown',
                'userFullName' => trim(($log['firstName'] ?? '') . ' ' . ($log['lastName'] ?? '')) ?: null,
                'siteId' => $log['siteId'],
                'siteName' => $log['siteName'] ?? 'Unknown',
                'searchQuery' => $log['searchQuery'],
                'replaceWith' => $log['replaceWith'],
                'count' => (int)$log['replacementCount'],
                'status' => $log['status'],
                'revertedAt' => $log['revertedAt'],
            ];
        }, $logs);

        return $this->asJson([
            'success' => true,
            'logs' => $formattedLogs,
            'total' => $total,
            'limit' => $filters['limit'],
            'offset' => $filters['offset'],
        ]);
    }

    /**
     * Revert a log entry
     */
    public function actionRevert(int $logId): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan('editrix:revert')) {
            return $this->asJson([
                'success' => false,
                'error' => 'Permission denied',
            ]);
        }

        $log = Editrix::$plugin->log->getLogById($logId);

        if (!$log) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('editrix', 'Log entry not found'),
            ]);
        }

        if ($log['status'] === 'reverted') {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('editrix', 'This operation has already been reverted'),
            ]);
        }

        $replacements = json_decode($log['replacements'], true) ?? [];
        $revertedCount = 0;

        foreach ($replacements as $replacement) {
            if (Editrix::$plugin->replace->revert($replacement)) {
                $revertedCount++;
            }
        }

        if ($revertedCount > 0) {
            Editrix::$plugin->log->markAsReverted($logId);
        }

        return $this->asJson([
            'success' => true,
            'revertedCount' => $revertedCount,
            'message' => Craft::t('editrix', 'Successfully reverted {count} change(s).', [
                'count' => $revertedCount,
            ]),
        ]);
    }

    /**
     * Delete a log entry
     */
    public function actionDelete(int $logId): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan('editrix:deleteLogs')) {
            return $this->asJson([
                'success' => false,
                'error' => 'Permission denied',
            ]);
        }

        $deleted = Editrix::$plugin->log->deleteLog($logId);

        return $this->asJson([
            'success' => $deleted,
            'message' => $deleted
                ? Craft::t('editrix', 'Log entry deleted')
                : Craft::t('editrix', 'Failed to delete log entry'),
        ]);
    }

    /**
     * Export logs
     */
    public function actionExport(): Response
    {
        if (!Editrix::$plugin->userCan('editrix:export')) {
            throw new \yii\web\ForbiddenHttpException('Permission denied');
        }

        $license = Editrix::$plugin->license;
        $request = Craft::$app->getRequest();

        $format = $request->getParam('format', 'csv');
        $filters = [
            'siteId' => $request->getParam('siteId'),
            'status' => $request->getParam('status'),
        ];

        if ($format === 'json' && !$license->hasFeature(LicenseService::FEATURE_EXPORT_JSON)) {
            return $this->asJson([
                'success' => false,
                'error' => 'JSON export requires Pro edition',
            ]);
        }

        if ($format === 'json') {
            $content = Editrix::$plugin->log->exportToJson($filters);
            $filename = 'editrix-logs-' . date('Y-m-d') . '.json';
            $mimeType = 'application/json';
        } else {
            $content = Editrix::$plugin->log->exportToCsv($filters);
            $filename = 'editrix-logs-' . date('Y-m-d') . '.csv';
            $mimeType = 'text/csv';
        }

        $response = Craft::$app->getResponse();
        $response->content = $content;
        $response->setDownloadHeaders($filename, $mimeType);

        return $response;
    }

    /**
     * Get relative date string
     */
    private function getRelativeDate(string $dateString): string
    {
        $date = new \DateTime($dateString);
        $now = new \DateTime();
        $diff = $now->diff($date);

        if ($diff->days === 0) {
            if ($diff->h === 0) {
                return $diff->i <= 1 ? 'Just now' : "{$diff->i} minutes ago";
            }
            return $diff->h === 1 ? '1 hour ago' : "{$diff->h} hours ago";
        }

        if ($diff->days === 1) {
            return 'Yesterday';
        }

        if ($diff->days < 7) {
            return "{$diff->days} days ago";
        }

        return $date->format('M j, Y');
    }

    /**
     * Build JS config
     */
    private function buildJsConfig(): array
    {
        $sites = Craft::$app->getSites()->getAllSites();
        $users = $this->getActiveUsers();
        $license = Editrix::$plugin->license;

        return [
            'sites' => array_map(fn($site) => [
                'id' => $site->id,
                'name' => $site->name,
                'handle' => $site->handle,
            ], $sites),
            'users' => $users,
            'currentSiteId' => Craft::$app->getSites()->getCurrentSite()->id,
            'apiUrl' => \craft\helpers\UrlHelper::actionUrl('editrix/log/list'),
            'revertUrl' => \craft\helpers\UrlHelper::actionUrl('editrix/log/revert'),
            'deleteUrl' => \craft\helpers\UrlHelper::actionUrl('editrix/log/delete'),
            'exportUrl' => \craft\helpers\UrlHelper::actionUrl('editrix/log/export'),
            'canRevert' => Editrix::$plugin->userCan('editrix:revert'),
            'canDelete' => Editrix::$plugin->userCan('editrix:deleteLogs'),
            'canExport' => Editrix::$plugin->userCan('editrix:export'),
            'license' => $license->getFeaturesConfig(),
            'view' => 'logs',
        ];
    }

    /**
     * Get users who have made replacements
     */
    private function getActiveUsers(): array
    {
        $users = (new \craft\db\Query())
            ->select(['u.id', 'u.username', 'u.firstName', 'u.lastName'])
            ->from(['u' => '{{%users}}'])
            ->innerJoin(['l' => '{{%editrix_logs}}'], '[[u.id]] = [[l.userId]]')
            ->groupBy(['u.id', 'u.username', 'u.firstName', 'u.lastName'])
            ->all();

        return array_map(fn($u) => [
            'id' => $u['id'],
            'username' => $u['username'],
            'name' => trim($u['firstName'] . ' ' . $u['lastName']) ?: $u['username'],
        ], $users);
    }
}
