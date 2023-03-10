<?php
/**
 * @link    https://craftcms.com/
 * @license MIT
 */

namespace craft\composer;

use Composer\Installer\LibraryInstaller;
use Composer\Package\CompletePackageInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use React\Promise\PromiseInterface;

/**
 * Installer is the Composer installer that installs Craft CMS plugins.
 *
 * @author Pixel & Tonic, Inc. <support@craftcms.com>
 */
class Installer extends LibraryInstaller
{
    const PLUGINS_FILE = 'craftcms/plugins.php';

    /**
     * @inheritdoc
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $addPlugin = function() use ($repo, $package) {
            // Add the plugin info to plugins.php
            try {
                $this->addPlugin($package);
            } catch (InvalidPluginException $e) {
                // Rollback
                parent::uninstall($repo, $package);
                throw $e;
            }
        };

        // Install the plugin in vendor/ like a normal Composer library
        $promise = parent::install($repo, $package);

        // Composer v2 might return a promise here
        if ($promise instanceof PromiseInterface) {
            return $promise->then($addPlugin);
        }

        $addPlugin();
        return null;
    }

    /**
     * @inheritdoc
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $updatePlugin = function() use ($repo, $initial, $target) {
            // Remove the old plugin info from plugins.php
            $initialPlugin = $this->removePlugin($initial);

            // Add the new plugin info to plugins.php
            try {
                $this->addPlugin($target);
            } catch (InvalidPluginException $e) {
                // Rollback
                parent::update($repo, $target, $initial);
                if ($initialPlugin !== null) {
                    $this->registerPlugin($initial->getName(), $initialPlugin);
                }
                throw $e;
            }
        };

        // Update the plugin in vendor/ like a normal Composer library
        $promise = parent::update($repo, $initial, $target);

        // Composer v2 might return a promise here
        if ($promise instanceof PromiseInterface) {
            return $promise->then($updatePlugin);
        }

        $updatePlugin();
        return null;
    }

    /**
     * @inheritdoc
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $removePlugin = function() use ($package) {
            // Remove the plugin info from plugins.php
            $this->removePlugin($package);
        };

        // Uninstall the plugin from vendor/ like a normal Composer library
        $promise = parent::uninstall($repo, $package);

        // Composer v2 might return a promise here
        if ($promise instanceof PromiseInterface) {
            return $promise->then($removePlugin);
        }

        $removePlugin();
        return null;
    }

    /**
     * @param PackageInterface $package
     * @param bool $isRoot
     * @throws InvalidPluginException() if there's an issue with the plugin
     */
    public function addPlugin(PackageInterface $package, $isRoot = false)
    {
        $extra = $package->getExtra();
        $prettyName = $package->getPrettyName();

        // Find the PSR-4 autoload aliases, the primary Plugin class, and base path
        $class = isset($extra['class']) ? $extra['class'] : null;
        $basePath = isset($extra['basePath']) ? $extra['basePath'] : null;
        $aliases = $this->generateDefaultAliases($package, $class, $basePath, $isRoot);

        // class + basePath (required)
        if ($class === null) {
            throw new InvalidPluginException($package, 'Unable to determine the Plugin class');
        }

        if ($basePath === null) {
            throw new InvalidPluginException($package, 'Unable to determine the base path');
        }

        // handle (required)
        if (!isset($extra['handle']) || !preg_match('/^_?[a-zA-Z][\w\-]*$/', $extra['handle'])) {
            throw new InvalidPluginException($package, 'Invalid or missing plugin handle');
        }

        // normalize the handle (copied from craft\services\Plugins::_normalizeHandle())
        $handle = $extra['handle'];
        if (strtolower($handle) !== $handle) {
            // copied from yii\helpers\BaseInflector::camel2id() w/ default options
            $handle = strtolower(trim(str_replace('_', '-', preg_replace('/(?<![A-Z])[A-Z]/', '-' . '\0', $handle)), '-'));
            // prevent double -'s
            $handle = preg_replace('/\-{2,}/', '-', $handle);
            // complain about it
            $this->io->write('<warning>' . $prettyName . ' uses the old plugin handle format ("' . $extra['handle'] . '"). It should be "' . $handle . '".</warning>');
        }

        $plugin = [
            'class' => $class,
            'basePath' => $basePath,
            'handle' => $handle,
        ];

        if ($aliases) {
            $plugin['aliases'] = $aliases;
        }

        if (strpos($prettyName, '/') !== false) {
            list($vendor, $name) = explode('/', $prettyName);
        } else {
            $vendor = null;
            $name = $prettyName;
        }

        // name
        if (isset($extra['name'])) {
            $plugin['name'] = $extra['name'];
        } else {
            $plugin['name'] = $name;
        }

        // version
        if (isset($extra['version'])) {
            $plugin['version'] = $extra['version'];
        } else {
            $plugin['version'] = $package->getPrettyVersion();
        }

        // schemaVersion
        if (isset($extra['schemaVersion'])) {
            $plugin['schemaVersion'] = $extra['schemaVersion'];
        }

        // description
        if (isset($extra['description'])) {
            $plugin['description'] = $extra['description'];
        } else if ($package instanceof CompletePackageInterface && ($description = $package->getDescription())) {
            $plugin['description'] = $description;
        }

        // developer
        if (isset($extra['developer'])) {
            $plugin['developer'] = $extra['developer'];
        } else if ($authorName = $this->getAuthorProperty($package, 'name')) {
            $plugin['developer'] = $authorName;
        } else if ($vendor !== null) {
            $plugin['developer'] = $vendor;
        }

        // developerUrl
        if (isset($extra['developerUrl'])) {
            $plugin['developerUrl'] = $extra['developerUrl'];
        } else if ($package instanceof CompletePackageInterface && ($homepage = $package->getHomepage())) {
            $plugin['developerUrl'] = $homepage;
        } else if ($authorHomepage = $this->getAuthorProperty($package, 'homepage')) {
            $plugin['developerUrl'] = $authorHomepage;
        }

        // developerEmail
        if (isset($extra['developerEmail'])) {
            $plugin['developerEmail'] = $extra['developerEmail'];
        } else if ($package instanceof CompletePackageInterface && ($support = $package->getSupport()) && isset($support['email'])) {
            $plugin['developerEmail'] = $support['email'];
        }

        // documentationUrl
        if (isset($extra['documentationUrl'])) {
            $plugin['documentationUrl'] = $extra['documentationUrl'];
        } else if ($package instanceof CompletePackageInterface && ($support = $package->getSupport()) && isset($support['docs'])) {
            $plugin['documentationUrl'] = $support['docs'];
        }

        // changelogUrl
        // todo: check $extra['support']['changelog'] if that becomes a thing - https://github.com/composer/composer/issues/6079
        if (isset($extra['changelogUrl'])) {
            $plugin['changelogUrl'] = $extra['changelogUrl'];
        }

        // downloadUrl
        if (isset($extra['downloadUrl'])) {
            $plugin['downloadUrl'] = $extra['downloadUrl'];
        }

        // t9nCategory
        if (isset($extra['t9nCategory'])) {
            $plugin['t9nCategory'] = $extra['t9nCategory'];
        }

        // sourceLanguage
        if (isset($extra['sourceLanguage'])) {
            $plugin['sourceLanguage'] = $extra['sourceLanguage'];
        }

        // hasCpSettings
        if (isset($extra['hasCpSettings'])) {
            $plugin['hasCpSettings'] = (bool)$extra['hasCpSettings'];
        }

        // hasCpSection
        if (isset($extra['hasCpSection'])) {
            $plugin['hasCpSection'] = (bool)$extra['hasCpSection'];
        }

        // components
        if (isset($extra['components'])) {
            $plugin['components'] = $extra['components'];
        }

        // modules
        if (isset($extra['modules'])) {
            $plugin['modules'] = $extra['modules'];
        }

        // minimum required version
        if (isset($extra['minVersionRequired'])) {
            $plugin['minVersionRequired'] = $extra['minVersionRequired'];
        }

        $this->registerPlugin($package->getName(), $plugin);
    }

    /**
     * @param string $name The plugin's package name
     * @param array $plugin The plugin config
     */
    protected function registerPlugin($name, array $plugin)
    {
        $plugins = $this->loadPlugins();
        $plugins[$name] = $plugin;
        $this->savePlugins($plugins);
    }

    /**
     * @param PackageInterface $package
     * @param $class
     * @param $basePath
     * @param bool $isRoot
     * @return array|null
     */
    protected function generateDefaultAliases(PackageInterface $package, &$class, &$basePath, $isRoot)
    {
        $autoload = $package->getAutoload();

        if (empty($autoload['psr-4'])) {
            return null;
        }

        $fs = new Filesystem();
        $vendorDir = $fs->normalizePath($this->vendorDir);
        $aliases = [];

        foreach ($autoload['psr-4'] as $namespace => $path) {
            if (is_array($path)) {
                // Yii doesn't support aliases that point to multiple base paths
                continue;
            }

            // Normalize $path to an absolute path
            $cwd = $fs->normalizePath(getcwd());
            if (!$fs->isAbsolutePath($path)) {
                if ($isRoot) {
                    $path = $cwd . '/' . $path;
                } else {
                    $path = $this->vendorDir . '/' . $package->getPrettyName() . '/' . $path;
                }
            }

            $path = $fs->normalizePath($path);
            $alias = '@' . str_replace('\\', '/', trim($namespace, '\\'));

            $aliases[$alias] = $this->_path($vendorDir, $cwd, $path);

            // If we're still looking for the primary Plugin class, see if it's in here
            if ($class === null && file_exists($path . '/Plugin.php')) {
                $class = $namespace . 'Plugin';
            }

            // If we're still looking for the base path but we know the primary Plugin class,
            // see if the class namespace matches up, and the file is in here.
            // If so, set the base path to whatever directory contains the plugin class.
            if ($basePath === null && $class !== null) {
                $n = strlen($namespace);
                if (strncmp($namespace, $class, $n) === 0) {
                    $testClassPath = $path . '/' . str_replace('\\', '/', substr($class, $n)) . '.php';
                    if (file_exists($testClassPath)) {
                        $basePath = $this->_path($vendorDir, $cwd, dirname($testClassPath));
                    }
                }
            }
        }

        return $aliases;
    }

    /**
     * @param PackageInterface $package
     * @param string $property
     *
     * @return null
     */
    protected function getAuthorProperty(PackageInterface $package, $property)
    {
        if (!$package instanceof CompletePackageInterface) {
            return null;
        }

        $authors = $package->getAuthors();
        if (empty($authors)) {
            return null;
        }

        $firstAuthor = reset($authors);

        if (!isset($firstAuthor[$property])) {
            return null;
        }

        return $firstAuthor[$property];
    }

    /**
     * @param PackageInterface $package
     *
     * @return array|null The removed plugin info, or null if it wasn't there in the first place
     */
    protected function removePlugin(PackageInterface $package)
    {
        return $this->unregisterPlugin($package->getName());
    }

    /**
     * @param string $name The plugin's package name
     *
     * @return array|null The removed plugin info, or null if it wasn't there in the first place
     */
    protected function unregisterPlugin($name)
    {
        $plugins = $this->loadPlugins();

        if (!isset($plugins[$name])) {
            return null;
        }

        $plugin = $plugins[$name];
        unset($plugins[$name]);
        $this->savePlugins($plugins);
        return $plugin;
    }

    /**
     * @return array|mixed
     */
    protected function loadPlugins()
    {
        $file = $this->vendorDir . '/' . static::PLUGINS_FILE;

        if (!is_file($file)) {
            return [];
        }

        // Invalidate opcache of plugins.php if it exists
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        /** @var array $plugins */
        $plugins = require($file);

        // Swap absolute paths with <vendor-dir> tags
        $vendorDir = str_replace('\\', '/', $this->vendorDir);
        $cwd = getcwd();

        foreach ($plugins as &$plugin) {
            // basePath
            if (isset($plugin['basePath'])) {
                $plugin['basePath'] = $this->_path($vendorDir, $cwd, $plugin['basePath']);
            }
            // aliases
            if (isset($plugin['aliases'])) {
                foreach ($plugin['aliases'] as $alias => $path) {
                    $plugin['aliases'][$alias] = $this->_path($vendorDir, $cwd, $plugin['aliases'][$alias]);
                }
            }
        }

        return $plugins;
    }

    /**
     * @param array $plugins
     */
    protected function savePlugins(array $plugins)
    {
        $file = $this->vendorDir . '/' . static::PLUGINS_FILE;

        if (!file_exists(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        $array = str_replace(["'<vendor-dir>", "'<root-dir>"], ['$vendorDir . \'', '$rootDir . \''], var_export($plugins, true));
        $fs = new Filesystem();
        file_put_contents($file, "<?php\n\n\$vendorDir = dirname(__DIR__);\n" .
            "\$rootDir = " . $fs->findShortestPathCode($this->vendorDir . '/craftcms', getcwd(), true) . ";\n\n" .
            "return $array;\n");

        // Invalidate opcache of plugins.php if it exists
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    /**
     * Prepares a path for inclusion in plugins.php.
     *
     * @param string $vendorDir The path to the vendor/ folder
     * @param string $cwd The current working directory
     * @param string $path The path to be normalized
     * @return string
     */
    private function _path(string $vendorDir, string $cwd, string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (strpos("$path/", "$vendorDir/") === 0) {
            return '<vendor-dir>' . substr($path, strlen($vendorDir));
        }
        if (strpos("$path/", "$cwd/") === 0) {
            return '<root-dir>' . substr($path, strlen($cwd));
        }
        return $path;
    }
}
