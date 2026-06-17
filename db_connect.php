<?php
// Database credentials (CHANGE THESE to your actual credentials)
$servername = "localhost"; // Usually 'localhost'
$username = "root";        // Your MySQL username
$password = "";            // Your MySQL password
$dbname = "name_randomizer_db"; // The database name you created

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set error reporting to be more robust
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
?>