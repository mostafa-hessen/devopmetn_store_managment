<?php
$conn = new mysqli('localhost', 'root', '', 'migration_store');
$result = $conn->query("SELECT username, role FROM users WHERE role='admin' LIMIT 1");
if ($row = $result->fetch_assoc()) {
    echo "ADMIN_USER:" . $row['username'];
} else {
    echo "NO_ADMIN_FOUND";
}
?>
