<?php
// randomize.php
header('Content-Type: application/json');
require_once 'db_connect.php';

$winner_name = '';
$winner_id = null;
$scheduled_draw_id = null;
$current_draw_number = 0;

// --- Transaction Start: Ensure all updates are atomic ---
$conn->begin_transaction();

try {
    // 1. Get and Increment Draw Counter
    $sql_counter_lock = "SELECT current_draw_number FROM draw_counter WHERE id = 1 FOR UPDATE";
    $result_counter = $conn->query($sql_counter_lock);
    $current_draw_number = $result_counter->fetch_assoc()['current_draw_number'] ?? 0;
    $next_draw_number = $current_draw_number + 1;

    $stmt_update_counter = $conn->prepare("UPDATE draw_counter SET current_draw_number = ? WHERE id = 1");
    $stmt_update_counter->bind_param("i", $next_draw_number);
    $stmt_update_counter->execute();
    $stmt_update_counter->close();

    // 2. Check for Scheduled Draw at the new draw number
    $sql_scheduled = "SELECT sd.id, p.id AS participant_id, p.name 
                      FROM scheduled_draws sd 
                      JOIN participants p ON sd.participant_id = p.id 
                      WHERE sd.draw_order = ? AND sd.drawn_status = FALSE";
    
    $stmt_check_schedule = $conn->prepare($sql_scheduled);
    $stmt_check_schedule->bind_param("i", $next_draw_number);
    $stmt_check_schedule->execute();
    $result_scheduled = $stmt_check_schedule->get_result();
    
    if ($result_scheduled->num_rows > 0) {
        // Scheduled winner found!
        $scheduled_winner = $result_scheduled->fetch_assoc();
        $winner_id = $scheduled_winner['participant_id'];
        $winner_name = htmlspecialchars($scheduled_winner['name']);
        $scheduled_draw_id = $scheduled_winner['id'];

        // Mark the scheduled draw entry as drawn
        $stmt_mark_drawn = $conn->prepare("UPDATE scheduled_draws SET drawn_status = TRUE WHERE id = ?");
        $stmt_mark_drawn->bind_param("i", $scheduled_draw_id);
        $stmt_mark_drawn->execute();
        $stmt_mark_drawn->close();

    } else {
        // No scheduled winner. Perform a truly random draw.
        $sql_select = "SELECT id, name FROM participants WHERE is_active = TRUE ORDER BY RAND() LIMIT 1";
        $result = $conn->query($sql_select);

        if ($result && $result->num_rows > 0) {
            $random_winner = $result->fetch_assoc();
            $winner_id = $random_winner['id'];
            $winner_name = htmlspecialchars($random_winner['name']);
        } else {
            // No active participants found, and no scheduled winner
            $conn->rollback();
            echo json_encode(['success' => false, 'name' => "Draw #{$next_draw_number} failed. No **ACTIVE** participants found or scheduled."]);
            return;
        }
    }

// 3. Mark the winner as inactive AND permanently mark them as a winner
    $stmt_update_participant = $conn->prepare("UPDATE participants SET is_active = 0, is_winner = 1 WHERE id = ?");
    $stmt_update_participant->bind_param("i", $winner_id);
    $stmt_update_participant->execute();
    $stmt_update_participant->close();

    // Commit all changes
    $conn->commit();
    echo json_encode(['success' => true, 'name' => $winner_name, 'draw_number' => $next_draw_number]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'name' => 'A critical system error occurred.', 'error' => $e->getMessage()]);
}

$conn->close();
?>