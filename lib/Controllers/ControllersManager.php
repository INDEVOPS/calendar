<?php

namespace Calendar\Controllers;

class ControllersManager
{
    //namespace of that class
    const NS = "Calendar\Controllers\\";

    /**
     * @var array lazy assoc array of instances of controllers with no initialized contains self::NS . class_name
     */
    private array $controllers = [];

    /**
     * @var \rcube rcube framework instance injected to controllers
     */
    private \rcube $rc;

    /**
     * @var \calendar calendar plugin instance injected to controllers
     */
    private \calendar $rc_plugin;

    /**
     * @var mixed driver instance injected to controllers
     */
    private $driver;

    public function __construct(\rcube $rc, $driver, \calendar $rc_plugin)
    {
        $this->rc = $rc;
        $this->rc_plugin = $rc_plugin;
        $this->driver = $driver;
    }

    /**
     * Register controller.
     * 
     * Note: doesn't init it
     */
    public function register(string $controller_class_name)
    {
        $this->controllers[$controller_class_name] = self::NS . $controller_class_name;
    }

    /**
     * Return controller with matching $task
     */
    public function match(string $task)
    {
        foreach ($this->controllers as $name => $controller_class_name) {
            if ($task === $controller_class_name::TASK) {
                return $this->load($name);
            }
        }
    }

    /**
     * Return controller by name
     * 
     * Used to directly used controllers
     */
    public function get_controller(string $name)
    {
        $class_name = ucfirst($name);

        return $this->load($class_name);
    }

    /**
     * If controller not initialized, init it and cache result
     * else return instance of controller
     */
    private function load(string $class_name)
    {
        // if not initialized swap name of class on instance of class
        if (gettype($this->controllers[$class_name]) === "string") {

            $class_name_with_ns = $this->controllers[$class_name];

            $controller_instance = new $class_name_with_ns($this->rc, $this->driver, $this->rc_plugin);

            $this->controllers[$class_name] = $controller_instance;

            return $controller_instance;
        }

        // if init return instance of controller
        return $this->controllers[$class_name];
    }
}
