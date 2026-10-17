<?php

namespace Acms\Plugins\ArchivesToMedia;

use ACMS_App;
use Acms\Services\Common\CorrectorFactory;
use Acms\Services\Common\HookFactory;
use Acms\Services\Common\InjectTemplate;
use Acms\Services\Common\ValidatorFactory;
use Acms\Services\Facades\Twig;

/**
 * Plugin (extension app) registration class.
 *
 * Layout (important):
 * The production code lives in `src/`, and `src/` is what gets deployed as the plugin root
 * (`extension/plugins/{Name}/` is a symlink/mount to this repository's `src/`). The core autoloader
 * resolves `Acms\Plugins\ → extension/plugins/`, so `Acms\Plugins\ArchivesToMedia\ServiceProvider` maps to
 * `extension/plugins/ArchivesToMedia/ServiceProvider.php` = `src/ServiceProvider.php`. That is why the
 * ServiceProvider and all other classes (GET/, POST/, Services/, Modules/, ...) sit directly under
 * `src/` with no extra namespace segment. See README.md for the layout rationale.
 */
class ServiceProvider extends ACMS_App
{
    /**
     * @var string
     */
    public $version = '0.1.0';

    /**
     * @var string
     */
    public $name = 'ArchivesToMedia';

    /**
     * @var string
     */
    public $author = 'appleple';

    /**
     * @var bool
     */
    public $module = false;

    /**
     * @var false|string
     */
    public $menu = 'archives_to_media_index';

    /**
     * @var string
     */
    public $desc = 'A starter archives_to_media for a-blog cms plugin development.';

    /**
     * Boot the plugin (runs on every request while the plugin is active).
     *
     * @return void
     */
    public function init()
    {
        // Extension points. Each factory calls a same-named method on the attached object if it exists.
        $hook = HookFactory::singleton();
        $hook->attach('ArchivesToMediaHook', new Hook());

        $corrector = CorrectorFactory::singleton();
        $corrector->attach('ArchivesToMediaCorrector', new Corrector());

        $validator = ValidatorFactory::singleton();
        $validator->attach('ArchivesToMediaValidator', new Validator());

        // Inject templates into the admin UI.
        $inject = InjectTemplate::singleton();
        $inject->add('admin-module-select', PLUGIN_DIR . 'ArchivesToMedia/template/module-select.html');
        $inject->add('admin-module-config-Sample', PLUGIN_DIR . 'ArchivesToMedia/template/config.html');

        // Register a Twig template directory under the "archives_to_media" namespace.
        Twig::addTemplatePath(PLUGIN_LIB_DIR . 'ArchivesToMedia/template', 'archives_to_media');

        if (defined('ADMIN') && ADMIN === 'app_' . $this->menu) {
            // Render the plugin settings screen written in Twig. Passing a callable to
            // InjectTemplate lets ACMS_GET_Admin_InjectTemplate expand the returned HTML.
            $inject->add('admin-main', static function (): string {
                return Twig::renderTemplate('@archives_to_media/admin/main.twig');
            });
            $inject->add('admin-topicpath', PLUGIN_DIR . 'ArchivesToMedia/template/admin/topicpath.html');
        }
    }

    /**
     * Environment check before installation.
     *
     * @return bool
     */
    public function checkRequirements()
    {
        return true;
    }

    /**
     * Runs on install (create tables, etc.). This archives_to_media uses no tables, so it does nothing.
     *
     * @return void
     */
    public function install()
    {
    }

    /**
     * Runs on uninstall (drop tables, etc.).
     *
     * @return void
     */
    public function uninstall()
    {
    }

    /**
     * Runs on update.
     *
     * @return bool
     */
    public function update()
    {
        return true;
    }

    /**
     * Runs on activate.
     *
     * @return bool
     */
    public function activate()
    {
        return true;
    }

    /**
     * Runs on deactivate.
     *
     * @return bool
     */
    public function deactivate()
    {
        return true;
    }
}
