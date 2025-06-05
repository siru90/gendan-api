<?php

namespace App\Utils;

trait GetInstances
{
    private static array $instances = [];

    /**
     * @return static
     */
    final static public function getInstance(): static
    {
        $name = get_called_class();
        if (!isset(self::$instances[$name])) {
            self::$instances[$name] = new static();
        }
        return self::$instances[$name];
    }
}
