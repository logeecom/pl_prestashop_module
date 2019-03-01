<?php
/**
 * 2019 Packlink
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    Packlink <support@packlink.com>
 * @copyright 2019 Packlink Shipping S.L
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

namespace Packlink\PrestaShop\Classes\Utility;

use Logeecom\Infrastructure\Logger\Logger;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\Exceptions\TaskRunnerStatusStorageUnavailableException;
use Packlink\BusinessLogic\ShippingMethod\Interfaces\ShopShippingMethodService;
use Packlink\BusinessLogic\ShippingMethod\Utility\ShipmentStatus;
use Packlink\PrestaShop\Classes\Bootstrap;
use Packlink\PrestaShop\Classes\BusinessLogicServices\CarrierService;
use Packlink\PrestaShop\Classes\BusinessLogicServices\ConfigurationService;
use Packlink\PrestaShop\Classes\Repositories\BaseRepository;
use Packlink\PrestaShop\Classes\Repositories\OrderRepository;

/**
 * Class PacklinkInstaller.
 *
 * @package Packlink\PrestaShop\Classes\Utility
 */
class PacklinkInstaller
{
    /**
     * Packlink module instance.
     *
     * @var \Packlink
     */
    private $module;
    /**
     * Packlink module directory.
     *
     * @var string
     */
    private $moduleDir;
    private static $hooks = array(
        'updateCarrier',
        'displayAfterCarrier',
        'actionValidateStepComplete',
        'displayBackOfficeOrderActions',
        'displayAdminOrderTabShip',
        'displayAdminOrderContentShip',
        'displayBeforeCarrier',
        'displayOrderConfirmation',
        'actionValidateOrder',
        'actionOrderStatusUpdate',
    );
    private static $controllers = array(
        'Debug',
        'Dashboard',
        'DefaultParcel',
        'DefaultWarehouse',
        'ShippingMethods',
        'OrderStateMapping',
        'ShipmentLabels',
        'BulkShipmentLabels',
        'OrderDraft',
    );

    /**
     * PacklinkInstaller constructor.
     *
     * @param \Packlink $module
     */
    public function __construct(\Packlink $module)
    {
        $this->module = $module;
        $this->moduleDir = _PS_MODULE_DIR_ . $this->module->name;
    }

    /**
     * Initializes plugin.
     *
     * @return bool
     */
    public function initializePlugin()
    {
        Bootstrap::init();
        if (!$this->createBaseTable() || !$this->extendOrdersTable() || !$this->addTab()) {
            return false;
        }

        $baseSource = $this->moduleDir . '/vendor/packlink/integration-core/src/BusinessLogic/Resources';
        $baseDestination = $this->moduleDir . '/views';
        try {
            Logger::logDebug(TranslationUtility::__('Started copying resources from Core'), 'Integration');
            $this->copyResources($baseSource . '/img/carriers', $baseDestination . '/img/carriers');
            $this->copyResources($baseSource . '/js', $baseDestination . '/js/core');
            $this->copyResources($baseSource . '/LocationPicker/js', $baseDestination . '/js/location');
            $this->copyResources($baseSource . '/LocationPicker/css', $baseDestination . '/css');
            Logger::logDebug(TranslationUtility::__('Resources copied to module resources directory'), 'Integration');
        } catch (\RuntimeException $e) {
            Logger::logError(
                TranslationUtility::__('Error copying resources. Error: %s', array($e->getMessage())),
                'Integration'
            );

            return false;
        }

        return $this->addShopConfiguration();
    }

    /**
     * Performs actions when module is being uninstalled.
     *
     * @return bool Result of method execution.
     */
    public function uninstall()
    {
        Bootstrap::init();

        if (!$this->dropBaseTable() || !$this->removeTab()) {
            return false;
        }

        $this->removeOrdersColumn();

        /** @var CarrierService $carrierService */
        $carrierService = ServiceRegister::getService(ShopShippingMethodService::CLASS_NAME);
        $carrierService->deletePacklinkCarriers();

        $this->removeControllers();

        // Make sure that deleted configuration is reflected into cached values as well.
        \Configuration::loadConfiguration();

        return true;
    }

    /**
     * Adds Packlink menu item to shipping tab group.
     *
     * @return bool Returns TRUE if tab has been successfully added, otherwise returns FALSE.
     */
    public function addTab()  {
        $tab = new \Tab();

        $languages = \Language::getLanguages(true, \Context::getContext()->shop->id);
        foreach ($languages as $language) {
            $tab->name[$language['id_lang']] = 'Packlink PRO';
        }

        $tab->class_name = 'Packlink';
        $tab->id_parent = (int) \Tab::getIdFromClassName('AdminParentShipping');
        $tab->module = 'packlink';

        return $tab->add();
    }

    /**
     * Removes Packlink menu item from shipping tab group.
     *
     * @return bool Returns TRUE if tab has been successfully removed, otherwise returns FALSE.
     */
    public function removeTab()
    {
        try {
            $tabId = (int)\Tab::getIdFromClassName('Packlink');

            if ($tabId) {
                $tab = new \Tab($tabId);
                return $tab->delete();
            }
        } catch (\PrestaShopException $e) {
            Logger::logWarning('Error removing admin tab. Error: ' . $e->getMessage(), 'Integration');
        }

        return false;
    }

    /**
     * Adds controllers and hooks.
     *
     * @return bool Result of method execution.
     */
    public function addControllersAndHooks()
    {
        return $this->addHooks() && $this->addControllers();
    }

    /**
     * Unregisters module hooks.
     *
     * @return bool
     */
    public function removeHooks()
    {
        $result = true;
        foreach (self::$hooks as $hook) {
            $result = $result && $this->module->unregisterHook($hook);
        }

        return $result;
    }

    /**
     * Unregisters module controllers.
     *
     * @return bool
     */
    public function removeControllers()
    {
        $result = true;
        foreach (self::$controllers as $controller) {
            $result = $result && $this->removeController($controller);
        }

        return $result;
    }

    /**
     * Adds configuration for current shop.
     *
     * @return bool
     */
    public function addShopConfiguration()
    {
        $this->addDefaultStatusMapping();

        return $this->addDefaultPluginConfiguration();
    }

    /**
     * Creates Packlink entity table.
     *
     * @return bool Result of create table query.
     */
    private function createBaseTable()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . _DB_NAME_ . '.' . _DB_PREFIX_ . BaseRepository::TABLE_NAME . '
            (
             `id` INT NOT NULL AUTO_INCREMENT,
             `type` VARCHAR(128) NOT NULL,
             `index_1` VARCHAR(255),
             `index_2` VARCHAR(255),
             `index_3` VARCHAR(255),
             `index_4` VARCHAR(255),
             `index_5` VARCHAR(255),
             `index_6` VARCHAR(255),
             `index_7` VARCHAR(255),
             `data` LONGTEXT NOT NULL,
              PRIMARY KEY(`id`)
            )
            ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8';

        try {
            return \Db::getInstance()->execute($sql);
        } catch (\PrestaShopException $e) {
            Logger::logError('Error creating base database table. Error: ' . $e->getMessage(), 'Integration');
        }

        return false;
    }

    /**
     * Drops base database table.
     *
     * @return bool
     */
    private function dropBaseTable()
    {
        $script = 'DROP TABLE IF EXISTS ' . _DB_NAME_ . '.' . _DB_PREFIX_ . BaseRepository::TABLE_NAME;

        try {
            return (bool)\Db::getInstance()->execute($script);
        } catch (\PrestaShopException $e) {
            Logger::logError('Error dropping base database table. Error: ' . $e->getMessage(), 'Integration');
        }

        return false;
    }

    /**
     * Adds packlink column to orders table.
     *
     * @return bool
     */
    private function extendOrdersTable()
    {
        $columnName = pSQL(OrderRepository::PACKLINK_ORDER_DRAFT_FIELD);
        $checkColumnSqlStatement = 'SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = \'' . _DB_NAME_
            . '\' AND TABLE_NAME = \'' . pSQL(_DB_PREFIX_ . 'orders')
            . '\' AND COLUMN_NAME = \'' . $columnName . '\'';
        try {
            $result = \Db::getInstance()->executeS($checkColumnSqlStatement);
        } catch (\PrestaShopDatabaseException $e) {
            Logger::logError('Error getting schema information. Error: ' . $e->getMessage(), 'Integration');

            return false;
        }

        if (is_array($result) && count($result) === 0) {
            $alterTableSqlStatement = 'ALTER TABLE ' . pSQL(_DB_NAME_ . '.' . _DB_PREFIX_ . 'orders')
                . ' ADD ' . $columnName . ' VARCHAR(100) DEFAULT NULL';
            try {
                return (bool)\Db::getInstance()->execute($alterTableSqlStatement);
            } catch (\PrestaShopDatabaseException $e) {
                Logger::logError('Error extending orders table. Error: ' . $e->getMessage(), 'Integration');

                return false;
            }
        }

        return true;
    }

    /**
     * Removes Orders table extended column.
     *
     * @return bool
     */
    private function removeOrdersColumn()
    {
        try {
            $sql = 'ALTER TABLE ' . _DB_NAME_ . '.' . _DB_PREFIX_ . 'orders '
                . 'DROP COLUMN ' . OrderRepository::PACKLINK_ORDER_DRAFT_FIELD;
            \Db::getInstance()->execute($sql);
        } catch (\PrestaShopDatabaseException $e) {
            Logger::logError('Error removing orders table column. Error: ' . $e->getMessage(), 'Integration');

            return false;
        }

        return true;
    }

    /**
     * Copies Packlink resources to module resource directory.
     *
     * @param string $source Source path.
     * @param string $destination Destination path.
     *
     * @throws \RuntimeException
     */
    private function copyResources($source, $destination)
    {
        if (is_dir($source)) {
            $dir_handle = opendir($source);

            while ($file = readdir($dir_handle)) {
                if ($file !== '.' && $file !== '..') {
                    if (is_dir($source . '/' . $file)
                        && !is_dir($destination . '/' . $file)
                        && !mkdir($concurrentDirectory = $destination . '/' . $file)
                        && !is_dir($concurrentDirectory)
                    ) {
                        throw new \RuntimeException(
                            sprintf($this->module->l('Directory "%s" was not created'), $concurrentDirectory)
                        );
                    }

                    $this->copyResources($source . '/' . $file, $destination . '/' . $file);
                }
            }

            closedir($dir_handle);
        } else {
            copy($source, $destination);
        }
    }

    /**
     * Initialize default configuration values that plugin needs.
     *
     * @return bool
     */
    private function addDefaultPluginConfiguration()
    {
        try {
            /** @var ConfigurationService $configService */
            $configService = ServiceRegister::getService(\Packlink\BusinessLogic\Configuration::CLASS_NAME);
            $configService->setTaskRunnerStatus('', null);
        } catch (TaskRunnerStatusStorageUnavailableException $e) {
            Logger::logError(
                $this->module->l('Error creating default task runner status configuration.'),
                'Integration'
            );

            return false;
        }

        return true;
    }

    /**
     * Registers module hooks.
     *
     * @return bool
     */
    private function addHooks()
    {
        $result = true;
        foreach (self::$hooks as $hook) {
            $result = $result && $this->module->registerHook($hook);
        }

        return $result;
    }

    /**
     * Registers module controllers.
     *
     * @return bool
     */
    private function addControllers()
    {
        $result = true;
        foreach (self::$controllers as $controller) {
            $result = $result && $this->addController($controller);
        }

        return $result;
    }

    /**
     * Registers controller.
     *
     * @param string $name Controller name.
     * @param int $parentId Id of parent controller.
     *
     * @return bool
     */
    private function addController($name, $parentId = -1)
    {
        try {
            $tab = new \Tab();
            $tab->active = 1;
            $tab->name[(int)\Configuration::get('PS_LANG_DEFAULT')] = $this->module->l('Packlink');
            $tab->class_name = $name;
            $tab->module = $this->module->name;
            $tab->id_parent = $parentId;
            $tab->add();

            return true;
        } catch (\PrestaShopException $e) {
            Logger::logWarning(
                'Failed to register controller "' . $name . '". Error: ' . $e->getMessage(),
                'Integration'
            );
        }

        return false;
    }

    /**
     * Removes controller.
     *
     * @param string $name Name of the controller.
     *
     * @return bool
     */
    private function removeController($name)
    {
        try {
            /** @noinspection PhpDeprecationInspection Because it exists in PS1.6 */
            $tab = new \Tab((int)\Tab::getIdFromClassName($name));
            if ($tab) {
                $tab->delete();
            }
        } catch (\PrestaShopException $e) {
            Logger::logWarning('Error removing controller "' . $name . '". Error: ' . $e->getMessage(), 'Integration');

            return false;
        }

        return true;
    }

    private function addDefaultStatusMapping()
    {
        /** @var ConfigurationService $configService */
        $configService = ServiceRegister::getService(\Packlink\BusinessLogic\Configuration::CLASS_NAME);
        $mappings = $configService->getOrderStatusMappings();

        if (empty($mappings)) {
            $configService->setOrderStatusMappings(
                array(
                    ShipmentStatus::STATUS_PENDING => 0,
                    ShipmentStatus::STATUS_ACCEPTED => 3,
                    ShipmentStatus::STATUS_READY => 3,
                    ShipmentStatus::STATUS_IN_TRANSIT => 4,
                    ShipmentStatus::STATUS_DELIVERED => 5,
                )
            );
        }
    }
}
