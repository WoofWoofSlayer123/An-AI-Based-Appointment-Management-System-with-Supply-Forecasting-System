<?php
$servername = "localhost";
$username = "root";
$password = "123456789";
$dbname = "health_center_system";

$conn = new mysqli($servername, $username, $password, $dbname);

if($conn -> connect_error){
    die("Connection Failed: " . $conn -> connect_error);
}
?>