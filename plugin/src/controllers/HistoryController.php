<?php

namespace brandindustry\editrix\controllers;

use Craft;
use craft\web\Controller;
use craft\web\Response;
use brandindustry\editrix\Editrix;

class HistoryController extends Controller
{
    protected int|bool $allowAnonymous = false;

    public function actionIndex(): Response
    {
        if (!Editrix::$plugin->userCan('editrix:search')) {
            throw new \yii\web\ForbiddenHttpException('You do not have permission to view history.');
        }

        // Retrieve logs using the existing LogService (no advanced filters for now)
        $logs = Editrix::$plugin->log->getLogs([]);

        return $this->renderTemplate('editrix/_logs/history', [
            'logs' => $logs,
        ]);
    }
}
