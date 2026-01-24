<?php
/**
 * Teacher Model
 * Handles all teacher-related database operations
 */

class Teacher {
    private $conn;
    private $table_name = "teachers";

    public $id;
    public $nip;
    public $name;
    public $email;
    public $password;
    public $phone;
    public $photo;
    public $created_at;
    public $updated_at;

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Get all teachers
     * @return PDOStatement
     */
    public function read() {
        $query = "SELECT id, nip, name, email, phone, photo, created_at 
                  FROM " . $this->table_name . " 
                  ORDER BY name ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        return $stmt;
    }

    /**
     * Get single teacher by ID
     * @return bool
     */
    public function readOne() {
        $query = "SELECT id, nip, name, email, phone, photo, created_at 
                  FROM " . $this->table_name . " 
                  WHERE id = ? 
                  LIMIT 0,1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if($row) {
            $this->nip = $row['nip'];
            $this->name = $row['name'];
            $this->email = $row['email'];
            $this->phone = $row['phone'];
            $this->photo = $row['photo'];
            $this->created_at = $row['created_at'];
            return true;
        }

        return false;
    }

    /**
     * Login teacher
     * @return bool
     */
    public function login() {
        $query = "SELECT id, nip, name, email, password, phone, photo 
                  FROM " . $this->table_name . " 
                  WHERE email = ? 
                  LIMIT 0,1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->email);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if($row && password_verify($this->password, $row['password'])) {
            $this->id = $row['id'];
            $this->nip = $row['nip'];
            $this->name = $row['name'];
            $this->phone = $row['phone'];
            $this->photo = $row['photo'];
            return true;
        }

        return false;
    }

    /**
     * Create new teacher
     * @return bool
     */
    public function create() {
        $query = "INSERT INTO " . $this->table_name . "
                  SET nip=:nip, name=:name, email=:email, password=:password, phone=:phone, photo=:photo";

        $stmt = $this->conn->prepare($query);

        $hashed_password = password_hash($this->password, PASSWORD_DEFAULT);

        $stmt->bindParam(":nip", $this->nip);
        $stmt->bindParam(":name", $this->name);
        $stmt->bindParam(":email", $this->email);
        $stmt->bindParam(":password", $hashed_password);
        $stmt->bindParam(":phone", $this->phone);
        $stmt->bindParam(":photo", $this->photo);

        if($stmt->execute()) {
            return true;
        }

        return false;
    }

    /**
     * Update teacher
     * @return bool
     */
    public function update() {
        $query = "UPDATE " . $this->table_name . "
                  SET nip=:nip, name=:name, email=:email, phone=:phone, photo=:photo
                  WHERE id=:id";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":nip", $this->nip);
        $stmt->bindParam(":name", $this->name);
        $stmt->bindParam(":email", $this->email);
        $stmt->bindParam(":phone", $this->phone);
        $stmt->bindParam(":photo", $this->photo);
        $stmt->bindParam(":id", $this->id);

        if($stmt->execute()) {
            return true;
        }

        return false;
    }

    /**
     * Delete teacher
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
