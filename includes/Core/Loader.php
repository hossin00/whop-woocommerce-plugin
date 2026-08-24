<?php

namespace Whop\WooCommerce\Core;

final class Loader
{
    /** @var array<int, array<string, mixed>> */
    private array $actions = [];

    /** @var array<int, array<string, mixed>> */
    private array $filters = [];

    public function register_action(string $hook, object $component, string $callback): void
    {
        $this->actions[] = [
            'hook' => $hook,
            'component' => $component,
            'callback' => $callback,
        ];
    }

    public function register_filter(string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1): void
    {
        $this->filters[] = [
            'hook' => $hook,
            'component' => $component,
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args,
        ];
    }

    public function run(): void
    {
        foreach ($this->actions as $action) {
            add_action($action['hook'], [$action['component'], $action['callback']]);
        }

        foreach ($this->filters as $filter) {
            add_filter(
                $filter['hook'],
                [$filter['component'], $filter['callback']],
                $filter['priority'],
                $filter['accepted_args']
            );
        }
    }
}
