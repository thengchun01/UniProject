<?php
class Tutorial {
    private $db;

    public function __construct($pdo) {
        $this->db = $pdo;
    }

    public function getAllTopics() {
        $stmt = $this->db->query("SELECT * FROM tutorial_topic ORDER BY order_index ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSectionsByTopic($topicId) {
        $stmt = $this->db->prepare("SELECT * FROM tutorial_section WHERE tutorial_id = :tid ORDER BY order_index ASC");
        $stmt->execute(['tid' => $topicId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function initUserProgress($userId) {
        // Fetch all sections
        $stmt = $this->db->query("SELECT section_id FROM tutorial_section");
        $sections = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($sections)) return;

        // Insert into user_progress if not exists
        $query = "INSERT IGNORE INTO user_progress (user_id, section_id, is_completed, last_accessed) VALUES ";
        $values = [];
        $params = [];
        foreach ($sections as $i => $secId) {
            $values[] = "(:uid$i, :sec$i, 0, CURRENT_TIMESTAMP)";
            $params["uid$i"] = $userId;
            $params["sec$i"] = $secId;
        }

        $query .= implode(', ', $values);
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
    }

    public function markCompleted($userId, $sectionId) {
        // Check if already completed
        $stmt = $this->db->prepare("SELECT is_completed FROM user_progress WHERE user_id = :uid AND section_id = :sid");
        $stmt->execute(['uid' => $userId, 'sid' => $sectionId]);
        $progress = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($progress) {
            if ($progress['is_completed'] == 0) {
                $update = $this->db->prepare("UPDATE user_progress SET is_completed = 1, completed_at = CURRENT_TIMESTAMP WHERE user_id = :uid AND section_id = :sid");
                $update->execute(['uid' => $userId, 'sid' => $sectionId]);
                return true; // Newly completed, should award XP
            }
            return false; // Already completed
        } else {
            // Doesn't exist, insert as completed
            $insert = $this->db->prepare("INSERT INTO user_progress (user_id, section_id, is_completed, completed_at, last_accessed) VALUES (:uid, :sid, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $insert->execute(['uid' => $userId, 'sid' => $sectionId]);
            return true;
        }
    }

    public function getUserProgress($userId, $sectionId) {
        $stmt = $this->db->prepare("SELECT * FROM user_progress WHERE user_id = :uid AND section_id = :sid");
        $stmt->execute(['uid' => $userId, 'sid' => $sectionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
