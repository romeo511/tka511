<?php
/**
 *
 * @package Kleeja
 * @copyright (c) 2007 Kleeja.com
 * @license http://www.kleeja.com/license
 *
 */
//no for directly open
if (!defined('IN_COMMON'))
{
    exit();
}


# We are in the plugin system, plugins files won't work outside here
define('IN_PLUGINS_SYSTEM', true);


/**
 * Kleeja Plugins System
 * @package plugins
 */
class Plugins
{
    /**
     * List of loaded plugins
     */
    private $plugins = array();

    /**
     * All hooks from all plugins listed in this variable
     */
    private $all_plugins_hooks = array();
    private $installed_plugins = array();
    private $installed_plugins_info = array();


    private $plugin_path = '';


    private static $instance;

    /**
     * Initiating the class
     */
    public function __construct()
    {
        global $SQL, $dbprefix;

        #if plugins system is turned off, then stop right now!
        if (defined('STOP_PLUGINS'))
        {
            return;
        }


        $this->plugin_path = PATH . KLEEJA_PLUGINS_FOLDER;

        # Get installed plugins
        $query = array(
            'SELECT' => "plg_name, plg_ver",
            'FROM' => "{$dbprefix}plugins",
            'WHERE' => "plg_disabled = 0"
        );

        $result = $SQL->build($query);

        while ($row = $SQL->fetch($result))
        {
            $this->installed_plugins[$row['plg_name']] = $row['plg_ver'];
        }
        $SQL->free($result);


        $this->load_enabled_plugins();
    }


    /**
     * Load the plugins from root/plugins folder
     */
    private function load_enabled_plugins()
    {
        $dh = opendir($this->plugin_path);

        while (false !== ($folder_name = readdir($dh)))
        {
            if (is_dir($this->plugin_path . '/' . $folder_name) && preg_match('/[a-z0-9_.]{3,}/', $folder_name))
            {

                if (!empty($this->installed_plugins[$folder_name]))
                {
                    if ($this->fetch_plugin($folder_name))
                    {
                        array_push($this->plugins, $folder_name);
                    }
                }
            }
        }

        #sort the plugins from high to low priority
        krsort($this->plugins);
    }

    /**
     * Get the plugin information and other things
     * @param string $plugin_name
     * @return bool
     */
    private function fetch_plugin($plugin_name)
    {
        #load the plugin
        @include_once $this->plugin_path . '/' . $plugin_name . '/init.php';

        if (empty($kleeja_plugin))
        {
            return false;
        }

        $priority = $kleeja_plugin[$plugin_name]['information']['plugin_priority'];
        $this->installed_plugins_info[$plugin_name] = $kleeja_plugin[$plugin_name]['information'];

        #bring the real priority of plugin and replace current one
        $plugin_current_priority = array_search($plugin_name, $this->plugins);
        unset($this->plugins[$plugin_current_priority]);
        $this->plugins[$priority] = $plugin_name;

        //update plugin if current loaded version is > than installed one
        if ($this->installed_plugins[$plugin_name])
            if (version_compare($this->installed_plugins[$plugin_name], $kleeja_plugin[$plugin_name]['information']['plugin_version'], '<'))
            {
                if (is_callable($kleeja_plugin[$plugin_name]['update']))
                {
                    global $SQL, $dbprefix;

                    #update plugin
                    $kleeja_plugin[$plugin_name]['update']($this->installed_plugins[$plugin_name], $kleeja_plugin[$plugin_name]['information']['plugin_version']);

                    #update current plugin version
                    $update_query = array(
                        'UPDATE' => "{$dbprefix}plugins",
                        'SET' => "plg_ver='" . $SQL->escape($kleeja_plugin[$plugin_name]['information']['plugin_version']) . "'",
                        'WHERE' => "plg_name='" . $SQL->escape($plugin_name) . "'"
                    );


                    $SQL->build($update_query);
                }
            }

        #add plugin hooks to global hooks, depend on its priority
        if (!empty($kleeja_plugin[$plugin_name]['functions']))
        {
            foreach ($kleeja_plugin[$plugin_name]['functions'] as $hook_name => $hook_value)
            {
                if (empty($this->all_plugins_hooks[$hook_name][$priority]))
                {
                    $this->all_plugins_hooks[$hook_name][$priority] = array();
                }
                array_push($this->all_plugins_hooks[$hook_name][$priority], $hook_value);
                krsort($this->all_plugins_hooks[$hook_name]);
            }
        }


        return true;
    }

    /**
     * get an installed plugin information
     * @param string $plugin_name
     * @return mixed|null
     */
    public function installed_plugin_info($plugin_name)
    {
        if (!empty($this->installed_plugins_info[$plugin_name]))
        {
            return $this->installed_plugins_info[$plugin_name];
        }

        return null;
    }


    /**
     * Bring all codes of this hook
     * This function scattered all over kleeja files
     * @param string $hook_name
     * @param array $args
     * @return array|null
     */
    public function run($hook_name, $args = array())
    {
        $return_value = $to_be_returned = array();

        if (!empty($this->all_plugins_hooks[$hook_name]))
        {
            foreach ($this->all_plugins_hooks[$hook_name] as $order => $functions)
            {
                foreach ($functions as $function)
                {
                    if (is_callable($function))
                    {
                        $return_value = $function($args);

                        if(is_array($return_value))
                        {
                            $args = array_merge($args, $return_value);
                            $to_be_returned = array_merge($to_be_returned, $return_value);
                        }
                    }
                }
            }
        }



        return sizeof($to_be_returned) ? $to_be_returned : null;
    }


    public static function getInstance()
    {
        if (is_null(self::$instance))
        {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * return debug info about plugins system
     * @return array
     */
    public function getDebugInfo(){
        if(!defined('DEV_STAGE'))
        {
            return array();
        }

        return array(
            'all_plugins_hooks' => $this->all_plugins_hooks,
            'installed_plugins' => $this->installed_plugins,
        );
    }
}
