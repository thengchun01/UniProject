<?php
class Classroom {
    private $db;

    public function __construct($pdo) {
        $this->db = $pdo;
    }

    public function getSettings($teacherId) {
        $stmt = $this->db->prepare("SELECT classroom_settings FROM users WHERE user_id = :id AND role = 'TEACHER'");
        $stmt->execute(['id' => $teacherId]);
        $res = $stmt->fetchColumn();
        if (!$res) return ['disabled_games' => [], 'disabled_tutorials' => []];
        return json_decode($res, true) ?: ['disabled_games' => [], 'disabled_tutorials' => []];
    }

    public function saveSettings($teacherId, $settingsArray) {
        $stmt = $this->db->prepare("UPDATE users SET classroom_settings = :json WHERE user_id = :id AND role = 'TEACHER'");
        $stmt->execute([
            'json' => json_encode($settingsArray),
            'id' => $teacherId
        ]);
    }

    public function getTeacherByClassroom($classroomName) {
        $stmt = $this->db->prepare("SELECT user_id, classroom_settings FROM users WHERE role = 'TEACHER' AND classroom = :cname LIMIT 1");
        $stmt->execute(['cname' => $classroomName]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function isGameDisabledForStudent($studentUser, $gameKey) {
        if ($studentUser['role'] === 'ADMIN' || $studentUser['role'] === 'TEACHER') return false;
        if (empty($studentUser['classroom'])) return false;

        $teacher = $this->getTeacherByClassroom($studentUser['classroom']);
        if (!$teacher || empty($teacher['classroom_settings'])) return false;

        $settings = json_decode($teacher['classroom_settings'], true);
        if (isset($settings['disabled_games']) && in_array($gameKey, $settings['disabled_games'])) {
            return true;
        }
        return false;
    }

    public function isTutorialDisabledForStudent($studentUser, $tutorialId) {
        if ($studentUser['role'] === 'ADMIN' || $studentUser['role'] === 'TEACHER') return false;
        if (empty($studentUser['classroom'])) return false;

        $teacher = $this->getTeacherByClassroom($studentUser['classroom']);
        if (!$teacher || empty($teacher['classroom_settings'])) return false;

        $settings = json_decode($teacher['classroom_settings'], true);
        if (isset($settings['disabled_tutorials']) && in_array($tutorialId, $settings['disabled_tutorials'])) {
            return true;
        }
        return false;
    }
}
?>
