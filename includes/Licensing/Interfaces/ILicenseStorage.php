<?php

namespace Whop\WooCommerce\Licensing\Interfaces;

/**
 * Interface ILicenseStorage
 * Defines the contract for storing and retrieving license data.
 * @package WhopWooCommerce\Includes\Licensing\Interfaces
 */
interface ILicenseStorage
{
    /**
     * Saves license data to persistent storage.
     * @param array $data The license data to save.
     * @return bool True on success, false on failure.
     */
    public function saveLicenseData(array $data): bool;

    /**
     * Retrieves license data from persistent storage.
     * @return array The stored license data, or an empty array if not found.
     */
    public function getLicenseData(): array;

    /**
     * Deletes all stored license data.
     * @return bool True on success, false on failure.
     */
    public function deleteLicenseData(): bool;

    /**
     * Saves the last checked timestamp.
     * @param int $timestamp The timestamp to save.
     * @return bool True on success, false on failure.
     */
    public function saveLastCheck(int $timestamp): bool;

    /**
     * Retrieves the last checked timestamp.
     * @return int The timestamp, or 0 if not found.
     */
    public function getLastCheck(): int;
}
