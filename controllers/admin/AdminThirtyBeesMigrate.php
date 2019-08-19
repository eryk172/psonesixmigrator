<?php
/**
 * Copyright (C) 2017-2019 thirty bees
 * Copyright (C) 2007-2016 PrestaShop SA
 *
 * thirty bees is an extension to the PrestaShop software by PrestaShop SA.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <modules@thirtybees.com>
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2017-2019 thirty bees
 * @copyright 2007-2016 PrestaShop SA
 * @license   Academic Free License (AFL 3.0)
 * PrestaShop is an internationally registered trademark of PrestaShop SA.
 */

use PsOneSixMigrator\Upgrader;
use PsOneSixMigrator\ConfigurationTest;
use PsOneSixMigrator\UpgraderTools;

require_once __DIR__.'/../../classes/autoload.php';

/**
 * Class AdminThirtyBeesMigrate
 *
 * @since 1.0.0
 */
class AdminThirtyBeesMigrateController extends AdminController
{
    // @codingStandardsIgnoreStart
    /** @var array $upgradeOptions */
    public $upgradeOptions = [];
    /** @var array $backupOptions */
    public $backupOptions = [];
    /** @var UpgraderTools $tool */
    protected $tools;
    /** @var Upgrader $upgrader */
    protected $upgrader;
    /** @var string|null $backupName Chosen backup name */
    protected $backupName = null;
    // @codingStandardsIgnoreEnd

    /**
     * AdminThirtyBeesMigrateController constructor.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        global $ajax;

        if (!empty($ajax)) {
            $this->ajax = true;
        }

        $this->bootstrap = true;

        parent::__construct();

        // Database instantiation (need to be cached because there will be at least 100k calls in the upgrade process
        $this->db = Db::getInstance();
        $this->tools = UpgraderTools::getInstance();
        $this->upgrader = Upgrader::getInstance();

        $fileTab = @filemtime($this->tools->autoupgradePath.DIRECTORY_SEPARATOR.'ajax-upgradetab.php');
        $file = @filemtime(_PS_ROOT_DIR_.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.$this->tools->autoupgradeDir.DIRECTORY_SEPARATOR.'ajax-upgradetab.php');

        if ($fileTab < $file) {
            @copy(
                _PS_ROOT_DIR_.DIRECTORY_SEPARATOR.'modules'.DIRECTORY_SEPARATOR.$this->autoupgradeDir.DIRECTORY_SEPARATOR.'ajax-upgradetab.php',
                $this->tools->autoupgradePath.DIRECTORY_SEPARATOR.'ajax-upgradetab.php'
            );
        }

        if (version_compare(_PS_VERSION_, '1.6.1.0', '>=') && !$this->ajax) {
            Context::getContext()->smarty->assign('display_header_javascript', true);
        }
    }

    /**
     * @return void
     *
     * @since 1.0.0
     */
    public function initContent()
    {
        $configurationKeys = [
            UpgraderTools::KEEP_MAILS              => true,
            UpgraderTools::DISABLE_CUSTOM_MODULES  => true,
            UpgraderTools::DISABLE_OVERRIDES       => true,
            UpgraderTools::SWITCH_TO_DEFAULT_THEME => version_compare(_PS_VERSION_, '1.6.1.0', '<') || version_compare(_PS_VERSION_, '1.6.2.0', '>='),
            UpgraderTools::PERFORMANCE             => 1,
            UpgraderTools::MANUAL_MODE             => false,
            UpgraderTools::DISPLAY_ERRORS          => false,
            UpgraderTools::BACKUP                  => true,
            UpgraderTools::BACKUP_IMAGES           => false,
        ];

        $config = UpgraderTools::getConfig();
        foreach ($configurationKeys as $k => $defaultValue) {
            if (!isset($config[$k])) {
                UpgraderTools::setConfig($k, $defaultValue);
            }
        }

        parent::initContent();

        /* PrestaShop demo mode */
        if (defined('_PS_MODE_DEMO_') && _PS_MODE_DEMO_) {
            $html = '<div class="error">'.$this->l('This functionality has been disabled.').'</div>';
            $this->context->smarty->assign('updaterContent', $html);
            $this->context->smarty->assign('content', $html);

            return;
        }

        if (!file_exists($this->tools->autoupgradePath.DIRECTORY_SEPARATOR.'ajax-upgradetab.php')) {
            $html = '<div class="alert alert-danger">'.$this->l('[TECHNICAL ERROR] ajax-upgradetab.php is missing. Please reinstall or reset the module.').'</div>';
            $this->context->smarty->assign('updaterContent', $html);
            $this->context->smarty->assign('content', $html);

            return;
        }

        $html = '<div class="row">';

        if (defined('_TB_VERSION_')) {
            $this->context->controller->informations[] = $this->l('This shop is running thirty bees already, you can safely remove this module.');
        } else {
            $html .= $this->displayAdminTemplate(__DIR__.'/../../views/templates/admin/welcome.phtml');

            $html .= $this->displayCurrentConfiguration();
            $html .= $this->displayBlockUpgradeButton();

            $html .= $this->displayAdminTemplate(__DIR__.'/../../views/templates/admin/anotherchecklist.phtml');
        }

        $html .= $this->getJsInit();
        $html .= '</div>';

        $this->context->smarty->assign('updaterContent', $html);

        $this->context->smarty->assign('content', $html);
    }

    /**
     * @return void
     *
     * @since 1.0.0
     */
    public function postProcess()
    {
        $this->setFields();

        // set default configuration to default channel & default configuration for backup and upgrade
        // (can be modified in expert mode)
        $config = UpgraderTools::getConfig('channel');
        if ($config === false) {
            $config = [];
            $config['channel'] = Upgrader::DEFAULT_CHANNEL;
            UpgraderTools::writeConfig($config);
            if (class_exists('Configuration', false)) {
                Configuration::updateValue('PS_UPGRADE_CHANNEL', $config['channel']);
            }

            UpgraderTools::writeConfig(
                [
                    UpgraderTools::PERFORMANCE             => 1,
                    UpgraderTools::DISABLE_CUSTOM_MODULES  => true,
                    UpgraderTools::DISABLE_OVERRIDES       => true,
                    UpgraderTools::SWITCH_TO_DEFAULT_THEME => version_compare(_PS_VERSION_, '1.6.1.0', '<') || version_compare(_PS_VERSION_, '1.6.2.0', '>='),
                    UpgraderTools::KEEP_MAILS              => true,
                    UpgraderTools::BACKUP                  => true,
                    UpgraderTools::BACKUP_IMAGES           => false,
                ]
            );
        }

        if (Tools::isSubmit('putUnderMaintenance') && version_compare(_PS_VERSION_, '1.5.0.0', '>=')) {
            foreach (Shop::getCompleteListOfShopsID() as $idShop) {
                Configuration::updateValue('PS_SHOP_ENABLE', 0, false, null, (int) $idShop);
            }
            Configuration::updateGlobalValue('PS_SHOP_ENABLE', 0);
        } elseif (Tools::isSubmit('putUnderMaintenance')) {
            Configuration::updateValue('PS_SHOP_ENABLE', 0);
        }

        if (Tools::isSubmit('channel')) {
            $channel = Tools::getValue('channel');
            if (in_array($channel, ['stable', 'rc', 'beta', 'alpha'])) {
                UpgraderTools::writeConfig(['channel' => Tools::getValue('channel')]);
            }
        }

        if (Tools::isSubmit('customSubmitAutoUpgrade')) {
            $configKeys = array_keys(array_merge($this->upgradeOptions, $this->backupOptions));
            $config = [];
            foreach ($configKeys as $key) {
                if (isset($_POST[$key])) {
                    $config[$key] = $_POST[$key];
                }
            }
            $res = UpgraderTools::writeConfig($config);
            if ($res) {
                Tools::redirectAdmin(self::$currentIndex.'&conf=6&token='.Tools::getValue('token'));
            }
        }

        if (Tools::isSubmit('deletebackup')) {
            $res = false;
            $name = Tools::getValue('name');
            $tools = UpgraderTools::getInstance();
            $fileList = scandir($tools->backupPath);
            foreach ($fileList as $filename) {
                // the following will match file or dir related to the selected backup
                if (!empty($filename) && $filename[0] != '.' && $filename != 'index.php' && $filename != '.htaccess'
                    && preg_match('#^(auto-backupfiles_|)'.preg_quote($name).'(\.zip|)$#', $filename, $matches)
                ) {
                    if (is_file($tools->backupPath.DIRECTORY_SEPARATOR.$filename)) {
                        $res &= unlink($tools->backupPath.DIRECTORY_SEPARATOR.$filename);
                    } elseif (!empty($name) && is_dir($tools->backupPath.DIRECTORY_SEPARATOR.$name.DIRECTORY_SEPARATOR)) {
                        $res = Tools::deleteDirectory($tools->backupPath.DIRECTORY_SEPARATOR.$name.DIRECTORY_SEPARATOR, true);
                    }
                }
            }
            if ($res) {
                Tools::redirectAdmin(static::$currentIndex.'&conf=1&token='.Tools::getValue('token'));
            } else {
                $this->errors[] = sprintf($this->l('Error when trying to delete backup %s'), $name);
            }
        }

        parent::postProcess();
    }

    /**
     * @return array
     *
     * @since 1.0.0
     */
    public function getCheckCurrentPsConfig()
    {
        static $allowedArray;

        if (empty($allowedArray)) {
            $allowedArray = [];
            $allowedArray['check'] = ConfigurationTest::check(ConfigurationTest::getDefaultTests());
            // This is expected to not work before the migration.
            unset($allowedArray['check']['Files']);

            $allowedArray['root_writable'] = $this->getRootWritable();
            $tools = UpgraderTools::getInstance();
            $adminDir = trim(str_replace(_PS_ROOT_DIR_, '', _PS_ADMIN_DIR_), DIRECTORY_SEPARATOR);
            $allowedArray['admin_au_writable'] = ConfigurationTest::testDir($adminDir.DIRECTORY_SEPARATOR.$tools->autoupgradeDir, false, $report);
            $allowedArray['shop_deactivated'] = ! Configuration::get('PS_SHOP_ENABLE');
            $allowedArray['cache_deactivated'] = !(defined('_PS_CACHE_ENABLED_') && _PS_CACHE_ENABLED_);
            $allowedArray['module_version_ok'] = true;
        }

        return $allowedArray;
    }

    /**
     * @return bool|null
     *
     * @since 1.0.0
     */
    public function getRootWritable()
    {
        // Root directory permissions cannot be checked recursively anymore, it takes too much time
        $tools = UpgraderTools::getInstance();
        $tools->root_writable = ConfigurationTest::testDir('/', false, $report);
        $tools->root_writable_report = $report;

        return $tools->root_writable;
    }

    /**
     * @return bool|null|string
     *
     * @since 1.0.0
     */
    public function getModuleVersion()
    {
        return false;
    }

    /**
     * @return bool
     *
     * @since 1.0.0
     */
    public function configOk()
    {
        $allowed = true;
        $allowedArray = $this->getCheckCurrentPsConfig();

        foreach ($allowedArray as $item) {
            $allowed = $allowed && (bool) $item;
        }

        $allowed = $allowed && $this->testsuiteOk();

        return $allowed;
    }

    /**
     * @return bool
     *
     * @since 2.0.0
     */
    public function testsuiteOk()
    {
        $ok = true;
        $testsuiteResult = $this->getCheckCurrentPsConfig();

        foreach ($testsuiteResult['check'] as $check) {
            if ($check !== 'ok') {
                $ok = false;
                break;
            }
        }

        return $ok;
    }

    /**
     * _displayBlockUpgradeButton
     * display the summary current version / target version + "Upgrade Now" button with a "more options" button
     *
     * @return string
     *
     * @since 1.0.0
     */
    private function displayBlockUpgradeButton()
    {
        return $this->displayAdminTemplate(__DIR__.'/../../views/templates/admin/blockupgradebutton.phtml');
    }

    /**
     * @param string $channel
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function getBlockSelectChannel($channel = 'master')
    {
        $download = $this->tools->downloadPath.DIRECTORY_SEPARATOR;
        $params = [
            'optChannels'     => ['stable', 'rc', 'beta', 'alpha'],
            'selectedChannel' => is_string($channel) ? $channel : 'master',
            'download'        => $download,
            'channelDir'      => glob($download.'*.zip'),
            'archiveFilename' => UpgraderTools::getConfig('archive.filename'),
        ];

        return $this->displayAdminTemplate(__DIR__.'/../../views/templates/admin/channelselector.phtml', $params);
    }

    /**
     * @return string
     *
     * @since 1.0.0
     */
    public function displayDevTools()
    {
        return $this->displayAdminTemplate(__DIR__.'/../../views/templates/admin/devtools.phtml');
    }

    /**
     * @return string
     *
     * @since 1.0.0
     */
    public function displayBlockActivityLog()
    {
        return $this->displayAdminTemplate(__DIR__.'/../../views/templates/admin/activitylog.phtml');
    }

    /**
     * Display a phtml template file
     *
     * @param string $file
     * @param array  $params
     *
     * @return string Content
     *
     * @since 1.0.0
     */
    public function displayAdminTemplate($file, $params = [])
    {
        foreach ($params as $name => $param) {
            $$name = $param;
        }

        ob_start();

        include($file);

        $content = ob_get_contents();
        if (ob_get_level() && ob_get_length() > 0) {
            ob_end_clean();
        }

        return $content;
    }

    public function setMedia()
    {
        parent::setMedia();

        $this->addJS(_PS_MODULE_DIR_.'psonesixmigrator/views/js/upgrader.js');
        $this->addCSS(_PS_MODULE_DIR_.'psonesixmigrator/views/css/admin.css', 'all');
    }

    /**
     * @param mixed  $string
     * @param string $class
     * @param bool   $addslashes
     * @param bool   $htmlentities
     *
     * @return mixed|string
     *
     * @since 1.0.0
     */
    protected function l($string, $class = 'AdminThirtyBeesMigrateController', $addslashes = false, $htmlentities = true)
    {
        // need to be called in order to populate $classInModule
        $str = UpgraderTools::findTranslation('psonesixmigrator', $string, 'AdminThirtyBeesMigrateController');
        $str = $htmlentities ? str_replace('"', '&quot;', htmlentities($str, ENT_QUOTES, 'utf-8')) : $str;
        $str = $addslashes ? addslashes($str) : stripslashes($str);

        return $str;
    }

    /** this returns fieldset containing the configuration points you need to use autoupgrade
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function displayCurrentConfiguration()
    {
        return $this->displayAdminTemplate(__DIR__.'/../../views/templates/admin/checklist.phtml');
    }

    /**
     * @param $name
     * @param $fields
     * @param $tabname
     * @param $size
     * @param $icon
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function displayConfigForm($name, $fields, $tabname, $size, $icon)
    {
        $params = [
            'name'    => $name,
            'fields'  => $fields,
            'tabname' => $tabname,
            'size'    => $size,
            'icon'    => $icon,
        ];

        return $this->displayAdminTemplate(__DIR__.'/../../views/templates/admin/displayform.phtml', $params);
    }

    /**
     * @return array
     *
     * @since 1.0.0
     */
    protected function getBackupDbAvailable()
    {
        $array = [];
        $files = scandir($this->tools->backupPath);
        foreach ($files as $file) {
            if ($file[0] === 'v' && is_dir($this->tools->backupPath.DIRECTORY_SEPARATOR.$file)) {
                $array[] = $file;
            }
        }
        return $array;

    }

    /**
     * @return array
     *
     * @since 1.0.0
     */
    protected function getBackupFilesAvailable()
    {
        $tools = UpgraderTools::getInstance();
        $array = [];
        $files = scandir($tools->backupPath);
        foreach ($files as $file) {
            if ($file[0] != '.') {
                if (substr($file, 0, 16) == 'auto-backupfiles') {
                    $array[] = preg_replace('#^auto-backupfiles_(.*-[0-9a-f]{1,8})\..*$#', '$1', $file);
                }
            }
        }

        return $array;
    }

    /**
     * function to set configuration fields display
     *
     * @return void
     */
    private function setFields()
    {
        $this->backupOptions[UpgraderTools::BACKUP] = [
            'title'        => $this->l('Back up my files and database'),
            'cast'         => 'intval',
            'validation'   => 'isBool',
            'defaultValue' => '1',
            'type'         => 'bool',
            'desc'         => $this->l('Automatically back up your database and files in order to restore your shop if needed. This is experimental: you should still perform your own manual backup for safety.'),
        ];
        $this->backupOptions[UpgraderTools::BACKUP_IMAGES] = [
            'title'        => $this->l('Back up my images'),
            'cast'         => 'intval',
            'validation'   => 'isBool',
            'defaultValue' => '1',
            'type'         => 'bool',
            'desc'         => $this->l('To save time, you can decide not to back your images up. In any case, always make sure you did back them up manually.'),
        ];

        $this->upgradeOptions[UpgraderTools::DISABLE_CUSTOM_MODULES] = [
            'title'      => $this->l('Disable non-native modules'),
            'cast'       => 'intval',
            'validation' => 'isBool',
            'type'       => 'bool',
            'desc'       => $this->l('As non-native modules can experience some compatibility issues, we recommend to disable them by default.').'<br />'.$this->l('Keeping them enabled might prevent you from loading the "Modules" page properly after the migration.'),
        ];

        $this->upgradeOptions[UpgraderTools::DISABLE_OVERRIDES] = [
            'title'      => $this->l('Disable overrides'),
            'cast'       => 'intval',
            'validation' => 'isBool',
            'type'       => 'bool',
            'desc'       => $this->l('As the overrides of some modules modules can cause compatibility issues, we recommend to disable these by default.').'<br />'.$this->l('Keeping them enabled might prevent you from loading the "Modules" page properly after the migration.'),
        ];

        $this->upgradeOptions[UpgraderTools::SWITCH_TO_DEFAULT_THEME] = [
            'title'      => $this->l('Switch to the thirty bees default theme'),
            'cast'       => 'intval',
            'validation' => 'isBool',
            'type'       => 'bool',
            'desc'       => $this->l('Your theme might not be fully compatible with thirty bees (PS != 1.6.1). In this case it is better to first switch to the default theme.'),
        ];

        if (UpgraderTools::getConfig(UpgraderTools::DISPLAY_ERRORS)) {
            UpgraderTools::writeConfig([UpgraderTools::DISPLAY_ERRORS => false]);
        }
    }

    /**
     * Get js init stuff
     *
     * @return string
     *
     * @since 1.0.0
     */
    private function getJsInit()
    {
        return $this->displayAdminTemplate(__DIR__.'/../../views/templates/admin/mainjs.phtml');
    }

    /**
     * Generate ajax token
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function generateAjaxToken()
    {
        $blowfish = new PsOneSixMigrator\Blowfish(_COOKIE_KEY_, _COOKIE_IV_);

        return $blowfish->encrypt('thirtybees1337H4ck0rzz');
    }

    /**
     * @return void
     *
     * @since 1.0.0
     */
    public function ajaxProcessSetConfig()
    {
        if (!Tools::isSubmit('configKey') || !Tools::isSubmit('configValue') || !Tools::isSubmit('configType')) {
            die(json_encode([
                'success' => false,
            ]));
        }

        $configKey = Tools::getValue('configKey');
        $configType = Tools::getValue('configType');
        $configValue = Tools::getValue('configValue');
        if ($configType === 'bool') {
            if ($configValue === 'false' || !$configValue) {
                $configValue = false;
            } else {
                $configValue = true;
            }
        } elseif ($configType === 'select') {
            $configValue = (int) $configValue;
        }

        UpgraderTools::setConfig($configKey, $configValue);

        die(json_encode([
            'success' => true,
        ]));
    }
}
