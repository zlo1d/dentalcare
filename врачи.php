<?php
session_start();
include 'config.php';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_doctor' || $action === 'edit_doctor') {
        $firstName = trim($_POST['firstName'] ?? '');
        $lastName = trim($_POST['lastName'] ?? '');
        $middleName = trim($_POST['middleName'] ?? '');
        $birthDate = $_POST['birthDate'] ?? null;
        $specialization = $_POST['specialization'] ?? '';
        $experience = (int)($_POST['experience'] ?? 0);
        $rating = min(5.0, max(0.0, (float)($_POST['rating'] ?? 4.5)));
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $education = trim($_POST['education'] ?? '');
        $skills = trim($_POST['skills'] ?? '');
        $schedule = trim($_POST['schedule'] ?? '');
        $status = in_array($_POST['status'] ?? 'available', ['available', 'busy', 'offline']) ? $_POST['status'] : 'available';

        if (empty($firstName) || empty($lastName) || empty($specialization) || empty($phone) || $experience < 0) {
            $error = "Пожалуйста, заполните все обязательные поля корректно.";
        } else {
            try {
                if ($action === 'add_doctor') {
                    $stmt = $pdo->prepare("
                        INSERT INTO doctors 
                        (first_name, last_name, middle_name, birth_date, specialization, experience, rating, phone, email, education, skills, schedule, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$firstName, $lastName, $middleName, $birthDate, $specialization, $experience, $rating, $phone, $email, $education, $skills, $schedule, $status]);
                    $success = "Врач успешно добавлен!";
                } else {
                    $doctorId = (int)($_POST['doctorId'] ?? 0);
                    $stmt = $pdo->prepare("
                        UPDATE doctors SET 
                        first_name = ?, last_name = ?, middle_name = ?, birth_date = ?, specialization = ?, 
                        experience = ?, rating = ?, phone = ?, email = ?, education = ?, skills = ?, schedule = ?, status = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$firstName, $lastName, $middleName, $birthDate, $specialization, $experience, $rating, $phone, $email, $education, $skills, $schedule, $status, $doctorId]);
                    $success = "Данные врача успешно обновлены!";
                }
            } catch (PDOException $e) {
                $error = "Ошибка при сохранении данных: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_doctor') {
        $doctorId = (int)($_POST['doctorId'] ?? 0);
        if ($doctorId > 0) {
            try {
                // Проверка записей
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id = ?");
                $stmt->execute([$doctorId]);
                if ($stmt->fetchColumn() > 0) {
                    $error = "Нельзя удалить врача, у которого есть записи.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM doctors WHERE id = ?");
                    $stmt->execute([$doctorId]);
                    $success = "Врач удалён.";
                }
            } catch (PDOException $e) {
                $error = "Ошибка при удалении: " . $e->getMessage();
            }
        } else {
            $error = "Некорректный ID врача.";
        }
    }
}

// Получение данных
$search = trim($_GET['search'] ?? '');
$specializationFilter = $_GET['specialization'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$sql = "
    SELECT id, first_name, last_name, middle_name, birth_date, specialization, 
           experience, rating, phone, email, education, skills, schedule, status
    FROM doctors
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $like = "%$search%";
    $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR middle_name LIKE ? OR specialization LIKE ? OR skills LIKE ?)";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}
if (!empty($specializationFilter)) {
    $sql .= " AND specialization = ?";
    $params[] = $specializationFilter;
}
if (!empty($statusFilter)) {
    $sql .= " AND status = ?";
    $params[] = $statusFilter;
}
$sql .= " ORDER BY last_name, first_name";

$doctors = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $doctors = [];
}

// Статистика
try {
    $totalDoctors = $pdo->query("SELECT COUNT(*) FROM doctors")->fetchColumn();
    $availableDoctors = $pdo->query("SELECT COUNT(*) FROM doctors WHERE status = 'available'")->fetchColumn();
    $busyDoctors = $pdo->query("SELECT COUNT(*) FROM doctors WHERE status = 'busy'")->fetchColumn();
    $specializationsCount = $pdo->query("SELECT COUNT(DISTINCT specialization) FROM doctors")->fetchColumn();
} catch (PDOException $e) {
    $totalDoctors = $availableDoctors = $busyDoctors = $specializationsCount = 0;
}

// Вспомогательная функция
function calculateAge($birthDate) {
    if (!$birthDate) return null;
    $birth = new DateTime($birthDate);
    $today = new DateTime();
    return $today->diff($birth)->y;
}

function renderStars($rating) {
    $full = floor($rating);
    $half = ($rating - $full) >= 0.5;
    $empty = 5 - $full - ($half ? 1 : 0);
    $html = str_repeat('<i class="fas fa-star"></i>', $full);
    if ($half) $html .= '<i class="fas fa-star-half-alt"></i>';
    $html .= str_repeat('<i class="far fa-star"></i>', $empty);
    return $html;
}

function getSpecializationName($key) {
    $map = [
        'терапевт' => 'Врач-терапевт',
        'хирург' => 'Хирург-стоматолог',
        'ортодонт' => 'Врач-ортодонт',
        'имплантолог' => 'Врач-имплантолог',
        'хирург-имплантолог' => 'Хирург-имплантолог',
        'ортопед' => 'Врач-ортопед',
        'пародонтолог' => 'Врач-пародонтолог'
    ];
    return $map[$key] ?? $key;
}

function renderScheduleDays($schedule) {
    if (!$schedule) return '';
    $days = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    $html = '';
    foreach ($days as $day) {
        $working = stripos($schedule, $day) !== false;
        $html .= '<div class="schedule-day ' . ($working ? 'working' : '') . '">' . $day . '</div>';
    }
    return $html;
}
?>

<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Врачи</title>
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
        .nav-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
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
        .theme-toggle:hover {
            background: var(--bg-secondary);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: var(--bg-card);
            padding: 1.5rem;
            border-radius: var(--radius);
            border-left: 4px solid var(--primary);
            box-shadow: var(--shadow);
            text-align: center;
        }
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-card.secondary { border-left-color: var(--secondary); }
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        .doctors-section {
            background: var(--bg-card);
            border-radius: var(--radius);
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
        .search-box {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .search-input {
            flex: 1;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--bg-primary);
            color: var(--text-primary);
        }
        .filters {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .filter-select {
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--bg-primary);
            color: var(--text-primary);
            min-width: 200px;
        }
        .doctors-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }
        .doctor-card {
            background: var(--bg-primary);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            overflow: hidden;
            transition: var(--transition);
        }
        .doctor-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }
        .doctor-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            position: relative;
        }
        .doctor-status {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            background: rgba(255,255,255,0.2);
        }
        .doctor-status.available { background: var(--success); color: white; }
        .doctor-status.busy { background: var(--warning); color: white; }
        .doctor-status.offline { background: var(--danger); color: white; }
        .doctor-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.5rem;
            margin-bottom: 1rem;
            border: 3px solid rgba(255,255,255,0.3);
        }
        .doctor-body {
            padding: 1.5rem;
        }
        .doctor-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat { text-align: center; }
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.25rem;
        }
        .stat-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }
        .doctor-skills .skills-list,
        .doctor-schedule .schedule-days {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .skill-tag, .schedule-day {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            background: var(--bg-secondary);
        }
        .schedule-day.working {
            background: var(--success);
            color: white;
        }
        .doctor-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
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
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-outline { background: transparent; border: 2px solid var(--primary); color: var(--primary); }
        .btn-outline:hover { background: var(--primary); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-sm { padding: 0.5rem 1rem; font-size: 0.75rem; }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 2rem;
            width: 100%;
            max-width: 600px;
            box-shadow: var(--shadow-xl);
            max-height: 90vh;
            overflow-y: auto;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--bg-primary);
            color: var(--text-primary);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
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
        .notification.success { border-left-color: var(--success); }
        .notification.error { border-left-color: var(--danger); }
        @media (max-width: 768px) {
            .dashboard { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .doctors-grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .filters { flex-direction: column; }
            .search-box { flex-direction: column; }
        }
    </style>
</head>
<body>
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

    <div class="dashboard">
        <aside class="sidebar">
            <div class="nav-brand" style="padding: 0 1.5rem 1.5rem;">
                <i class="fas fa-tooth logo-icon"></i>
                <span class="brand-text">Здоровье <strong></strong></span>
            </div>
            <ul class="sidebar-menu">
                <li><a href="index.php" class="menu-item"><i class="fas fa-home"></i> Главная</a></li>
                <li><a href="запись.php" class="menu-item"><i class="fas fa-calendar-plus"></i> Запись</a></li>
                <li><a href="пациенты.php" class="menu-item"><i class="fas fa-users"></i> Пациенты</a></li>
                <li><a href="расписание.php" class="menu-item"><i class="fas fa-calendar-alt"></i> Расписание</a></li>
                <li><a href="врачи.php" class="menu-item active"><i class="fas fa-user-md"></i> Врачи</a></li>
                <li><a href="настройки.php" class="menu-item"><i class="fas fa-cog"></i> Настройки</a></li>
            </ul>
        </aside>
        <main class="main-content">
            <div class="content-header">
                <h1>Команда врачей</h1>
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

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= $totalDoctors ?></div>
                    <div class="stat-label">Всего врачей</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-value"><?= $availableDoctors ?></div>
                    <div class="stat-label">Доступно сейчас</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-value"><?= $busyDoctors ?></div>
                    <div class="stat-label">На приёме</div>
                </div>
                <div class="stat-card secondary">
                    <div class="stat-value"><?= $specializationsCount ?></div>
                    <div class="stat-label">Специализаций</div>
                </div>
            </div>

            <div class="doctors-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-user-md"></i> Наша команда</h2>
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-user-plus"></i> Добавить врача
                    </button>
                </div>

                <form method="GET" class="search-box">
                    <input type="text" class="search-input" name="search" placeholder="Поиск по имени или специализации..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Поиск</button>
                </form>

                <div class="filters">
                    <select name="specialization" class="filter-select" onchange="this.form.submit()">
                        <option value="">Все специализации</option>
                        <option value="терапевт" <?= $specializationFilter === 'терапевт' ? 'selected' : '' ?>>Терапевт</option>
                        <option value="хирург" <?= $specializationFilter === 'хирург' ? 'selected' : '' ?>>Хирург</option>
                        <option value="ортодонт" <?= $specializationFilter === 'ортодонт' ? 'selected' : '' ?>>Ортодонт</option>
                        <option value="имплантолог" <?= $specializationFilter === 'имплантолог' ? 'selected' : '' ?>>Имплантолог</option>
                        <option value="хирург-имплантолог" <?= $specializationFilter === 'хирург-имплантолог' ? 'selected' : '' ?>>Хирург-имплантолог</option>
                        <option value="ортопед" <?= $specializationFilter === 'ортопед' ? 'selected' : '' ?>>Ортопед</option>
                        <option value="пародонтолог" <?= $specializationFilter === 'пародонтолог' ? 'selected' : '' ?>>Пародонтолог</option>
                    </select>
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="">Все статусы</option>
                        <option value="available" <?= $statusFilter === 'available' ? 'selected' : '' ?>>Доступен</option>
                        <option value="busy" <?= $statusFilter === 'busy' ? 'selected' : '' ?>>На приёме</option>
                        <option value="offline" <?= $statusFilter === 'offline' ? 'selected' : '' ?>>Недоступен</option>
                    </select>
                </div>

                <div class="doctors-grid">
                    <?php if (empty($doctors)): ?>
                        <div style="grid-column: 1 / -1; text-align: center; padding: 3rem; color: var(--text-secondary);">
                            <i class="fas fa-user-md" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                            <p>Врачи не найдены</p>
                        </div>
                    <?php else: ?>
                        <?php foreach($doctors as $doctor): ?>
                        <div class="doctor-card">
                            <div class="doctor-header">
                                <div class="doctor-status <?= $doctor['status'] ?>">
                                    <?php
                                    if ($doctor['status'] === 'available') echo 'Доступен';
                                    elseif ($doctor['status'] === 'busy') echo 'На приёме';
                                    else echo 'Недоступен';
                                    ?>
                                </div>
                                <div class="doctor-avatar">
                                    <?= strtoupper(substr($doctor['first_name'], 0, 1) . substr($doctor['last_name'], 0, 1)) ?>
                                </div>
                                <div class="doctor-info">
                                    <h3>Др. <?= htmlspecialchars($doctor['last_name'] . ' ' . $doctor['first_name']) ?></h3>
                                    <div class="doctor-specialty"><?= htmlspecialchars($doctor['specialization']) ?></div>
                                    <div class="doctor-rating">
                                        <div class="stars">
                                            <?= renderStars($doctor['rating'] ?? 4.5) ?>
                                        </div>
                                        <span><?= number_format($doctor['rating'] ?? 4.5, 1) ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="doctor-body">
                                <div class="doctor-stats">
                                    <div class="stat">
                                        <div class="stat-value"><?= $doctor['experience'] ?? '0' ?>+</div>
                                        <div class="stat-label">лет опыта</div>
                                    </div>
                                    <div class="stat">
                                        <div class="stat-value">0</div>
                                        <div class="stat-label">записей</div>
                                    </div>
                                </div>
                                <?php if (!empty($doctor['phone'])): ?>
                                <div style="margin-bottom: 1rem;">
                                    <small style="color: var(--text-secondary);">Телефон:</small>
                                    <div><?= htmlspecialchars($doctor['phone']) ?></div>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($doctor['email'])): ?>
                                <div style="margin-bottom: 1rem;">
                                    <small style="color: var(--text-secondary);">Email:</small>
                                    <div><?= htmlspecialchars($doctor['email']) ?></div>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($doctor['skills'])): ?>
                                <div class="doctor-skills">
                                    <div class="skills-title">Навыки:</div>
                                    <div class="skills-list">
                                        <?php
                                        $skills = explode(',', $doctor['skills']);
                                        foreach (array_slice($skills, 0, 3) as $skill):
                                        ?>
                                        <div class="skill-tag"><?= trim($skill) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="doctor-actions">
                                    <button class="btn btn-outline btn-sm" onclick="openEditModal(<?= $doctor['id'] ?>)">
                                        <i class="fas fa-edit"></i> Изменить
                                    </button>
                                    <button class="btn btn-danger btn-sm" onclick="deleteDoctor(<?= $doctor['id'] ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Модальное окно добавления/редактирования -->
            <div class="modal" id="doctorModal">
                <div class="modal-content">
                    <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                        <h2 id="modalTitle">Добавить врача</h2>
                        <button class="modal-close" onclick="closeModal()">&times;</button>
                    </div>
                    <form method="POST" id="doctorForm">
                        <input type="hidden" name="action" id="formAction" value="add_doctor">
                        <input type="hidden" name="doctorId" id="editDoctorId">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Имя *</label>
                                <input type="text" name="firstName" id="firstName" required>
                            </div>
                            <div class="form-group">
                                <label>Фамилия *</label>
                                <input type="text" name="lastName" id="lastName" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Отчество</label>
                                <input type="text" name="middleName" id="middleName">
                            </div>
                            <div class="form-group">
                                <label>Дата рождения</label>
                                <input type="date" name="birthDate" id="birthDate">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Специализация *</label>
                            <select name="specialization" id="specialization" required>
                                <option value="">Выберите...</option>
                                <option value="терапевт">Терапевт</option>
                                <option value="хирург">Хирург</option>
                                <option value="ортодонт">Ортодонт</option>
                                <option value="имплантолог">Имплантолог</option>
                                <option value="хирург-имплантолог">Хирург-имплантолог</option>
                                <option value="ортопед">Ортопед</option>
                                <option value="пародонтолог">Пародонтолог</option>
                            </select>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Опыт (лет) *</label>
                                <input type="number" name="experience" id="experience" min="0" required>
                            </div>
                            <div class="form-group">
                                <label>Рейтинг</label>
                                <input type="number" name="rating" id="rating" min="0" max="5" step="0.1" value="4.5">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Телефон *</label>
                                <input type="tel" name="phone" id="phone" required>
                            </div>
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="email" id="email">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Образование</label>
                            <textarea name="education" id="education" rows="2"></textarea>
                        </div>
                        <div class="form-group">
                            <label>Навыки (через запятую)</label>
                            <textarea name="skills" id="skills" rows="2"></textarea>
                        </div>
                        <div class="form-group">
                            <label>График работы</label>
                            <textarea name="schedule" id="schedule" rows="2" placeholder="Пн-Пт: 9:00-18:00"></textarea>
                        </div>
                        <div class="form-group">
                            <label>Статус</label>
                            <select name="status" id="status">
                                <option value="available">Доступен</option>
                                <option value="busy">На приёме</option>
                                <option value="offline">Недоступен</option>
                            </select>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-save"></i> Сохранить
                            </button>
                            <button type="button" class="btn btn-outline btn-block" onclick="closeModal()">
                                Отмена
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

                // Модальные окна
                function openAddModal() {
                    document.getElementById('modalTitle').textContent = 'Добавить врача';
                    document.getElementById('formAction').value = 'add_doctor';
                    document.getElementById('editDoctorId').value = '';
                    document.getElementById('doctorForm').reset();
                    document.getElementById('doctorModal').style.display = 'flex';
                }
                function closeModal() {
                    document.getElementById('doctorModal').style.display = 'none';
                }
                function openEditModal(id) {
                    fetch(`get_doctor.php?id=${id}`)
                        .then(res => res.json())
                        .then(doctor => {
                            document.getElementById('modalTitle').textContent = 'Редактировать врача';
                            document.getElementById('formAction').value = 'edit_doctor';
                            document.getElementById('editDoctorId').value = doctor.id;
                            document.getElementById('firstName').value = doctor.first_name;
                            document.getElementById('lastName').value = doctor.last_name;
                            document.getElementById('middleName').value = doctor.middle_name || '';
                            document.getElementById('birthDate').value = doctor.birth_date || '';
                            document.getElementById('specialization').value = doctor.specialization;
                            document.getElementById('experience').value = doctor.experience;
                            document.getElementById('rating').value = doctor.rating;
                            document.getElementById('phone').value = doctor.phone;
                            document.getElementById('email').value = doctor.email || '';
                            document.getElementById('education').value = doctor.education || '';
                            document.getElementById('skills').value = doctor.skills || '';
                            document.getElementById('schedule').value = doctor.schedule || '';
                            document.getElementById('status').value = doctor.status;
                            document.getElementById('doctorModal').style.display = 'flex';
                        })
                        .catch(() => alert('Ошибка загрузки данных врача'));
                }
                function deleteDoctor(id) {
                    if (confirm('Удалить врача?')) {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.style.display = 'none';
                        form.innerHTML = `
                            <input name="action" value="delete_doctor">
                            <input name="doctorId" value="${id}">
                        `;
                        document.body.appendChild(form);
                        form.submit();
                    }
                }
                // Закрытие модалки по клику снаружи
                document.getElementById('doctorModal').addEventListener('click', e => {
                    if (e.target === document.getElementById('doctorModal')) closeModal();
                });
            </script>
        </main>
    </div>
</body>
</html>