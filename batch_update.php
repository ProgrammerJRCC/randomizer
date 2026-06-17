<?php
// batch_update.php
require_once 'db_connect.php';
header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] != "POST" || !isset($_POST['participant_ids'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request method or missing data.']);
    exit;
}

// Get the array of IDs that were CHECKED (meaning they should be active)
$active_ids = $_POST['participant_ids'];
// The submitted array contains only the IDs that were checked.
// We assume all other IDs are to be set as inactive.

$conn->begin_transaction();
$success = true;

try {
    // 1. Set ALL participants to INACTIVE (0) first
    $sql_deactivate_all = "UPDATE participants SET is_active = 0";
    if (!$conn->query($sql_deactivate_all)) {
        throw new Exception("Error deactivating all: " . $conn->error);
    }
    
    // 2. If any IDs were checked, set those specific IDs back to ACTIVE (1)
    if (!empty($active_ids)) {
        // Prepare the list of placeholders for the IN clause (e.g., ?, ?, ?)
        $placeholders = implode(',', array_fill(0, count($active_ids), '?'));
        
        // Prepare the list of types for bind_param ('i' for each ID)
        $types = str_repeat('i', count($active_ids));
        
        $sql_activate_checked = "UPDATE participants SET is_active = 1 WHERE id IN ($placeholders)";
        $stmt_activate = $conn->prepare($sql_activate_checked);
        
        // Use call_user_func_array to bind the parameters dynamically
        // Note: The first argument must be a reference to the type string
        $params = array_merge([$types], $active_ids);
        if (!call_user_func_array([$stmt_activate, 'bind_param'], $params)) {
            throw new Exception("Error binding parameters.");
        }

        if (!$stmt_activate->execute()) {
            throw new Exception("Error activating checked: " . $stmt_activate->error);
        }
        $stmt_activate->close();
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Batch update completed successfully.']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Batch update failed: ' . $e->getMessage()]);
    $success = false;
}

$conn->close();
?>