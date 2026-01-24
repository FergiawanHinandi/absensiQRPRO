<?php
/**
 * Student Model
 * Handles all student-related database operations
 */

class Student {
    private $conn;
    private $table_name = "students";

    public $id;
    public $nis;
    public $name;
    public $class;
    public $qr_code;
    public $photo;
    public $created_at;
    public $updated_at;

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Get all students
     * @return PDOStatement
     */
    public function read() {
        $query = "SELECT id, nis, name, class, qr_code, photo, created_at 
                  FROM " . $this->table_name . " 
                  ORDER BY class, name ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        return $stmt;
    }

    /**
     * Get single student by ID
     * @return bool
     */
    public function readOne() {
        $query = "SELECT id, nis, name, class, qr_code, photo, created_at 
                  FROM " . $this->table_name . " 
                  WHERE id = ? 
                  LIMIT 0,1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if($row) {
            $this->nis = $row['nis'];
            $this->name = $row['name'];
            $this->class = $row['class'];
            $this->qr_code = $row['qr_code'];
            $this->photo = $row['photo'];
            $this->created_at = $row['created_at'];
            return true;
        }

        return false;
    }

    /**
     * Get student by QR code
     * @return bool
     */
    public function readByQRCode() {
        $query = "SELECT id, nis, name, class, qr_code, photo 
                  FROM " . $this->table_name . " 
                  WHERE qr_code = ? 
                  LIMIT 0,1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->qr_code);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if($row) {
            $this->id = $row['id'];
            $this->nis = $row['nis'];
            $this->name = $row['name'];
            $this->class = $row['class'];
            $this->photo = $row['photo'];
            return true;
        }

        return false;
    }

    /**
     * Create new student
     * @return bool
     */
    public function create() {
        $query = "INSERT INTO " . $this->table_name . "
                  SET nis=:nis, name=:name, class=:class, qr_code=:qr_code, photo=:photo";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":nis", $this->nis);
        $stmt->bindParam(":name", $this->name);
        $stmt->bindParam(":class", $this->class);
        $stmt->bindParam(":qr_code", $this->qr_code);
        $stmt->bindParam(":photo", $this->photo);

        if($stmt->execute()) {
            return true;
        }

        return false;
    }

    /**
     * Update student
     * @return bool
     */
    public function update() {
        $query = "UPDATE " . $this->table_name . "
                  SET nis=:nis, name=:name, class=:class, qr_code=:qr_code, photo=:photo
                  WHERE id=:id";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":nis", $this->nis);
        $stmt->bindParam(":name", $this->name);
        $stmt->bindParam(":class", $this->class);
        $stmt->bindParam(":qr_code", $this->qr_code);
        $stmt->bindParam(":photo", $this->photo);
        $stmt->bindParam(":id", $this->id);

        if($stmt->execute()) {
            return true;
        }

        return false;
    }

    /**
     * Delete student
     * @return bool
     */
    public function delete() {
        $query = "DELETE FROM " . $this->table_name . " WHERE id = ?";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);

        if($stmt->execute()) {
            return true;
        }

        return false;
    }
}
?>
