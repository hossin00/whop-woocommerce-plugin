<?php

namespace Whop\WooCommerce\Licensing\Providers;

use Whop\WooCommerce\Licensing\Interfaces\ILicenseProvider;

/**
 * Class PlaceholderProvider
 * A generic placeholder provider for testing the licensing foundation.
 * @package Whop\WooCommerce\Licensing\Providers
 */
class PlaceholderProvider implements ILicenseProvider
{
    /**
     * @inheritDoc
     */
    public function activate(string $licenseKey, string $instanceName): array
    {
        return [
            'success' => true,
            'message' => 'Simulated activation successful.',
            'data' => [
                'instance_id' => 'inst_placeholder_123',
                'status' => 'active',
                'license_type' => 'single',
                'support_expiration' => date('Y-m-d', strtotime('+1 year')),
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function deactivate(string $instanceId): array
    {
        return [
            'success' => true,
            'message' => 'Simulated deactivation successful.',
        ];
    }

    /**
     * @inheritDoc
     */
    public function validate(string $licenseKey, string $instanceId): array
    {
        return [
            'success' => true,
            'message' => 'Simulated validation successful.',
            'data' => [
                'status' => 'active',
                'license_type' => 'single',
                'support_expiration' => date('Y-m-d', strtotime('+1 year')),
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function checkForUpdates(string $licenseKey, string $instanceId, string $currentVersion): array
    {
        return [
            'success' => true,
            'message' => 'No updates available (Simulated).',
            'data' => [
                'new_version' => $currentVersion,
                'package' => '',
            ],
        ];
    }
}
