<?php
session_start();
include 'config.php';

// Обработка POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_appointment') {
        $patientName = $_POST['patientName'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $email = $_POST['email'] ?? '';
        $date = $_POST['date'] ?? '';
        $time = $_POST['time'] ?? '';
        $doctorId = (int)($_POST['doctorId'] ?? 0);
        $serviceId = (int)($_POST['serviceId'] ?? 0);
        $notes = $_POST['notes'] ?? '';

        if (empty($patientName) || empty($phone) || empty($date) || empty($time) || !$doctorId || !$serviceId) {
            $error = "Заполните все обязательные поля";
        } else {
            try {
                // Разбор ФИО
                $parts = explode(' ', $patientName, 3);
                $lastName = $parts[0] ?? '';
                $firstName = $parts[1] ?? '';
                $middleName = $parts[2] ?? '';

                // Поиск или создание пациента
                $stmt = $pdo->prepare("SELECT id FROM patients WHERE phone = ?");
                $stmt->execute([$phone]);
                $patient = $stmt->fetch();
                if ($patient) {
                    $patientId = $patient['id'];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO patients (first_name, last_name, middle_name, phone, email) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$firstName, $lastName, $middleName, $phone, $email]);
                    $patientId = $pdo->lastInsertId();
                }

                // Проверка занятости времени
                $stmt = $pdo->prepare("SELECT id FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ?");
                $stmt->execute([$doctorId, $date, $time]);
                if ($stmt->fetch()) {
                    $error = "Врач уже занят в это время";
                } else {
                    // Создание записи
                    $stmt = $pdo->prepare("INSERT INTO appointments (patient_id, doctor_id, service_id, appointment_date, appointment_time, notes) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$patientId, $doctorId, $serviceId, $date, $time, $notes]);
                    $appointmentId = $pdo->lastInsertId();

                    // Сохранение активности
                    $stmt = $pdo->prepare("INSERT INTO activities (action, patient_name, appointment_id, type) VALUES (?, ?, ?, 'new_appointment')");
                    $stmt->execute(["Новая запись", trim("$lastName $firstName"), $appointmentId]);

                    $success = "Запись успешно создана!";
                }
            } catch (PDOException $e) {
                $error = "Ошибка при создании записи: " . $e->getMessage();
            }
        }
    }

    if ($action === 'update_appointment') {
        $id = (int)($_POST['id'] ?? 0);
        $patientName = $_POST['patientName'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $date = $_POST['date'] ?? '';
        $time = $_POST['time'] ?? '';
        $doctorId = (int)($_POST['doctorId'] ?? 0);
        $serviceId = (int)($_POST['serviceId'] ?? 0);
        $notes = $_POST['notes'] ?? '';

        if (empty($patientName) || empty($phone) || empty($date) || empty($time) || !$doctorId || !$serviceId || !$id) {
            $error = "Заполните все обязательные поля";
        } else {
            try {
                $parts = explode(' ', $patientName, 3);
                $lastName = $parts[0] ?? '';
                $firstName = $parts[1] ?? '';
                $middleName = $parts[2] ?? '';

                $stmt = $pdo->prepare("SELECT id FROM patients WHERE phone = ?");
                $stmt->execute([$phone]);
                $patient = $stmt->fetch();
                if ($patient) {
                    $patientId = $patient['id'];
                    $stmt = $pdo->prepare("UPDATE patients SET first_name = ?, last_name = ?, middle_name = ?, email = ? WHERE id = ?");
                    $stmt->execute([$firstName, $lastName, $middleName, $_POST['email'] ?? '', $patientId]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO patients (first_name, last_name, middle_name, phone, email) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$firstName, $lastName, $middleName, $phone, $_POST['email'] ?? '']);
                    $patientId = $pdo->lastInsertId();
                }

                // Проверка конфликта времени (кроме текущей записи)
                $stmt = $pdo->prepare("SELECT id FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND id != ?");
                $stmt->execute([$doctorId, $date, $time, $id]);
                if ($stmt->fetch()) {
                    $error = "Врач уже занят в это время";
                } else {
                    $stmt = $pdo->prepare("UPDATE appointments SET patient_id = ?, doctor_id = ?, service_id = ?, appointment_date = ?, appointment_time = ?, notes = ? WHERE id = ?");
                    $stmt->execute([$patientId, $doctorId, $serviceId, $date, $time, $notes, $id]);
                    $success = "Запись обновлена!";
                }
            } catch (PDOException $e) {
                $error = "Ошибка при обновлении: " . $e->getMessage();
            }
        }
    }

    if ($action === 'delete_appointment') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                $pdo->prepare("DELETE FROM appointments WHERE id = ?")->execute([$id]);
                $success = "Запись удалена";
            } catch (PDOException $e) {
                $error = "Ошибка при удалении";
            }
        }
    }
}

// Получение данных
$doctors = $pdo->query("SELECT * FROM doctors ORDER BY last_name")->fetchAll(PDO::FETCH_ASSOC);
$services = $pdo->query("SELECT * FROM services ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Ближайшие записи
$upcoming = $pdo->query("
    SELECT a.*, 
           CONCAT(p.last_name, ' ', p.first_name, ' ', p.middle_name) AS patient_name,
           p.phone, p.email,
           CONCAT(d.last_name, ' ', d.first_name) AS doctor_name,
           d.specialization,
           s.name AS service_name, s.price
    FROM appointments a
    JOIN patients p ON a.patient_id = p.id
    JOIN doctors d ON a.doctor_id = d.id
    JOIN services s ON a.service_id = s.id
    WHERE a.appointment_date >= CURDATE()
    ORDER BY a.appointment_date, a.appointment_time
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// Для календаря: занятые даты
$busyDates = $pdo->query("SELECT DISTINCT appointment_date FROM appointments WHERE appointment_date >= CURDATE()")->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Запись пациентов</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
    <style>
        
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary: #8b5cf6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
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
            --radius: 8px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        [data-theme="dark"] {
            --bg-primary: #111827;
            --bg-secondary: #1f2937;
            --bg-card: #374151;
            --text-primary: #f9fafb;
            --text-secondary: #d1d5db;
            --border: #374151;
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
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        .nav-actions {
    display: flex;
    align-items: center;  /* ← ДОБАВЬТЕ ЭТУ СТРОЧКУ */
    gap: 1rem;
}
        .theme-toggle {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--text-primary);
            cursor: pointer;
            padding: 0.5rem;
            border-radius: var(--radius);
            transition: var(--transition);
        }
        .dashboard {
            display: grid;
            grid-template-columns: 250px 1fr;
            min-height: 100vh;
        }
        .sidebar {
            background: var(--bg-card);
            border-right: 1px solid var(--border);
            padding: 1.5rem 0;
        }
        .sidebar-menu {
            list-style: none;
        }
        .menu-item {
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--text-secondary);
            text-decoration: none;
            transition: var(--transition);
            border-left: 3px solid transparent;
        }
        .menu-item:hover, .menu-item.active {
            background: var(--bg-secondary);
            color: var(--primary);
            border-left-color: var(--primary);
        }
        .main-content {
            padding: 2rem;
            background: var(--bg-secondary);
        }
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius);
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.875rem;
        }
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg);
        }
        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }
        .btn-success {
            background: var(--success);
            color: white;
        }
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        .appointment-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        .form-card, .calendar-card {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        .form-title {
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-primary);
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--bg-primary);
            color: var(--text-primary);
            transition: var(--transition);
            font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .calendar-day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius);
            background: var(--bg-secondary);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
        }
        .calendar-day:hover {
            background: var(--primary);
            color: white;
        }
        .calendar-day.current {
            background: var(--primary);
            color: white;
        }
        .calendar-day.busy {
            background: var(--danger);
            color: white;
        }
        .time-slots {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.5rem;
        }
        .time-slot {
            padding: 0.5rem;
            text-align: center;
            background: var(--bg-secondary);
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.875rem;
        }
        .time-slot:hover {
            background: var(--primary);
            color: white;
        }
        .time-slot.selected {
            background: var(--primary);
            color: white;
        }
        .time-slot.busy {
            background: var(--danger);
            color: white;
            cursor: not-allowed;
        }
        .appointments-list {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        .section-title {
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .appointments-grid {
            display: grid;
            gap: 1rem;
        }
        .appointment-card {
            background: var(--bg-primary);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 1px solid var(--border);
            transition: var(--transition);
        }
        .appointment-card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }
        .appointment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        .patient-info h3 {
            font-size: 1.125rem;
            margin-bottom: 0.25rem;
        }
        .patient-info p {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        .appointment-meta {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        .appointment-actions {
            display: flex;
            gap: 0.5rem;
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .status-confirmed {
            background: #d1fae5;
            color: #065f46;
        }
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--bg-card);
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow-xl);
            border-left: 4px solid var(--primary);
            display: flex;
            align-items: center;
            gap: 1rem;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            z-index: 1001;
            max-width: 400px;
        }
        .notification.show {
            transform: translateX(0);
        }
        .notification.success {
            border-left-color: var(--success);
        }
        .notification.error {
            border-left-color: var(--danger);
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 2rem;
            width: 100%;
            max-width: 500px;
            box-shadow: var(--shadow-xl);
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 0.25rem;
        }
        @media (max-width: 1024px) {
            .appointment-section {
                grid-template-columns: 1fr;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 768px) {
            .dashboard {
                grid-template-columns: 1fr;
            }
            .sidebar {
                display: none;
            }
            .time-slots {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        @media (max-width: 480px) {
            .time-slots {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <aside class="sidebar">
            <div class="nav-brand" style="padding: 0 1.5rem 1.5rem;">
                <i class="fas fa-tooth logo-icon"></i>
                <span class="brand-text">Здоровье <strong></strong></span>
            </div>
            <ul class="sidebar-menu">
                <li><a href="index.php" class="menu-item"><i class="fas fa-home"></i> Главная</a></li>
                <li><a href="запись.php" class="menu-item active"><i class="fas fa-calendar-plus"></i> Запись</a></li>
                <li><a href="пациенты.php" class="menu-item"><i class="fas fa-users"></i> Пациенты</a></li>
                <li><a href="расписание.php" class="menu-item"><i class="fas fa-calendar-alt"></i> Расписание</a></li>
                <li><a href="врачи.php" class="menu-item"><i class="fas fa-user-md"></i> Врачи</a></li>
                <li><a href="настройки.php" class="menu-item"><i class="fas fa-cog"></i> Настройки</a></li>
            </ul>
        </aside>
        <main class="main-content">
            <div class="content-header">
                <h1>Запись пациентов</h1>
                <div class="nav-actions">
                    <button class="theme-toggle" id="themeToggle">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="user-menu">
                        <button class="btn btn-outline">
                            <i class="fas fa-user-circle"></i> Администратор
                        </button>
                    </div>
                </div>
            </div>

            <!-- Уведомления -->
            <?php if (!empty($success)): ?>
            <div class="notification success show">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($success) ?></span>
                <button class="notification-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
            <div class="notification error show">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error) ?></span>
                <button class="notification-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
            <?php endif; ?>

            <div class="appointment-section">
                <div class="form-card">
                    <h2 class="form-title">
                        <i class="fas fa-user-plus"></i> Новая запись
                    </h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="create_appointment">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> ФИО пациента *</label>
                            <input type="text" name="patientName" placeholder="Иванов Иван Иванович" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Телефон *</label>
                            <input type="tel" name="phone" placeholder="+7 (912) 345-67-89" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" name="email" placeholder="email@example.com">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-calendar"></i> Дата *</label>
                                <input type="date" name="date" min="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-clock"></i> Время *</label>
                                <input type="time" name="time" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user-md"></i> Врач *</label>
                            <select name="doctorId" required>
                                <option value="">Выберите врача</option>
                                <?php foreach ($doctors as $d): ?>
                                <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['last_name'] . ' ' . $d['first_name'] . ' – ' . $d['specialization']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-teeth"></i> Услуга *</label>
                            <select name="serviceId" required>
                                <option value="">Выберите услугу</option>
                                <?php foreach ($services as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name'] . ' – ' . number_format($s['price'], 0, ' ', ' ') . ' ₽') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-sticky-note"></i> Примечания</label>
                            <textarea name="notes" rows="3" placeholder="Дополнительная информация..."></textarea>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-calendar-check"></i> Создать запись
                            </button>
                        </div>
                    </form>
                </div>

                <div class="calendar-card">
                    <div class="calendar-header">
                        <h2 class="form-title">
                            <i class="fas fa-calendar-alt"></i> Занятые дни
                        </h2>
                    </div>
                    <div class="calendar-grid">
                        <?php
                        $today = new DateTime();
                        $month = $today->format('m');
                        $year = $today->format('Y');
                        $firstDay = new DateTime("$year-$month-01");
                        $startDay = (int)$firstDay->format('N') - 1; // Понедельник = 0
                        $daysInMonth = $firstDay->format('t');
                        // Заголовки дней
                        $weekDays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
                        foreach ($weekDays as $wd) echo "<div class='calendar-day' style='font-weight:bold'>$wd</div>";
                        // Пустые дни до начала месяца
                        for ($i = 0; $i < $startDay; $i++) echo "<div class='calendar-day'></div>";
                        // Дни месяца
                        $busySet = array_flip($busyDates);
                        for ($day = 1; $day <= $daysInMonth; $day++) {
                            $dateStr = "$year-$month-" . str_pad($day, 2, '0', STR_PAD_LEFT);
                            $isToday = $dateStr === date('Y-m-d');
                            $isBusy = isset($busySet[$dateStr]);
                            $class = 'calendar-day';
                            if ($isToday) $class .= ' current';
                            if ($isBusy) $class .= ' busy';
                            echo "<div class='$class'>$day</div>";
                        }
                        ?>
                    </div>
                </div>
            </div>

            <div class="appointments-list">
                <h2 class="section-title">
                    <i class="fas fa-list"></i> Ближайшие записи
                </h2>
                <div class="appointments-grid">
                    <?php if (empty($upcoming)): ?>
                    <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                        <i class="fas fa-calendar-times" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                        <p>Нет активных записей</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($upcoming as $a): ?>
                    <div class="appointment-card">
                        <div class="appointment-header">
                            <div class="patient-info">
                                <h3><?= htmlspecialchars($a['patient_name']) ?></h3>
                                <p><?= htmlspecialchars($a['phone']) ?><?php if (!empty($a['email'])): ?> • <?= $a['email'] ?><?php endif; ?></p>
                            </div>
                            <span class="status-badge status-<?= $a['status'] ?>">
                                <?php
                                if ($a['status'] === 'confirmed') echo 'Подтверждена';
                                elseif ($a['status'] === 'pending') echo 'Ожидание';
                                else echo 'Отменена';
                                ?>
                            </span>
                        </div>
                        <div class="appointment-meta">
                            <div class="meta-item">
                                <i class="fas fa-calendar"></i>
                                <span><?= date('d.m.Y', strtotime($a['appointment_date'])) ?></span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-clock"></i>
                                <span><?= $a['appointment_time'] ?></span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-user-md"></i>
                                <span>Др. <?= htmlspecialchars($a['doctor_name']) ?></span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-teeth"></i>
                                <span><?= htmlspecialchars($a['service_name']) ?></span>
                            </div>
                        </div>
                        <?php if (!empty($a['notes'])): ?>
                        <div style="margin: 1rem 0; font-size: 0.875rem; color: var(--text-secondary);">
                            <strong>Примечания:</strong> <?= htmlspecialchars($a['notes']) ?>
                        </div>
                        <?php endif; ?>
                        <div class="appointment-actions">
                            <button class="btn btn-outline" onclick="editAppointment(<?= $a['id'] ?>)">
                                <i class="fas fa-edit"></i> Редактировать
                            </button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить запись?')">
                                <input type="hidden" name="action" value="delete_appointment">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn btn-danger">
                                    <i class="fas fa-trash"></i> Удалить
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal редактирования -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Редактировать запись</h2>
                <button class="modal-close" onclick="document.getElementById('editModal').style.display='none'">&times;</button>
            </div>
            <form id="editForm" method="POST">
                <input type="hidden" name="action" value="update_appointment">
                <input type="hidden" name="id" id="editId">
                <div class="form-group">
                    <label>ФИО пациента *</label>
                    <input type="text" name="patientName" id="editPatientName" required>
                </div>
                <div class="form-group">
                    <label>Телефон *</label>
                    <input type="tel" name="phone" id="editPhone" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="editEmail">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Дата *</label>
                        <input type="date" name="date" id="editDate" min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Время *</label>
                        <input type="time" name="time" id="editTime" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Врач *</label>
                    <select name="doctorId" id="editDoctorId" required>
                        <option value="">Выберите врача</option>
                        <?php foreach ($doctors as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['last_name'] . ' ' . $d['first_name'] . ' – ' . $d['specialization']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Услуга *</label>
                    <select name="serviceId" id="editServiceId" required>
                        <option value="">Выберите услугу</option>
                        <?php foreach ($services as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name'] . ' – ' . number_format($s['price'], 0, ' ', ' ') . ' ₽') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Примечания</label>
                    <textarea name="notes" id="editNotes" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-save"></i> Сохранить
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Тема
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
        });
        function updateThemeIcon(theme) {
            document.getElementById('themeToggle').innerHTML = theme === 'dark' ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        }

        // Редактирование
        function editAppointment(id) {
            // Здесь можно реализовать AJAX-загрузку через PHP, но для простоты — предзаполним из DOM
            // В реальном проекте лучше использовать отдельный endpoint get_appointment.php
            const card = document.querySelector(`.appointment-card:nth-child(${Array.from(document.querySelectorAll('.appointment-card')).findIndex(c => c.querySelector('form input[name="id"][value="' + id + '"]')) + 1})`);
            if (!card) return;
            document.getElementById('editId').value = id;
            document.getElementById('editPatientName').value = card.querySelector('.patient-info h3').textContent;
            document.getElementById('editPhone').value = card.querySelector('.patient-info p').textContent.split(' • ')[0];
            document.getElementById('editEmail').value = card.querySelector('.patient-info p').textContent.includes('•') ? card.querySelector('.patient-info p').textContent.split(' • ')[1] : '';
            document.getElementById('editDate').value = card.querySelector('.meta-item:nth-child(1) span').textContent.split('.').reverse().join('-');
            document.getElementById('editTime').value = card.querySelector('.meta-item:nth-child(2) span').textContent;
            // Врач и услуга — по ID (в реале — через API)
            document.getElementById('editModal').style.display = 'flex';
        }

        // Закрытие модалки
        document.getElementById('editModal').addEventListener('click', e => {
            if (e.target === document.getElementById('editModal')) {
                document.getElementById('editModal').style.display = 'none';
            }
        });
    </script>
</body>
</html>