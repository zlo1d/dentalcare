<?php
session_start();
include 'config.php';

// Обработка POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_patient' || $action === 'edit_patient') {
        $firstName = trim($_POST['firstName'] ?? '');
        $lastName = trim($_POST['lastName'] ?? '');
        $middleName = trim($_POST['middleName'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $birthDate = $_POST['birthDate'] ?? null;
        $address = trim($_POST['address'] ?? '');
        $medicalNotes = trim($_POST['medicalNotes'] ?? '');
        $status = in_array($_POST['status'] ?? 'new', ['active', 'inactive', 'new']) ? $_POST['status'] : 'new';
        $doctorId = !empty($_POST['doctorId']) ? (int)$_POST['doctorId'] : null;
        $hasDebt = !empty($_POST['hasDebt']) ? 1 : 0;

        if (empty($firstName) || empty($lastName) || empty($phone) || empty($birthDate)) {
            $error = "Заполните все обязательные поля";
        } else {
            try {
                if ($action === 'add_patient') {
                    $stmt = $pdo->prepare("
                        INSERT INTO patients 
                        (first_name, last_name, middle_name, phone, email, birth_date, address, medical_notes, status, doctor_id, has_debt) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$firstName, $lastName, $middleName, $phone, $email, $birthDate, $address, $medicalNotes, $status, $doctorId, $hasDebt]);
                    $success = "Пациент успешно добавлен!";
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    $stmt = $pdo->prepare("
                        UPDATE patients SET 
                        first_name = ?, last_name = ?, middle_name = ?, phone = ?, email = ?, 
                        birth_date = ?, address = ?, medical_notes = ?, status = ?, doctor_id = ?, has_debt = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$firstName, $lastName, $middleName, $phone, $email, $birthDate, $address, $medicalNotes, $status, $doctorId, $hasDebt, $id]);
                    $success = "Данные пациента обновлены!";
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = "Пациент с таким телефоном уже существует";
                } else {
                    $error = "Ошибка: " . $e->getMessage();
                }
            }
        }
    }

    if ($action === 'delete_patient') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                $pdo->prepare("DELETE FROM patients WHERE id = ?")->execute([$id]);
                $success = "Пациент удалён";
            } catch (PDOException $e) {
                $error = "Ошибка при удалении";
            }
        }
    }
}

// Загрузка врачей для фильтра и формы
$doctors = $pdo->query("SELECT id, last_name, first_name, specialization FROM doctors ORDER BY last_name")->fetchAll();

// Параметры пагинации
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// Фильтры
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$doctorFilter = !empty($_GET['doctor']) ? (int)$_GET['doctor'] : '';

// Формирование SQL
$sql = "
    SELECT p.*, 
           CONCAT(d.last_name, ' ', d.first_name) as doctor_name,
           d.specialization
    FROM patients p
    LEFT JOIN doctors d ON p.doctor_id = d.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $like = "%$search%";
    $sql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.middle_name LIKE ? OR p.phone LIKE ? OR p.email LIKE ?)";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}
if (!empty($statusFilter)) {
    $sql .= " AND p.status = ?";
    $params[] = $statusFilter;
}
if (!empty($doctorFilter)) {
    $sql .= " AND p.doctor_id = ?";
    $params[] = $doctorFilter;
}

// Сортировка
$sortBy = $_GET['sort'] ?? 'name';
$sortMap = [
    'name' => 'p.last_name, p.first_name',
    'date' => 'p.created_at DESC',
    'lastVisit' => 'p.created_at DESC' // В реальной БД можно добавить last_appointment_date
];
$orderBy = $sortMap[$sortBy] ?? 'p.last_name, p.first_name';
$sql .= " ORDER BY $orderBy LIMIT $limit OFFSET $offset";

// Загрузка пациентов
$patients = $pdo->prepare($sql);
$patients->execute($params);
$patientsList = $patients->fetchAll(PDO::FETCH_ASSOC);

// Общее количество (для пагинации)
$countSql = "
    SELECT COUNT(*) 
    FROM patients p
    LEFT JOIN doctors d ON p.doctor_id = d.id
    WHERE 1=1
";
$countParams = [];
if (!empty($search)) {
    $countParams = array_merge($countParams, [$like, $like, $like, $like, $like]);
    $countSql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.middle_name LIKE ? OR p.phone LIKE ? OR p.email LIKE ?)";
}
if (!empty($statusFilter)) {
    $countParams[] = $statusFilter;
    $countSql .= " AND p.status = ?";
}
if (!empty($doctorFilter)) {
    $countParams[] = $doctorFilter;
    $countSql .= " AND p.doctor_id = ?";
}
$total = $pdo->prepare($countSql);
$total->execute($countParams);
$totalCount = (int)$total->fetchColumn();
$totalPages = ceil($totalCount / $limit);

// Статистика
try {
    $totalPatients = $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
    $activePatients = $pdo->query("SELECT COUNT(*) FROM patients WHERE status = 'active'")->fetchColumn();
    $oneMonthAgo = date('Y-m-d', strtotime('-1 month'));
    $newPatients = $pdo->query("SELECT COUNT(*) FROM patients WHERE created_at >= '$oneMonthAgo'")->fetchColumn();
    $debtPatients = $pdo->query("SELECT COUNT(*) FROM patients WHERE has_debt = 1")->fetchColumn();
} catch (PDOException $e) {
    $totalPatients = $activePatients = $newPatients = $debtPatients = 0;
}

// Вспомогательные функции
function calculateAge($birthDate) {
    if (!$birthDate) return '–';
    $birth = new DateTime($birthDate);
    $today = new DateTime();
    return $today->diff($birth)->y;
}

function formatDate($date) {
    if (!$date) return '–';
    return date('d.m.Y', strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Пациенты - DentalCare Pro</title>
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
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        .nav-actions {
    display: flex;
    align-items: center;  
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
        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
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
        .stat-card.success {
            border-left-color: var(--success);
        }
        .stat-card.warning {
            border-left-color: var(--warning);
        }
        .stat-card.danger {
            border-left-color: var(--danger);
        }
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        .patients-section {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
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
            padding: 0.5rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--bg-primary);
            color: var(--text-primary);
        }
        .patients-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5rem;
        }
        .patients-table th {
            background: var(--bg-secondary);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-primary);
            border-bottom: 1px solid var(--border);
        }
        .patients-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
        }
        .patients-table tr:hover {
            background: var(--bg-secondary);
        }
        .patient-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .patient-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }
        .patient-details h4 {
            margin-bottom: 0.25rem;
        }
        .patient-details p {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .status-active {
            background: #d1fae5;
            color: #065f46;
        }
        .status-inactive {
            background: #fef3c7;
            color: #92400e;
        }
        .status-new {
            background: #dbeafe;
            color: #1e40af;
        }
        .table-actions {
            display: flex;
            gap: 0.5rem;
        }
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.5rem;
        }
        .pagination-info {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        .pagination-controls {
            display: flex;
            gap: 0.5rem;
        }
        .pagination-btn {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border);
            background: var(--bg-primary);
            color: var(--text-primary);
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
        }
        .pagination-btn:hover:not(:disabled) {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .pagination-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
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
            max-width: 600px;
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
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
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
        @media (max-width: 768px) {
            .dashboard { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .search-box { flex-direction: column; }
            .filters { flex-direction: column; }
            .form-row { grid-template-columns: 1fr; }
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
                <li><a href="запись.php" class="menu-item"><i class="fas fa-calendar-plus"></i> Запись</a></li>
                <li><a href="пациенты.php" class="menu-item active"><i class="fas fa-users"></i> Пациенты</a></li>
                <li><a href="расписание.php" class="menu-item"><i class="fas fa-calendar-alt"></i> Расписание</a></li>
                <li><a href="врачи.php" class="menu-item"><i class="fas fa-user-md"></i> Врачи</a></li>
                <li><a href="настройки.php" class="menu-item"><i class="fas fa-cog"></i> Настройки</a></li>
            </ul>
        </aside>
        <main class="main-content">
            <div class="content-header">
                <h1>Управление пациентами</h1>
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

            <!-- Статистика -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= $totalPatients ?></div>
                    <div class="stat-label">Всего пациентов</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-value"><?= $activePatients ?></div>
                    <div class="stat-label">Активные пациенты</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-value"><?= $newPatients ?></div>
                    <div class="stat-label">Новые за месяц</div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-value"><?= $debtPatients ?></div>
                    <div class="stat-label">С задолженностью</div>
                </div>
            </div>

            <!-- Список пациентов -->
            <div class="patients-section">
                <div class="section-header">
                    <h2 class="section-title"><i class="fas fa-users"></i> Список пациентов</h2>
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-user-plus"></i> Добавить пациента
                    </button>
                </div>

                <form method="GET" class="search-box">
                    <input type="text" class="search-input" name="search" placeholder="Поиск по имени, телефону или email..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Поиск</button>
                </form>

                <div class="filters">
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="">Все статусы</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Активные</option>
                        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Неактивные</option>
                        <option value="new" <?= $statusFilter === 'new' ? 'selected' : '' ?>>Новые</option>
                    </select>

                    <select name="doctor" class="filter-select" onchange="this.form.submit()">
                        <option value="">Все врачи</option>
                        <?php foreach ($doctors as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $doctorFilter == $d['id'] ? 'selected' : '' ?>>
                            Др. <?= htmlspecialchars($d['last_name'] . ' ' . $d['first_name'] . ' – ' . $d['specialization']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="sort" class="filter-select" onchange="this.form.submit()">
                        <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>По имени</option>
                        <option value="date" <?= $sortBy === 'date' ? 'selected' : '' ?>>По дате регистрации</option>
                        <option value="lastVisit" <?= $sortBy === 'lastVisit' ? 'selected' : '' ?>>По последнему визиту</option>
                    </select>
                </div>

                <table class="patients-table">
                    <thead>
                        <tr>
                            <th>Пациент</th>
                            <th>Контакты</th>
                            <th>Последний визит</th>
                            <th>Статус</th>
                            <th>Лечащий врач</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($patientsList)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                                <i class="fas fa-user-slash" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                                <p>Пациенты не найдены</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($patientsList as $p): ?>
                        <tr>
                            <td>
                                <div class="patient-info">
                                    <div class="patient-avatar"><?= strtoupper(substr($p['first_name'], 0, 1) . substr($p['last_name'], 0, 1)) ?></div>
                                    <div class="patient-details">
                                        <h4><?= htmlspecialchars($p['last_name'] . ' ' . $p['first_name'] . ' ' . ($p['middle_name'] ?? '')) ?></h4>
                                        <p><?= calculateAge($p['birth_date']) ?> лет</p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="patient-details">
                                    <p><?= htmlspecialchars($p['phone']) ?></p>
                                    <p><?= htmlspecialchars($p['email'] ?? '—') ?></p>
                                </div>
                            </td>
                            <td><?= formatDate($p['created_at']) ?></td>
                            <td>
                                <span class="status-badge status-<?= $p['status'] ?>">
                                    <?php
                                    if ($p['status'] === 'active') echo 'Активный';
                                    elseif ($p['status'] === 'new') echo 'Новый';
                                    else echo 'Неактивный';
                                    ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($p['doctor_name'] ?? '—') ?></td>
                            <td>
                                <div class="table-actions">
                                    <button class="btn btn-outline btn-sm" onclick="viewPatient(<?= $p['id'] ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-outline btn-sm" onclick="editPatient(<?= $p['id'] ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить?')">
                                        <input type="hidden" name="action" value="delete_patient">
                                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

             <!-- Пагинация -->
<div class="pagination">
    <div class="pagination-info">
        Показано <?= (($page - 1) * $limit + 1) ?>–<?= min($page * $limit, $totalCount) ?> из <?= $totalCount ?> пациентов
    </div>
    <div class="pagination-controls">
        <?php 
        // Создаем копию GET параметров без page
        $queryParams = $_GET;
        unset($queryParams['page']);
        $queryString = http_build_query($queryParams);
        ?>
        
        <?php if ($page > 1): ?>
        <a href="?page=<?= $page - 1 ?>&<?= $queryString ?>" class="pagination-btn">
            <i class="fas fa-chevron-left"></i>
        </a>
        <?php endif; ?>
        
        <?php for ($i = max(1, $page - 1); $i <= min($totalPages, $page + 1); $i++): ?>
        <a href="?page=<?= $i ?>&<?= $queryString ?>" class="pagination-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
        
        <?php if ($page < $totalPages): ?>
        <a href="?page=<?= $page + 1 ?>&<?= $queryString ?>" class="pagination-btn">
            <i class="fas fa-chevron-right"></i>
        </a>
        <?php endif; ?>
    </div>
</div>
    <!-- Modal добавления/редактирования -->
    <div class="modal" id="addEditModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Добавить пациента</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" id="patientForm">
                <input type="hidden" name="action" id="formAction" value="add_patient">
                <input type="hidden" name="id" id="editId">
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
                        <label>Дата рождения *</label>
                        <input type="date" name="birthDate" id="birthDate" required>
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
                    <label>Адрес</label>
                    <input type="text" name="address" id="address">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Лечащий врач</label>
                        <select name="doctorId" id="doctorId">
                            <option value="">Не назначен</option>
                            <?php foreach ($doctors as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['last_name'] . ' ' . $d['first_name'] . ' – ' . $d['specialization']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Статус</label>
                        <select name="status" id="status">
                            <option value="active">Активный</option>
                            <option value="new">Новый</option>
                            <option value="inactive">Неактивный</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Медицинские заметки</label>
                    <textarea name="medicalNotes" id="medicalNotes" rows="4"></textarea>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="hasDebt" value="1"> С задолженностью
                    </label>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-save"></i> Сохранить
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal просмотра (упрощённый — в реале можно сделать отдельный endpoint) -->
    <div class="modal" id="viewModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Информация о пациенте</h2>
                <button class="modal-close" onclick="document.getElementById('viewModal').style.display='none'">&times;</button>
            </div>
            <div id="viewContent" style="padding: 1rem;"></div>
        </div>
    </div>

    <script>
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
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Добавить пациента';
            document.getElementById('formAction').value = 'add_patient';
            document.getElementById('patientForm').reset();
            document.getElementById('addEditModal').style.display = 'flex';
        }
        function editPatient(id) {
            // В реальном проекте — AJAX-запрос к get_patient.php?id=...
            alert('Редактирование пациента с ID ' + id + '. Реализуйте через AJAX в production.');
        }
        function viewPatient(id) {
            // В реальном проекте — загрузка данных через AJAX
            document.getElementById('viewContent').innerHTML = '<p>Полная информация о пациенте с ID ' + id + '</p>';
            document.getElementById('viewModal').style.display = 'flex';
        }
        function closeModal() {
            document.getElementById('addEditModal').style.display = 'none';
        }
        document.getElementById('addEditModal').addEventListener('click', e => {
            if (e.target === document.getElementById('addEditModal')) closeModal();
        });
    </script>
</body>
</html>