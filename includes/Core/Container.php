<?php

namespace Whop\WooCommerce\Core;

use Closure;

final class Container
{
    /** @var array<string, Closure> */
    private array $services = [];

    /** @var array<string, object> */
    private array $instances = [];

    public function register(string $name, Closure $resolver): void
    {
        $this->services[$name] = $resolver;
    }

    /** @return mixed */
    public function get(string $name)
    {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        if (!isset($this->services[$name])) {
            throw new \InvalidArgumentException(sprintf('Service "%s" is not registered.', $name));
        }

        $this->instances[$name] = $this->services[$name]();

        return $this->instances[$name];
    }
}
