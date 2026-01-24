<?php
/**
 * Attendance Model
 * Handles all attendance-related database operations
 */

class Attendance {
    private $conn;
    private $table_name = "attendance";

    public $id;
    public $student_id;
    public $teacher_id;
    public $date;
    public $time;
    public $status;
    public $notes;
    public $created_at;

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Get all attendance records
     * @return PDOStatement
     */
    public function read() {
        $query = "SELECT a.id, a.student_id, a.teacher_id, a.date, a.time, a.status, a.notes, a.created_at,
                         s.name as student_name, s.nis, s.class,
                         t.name as teacher_name
                  FROM " . $this->table_name . " a
                  LEFT JOIN students s ON a.student_id = s.id
                  LEFT JOIN teachers t ON a.teacher_id = t.id
                  ORDER BY a.date DESC, a.time DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        return $stmt;
    }

    /**
     * Get attendance by date
     * @return PDOStatement
     */
    public function readByDate() {
        $query = "SELECT a.id, a.student_id, a.teacher_id, a.date, a.time, a.status, a.notes,
                         s.name as student_name, s.nis, s.class,
                         t.name as teacher_name
                  FROM " . $this->table_name . " a
                  LEFT JOIN students s ON a.student_id = s.id
                  LEFT JOIN teachers t ON a.teacher_id = t.id
                  WHERE a.date = ?
                  ORDER BY a.time DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->date);
        $stmt->execute();

        return $stmt;
    }

    /**
     * Get attendance by student
     * @return PDOStatement
     */
    public function readByStudent() {
        $query = "SELECT a.id, a.date, a.time, a.status, a.notes,
                         t.name as teacher_name
                  FROM " . $this->table_name . " a
                  LEFT JOIN teachers t ON a.teacher_id = t.id
                  WHERE a.student_id = ?
                  ORDER BY a.date DESC, a.time DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->student_id);
        $stmt->execute();

        return $stmt;
    }

    /**
     * Create attendance record
     * @return bool
     */
    public function create() {
        // Check if attendance already exists for this student today
        $check_query = "SELECT id FROM " . $this->table_name . " 
                       WHERE student_id = ? AND date = ?";
        
        $check_stmt = $this->conn->prepare($check_query);
        $check_stmt->bindParam(1, $this->student_id);
        $check_stmt->bindParam(2, $this->date);
        $check_stmt->execute();

        if($check_stmt->rowCount() > 0) {
            return false; // Already marked attendance today
        }

        $query = "INSERT INTO " . $this->table_name . "
                  SET student_id=:student_id, teacher_id=:teacher_id, date=:date, 
                      time=:time, status=:status, notes=:notes";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":student_id", $this->student_id);
        $stmt->bindParam(":teacher_id", $this->teacher_id);
        $stmt->bindParam(":date", $this->date);
        $stmt->bindParam(":time", $this->time);
        $stmt->bindParam(":status", $this->status);
        $stmt->bindParam(":notes", $this->notes);

        if($stmt->execute()) {
            return true;
        }

        return false;
    }

    /**
     * Update attendance
     * @return bool
     */
    public function update() {
        $query = "UPDATE " . $this->table_name . "
                  SET status=:status, notes=:notes
                  WHERE id=:id";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":status", $this->status);
        $stmt->bindParam(":notes", $this->notes);
        $stmt->bindParam(":id", $this->id);

        if($stmt->execute()) {
            return true;
        }

        return false;
    }

    /**
     * Delete attendance
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
