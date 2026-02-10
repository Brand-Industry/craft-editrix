<?php

namespace brandindustry\editrix;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use yii\base\Event;
use brandindustry\editrix\models\Settings;
use brandindustry\editrix\services\SearchService;
use brandindustry\editrix\services\ReplaceService;
use brandindustry\editrix\services\LogService;
use brandindustry\editrix\services\LicenseService;

/**
 * Editrix - The safest way to update content at scale in Craft CMS
 *
 * @property SearchService $search
 * @property ReplaceService $replace
 * @property LogService $log
 * @property LicenseService $license
 */
class Editrix extends Plugin
{
    public static Editrix $plugin;

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    // Start with Free edition by default; will be overridden by Craft Store at runtime
    public string $edition = self::EDITION_FREE;

    // Edition constants (align with Craft Plugin Store slugs)
    public const EDITION_FREE = 'free';
    public const EDITION_STANDARD = 'standard';
    public const EDITION_PRO = 'pro';

    // License tiers (Craft Plugin Store slugs) (aliases)
    // Note: the FREE edition slug is defined here; legacy LITE alias is removed

    public static function editions(): array
    {
        return [
            self::EDITION_FREE,
            self::EDITION_STANDARD,
            self::EDITION_PRO,
        ];
    }

    /**
     * Enforce required edition for a block of code
     * Will throw a 403 if current edition is lower than requested.
     */
    public static function requireEdition(string $edition): void
    {
        $plugin = self::$plugin ?? null;
        if (!$plugin) {
            // If plugin instance is not yet available, skip gate (fallback)
            return;
        }
        if (!$plugin->isEdition($edition)) {
            throw new \yii\web\ForbiddenHttpException("Requires {$edition} edition.");
        }
    }

    // Convenience: get the plugin instance
    public static function getInstance(): ?self
    {
        return self::$plugin ?? null;
    }

    // Edition gating uses the store-edition when available; fallback to license service
    // (This method is kept for compatibility with existing calls in the codebase)
    public function isEdition(string $edition): bool
    {
        if (!empty($this->edition) && in_array($this->edition, [self::EDITION_FREE, self::EDITION_STANDARD, self::EDITION_PRO], true)) {
            return $this->edition === $edition;
        }
        return $this->license->isEdition($edition);
    }

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        // Register services
        $this->setComponents([
            'search' => SearchService::class,
            'replace' => ReplaceService::class,
            'log' => LogService::class,
            'license' => LicenseService::class,
        ]);

        // Register CP routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                // Main routes
                $event->rules['editrix'] = 'editrix/search/index';
                $event->rules['editrix/search'] = 'editrix/search/index';
                $event->rules['editrix/logs'] = 'editrix/log/index';
                $event->rules['editrix/logs/<siteHandle:{handle}>'] = 'editrix/log/site';
                // History view
                $event->rules['editrix/history/index'] = 'editrix/history/index';
                
                // API routes
                $event->rules['editrix/api/search'] = 'editrix/search/search';
                $event->rules['editrix/api/replace'] = 'editrix/replace/execute';
                $event->rules['editrix/api/preview'] = 'editrix/replace/preview';
                $event->rules['editrix/api/logs'] = 'editrix/log/list';
                $event->rules['editrix/api/logs/revert/<logId:\d+>'] = 'editrix/log/revert';
                $event->rules['editrix/api/logs/delete/<logId:\d+>'] = 'editrix/log/delete';
                $event->rules['editrix/api/logs/export'] = 'editrix/log/export';
                
                // Scope data routes
                $event->rules['editrix/api/scope/sections'] = 'editrix/scope/sections';
                $event->rules['editrix/api/scope/sites'] = 'editrix/scope/sites';
                $event->rules['editrix/api/scope/fields'] = 'editrix/scope/fields';
                $event->rules['editrix/api/scope/entry-types'] = 'editrix/scope/entry-types';
            }
        );

        // Register permissions (only for Craft Pro)
        if (Craft::$app->getEdition() === Craft::Pro) {
            Event::on(
                UserPermissions::class,
                UserPermissions::EVENT_REGISTER_PERMISSIONS,
                function (RegisterUserPermissionsEvent $event) {
                    $event->permissions[] = [
                        'heading' => Craft::t('editrix', 'Editrix'),
                        'permissions' => $this->getPluginPermissions(),
                    ];
                }
            );
        }

        Craft::info(
            Craft::t('editrix', '{name} plugin loaded ({edition} edition)', [
                'name' => $this->name,
                'edition' => $this->license->getEdition(),
            ]),
            __METHOD__
        );
    }

    /**
     * Get plugin permissions
     */
    private function getPluginPermissions(): array
    {
        return [
            'editrix:search' => [
                'label' => Craft::t('editrix', 'Search content'),
            ],
            'editrix:replace' => [
                'label' => Craft::t('editrix', 'Search and replace content'),
                'nested' => [
                    'editrix:search' => [
                        'label' => Craft::t('editrix', 'Search content'),
                    ],
                ],
            ],
            'editrix:revert' => [
                'label' => Craft::t('editrix', 'Revert changes from logs'),
            ],
            'editrix:deleteLogs' => [
                'label' => Craft::t('editrix', 'Delete logs'),
            ],
            'editrix:export' => [
                'label' => Craft::t('editrix', 'Export logs'),
            ],
        ];
    }

    /**
     * Check if user has permission
     */
    public function userCan(string $permission): bool
    {
        // Craft Solo: admin has all permissions
        if (Craft::$app->getEdition() !== Craft::Pro) {
            return Craft::$app->getUser()->getIsAdmin();
        }

        // Craft Pro: check specific permission
        return Craft::$app->getUser()->checkPermission($permission);
    }

    /**
     * Check if a feature is available in current edition
     */
    public function hasFeature(string $feature): bool
    {
        return $this->license->hasFeature($feature);
    }

    /**
     * Get current edition
     */
    public function getEdition(): string
    {
        // Use edition property if available; otherwise fallback to license service
        if (!empty($this->edition)) {
            return $this->edition;
        }
        return $this->license->getEdition();
    }

    /**
     * Create settings model
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Settings HTML
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'editrix/_settings/index',
            [
                'settings' => $this->getSettings(),
                'edition' => $this->getEdition(),
            ]
        );
    }

    /**
     * CP nav item
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'Editrix';
        // Views: New Operation, Logs, History, Settings
        $item['subnav'] = [
            'new' => [
                'label' => 'New Operation',
                'url' => 'editrix/search',
            ],
            'logs' => [
                'label' => 'Logs',
                'url' => 'editrix/log/index',
            ],
            'history' => [
                'label' => 'History',
                'url' => 'editrix/history/index',
            ],
            'settings' => [
                'label' => Craft::t('editrix', 'Settings'),
                'url' => 'editrix/settings',
            ],
        ];
        
        return $item;
    }
}
