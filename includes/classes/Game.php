<?php
class Game {
    private $db;
    
    // Level requirements for games
    private $levelRequirements = [
        'recognition' => 1,
        'identify' => 2,
        'car_race' => 1
    ];

    public function __construct($pdo) {
        $this->db = $pdo;
    }

    public function getLevelRequirement($gameKey) {
        return $this->levelRequirements[$gameKey] ?? 1;
    }

    public function canUserPlay($user, $gameKey) {
        if ($user['role'] === 'ADMIN' || $user['role'] === 'TEACHER') {
            return true;
        }
        $req = $this->getLevelRequirement($gameKey);
        return $user['level'] >= $req;
    }

    public function logGameActivity($userId, $gameKey, $title, $score, $accuracy, $duration) {
        $stmt = $this->db->prepare(
            "INSERT INTO user_activity_log (user_id, activity_type, activity_title, attribute, created_at)
             VALUES (:user_id, :type, :title, :attr, CURRENT_TIMESTAMP)"
        );
        
        $attribute = json_encode([
            'score' => $score,
            'accuracy' => $accuracy,
            'duration_seconds' => $duration
        ]);

        $stmt->execute([
            'user_id' => $userId,
            'type' => 'GAME',
            'title' => $title,
            'attr' => $attribute
        ]);
    }

    public function getGlobalRanking($gameKey, $limit = 10) {
        // Since high scores are stored in JSON `records`, we can't easily ORDER BY a JSON key in MariaDB 10.4 directly using standard SQL without JSON_EXTRACT.
        // Assuming MariaDB >= 10.2 which supports JSON_EXTRACT:
        $stmt = $this->db->prepare(
            "SELECT username, JSON_EXTRACT(records, CONCAT('$.', :gameKey)) as score
             FROM users 
             WHERE records LIKE :likePattern
             ORDER BY CAST(JSON_EXTRACT(records, CONCAT('$.', :gameKey2)) AS DECIMAL) DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':gameKey', $gameKey, PDO::PARAM_STR);
        $stmt->bindValue(':gameKey2', $gameKey, PDO::PARAM_STR);
        $stmt->bindValue(':likePattern', '%"'.$gameKey.'":%', PDO::PARAM_STR);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getClassroomRanking($gameKey, $classroom, $limit = 10) {
        $stmt = $this->db->prepare(
            "SELECT username, JSON_EXTRACT(records, CONCAT('$.', :gameKey)) as score
             FROM users 
             WHERE classroom = :classroom AND records LIKE :likePattern
             ORDER BY CAST(JSON_EXTRACT(records, CONCAT('$.', :gameKey2)) AS DECIMAL) DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':gameKey', $gameKey, PDO::PARAM_STR);
        $stmt->bindValue(':gameKey2', $gameKey, PDO::PARAM_STR);
        $stmt->bindValue(':classroom', $classroom, PDO::PARAM_STR);
        $stmt->bindValue(':likePattern', '%"'.$gameKey.'":%', PDO::PARAM_STR);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
