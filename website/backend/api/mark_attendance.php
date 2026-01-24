<?php
/**
 * Attendance API - Mark attendance
 * Endpoint for marking student attendance
 */

include_once '../config/cors.php';
include_once '../config/database.php';
include_once '../models/Attendance.php';

$database = new Database();
$db = $database->getConnection();

$attendance = new Attendance($db);

$data = json_decode(file_get_contents("php://input"));

if(!empty($data->student_id) && !empty($data->teacher_id)) {
    $attendance->student_id = $data->student_id;
    $attendance->teacher_id = $data->teacher_id;
    $attendance->date = date('Y-m-d');
    $attendance->time = date('H:i:s');
    $attendance->status = !empty($data->status) ? $data->status : 'present';
    $attendance->notes = !empty($data->notes) ? $data->notes : '';

    if($attendance->create()) {
        http_response_code(201);
        echo json_encode(array("message" => "Attendance marked successfully."));
    } else {
        http_response_code(503);
        echo json_encode(array("message" => "Unable to mark attendance. Student may have already been marked today."));
    }
} else {
    http_response_code(400);
    echo json_encode(array("message" => "Unable to mark attendance. Student ID and Teacher ID required."));
}
?>
