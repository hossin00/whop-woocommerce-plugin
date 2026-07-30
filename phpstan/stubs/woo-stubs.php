<?php

// Minimal WooCommerce stubs for PHPStan analysis

class WC_Product {
    public function get_id(): int {}
    public function is_purchasable(): bool {}
}

class WC_Order {
    public function get_id(): int {}
    public function add_product($product, $qty) {}
    public function set_customer_id(int $id) {}
    public function set_status(string $status) {}
    public function save() {}
    public function get_items(): array {}
    public function get_billing_email(): string {}
    public function get_billing_first_name(): string {}
    public function get_billing_last_name(): string {}
    public function get_status(): string {}
    public function is_paid(): bool {}
    public function payment_complete(?string $transaction_id = null) {}
    public function add_order_note(string $note) {}
}

function wc_get_product($id) {}
function wc_create_order() {}
function wc_get_order($id) {}
function wc_get_orders(array $args = []) {}
function WC() {}
function wc_get_page_permalink($page) {}
