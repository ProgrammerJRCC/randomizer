<?php
// schedule.php (COMPLETE CODE - REDIRECTION REMOVED)
require_once 'db_connect.php';

$message = '';
$is_post_request = $_SERVER["REQUEST_METHOD"] == "POST";

// --- 1. Logic to Handle New Schedule Entry ---
if ($is_post_request && isset($_POST['schedule_entry'])) {
    $participant_id = filter_input(INPUT_POST, 'participant_id', FILTER_VALIDATE_INT);
    $draw_order = filter_input(INPUT_POST, 'draw_order', FILTER_VALIDATE_INT);

    if ($participant_id && $draw_order && $draw_order > 0) {
        $stmt_check = $conn->prepare("SELECT is_active FROM participants WHERE id = ?");
        $stmt_check->bind_param("i", $participant_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        $is_active = $result_check->fetch_assoc()['is_active'] ?? 0;
        $stmt_check->close();

        if ($is_active) {
            $stmt = $conn->prepare("INSERT INTO scheduled_draws (draw_order, participant_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $draw_order, $participant_id);

            if ($stmt->execute()) {
                $message = "<div class='alert alert-success'><i class='fas fa-lock'></i> Draw **#{$draw_order}** successfully scheduled!</div>";
            } else {
                if ($conn->errno == 1062) {
                    $message = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle'></i> Error: Draw **#{$draw_order}** already has a winner scheduled.</div>";
                } else {
                    $message = "<div class='alert alert-danger'>Error scheduling draw: " . htmlspecialchars($conn->error) . "</div>";
                }
            }
            $stmt->close();
        } else {
            $message = "<div class='alert alert-warning'><i class='fas fa-times-circle'></i> Error: Participant must be **Active** to be scheduled.</div>";
        }
    } else {
        $message = "<div class='alert alert-danger'>Invalid input. Draw number must be a positive integer.</div>";
    }
}

// --- 2. Logic to Delete Scheduled Entry ---
if ($is_post_request && isset($_POST['delete_id'])) {
    $delete_id = filter_input(INPUT_POST, 'delete_id', FILTER_VALIDATE_INT);
    if ($delete_id) {
        $stmt = $conn->prepare("DELETE FROM scheduled_draws WHERE id = ? AND drawn_status = FALSE");
        $stmt->bind_param("i", $delete_id);
        if ($stmt->execute()) {
             $message = "<div class='alert alert-success'><i class='fas fa-check'></i> Scheduled draw deleted successfully.</div>";
        } else {
             $message = "<div class='alert alert-danger'><i class='fas fa-times'></i> Error deleting scheduled draw.</div>";
        }
        $stmt->close();
        header("Location: schedule.php"); 
        exit();
    }
}

// --- 3. Logic to Reset Draw Counter ---
if ($is_post_request && isset($_POST['reset_counter'])) {
    $conn->query("UPDATE draw_counter SET current_draw_number = 0 WHERE id = 1");
    $conn->query("DELETE FROM scheduled_draws WHERE drawn_status = FALSE"); 
    $message = "<div class='alert alert-danger'><i class='fas fa-sync'></i> Draw counter reset to #0. **All upcoming schedules deleted.**</div>";
    header("Location: schedule.php"); 
    exit();
}

// --- 4. Logic to Set Randomizer Status (for remote start) ---
if ($is_post_request && isset($_POST['set_start'])) {
    $stmt = $conn->prepare("UPDATE draw_counter SET randomizer_status = 'START' WHERE id = 1");
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
    $stmt->close();
    $conn->close();
    exit; 
}


// --- 5. Logic to Retrieve Data for Display ---
$participants = [];
$sql_participants = "SELECT id, name, is_active FROM participants ORDER BY name ASC";
$result_participants = $conn->query($sql_participants);
while ($row = $result_participants->fetch_assoc()) {
    $participants[] = $row;
}

$sql_counter = "SELECT current_draw_number FROM draw_counter WHERE id = 1";
$current_draw_number = $conn->query($sql_counter)->fetch_assoc()['current_draw_number'] ?? 0;

$scheduled_draws = [];
$sql_scheduled = "SELECT sd.id, sd.draw_order, p.name 
                  FROM scheduled_draws sd 
                  JOIN participants p ON sd.participant_id = p.id 
                  WHERE sd.drawn_status = FALSE
                  ORDER BY sd.draw_order ASC";
$result_scheduled = $conn->query($sql_scheduled);
while ($row = $result_scheduled->fetch_assoc()) {
    $scheduled_draws[] = $row;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secret Draw Scheduler</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    
    <style>
        .admin-panel {
            border: 2px dashed #dc3545;
            padding: 20px;
            margin-top: 50px;
            background-color: #f8d7da;
        }
        .select2-container {
            width: 100% !important;
        }
    </style>
</head>
<body>

    <div class="container mt-5">
        <h1 class="text-center mb-2"><i class="fas fa-user-secret"></i> Secret Draw Scheduler</h1>
        <p class="text-center text-danger mb-4">The schedules you set here are **hidden** from the public view.</p>

        <div class="text-center mb-4">
            
            <button id="startDrawBtn" class="btn btn-lg btn-danger me-3">
                <i class="fas fa-play-circle"></i> Start Randomizer Draw on Index Page
            </button>
            
            <a href="index.php" class="btn btn-lg btn-primary">Go to Randomizer</a>
            <a href="manage.php" class="btn btn-lg btn-secondary">Go to Participant Manager</a>
        </div>
        
        <?php echo $message; ?>

        <div class="alert alert-info text-center">
            Current Draw Count: <strong>#<?php echo $current_draw_number; ?></strong>. The next draw will be **#<?php echo $current_draw_number + 1; ?>**.
        </div>

        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow-lg mb-4">
                    <div class="card-header bg-success text-white">
                        <h5><i class="fas fa-calendar-plus"></i> Schedule Next Winner</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label class="form-label">Target Draw Number:</label>
                                <input type="number" class="form-control" name="draw_order" required min="<?php echo $current_draw_number + 1; ?>" value="<?php echo $current_draw_number + 1; ?>">
                                <small class="text-muted">Enter a number (e.g., '5') to choose the winner for that draw.</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Select Participant:</label>
                                <select class="form-select" id="participant_select" name="participant_id" required> 
                                    <option value="">-- Select Active Participant --</option>
                                    <?php foreach ($participants as $p): ?>
                                        <option value="<?php echo $p['id']; ?>" <?php echo $p['is_active'] ? '' : 'disabled'; ?>>
                                            <?php echo htmlspecialchars($p['name']); ?> (<?php echo $p['is_active'] ? 'Active' : 'Inactive'; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Type to search for a participant.</small>
                            </div>
                            <button type="submit" name="schedule_entry" class="btn btn-success w-100">
                                <i class="fas fa-check-circle"></i> Schedule Winner Secretly
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="admin-panel shadow-lg">
                    <h4 class="text-danger text-center"><i class="fas fa-exclamation-triangle"></i> Administrator Management Panel</h4>
                    <hr>

                    <h5 class="text-danger"><i class="fas fa-eye-slash"></i> Upcoming Secret Schedules</h5>
                    <?php if (count($scheduled_draws) > 0): ?>
                        <ul class="list-group mb-4">
                            <?php foreach ($scheduled_draws as $s): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div class="fw-bold">
                                        Draw #<?php echo $s['draw_order']; ?>:
                                        <span class="text-success"><?php echo htmlspecialchars($s['name']); ?></span>
                                    </div>
                                    <form method="POST" action="" class="m-0">
                                        <input type="hidden" name="delete_id" value="<?php echo $s['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete the schedule for Draw #<?php echo $s['draw_order']; ?>?');">
                                            <i class="fas fa-trash-alt"></i> Delete
                                        </button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="alert alert-success text-center">No secret draws currently scheduled.</div>
                    <?php endif; ?>

                    <h5 class="text-danger mt-3"><i class="fas fa-undo"></i> Reset Draw Counter</h5>
                    <p class="text-danger">This resets the counter to 0 and deletes ALL upcoming secret schedules.</p>
                    <form method="POST" action="">
                        <button type="submit" name="reset_counter" class="btn btn-danger w-100 mt-2" onclick="return confirm('EXTREME WARNING: Are you sure you want to reset the entire Draw Counter and delete all UPCOMING secret schedules?');">
                            Reset Draw Counter (Currently: #<?php echo $current_draw_number; ?>)
                        </button>
                    </form>
                </div>
            </div>
        </div>

    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Initialize Select2 on the participant dropdown
            $('#participant_select').select2({
                 theme: "bootstrap-5" 
            });
            
            const startDrawBtn = document.getElementById('startDrawBtn');

            // --- Draw Logic: Send signal to DB (NO REDIRECTION) ---
            startDrawBtn.addEventListener('click', () => {
                startDrawBtn.disabled = true;
                startDrawBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Sending Signal...';
                
                fetch('schedule.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'set_start=1'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Success! Show alert and keep user on this page.
                        alert('✅ Signal sent successfully! The randomizer on index.php should now be spinning.');
                        startDrawBtn.disabled = false;
                        startDrawBtn.innerHTML = '<i class="fas fa-play-circle"></i> Start Randomizer Draw on Index Page';
                    } else {
                        alert('❌ Failed to send signal: ' + data.error);
                        startDrawBtn.disabled = false;
                        startDrawBtn.innerHTML = '<i class="fas fa-play-circle"></i> Start Randomizer Draw on Index Page';
                    }
                })
                .catch(error => {
                    alert('⚠️ System Error: Could not reach server.');
                    console.error('Fetch Error:', error);
                    startDrawBtn.disabled = false;
                    startDrawBtn.innerHTML = '<i class="fas fa-play-circle"></i> Start Randomizer Draw on Index Page';
                });
            });
        });
    </script>
</body>
</html>