<?php
require_once __DIR__ . '/includes/config.php';
$stmt = db()->query("SELECT section_id, tutorial_id, order_index, content FROM tutorial_section");
echo json_encode($stmt->fetchAll());
