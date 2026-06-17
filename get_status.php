<?php
// get_status.php
require_once 'db_connect.php';

header('Content-Type: application/json');

$sql = "SELECT randomizer_status FROM draw_counter WHERE id = 1";
$result = $conn->query($sql);

if ($result && $row = $result->fetch_assoc()) {
    echo json_encode(['status' => $row['randomizer_status']]);
} else {
    echo json_encode(['status' => 'IDLE']);
}

$conn->close();
?>