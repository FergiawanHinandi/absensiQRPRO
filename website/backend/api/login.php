<?php
/**
 * Teacher Login API
 * Endpoint for teacher authentication
 */

include_once '../config/cors.php';
include_once '../config/database.php';
include_once '../models/Teacher.php';

$database = new Database();
$db = $database->getConnection();

$teacher = new Teacher($db);

$data = json_decode(file_get_contents("php://input"));

if(!empty($data->email) && !empty($data->password)) {
    $teacher->email = $data->email;
    $teacher->password = $data->password;

    if($teacher->login()) {
        http_response_code(200);
        echo json_encode(array(
            "message" => "Login successful",
            "data" => array(
                "id" => $teacher->id,
                "nip" => $teacher->nip,
                "name" => $teacher->name,
                "email" => $teacher->email,
                "phone" => $teacher->phone,
                "photo" => $teacher->photo
            )
        ));
    } else {
        http_response_code(401);
        echo json_encode(array("message" => "Login failed. Invalid email or password."));
    }
} else {
    http_response_code(400);
    echo json_encode(array("message" => "Unable to login. Email and password required."));
}
?>
