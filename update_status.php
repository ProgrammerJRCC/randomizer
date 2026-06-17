<?php
// update_status.php
header('Content-Type: application/json');

require_once 'db_connect.php';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['id'], $_POST['status'])) {
    $id = intval($_POST['id']);
    // 'status' is a string "true" or "false" from JS, convert it to a boolean value (1 or 0) for MySQL
    $status = $_POST['status'] === 'true' ? 1 : 0; 
    
    $stmt = $conn->prepare("UPDATE participants SET is_active = ? WHERE id = ?");
    $stmt->bind_param("ii", $status, $id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'id' => $id, 'new_status' => $status]);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }

    $stmt->close();
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request parameters.']);
}

$conn->close();
?>