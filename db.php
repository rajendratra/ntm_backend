<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */
 //Asia/Kolkata
error_reporting(1);

ini_set('display_errors', 'On');
date_default_timezone_set('Asia/Kolkata');
$script_tz = date_default_timezone_get();


$host = "localhost";
$user = "root";
$pass = "Fdow93sd48MF3PEugNTT%%$$##@@!!";
$dbname = "BlueBytes";
$con = mysql_connect($host,$user,$pass);
$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
/**
 * Get mysqli database connection
 * @return mysqli
 */
function getDBConnection() {
    static $conn = null;
    
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if ($conn->connect_error) {
            error_log("Database connection failed: " . $conn->connect_error);
            return null;
        }
        
        $conn->set_charset("utf8mb4");
    }
    
    return $conn;
}

// Get connection for backward compatibility
 $conn = getDBConnection();

// Log errors to file instead of displaying
function logError($message) {
    $logFile = __DIR__ . '/error_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}
?>

