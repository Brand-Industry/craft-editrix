<?php

namespace brandindustry\editrix\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;
use brandindustry\editrix\Editrix;

class SettingsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireAdmin();

        return $this->renderTemplate("editrix/_settings/index", [
            "settings" => Editrix::$plugin->getSettings(),
            "edition" => Editrix::$plugin->getEdition(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $request = Craft::$app->getRequest();
        $settings = Editrix::$plugin->getSettings();

        $settings->searchEntries = (bool) $request->getBodyParam(
            "searchEntries"
        );
        $settings->searchGlobals = (bool) $request->getBodyParam(
            "searchGlobals"
        );
        $settings->searchMatrix = (bool) $request->getBodyParam("searchMatrix");
        $settings->searchCategories = (bool) $request->getBodyParam(
            "searchCategories"
        );
        $settings->logRetentionDays =
            (int) ($request->getBodyParam("logRetentionDays") ?: 30);
        $settings->bulkConfirmationThreshold =
            (int) ($request->getBodyParam("bulkConfirmationThreshold") ?: 100);
        $settings->showEnvironmentIndicator = (bool) $request->getBodyParam(
            "showEnvironmentIndicator"
        );
        $settings->productionSafeMode = (bool) $request->getBodyParam(
            "productionSafeMode"
        );

        if (!$settings->validate()) {
            Craft::$app
                ->getSession()
                ->setError(Craft::t("editrix", 'Couldn\'t save settings.'));
            return $this->renderTemplate("editrix/_settings/index", [
                "settings" => $settings,
                "edition" => Editrix::$plugin->getEdition(),
            ]);
        }

        if (
            !Craft::$app
                ->getPlugins()
                ->savePluginSettings(Editrix::$plugin, $settings->toArray())
        ) {
            Craft::$app
                ->getSession()
                ->setError(Craft::t("editrix", 'Couldn\'t save settings.'));
            return $this->renderTemplate("editrix/_settings/index", [
                "settings" => $settings,
                "edition" => Editrix::$plugin->getEdition(),
            ]);
        }

        Craft::$app
            ->getSession()
            ->setNotice(Craft::t("editrix", "Settings saved."));

        return $this->redirectToPostedUrl();
    }
}
