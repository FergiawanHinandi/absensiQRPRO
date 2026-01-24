<?php
/**
 * Students API - Get all students
 * Endpoint for getting list of all students
 */

include_once '../config/cors.php';
include_once '../config/database.php';
include_once '../models/Student.php';

$database = new Database();
$db = $database->getConnection();

$student = new Student($db);

$stmt = $student->read();
$num = $stmt->rowCount();

if($num > 0) {
    $students_arr = array();
    $students_arr["records"] = array();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        extract($row);

        $student_item = array(
            "id" => $id,
            "nis" => $nis,
            "name" => $name,
            "class" => $class,
            "qr_code" => $qr_code,
            "photo" => $photo,
            "created_at" => $created_at
        );

        array_push($students_arr["records"], $student_item);
    }

    http_response_code(200);
    echo json_encode($students_arr);
} else {
    http_response_code(404);
    echo json_encode(array("message" => "No students found."));
}
?>
