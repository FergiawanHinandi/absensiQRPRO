<?php
/**
 * Student API - Get student by QR code
 * Endpoint for scanning QR code and getting student info
 */

include_once '../config/cors.php';
include_once '../config/database.php';
include_once '../models/Student.php';

$database = new Database();
$db = $database->getConnection();

$student = new Student($db);

$data = json_decode(file_get_contents("php://input"));

if(!empty($data->qr_code)) {
    $student->qr_code = $data->qr_code;

    if($student->readByQRCode()) {
        http_response_code(200);
        echo json_encode(array(
            "message" => "Student found",
            "data" => array(
                "id" => $student->id,
                "nis" => $student->nis,
                "name" => $student->name,
                "class" => $student->class,
                "qr_code" => $student->qr_code,
                "photo" => $student->photo
            )
        ));
    } else {
        http_response_code(404);
        echo json_encode(array("message" => "Student not found."));
    }
} else {
    http_response_code(400);
    echo json_encode(array("message" => "QR code required."));
}
?>
