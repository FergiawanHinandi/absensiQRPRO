<?php
/**
 * Attendance API - Get attendance records
 * Endpoint for getting attendance records by date
 */

include_once '../config/cors.php';
include_once '../config/database.php';
include_once '../models/Attendance.php';

$database = new Database();
$db = $database->getConnection();

$attendance = new Attendance($db);

// Get date parameter from URL
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$attendance->date = $date;

$stmt = $attendance->readByDate();
$num = $stmt->rowCount();

if($num > 0) {
    $attendance_arr = array();
    $attendance_arr["records"] = array();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        extract($row);

        $attendance_item = array(
            "id" => $id,
            "student_id" => $student_id,
            "student_name" => $student_name,
            "nis" => $nis,
            "class" => $class,
            "teacher_id" => $teacher_id,
            "teacher_name" => $teacher_name,
            "date" => $date,
            "time" => $time,
            "status" => $status,
            "notes" => $notes
        );

        array_push($attendance_arr["records"], $attendance_item);
    }

    http_response_code(200);
    echo json_encode($attendance_arr);
} else {
    http_response_code(404);
    echo json_encode(array("message" => "No attendance records found for this date."));
}
?>
