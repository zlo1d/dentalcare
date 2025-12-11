<?php
session_start();
include 'config.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Функция для форматирования времени (убрал лишнее двоеточие)
function formatTimeForDisplay($time) {
    // Убираем секунды, если они есть
    if (strlen($time) === 8) { // "HH:MM:SS"
        return substr($time, 0, 5); // "HH:MM"
    }
    return $time;
}

// Неделя из GET или текущая
$weekParam = $_GET['week'] ?? date('Y-\WW');
if (!preg_match('/^\d{4}-W\d{2}$/', $weekParam)) {
    $weekParam = date('Y-\WW');
}
list($year, $week) = explode('-W', $weekParam);

// Генерация дат недели (Пн–Вс)
$dates = [];
for ($day = 1; $day <= 7; $day++) {
    $date = new DateTime();
    $date->setISODate((int)$year, (int)$week, $day);
    $dates[] = $date->format('Y-m-d');
}
$startDate = $dates[0];
$endDate = $dates[6];
$today = date('Y-m-d');

// Загрузка данных (ПЕРЕНЕСЕНО В НАЧАЛО)
$doctors = $pdo->query("SELECT id, last_name, first_name, specialization FROM doctors WHERE status != 'offline' ORDER BY last_name")->fetchAll();
$patients = $pdo->query("SELECT id, first_name, last_name, phone FROM patients ORDER BY last_name")->fetchAll();
$services = $pdo->query("SELECT id, name FROM services")->fetchAll();

// Фильтры
$doctorFilter = $_GET['doctor'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';

// ОБРАБОТКА БЫСТРОЙ ЗАПИСИ (ПЕРЕМЕЩЕНА ПОСЛЕ ОПРЕДЕЛЕНИЯ ПЕРЕМЕННЫХ)
$success = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_appointment') {
    $patientId = (int)($_POST['patientId'] ?? 0);
    $doctorId = (int)($_POST['doctorId'] ?? 0);
    $serviceId = (int)($_POST['serviceId'] ?? 0);
    $date = $_POST['date'] ?? '';
    $time = $_POST['time'] ?? '';
    
    // Отладка
    error_log("Создание записи: patientId=$patientId, doctorId=$doctorId, serviceId=$serviceId, date=$date, time=$time");
    
    if (!$patientId || !$doctorId || !$serviceId || !$date || !$time) {
        $error = "Заполните все обязательные поля!";
    } else {
        try {
            // Проверка занятости времени
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ?");
            $checkStmt->execute([$doctorId, $date, $time]);
            $exists = $checkStmt->fetchColumn();
            
            if ($exists > 0) {
                $error = "Это время уже занято у выбранного врача!";
            } else {
                // Создание записи
                $stmt = $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, service_id, appointment_date, appointment_time, status) VALUES (?, ?, ?, ?, ?, 'confirmed')");
                
                if ($stmt->execute([$patientId, $doctorId, $serviceId, $date, $time])) {
                    $lastId = $pdo->lastInsertId();
                    
                    // Получаем информацию о новой записи для отображения
                    $newAppointmentStmt = $pdo->prepare("
                        SELECT a.*, 
                               CONCAT(p.last_name, ' ', p.first_name) as patient_name,
                               CONCAT(d.last_name, ' ', d.first_name, ' – ', d.specialization) as doctor_full
                        FROM appointments a
                        JOIN patients p ON a.patient_id = p.id
                        JOIN doctors d ON a.doctor_id = d.id
                        WHERE a.id = ?
                    ");
                    $newAppointmentStmt->execute([$lastId]);
                    $newAppointment = $newAppointmentStmt->fetch(PDO::FETCH_ASSOC);
                    
                    $success = "✅ Запись #$lastId создана успешно! " . 
                               "Пациент: " . ($newAppointment['patient_name'] ?? '') . ", " .
                               "Врач: " . ($newAppointment['doctor_full'] ?? '');
                    
                    // ОБЯЗАТЕЛЬНО: обновляем переменную $appointments после создания новой записи
                    // Перезагружаем страницу с тем же GET-параметром недели
                    header("Location: расписание.php?week=$weekParam&success=1");
                    exit;
                } else {
                    $error = "Ошибка при создании записи!";
                }
            }
        } catch (PDOException $e) {
            $error = "Ошибка при создании записи: " . $e->getMessage();
            error_log("PDO Error: " . $e->getMessage());
        }
    }
}

// Записи на неделю (ВЫПОЛНЯЕТСЯ ПОСЛЕ ВСЕХ ДЕЙСТВИЙ)
$stmt = $pdo->prepare("
    SELECT a.*, 
           CONCAT(p.last_name, ' ', p.first_name) as patient_name,
           p.phone as patient_phone,
           CONCAT(d.last_name, ' ', d.first_name, ' – ', d.specialization) as doctor_full,
           s.name as service_name
    FROM appointments a
    JOIN patients p ON a.patient_id = p.id
    JOIN doctors d ON a.doctor_id = d.id
    JOIN services s ON a.service_id = s.id
    WHERE a.appointment_date BETWEEN ? AND ?
    ORDER BY a.appointment_date, a.appointment_time
");
$stmt->execute([$startDate, $endDate]);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Статистика для карточек
$totalAppointments = count($appointments);
$confirmedAppointments = array_filter($appointments, function($a) { return $a['status'] === 'confirmed'; });
$todayAppointmentsCount = array_filter($appointments, function($a) use ($today) { return $a['appointment_date'] === $today; });

// Проверяем success из GET
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success = "✅ Запись создана успешно!";
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Расписание приёмов</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #8b5cf6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;
            --dark: #1f2937;
            --light: #f8fafc;
            --gray: #6b7280;
            --border: #e5e7eb;
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-card: #ffffff;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --radius: 12px;
            --radius-lg: 16px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --gradient: linear-gradient(135deg, var(--primary), var(--secondary));
        }
        
        [data-theme="dark"] {
            --bg-primary: #0f172a;
            --bg-secondary: #1e293b;
            --bg-card: #334155;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --border: #475569;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
            transition: var(--transition);
        }

        .dashboard {
            display: grid;
            grid-template-columns: 280px 1fr;
            min-height: 100vh;
        }

        .sidebar {
            background: var(--bg-card);
            border-right: 1px solid var(--border);
            padding: 2rem 0;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 0 1.5rem 2rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1rem;
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.25rem;
            font-weight: 700;
        }

        .logo-icon {
            color: var(--primary);
            font-size: 1.75rem;
        }

        .brand-text {
            background: var(--gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .sidebar-menu {
            list-style: none;
        }

        .menu-item {
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--text-secondary);
            text-decoration: none;
            transition: var(--transition);
            border-left: 4px solid transparent;
            margin: 0.25rem 0;
        }

        .menu-item:hover, .menu-item.active {
            background: var(--bg-secondary);
            color: var(--primary);
            border-left-color: var(--primary);
        }

        .main-content {
            padding: 2rem;
            background: var(--bg-secondary);
            overflow-x: auto;
        }

        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .header-title h1 {
            font-size: 2rem;
            font-weight: 700;
            background: var(--gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .header-title p {
            color: var(--text-secondary);
            font-size: 1rem;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .theme-toggle {
            background: var(--bg-card);
            border: 1px solid var(--border);
            font-size: 1.25rem;
            color: var(--text-primary);
            cursor: pointer;
            padding: 0.75rem;
            border-radius: var(--radius);
            transition: var(--transition);
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .theme-toggle:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.875rem 1.5rem;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.875rem;
        }

        .btn-primary {
            background: var(--gradient);
            color: white;
            box-shadow: var(--shadow);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--bg-card);
            padding: 1.75rem;
            border-radius: var(--radius-lg);
            border-left: 6px solid var(--primary);
            box-shadow: var(--shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card.success { border-left-color: var(--success); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-card.info { border-left-color: var(--info); }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            background: var(--gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 0.875rem;
            font-weight: 500;
        }

        .stat-icon {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            opacity: 0.1;
            font-size: 3rem;
        }

        .schedule-section {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            margin-bottom: 2rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-title i {
            color: var(--primary);
        }

        .schedule-nav {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .current-week {
            background: var(--gradient);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            font-weight: 600;
            min-width: 200px;
            text-align: center;
        }

        .schedule-controls {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            background: var(--bg-secondary);
            padding: 1.5rem;
            border-radius: var(--radius);
        }

        .control-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            flex: 1;
            min-width: 200px;
        }

        .control-group label {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.875rem;
        }

        .control-select {
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 0.875rem;
            transition: var(--transition);
        }

        .control-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .schedule-view {
            background: var(--bg-primary);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .schedule-header {
            display: grid;
            grid-template-columns: 120px repeat(7, 1fr);
            background: var(--gradient);
            color: white;
        }

        .schedule-day {
            padding: 1rem;
            text-align: center;
            font-weight: 600;
            border-right: 1px solid rgba(255,255,255,0.2);
            position: relative;
        }

        .schedule-day:last-child { border-right: none; }
        .schedule-day.today { background: rgba(255,255,255,0.2); }
        .schedule-day.weekend { background: rgba(255,255,255,0.1); }

        .schedule-body {
            max-height: 70vh;
            overflow-y: auto;
        }

        .schedule-row {
            display: grid;
            grid-template-columns: 120px repeat(7, 1fr);
            border-bottom: 1px solid var(--border);
            transition: var(--transition);
        }

        .schedule-row:hover {
            background: var(--bg-secondary);
        }

        .schedule-row:last-child { border-bottom: none; }

        .time-slot {
            padding: 1rem;
            border-right: 1px solid var(--border);
            min-height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 0.875rem;
        }

        .schedule-cell {
            padding: 0.5rem;
            border-right: 1px solid var(--border);
            min-height: 80px;
            position: relative;
            transition: var(--transition);
        }

        .schedule-cell:last-child { border-right: none; }

        .schedule-cell.available {
            background: var(--bg-primary);
            cursor: pointer;
        }

        .schedule-cell.available:hover {
            background: #f0f9ff;
            transform: scale(1.02);
        }

        .schedule-cell.busy {
            background: #ffebee;
            cursor: pointer;
            border: 2px solid #f44336;
        }

        .schedule-cell.current {
            background: #f0f9ff;
            border: 2px solid var(--primary);
        }

        .appointment-card {
            background: var(--success);
            color: white;
            padding: 0.5rem;
            border-radius: 6px;
            margin: 0.125rem;
            font-size: 0.7rem;
            cursor: pointer;
            transition: var(--transition);
            border-left: 3px solid rgba(255,255,255,0.3);
            box-shadow: var(--shadow);
        }

        .appointment-card:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg);
        }

        .appointment-card.confirmed { background: var(--success); }
        .appointment-card.pending { background: var(--warning); }
        .appointment-card.cancelled { background: var(--danger); opacity: 0.7; }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 2.5rem;
            width: 100%;
            max-width: 500px;
            box-shadow: var(--shadow-xl);
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid var(--border);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .modal-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: var(--radius);
            transition: var(--transition);
        }

        .modal-close:hover {
            background: var(--bg-secondary);
            color: var(--danger);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 0.875rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--bg-primary);
            color: var(--text-primary);
            transition: var(--transition);
            font-family: inherit;
            font-size: 0.875rem;
        }

        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--bg-card);
            padding: 1.25rem 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow-xl);
            border-left: 4px solid var(--primary);
            display: flex;
            align-items: center;
            gap: 1rem;
            z-index: 1001;
            max-width: 400px;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .notification.success { 
            border-left-color: var(--success); 
            background: #f0fdf4;
        }
        .notification.error { 
            border-left-color: var(--danger); 
            background: #fef2f2;
        }

        .notification-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: var(--radius);
        }

        @media (max-width: 1024px) {
            .dashboard { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .schedule-header, .schedule-row { grid-template-columns: 80px repeat(7, 1fr); }
        }

        @media (max-width: 768px) {
            .main-content { padding: 1rem; }
            .section-header { flex-direction: column; gap: 1rem; align-items: flex-start; }
            .schedule-controls { flex-direction: column; }
            .schedule-header, .schedule-row { grid-template-columns: 60px repeat(7, 1fr); font-size: 0.75rem; }
        }
    </style>
</head>
<body>
    <!-- Уведомления -->
    <?php if (!empty($success)): ?>
    <div class="notification success">
        <i class="fas fa-check-circle"></i>
        <span><?= htmlspecialchars($success) ?></span>
        <button class="notification-close" onclick="this.parentElement.remove()">&times;</button>
    </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
    <div class="notification error">
        <i class="fas fa-exclamation-circle"></i>
        <span><?= htmlspecialchars($error) ?></span>
        <button class="notification-close" onclick="this.parentElement.remove()">&times;</button>
    </div>
    <?php endif; ?>

    <div class="dashboard">
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="nav-brand">
                    <i class="fas fa-tooth logo-icon"></i>
                    <span class="brand-text">Здоровье <strong></strong></span>
                </div>
            </div>
            <ul class="sidebar-menu">
                <li><a href="index.php" class="menu-item"><i class="fas fa-home"></i> Главная</a></li>
                <li><a href="запись.php" class="menu-item"><i class="fas fa-calendar-plus"></i> Запись</a></li>
                <li><a href="пациенты.php" class="menu-item"><i class="fas fa-users"></i> Пациенты</a></li>
                <li><a href="расписание.php" class="menu-item active"><i class="fas fa-calendar-alt"></i> Расписание</a></li>
                <li><a href="врачи.php" class="menu-item"><i class="fas fa-user-md"></i> Врачи</a></li>
                <li><a href="настройки.php" class="menu-item"><i class="fas fa-cog"></i> Настройки</a></li>
            </ul>
        </aside>

        <main class="main-content">
            <!-- Заголовок -->
            <div class="content-header">
                <div class="header-title">
                    <h1>📅 Расписание приёмов</h1>
                    <p>Управление записями пациентов и расписанием врачей</p>
                </div>
                <div class="nav-actions">
                    <button class="theme-toggle" id="themeToggle">
                        <i class="fas fa-moon"></i>
                    </button>
                    <button class="btn btn-outline">
                        <i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['username'] ?? 'Администратор') ?>
                    </button>
                </div>
            </div>

            <!-- Статистика -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= $totalAppointments ?></div>
                    <div class="stat-label">Всего записей на неделю</div>
                    <i class="fas fa-calendar-check stat-icon"></i>
                </div>
                <div class="stat-card success">
                    <div class="stat-value"><?= count($confirmedAppointments) ?></div>
                    <div class="stat-label">Подтверждённые записи</div>
                    <i class="fas fa-check-circle stat-icon"></i>
                </div>
                <div class="stat-card warning">
                    <div class="stat-value"><?= count($todayAppointmentsCount) ?></div>
                    <div class="stat-label">Записей на сегодня</div>
                    <i class="fas fa-clock stat-icon"></i>
                </div>
                <div class="stat-card info">
                    <div class="stat-value"><?= count($doctors) ?></div>
                    <div class="stat-label">Активных врачей</div>
                    <i class="fas fa-user-md stat-icon"></i>
                </div>
            </div>

            <!-- Основное расписание -->
            <div class="schedule-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-calendar-week"></i> Еженедельное расписание
                    </h2>
                    <div class="schedule-nav">
                        <a href="?week=<?= date('Y-\WW', strtotime("$startDate -1 week")) ?>" class="btn btn-outline btn-sm">
                            <i class="fas fa-chevron-left"></i> Назад
                        </a>
                        <div class="current-week">
                            <?= date('d.m.Y', strtotime($startDate)) . ' – ' . date('d.m.Y', strtotime($endDate)) ?>
                        </div>
                        <a href="?week=<?= date('Y-\WW', strtotime("$startDate +1 week")) ?>" class="btn btn-outline btn-sm">
                            Вперёд <i class="fas fa-chevron-right"></i>
                        </a>
                        <button class="btn btn-primary btn-sm" onclick="openQuickAppointment('', '')">
                            <i class="fas fa-plus"></i> Новая запись
                        </button>
                    </div>
                </div>

                <!-- Фильтры -->
                <div class="schedule-controls">
                    <div class="control-group">
                        <label><i class="fas fa-user-md"></i> Врач</label>
                        <select class="control-select" id="doctorFilter" onchange="applyFilters()">
                            <option value="all">Все врачи</option>
                            <?php foreach ($doctors as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= $doctorFilter == $d['id'] ? 'selected' : '' ?>>
                                Др. <?= htmlspecialchars($d['last_name'] . ' ' . $d['first_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="control-group">
                        <label><i class="fas fa-info-circle"></i> Статус</label>
                        <select class="control-select" id="statusFilter" onchange="applyFilters()">
                            <option value="all">Все записи</option>
                            <option value="confirmed" <?= $statusFilter === 'confirmed' ? 'selected' : '' ?>>Подтверждённые</option>
                            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Ожидающие</option>
                            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Отменённые</option>
                        </select>
                    </div>
                </div>

                <!-- Сетка расписания -->
                <div class="schedule-view">
                    <div class="schedule-header">
                        <div class="schedule-day">Время</div>
                        <?php foreach ($dates as $d): ?>
                        <div class="schedule-day <?= $d === $today ? 'today' : '' ?> <?= (date('N', strtotime($d)) >= 6) ? 'weekend' : '' ?>">
                            <?php
                            $names = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
                            $dayName = $names[date('N', strtotime($d)) - 1];
                            $dayNumber = date('d', strtotime($d));
                            echo "<div style='font-size: 0.875rem;'>$dayName</div>";
                            echo "<div style='font-size: 1.125rem; font-weight: 700;'>$dayNumber</div>";
                            ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="schedule-body">
                        <?php
                        $start = 8;
                        $end = 20;
                        
                        for ($h = $start; $h < $end; $h++) {
                            for ($m = 0; $m < 60; $m += 30) {
                                $time = sprintf('%02d:%02d', $h, $m);
                                echo '<div class="schedule-row">';
                                echo "<div class='time-slot'>$time</div>";
                                
                                foreach ($dates as $date) {
                                    $isCurrent = ($date === $today && $time === date('H:i'));
                                    $classes = ['schedule-cell'];
                                    $appointmentsInSlot = [];
                                    
                                    // ПРЯМАЯ ПРОВЕРКА ЗАПИСЕЙ
                                    foreach ($appointments as $appt) {
                                        // ВАЖНО: используем отформатированное время для сравнения
                                        $dbTime = formatTimeForDisplay($appt['appointment_time']);
                                        
                                        if ($appt['appointment_date'] == $date && $dbTime == $time) {
                                            // Проверяем фильтры
                                            $doctorMatch = ($doctorFilter === 'all' || $doctorFilter == $appt['doctor_id']);
                                            $statusMatch = ($statusFilter === 'all' || $statusFilter === $appt['status']);
                                            
                                            if ($doctorMatch && $statusMatch) {
                                                $appointmentsInSlot[] = $appt;
                                            }
                                        }
                                    }
                                    
                                    if (!empty($appointmentsInSlot)) {
                                        $classes[] = 'busy';
                                    } else {
                                        $classes[] = 'available';
                                    }
                                    if ($isCurrent) $classes[] = 'current';
                                    
                                    echo '<div class="' . implode(' ', $classes) . '"';
                                    
                                    if (empty($appointmentsInSlot)) {
                                        echo " onclick=\"openQuickAppointment('$date', '$time')\"";
                                        echo " title='Свободно - кликните для записи'";
                                    } else {
                                        echo " title='Записей: " . count($appointmentsInSlot) . "'";
                                    }
                                    echo '>';
                                    
                                    // ОТОБРАЖАЕМ ЗАПИСИ
                                    foreach ($appointmentsInSlot as $appt) {
                                        $patientShort = substr($appt['patient_name'], 0, 8) . (strlen($appt['patient_name']) > 8 ? '...' : '');
                                        $doctorShort = substr(str_replace('Др. ', '', $appt['doctor_full']), 0, 8) . (strlen($appt['doctor_full']) > 8 ? '...' : '');
                                        
                                        echo "<div class='appointment-card {$appt['status']}' ";
                                        echo "onclick='showAppointmentDetails({$appt['id']})' ";
                                        echo " title='{$appt['patient_name']} → {$appt['doctor_full']}'>";
                                        
                                        echo "<div style='font-weight: 600; font-size: 0.65rem;'>👤 $patientShort</div>";
                                        echo "<div style='font-size: 0.6rem; opacity: 0.9;'>👨‍⚕️ $doctorShort</div>";
                                        
                                        echo '</div>';
                                    }
                                    
                                    if (empty($appointmentsInSlot)) {
                                        echo "<div style='color: var(--text-secondary); font-size: 0.7rem; text-align: center; padding: 0.25rem;'>";
                                        echo "<i class='fas fa-plus' style='font-size: 0.6rem;'></i> Свободно";
                                        echo "</div>";
                                    }
                                    
                                    echo '</div>';
                                }
                                echo '</div>';
                            }
                        }
                        ?>
                    </div>
                </div>
            </div>

            <!-- Быстрые действия -->
            <div class="schedule-section">
                <h2 class="section-title" style="margin-bottom: 1.5rem;">
                    <i class="fas fa-bolt"></i> Быстрые действия
                </h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <div class="action-card" onclick="location.href='?week=<?= date('Y-\WW') ?>'" style="cursor: pointer; background: var(--bg-card); padding: 1rem; border-radius: var(--radius); transition: var(--transition); border: 1px solid var(--border);">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="width: 50px; height: 50px; background: var(--gradient); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.25rem;">
                                <i class="fas fa-calendar-day"></i>
                            </div>
                            <div>
                                <h3 style="margin-bottom: 0.25rem;">Сегодня</h3>
                                <p style="color: var(--text-secondary); font-size: 0.875rem;">Перейти к сегодняшнему дню</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="action-card" onclick="openQuickAppointment('<?= date('Y-m-d') ?>', '09:00')" style="cursor: pointer; background: var(--bg-card); padding: 1rem; border-radius: var(--radius); transition: var(--transition); border: 1px solid var(--border);">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="width: 50px; height: 50px; background: var(--success); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.25rem;">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <div>
                                <h3 style="margin-bottom: 0.25rem;">Запись на сегодня</h3>
                                <p style="color: var(--text-secondary); font-size: 0.875rem;">Быстро создать запись</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="action-card" onclick="location.href='?week=2025-W47'" style="cursor: pointer; background: var(--bg-card); padding: 1rem; border-radius: var(--radius); transition: var(--transition); border: 1px solid var(--border);">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="width: 50px; height: 50px; background: var(--info); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.25rem;">
                                <i class="fas fa-calendar"></i>
                            </div>
                            <div>
                                <h3 style="margin-bottom: 0.25rem;">Неделя 21 числа</h3>
                                <p style="color: var(--text-secondary); font-size: 0.875rem;">Перейти к неделе с 21 ноября</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Модальное окно быстрой записи -->
    <div class="modal" id="quickAppointmentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>📝 Новая запись</h2>
                <button class="modal-close" onclick="closeQuickAppointment()">&times;</button>
            </div>
            <form id="quickForm" method="POST">
                <input type="hidden" name="action" value="create_appointment">
                <input type="hidden" name="date" id="modalDate">
                <input type="hidden" name="time" id="modalTime">
                
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Пациент *</label>
                    <select name="patientId" required id="patientSelect">
                        <option value="">Выберите пациента...</option>
                        <?php foreach ($patients as $p): ?>
                        <option value="<?= $p['id'] ?>">
                            <?= htmlspecialchars($p['last_name'] . ' ' . $p['first_name'] . ' 📞 ' . $p['phone']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-user-md"></i> Врач *</label>
                    <select name="doctorId" required id="doctorSelect">
                        <option value="">Выберите врача...</option>
                        <?php foreach ($doctors as $d): ?>
                        <option value="<?= $d['id'] ?>">
                            👨‍⚕️ Др. <?= htmlspecialchars($d['last_name'] . ' ' . $d['first_name'] . ' - ' . $d['specialization']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-stethoscope"></i> Услуга *</label>
                    <select name="serviceId" required id="serviceSelect">
                        <option value="">Выберите услугу...</option>
                        <?php foreach ($services as $s): ?>
                        <option value="<?= $s['id'] ?>">🦷 <?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="background: var(--bg-secondary); padding: 1rem; border-radius: var(--radius); margin-bottom: 1.5rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <i class="fas fa-calendar" style="color: var(--primary);"></i>
                        <strong>Дата:</strong>
                        <span id="selectedDate" style="color: var(--primary); font-weight: 600;"></span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-clock" style="color: var(--primary);"></i>
                        <strong>Время:</strong>
                        <span id="selectedTime" style="color: var(--primary); font-weight: 600;"></span>
                    </div>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary btn-lg" style="width: 100%;">
                        <i class="fas fa-calendar-plus"></i> Создать запись
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Управление темой
        document.addEventListener('DOMContentLoaded', () => {
            const saved = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', saved);
            updateThemeIcon(saved);
            
            document.getElementById('themeToggle').addEventListener('click', () => {
                const current = document.documentElement.getAttribute('data-theme');
                const next = current === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', next);
                localStorage.setItem('theme', next);
                updateThemeIcon(next);
            });
            
            // Проверка выбранных значений в форме
            const quickForm = document.getElementById('quickForm');
            if (quickForm) {
                quickForm.addEventListener('submit', (e) => {
                    const patientId = document.getElementById('patientSelect').value;
                    const doctorId = document.getElementById('doctorSelect').value;
                    const serviceId = document.getElementById('serviceSelect').value;
                    const date = document.getElementById('modalDate').value;
                    const time = document.getElementById('modalTime').value;
                    
                    if (!patientId || !doctorId || !serviceId) {
                        e.preventDefault();
                        alert('Пожалуйста, заполните все поля!');
                    }
                });
            }
        });

        function updateThemeIcon(theme) {
            document.getElementById('themeToggle').innerHTML = theme === 'dark' ? 
                '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        }

        // Фильтры
        function applyFilters() {
            const doctor = document.getElementById('doctorFilter').value;
            const status = document.getElementById('statusFilter').value;
            const url = new URL(window.location);
            url.searchParams.set('doctor', doctor);
            url.searchParams.set('status', status);
            window.location.href = url;
        }

        // Модальные окна
        function openQuickAppointment(date, time) {
            const modalDate = document.getElementById('modalDate');
            const modalTime = document.getElementById('modalTime');
            const selectedDate = document.getElementById('selectedDate');
            const selectedTime = document.getElementById('selectedTime');
            
            // Используем текущую неделю для даты по умолчанию
            const currentDate = date || '<?= date('Y-m-d') ?>';
            const currentTime = time || '09:00';
            
            modalDate.value = currentDate;
            modalTime.value = currentTime;
            
            const dateObj = new Date(currentDate + 'T' + currentTime);
            selectedDate.textContent = dateObj.toLocaleDateString('ru-RU', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            selectedTime.textContent = dateObj.toLocaleTimeString('ru-RU', { 
                hour: '2-digit', 
                minute: '2-digit' 
            });
            
            document.getElementById('quickAppointmentModal').style.display = 'flex';
        }

        function closeQuickAppointment() {
            document.getElementById('quickAppointmentModal').style.display = 'none';
        }

        function showAppointmentDetails(id) {
            // В реальном проекте здесь будет AJAX запрос к серверу
            alert('Просмотр записи ID: ' + id + '\nДля просмотра деталей перейдите в раздел управления записями.');
        }

        // Закрытие модалок по клику вне области
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal')) {
                e.target.style.display = 'none';
            }
        });

        // Автоскрытие уведомлений
        setTimeout(() => {
            document.querySelectorAll('.notification').forEach(notification => {
                notification.remove();
            });
        }, 5000);
    </script>
</body>
</html>