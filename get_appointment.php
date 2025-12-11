<?php
session_start();
include 'config.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(new stdClass());
    exit;
}

$stmt = $pdo->prepare("
    SELECT a.*, 
           CONCAT(p.last_name, ' ', p.first_name) as patient_name,
           CONCAT(d.last_name, ' ', d.first_name, ' – ', d.specialization) as doctor_full,
           s.name as service_name
    FROM appointments a
    JOIN patients p ON a.patient_id = p.id
    JOIN doctors d ON a.doctor_id = d.id
    JOIN services s ON a.service_id = s.id
    WHERE a.id = ?
");
$stmt->execute([$id]);
echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: new stdClass());
?>