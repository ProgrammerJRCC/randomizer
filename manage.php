<?php
// manage.php (COMPLETE CODE - REDIRECTION REMOVED)
require_once 'db_connect.php';

$message = '';
$message_type = '';
$search_term = '';

// --- 1. Logic to Add a New Participant ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_participant'])) {
    $name = trim($_POST['participant_name']);

    if (!empty($name)) {
        $stmt = $conn->prepare("INSERT INTO participants (name, is_active) VALUES (?, TRUE)");
        $stmt->bind_param("s", $name);

        if ($stmt->execute()) {
            $message = "Participant **" . htmlspecialchars($name) . "** added successfully!";
            $message_type = "success";
        } else {
            $message = "Error adding participant: " . $conn->error;
            $message_type = "danger";
        }
        $stmt->close();
    } else {
        $message = "Name cannot be empty.";
        $message_type = "warning";
    }
}

// --- 2. Logic for Batch Status Update (Set Active/Set Inactive) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['batch_action'])) {
    $action = $_POST['batch_action']; 
    $selected_ids = isset($_POST['participant_ids']) ? $_POST['participant_ids'] : [];
    $status = ($action == 'set_active') ? 1 : 0;
    
    if (!empty($selected_ids)) {
        $conn->begin_transaction();
        
        try {
            $original_count = count($selected_ids);
            $excluded_count = 0;
            
            // New Rule: If the action is 'set_active', filter the list to exclude past winners (is_winner = TRUE).
            if ($status == 1) {
                $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
                $types = str_repeat('i', count($selected_ids));

                $sql_filter = "SELECT id FROM participants WHERE id IN ($placeholders) AND is_winner = TRUE";
                $stmt_filter = $conn->prepare($sql_filter);
                
                $filter_params = [$types];
                foreach ($selected_ids as $key => $id) {
                    $filter_params[] = &$selected_ids[$key];
                }
                
                if (!call_user_func_array([$stmt_filter, 'bind_param'], $filter_params)) {
                     throw new Exception("Error binding parameters during filter.");
                }
                
                $stmt_filter->execute();
                $result_filter = $stmt_filter->get_result();
                $winner_ids = $result_filter->fetch_all(MYSQLI_ASSOC);
                $stmt_filter->close();

                $winner_ids_list = array_column($winner_ids, 'id');
                
                $selected_ids = array_diff($selected_ids, $winner_ids_list);
                $excluded_count = $original_count - count($selected_ids);
            }
            
            if (!empty($selected_ids)) {
                
                $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
                $types = 'i' . str_repeat('i', count($selected_ids));
                $sql_update = "UPDATE participants SET is_active = ? WHERE id IN ($placeholders)";
                $stmt = $conn->prepare($sql_update);
                
                $params = [$types];
                $params[] = &$status; 
                
                $selected_ids_temp = array_values($selected_ids); 
                foreach ($selected_ids_temp as $key => $id) {
                    $params[] = &$selected_ids_temp[$key];
                }
                
                if (!call_user_func_array([$stmt, 'bind_param'], $params)) {
                    throw new Exception("Error binding parameters. Check argument count.");
                }

                if (!$stmt->execute()) {
                    throw new Exception("Error updating status: " . $stmt->error);
                }
                $stmt->close();
                
                $conn->commit();
                
                $action_text = ($status == 1) ? 'Active' : 'Inactive';
                $message = "Successfully set **" . count($selected_ids) . "** participants to **$action_text**.";
                
                if ($status == 1 && $excluded_count > 0) {
                     $message .= " (Note: **$excluded_count** past winners were **excluded** from reactivation.)";
                }
                $message_type = "success";

            } else {
                 $message = "No participants were updated.";
                 if ($status == 1 && $original_count > 0 && $excluded_count == $original_count) {
                    $message = "All **" . $original_count . "** selected names are past winners and cannot be reactivated.";
                 } else if ($original_count > 0) {
                     $message = "No participants selected for the batch action.";
                 }
                 $message_type = "warning";
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Batch update failed: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = "No participants selected for the batch action.";
        $message_type = "warning";
    }
}


// --- 3. Logic to Set Randomizer Status (for remote start) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['set_start'])) {
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


// --- 4. Logic to Retrieve all Participants (with Search Filter) ---
$participants = [];
$sql_select = "SELECT id, name, is_active, is_winner FROM participants";
$where_clauses = [];

if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $search_term = trim($_GET['search']);
    $where_clauses[] = "name LIKE ?";
}

if (!empty($where_clauses)) {
    $sql_select .= " WHERE " . implode(' AND ', $where_clauses);
}

$sql_select .= " ORDER BY name ASC";

$stmt_select = $conn->prepare($sql_select);
if (!empty($search_term)) {
    $like_search = "%" . $search_term . "%";
    $stmt_select->bind_param("s", $like_search);
}

$stmt_select->execute();
$result = $stmt_select->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $participants[] = $row;
    }
}

$stmt_select->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Participants</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body>

    <div class="container mt-5">
        <h1 class="text-center mb-4"><i class="fas fa-users-gear"></i> Manage Participants</h1>
        <div class="text-center mb-4">
            
            <button id="startDrawBtn" class="btn btn-lg btn-danger me-3">
                <i class="fas fa-play-circle"></i> Start Randomizer Draw on Index Page
            </button>
            
            <a href="index.php" class="btn btn-lg btn-success">
                <i class="fas fa-up-right-from-square"></i> Open Full-Screen Randomizer
            </a>
        </div>

        <div id="statusMessage" class="mt-3">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-user-plus"></i> Add New Participant</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                            <div class="mb-3">
                                <label for="participant_name" class="form-label">Participant Name:</label>
                                <input type="text" class="form-control" id="participant_name" name="participant_name" required>
                            </div>
                            <button type="submit" name="add_participant" class="btn btn-primary w-100">
                                <i class="fas fa-check-circle"></i> Add Participant
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-secondary text-white">
                         <h5 class="mb-0"><i class="fas fa-list-check"></i> Participants (<?php echo count($participants); ?>)</h5>
                    </div>
                    <div class="card-body">
                        
                        <form method="GET" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="mb-3">
                            <div class="input-group">
                                <input type="text" class="form-control" placeholder="Search name..." name="search" value="<?php echo htmlspecialchars($search_term); ?>">
                                <button class="btn btn-info text-white" type="submit"><i class="fas fa-search"></i> Search</button>
                                <?php if (!empty($search_term)): ?>
                                    <a href="manage.php" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
                                <?php endif; ?>
                            </div>
                        </form>

                        <form id="batchUpdateForm" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                            <p class="text-muted">Check the box next to the names you want to modify, then select an action below.</p>
                            
                            <div class="d-flex justify-content-between mb-3">
                                <button id="selectAllBtn" type="button" class="btn btn-sm btn-info text-white">Select/Deselect All</button>
                                <button type="submit" name="batch_action" value="set_active" class="btn btn-success me-2">
                                    <i class="fas fa-check"></i> Set Selected Active
                                </button>
                                <button type="submit" name="batch_action" value="set_inactive" class="btn btn-danger">
                                    <i class="fas fa-times"></i> Set Selected Inactive
                                </button>
                            </div>
                            
                            <div style="max-height: 400px; overflow-y: auto;" class="mb-3">
                                <ul class="list-group">
                                    <?php if (count($participants) > 0): ?>
                                        <?php foreach ($participants as $p): ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <label class="form-check-label w-75 d-flex align-items-center">
                                                    <input 
                                                        class="form-check-input me-3 participant-checkbox" 
                                                        type="checkbox" 
                                                        name="participant_ids[]" 
                                                        value="<?php echo $p['id']; ?>" 
                                                        <?php echo $p['is_winner'] ? 'disabled' : ''; ?>
                                                    >
                                                    <span class="<?php 
                                                        if ($p['is_winner']) {
                                                            echo 'fw-bold text-danger text-decoration-line-through';
                                                        } else if ($p['is_active']) {
                                                            echo 'fw-bold text-dark';
                                                        } else {
                                                            echo 'text-muted';
                                                        }
                                                    ?>">
                                                        <?php echo htmlspecialchars($p['name']); ?>
                                                    </span>
                                                </label>
                                                <span class="badge rounded-pill 
                                                    <?php 
                                                        if ($p['is_winner']) {
                                                            echo 'bg-danger';
                                                        } else if ($p['is_active']) {
                                                            echo 'bg-success';
                                                        } else {
                                                            echo 'bg-secondary';
                                                        }
                                                    ?> me-2">
                                                    <?php 
                                                        if ($p['is_winner']) {
                                                            echo '<i class="fas fa-crown"></i> WINNER';
                                                        } else if ($p['is_active']) {
                                                            echo 'Active';
                                                        } else {
                                                            echo 'Inactive';
                                                        }
                                                    ?>
                                                </span>
                                                <span class="badge bg-secondary rounded-pill"><?php echo $p['id']; ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="alert alert-info text-center mt-3" role="alert">
                                            <i class="fas fa-info-circle"></i> No participants found.
                                        </div>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const selectAllBtn = document.getElementById('selectAllBtn');
            const checkboxes = document.querySelectorAll('.participant-checkbox:not(:disabled)');
            const startDrawBtn = document.getElementById('startDrawBtn');

            // --- Select All/None Logic ---
            let allSelected = false; 
            selectAllBtn.addEventListener('click', () => {
                allSelected = !allSelected;
                checkboxes.forEach(cb => {
                    cb.checked = allSelected;
                });
                selectAllBtn.textContent = allSelected ? 'Deselect All' : 'Select All';
            });
            
            // --- Draw Logic: Send signal to DB (NO REDIRECTION) ---
            startDrawBtn.addEventListener('click', () => {
                startDrawBtn.disabled = true;
                startDrawBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Sending Signal...';
                
                fetch('manage.php', {
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