<?php

namespace Whop\WooCommerce\Helpers;

final class TemplateLoader
{
    /** @param array<string, mixed> $data */
    public function load(string $template, array $data = []): void
    {
        $template_path = WHOP_WOOCOMMERCE_TEMPLATES . '/' . ltrim($template, '/');

        if (!file_exists($template_path)) {
            return;
        }

        $this->include_template($template_path, $data);
    }

    /**
     * @param string $template_path
     * @param array<string, mixed> $data
     */
    private function include_template(string $template_path, array $data): void
    {
        include $template_path;
    }
}
