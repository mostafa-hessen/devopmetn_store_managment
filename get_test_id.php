<?php
$conn = new mysqli('localhost', 'root', '', 'migration_store');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$result = $conn->query("SELECT id FROM invoices_out WHERE delivered != 'canceled' AND delivered != 'reverted' LIMIT 1");
if ($row = $result->fetch_assoc()) {
    echo "VALID_INVOICE_ID:" . $row['id'];
} else {
    echo "NO_INVOICE_FOUND";
}
?>
