<?php
class User {
    private $db;

    public function __construct($pdo) {
        $this->db = $pdo;
    }

    public function getUser($userId) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && isset($user['role']) && function_exists('normalize_role')) {
            $user['role'] = normalize_role($user['role']);
        } elseif ($user && isset($user['role'])) {
            $user['role'] = strtoupper(trim((string) $user['role']));
        }
        return $user;
    }

    public function addExperience($userId, $xpAmount) {
        $user = $this->getUser($userId);
        if (!$user) return false;

        $newXp = $user['experience'] + $xpAmount;
        $newLevel = $this->calculateLevel($newXp);

        $stmt = $this->db->prepare("UPDATE users SET experience = :xp, level = :lvl WHERE user_id = :id");
        $stmt->execute([
            'xp' => $newXp,
            'lvl' => $newLevel,
            'id' => $userId
        ]);
        
        return [
            'experience' => $newXp,
            'level' => $newLevel,
            'level_up' => $newLevel > $user['level']
        ];
    }

    private function calculateLevel($xp) {
        // Simple leveling curve: Level = 1 + floor(sqrt(XP / 100))
        // 0 XP = Level 1
        // 100 XP = Level 2
        // 400 XP = Level 3
        // 900 XP = Level 4
        return 1 + floor(sqrt($xp / 100));
    }

    public function updateRecord($userId, $gameKey, $score) {
        $user = $this->getUser($userId);
        if (!$user) return false;

        $records = [];
        if (!empty($user['records'])) {
            $records = json_decode($user['records'], true);
            if (!is_array($records)) $records = [];
        }

        if (!isset($records[$gameKey]) || $score > $records[$gameKey]) {
            $records[$gameKey] = $score;
            
            $stmt = $this->db->prepare("UPDATE users SET records = :records WHERE user_id = :id");
            $stmt->execute([
                'records' => json_encode($records),
                'id' => $userId
            ]);
            return true;
        }
        return false;
    }

    public function getRecords($userId) {
        $user = $this->getUser($userId);
        if (!$user || empty($user['records'])) return [];
        return json_decode($user['records'], true) ?: [];
    }
}
?>
