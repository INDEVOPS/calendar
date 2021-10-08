<?php

namespace Calendar\Controllers;

abstract class Controller
{

    /**
     * @var array Handlers for Roundcube actions
     * 
     * @example  ' ["test"] - create "test" action and attach "$this->test()" method to it
     * @example  ' ["test" => "test_method"] - create "test" action and attach "$this->test_method()" method to it
     */
    const ACTIONS = [];

    /**
     * @var array Handlers for Roundcube hooks
     * @see Controller::ACTIONS
     */
    const HOOKS = [];

    /**
     * @var \rcube Roundcube framework class
     */
    protected \rcube $rc;

    /**
     * @var \calendar_driver Driver responsible for CRUD operation on calendars/events 
     */
    protected \calendar_driver $driver;

    /**
     * Plugin which is using this controller
     * Give access to rcube_plugin_api
     *
     * @var \calendar
     */
    protected \calendar $rc_plugin;

    /**
     * Controller constructor
     * 
     * @param \rcube $rc
     * @param mixed $driver
     * @param \calendar $rcube_plugin
     */
    public function __construct(\rcube $rc, $driver, \calendar $rcube_plugin)
    {
        $this->rc = $rc;
        $this->driver = $driver;
        $this->rc_plugin = $rcube_plugin;
        $this->add_hooks();
        $this->register_actions();
    }

    /**
     * Add controller hook handlers
     * 
     * @return void
     */
    protected function add_hooks(): void
    {
        foreach (static::HOOKS as $hook => $handler) {
            $name = is_numeric($hook) ? $handler : $hook;
            $this->rc_plugin->add_hook($name, array($this, $handler));
        }
    }

    /**
     * Register controller action handlers
     * 
     * @return void
     */
    protected function register_actions(): void
    {
        foreach (static::ACTIONS as $action => $handler) {
            $name = is_numeric($action) ? $handler : $action;
            $this->rc_plugin->register_action($name, array($this, $handler));
        }
    }
}
