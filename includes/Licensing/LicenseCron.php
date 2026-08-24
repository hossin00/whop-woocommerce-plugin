<?php

namespace Whop\WooCommerce\Licensing;

use Whop\WooCommerce\Licensing\Interfaces\ILicenseManager;

/**
 * Class LicenseCron
 * Schedules and handles daily license validation via WordPress Cron.
 * @package Whop\WooCommerce\Licensing
 */
class LicenseCron
{
    private const HOOK_NAME = 'whop_wc_daily_license_check';
    private const RECURRENCE = 'daily';

    private $licenseManager;

    public function __construct(ILicenseManager $licenseManager)
    {
        $this->licenseManager = $licenseManager;
    }

    public function register(): void
    {
        add_action(self::HOOK_NAME, [$this, 'validateLicense']);

        if (!wp_next_scheduled(self::HOOK_NAME)) {
            wp_schedule_event(time(), self::RECURRENCE, self::HOOK_NAME);
        }
    }

    public function unregister(): void
    {
        wp_clear_scheduled_hook(self::HOOK_NAME);
    }

    public function validateLicense(): void
    {
        $licenseInfo = $this->licenseManager->getLicenseInfo();
        if (empty($licenseInfo['license_key'])) {
            return;
        }

        $this->licenseManager->checkLicense();
    }
}
