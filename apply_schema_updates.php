<?php
require_once 'config.php';

$commands = [
    // 1. Create customer_transactions table
    "CREATE TABLE IF NOT EXISTS `customer_transactions` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `customer_id` int(11) NOT NULL,
      `type` enum('invoice', 'payment', 'return', 'adjustment') NOT NULL,
      `amount` decimal(12,2) NOT NULL COMMENT 'Positive for Debit (Invoice), Negative for Credit (Payment/Return)',
      `reference_id` int(11) DEFAULT NULL COMMENT 'ID of Invoice, Payment, or Return',
      `description` text DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `created_by` int(11) DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `customer_id` (`customer_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 2. Create sales_returns table
    "CREATE TABLE IF NOT EXISTS `sales_returns` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `customer_id` int(11) NOT NULL,
      `original_invoice_id` int(11) DEFAULT NULL,
      `return_date` timestamp NOT NULL DEFAULT current_timestamp(),
      `total_amount` decimal(12,2) NOT NULL,
      `notes` text DEFAULT NULL,
      `created_by` int(11) DEFAULT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 3. Create sales_return_items table
    "CREATE TABLE IF NOT EXISTS `sales_return_items` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `return_id` int(11) NOT NULL,
      `product_id` int(11) NOT NULL,
      `quantity` decimal(10,2) NOT NULL,
      `unit_price` decimal(10,2) NOT NULL,
      `total_price` decimal(10,2) NOT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 4. Add job_name to invoices_out
    "ALTER TABLE `invoices_out` ADD COLUMN `job_name` VARCHAR(255) NULL COMMENT 'اسم الشغلانة / الصنايعي' AFTER `customer_id`;"
];

foreach ($commands as $sql) {
    try {
        if ($conn->query($sql) === TRUE) {
            echo "Success: " . substr($sql, 0, 50) . "...\n";
        } else {
            // Check if error is "Duplicate column name" which is fine
            if ($conn->errno == 1060) {
                 echo "Skipped (Column exists): " . substr($sql, 0, 50) . "...\n";
            } else {
                 echo "Error: " . $conn->error . "\nSQL: " . $sql . "\n";
            }
        }
    } catch (Exception $e) {
        echo "Exception: " . $e->getMessage() . "\n";
    }
}

echo "Database schema update completed.\n";
?>
