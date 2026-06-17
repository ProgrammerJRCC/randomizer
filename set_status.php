<?php
// set_status.php
require_once 'db_connect.php';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['status'])) {
    $status = $_POST['status'];
    
    if ($status === 'IDLE') {
        $stmt = $conn->prepare("UPDATE draw_counter SET randomizer_status = 'IDLE' WHERE id = 1");
        $stmt->execute();
        $stmt->close();
    }
}

$conn->close();
?>