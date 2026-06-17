<?php
// index.php (COMPLETE CODE)
require_once 'db_connect.php';

// --- Fetch Current Draw Number AND Status ---
$sql_counter = "SELECT current_draw_number, randomizer_status FROM draw_counter WHERE id = 1";
$result_counter = $conn->query($sql_counter)->fetch_assoc();
$current_draw_number = $result_counter['current_draw_number'] ?? 0;
$initial_status = $result_counter['randomizer_status'] ?? 'IDLE'; 
// --- End Counter Fetch ---

// --- Logic to Retrieve all Participant Names (Active and Inactive) for the Spin Effect ---
$participants = [];
// Query all participants for the visual spin
$sql_select = "SELECT name FROM participants"; 
$result = $conn->query($sql_select);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $participants[] = $row;
    }
}

$participant_names_json = json_encode(array_column($participants, 'name'));

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Full-Screen Name Randomizer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        body, html {
            height: 100%;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            background-color: #f0f2f5; 
        }
        .randomizer-container {
            text-align: center;
            max-width: 90%;
        }
        .random-result {
            font-size: 5rem; 
            font-weight: bold;
            padding: 50px;
            margin-bottom: 40px;
            border: 5px solid #0d6efd;
            border-radius: 15px;
            min-height: 250px; 
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: white;
            transition: all 0.5s ease-in-out;
        }
        .spinning {
            animation: shake 0.1s infinite;
        }
        @keyframes shake {
            0% { transform: translate(1px, 1px) rotate(0deg); }
            50% { transform: translate(-1px, -2px) rotate(-1deg); }
            100% { transform: translate(-3px, 0px) rotate(1deg); }
        }
    </style>
</head>
<body>

    <div class="randomizer-container">
        <h1 class="display-3 mb-3 text-dark">🏆 The Grand Randomizer 🏆</h1>
        <p class="h4 text-muted mb-4">Upcoming Draw: #<span id="drawCounter"><?php echo $current_draw_number + 1; ?></span></p>
        
        <div class="random-result shadow-lg" id="randomNameDisplay">
            <?php 
            // Display message based on initial status
            echo ($initial_status == 'START') ? 'Receiving signal to start draw...' : 'Click the Button or Start Remotely!';
            ?>
        </div>

        <button id="randomizeBtn" class="btn btn-success btn-lg mt-4 w-50" <?php echo ($initial_status == 'START') ? 'disabled' : ''; ?>>
            <i class="fas fa-wand-magic-sparkles"></i> Start 10-Second Draw!
        </button>
        
        <a href="manage.php" class="btn btn-secondary btn-sm position-absolute top-0 start-0 m-3">
            <i class="fas fa-list"></i> Manage Participants
        </a>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        const participantNames = <?php echo $participant_names_json; ?>;
        const randomizeBtn = document.getElementById('randomizeBtn');
        const display = document.getElementById('randomNameDisplay');
        const drawCounterDisplay = document.getElementById('drawCounter');
        let spinInterval;
        let isDrawing = <?php echo ($initial_status == 'START') ? 'true' : 'false'; ?>;

        // --- Core Draw Functions ---
        
        function startSpin() {
            if (participantNames.length === 0) {
                display.innerHTML = 'No names to draw! Go to Manage.';
                randomizeBtn.disabled = true;
                return;
            }

            randomizeBtn.disabled = true;
            display.classList.add('spinning');
            display.style.backgroundColor = '#fff3cd'; 
            display.style.borderColor = '#ffc107'; 
            isDrawing = true;
            
            spinInterval = setInterval(() => {
                const randomIndex = Math.floor(Math.random() * participantNames.length);
                display.innerHTML = participantNames[randomIndex];
            }, 100); 
        }

        function stopSpin(duration = 10000) { 
            startSpin();

            setTimeout(() => {
                clearInterval(spinInterval);
                display.classList.remove('spinning');
                fetchWinner();
            }, duration);
        }

        function fetchWinner() {
            display.innerHTML = 'Finalizing...';
            
            fetch('randomize.php')
                .then(response => response.json())
                .then(data => {
                    randomizeBtn.disabled = false; 
                    isDrawing = false; // Draw finished
                    resetStatus(); // Reset the DB status after the draw is complete
                    
                    if (data.success) {
                        display.innerHTML = data.name;
                        display.style.backgroundColor = '#d1e7dd'; 
                        display.style.borderColor = '#0f5132'; 
                        if (data.draw_number) {
                            drawCounterDisplay.textContent = data.draw_number + 1;
                        }
                    } else {
                        display.innerHTML = data.name; 
                        display.style.backgroundColor = '#f8d7da'; 
                        display.style.borderColor = '#842029'; 
                    }
                })
                .catch(error => {
                    randomizeBtn.disabled = false;
                    isDrawing = false;
                    resetStatus(); 
                    display.innerHTML = 'System Error! Could not reach server.';
                    display.style.backgroundColor = '#f8d7da'; 
                    display.style.borderColor = '#842029';
                    console.error('Fetch Error:', error);
                });
        }
        
        // --- Status Management Functions ---
        
        function checkStatus() {
            // Only check if we are not currently running a draw
            if (isDrawing) return;
            
            fetch('get_status.php') // Use new file to quickly fetch only the status
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'START') {
                        // Received signal to start!
                        stopSpin(10000); 
                    }
                })
                .catch(error => {
                    console.error('Status check error:', error);
                });
        }
        
        function resetStatus() {
            // Tell the DB that the draw is complete
             fetch('set_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'status=IDLE'
            });
        }

        // --- Initialization ---
        
        // Local button click logic (user manually presses the button)
        randomizeBtn.addEventListener('click', () => {
            if (participantNames.length > 0) {
                 stopSpin(10000); 
            } else {
                 display.innerHTML = 'No active names! Go to Manage.';
                 display.style.backgroundColor = '#f8d7da'; 
                 display.style.borderColor = '#842029';
            }
        });

        // Start checking status every 1 second
        setInterval(checkStatus, 1000);

        // If the page loaded while the status was already START (e.g., from a redirect), start the draw immediately
        if (isDrawing) {
            stopSpin(10000);
        }
    </script>

</body>
</html>