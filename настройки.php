<?php
session_start();
include 'config.php';

// Инициализация таблицы настроек (если пуста)
$pdo->query("
    INSERT INTO settings (id) 
    SELECT 1 
    WHERE NOT EXISTS (SELECT 1 FROM settings WHERE id = 1)
");

// Обработка сохранения настроек
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_general') {
        $clinicName = $_POST['clinicName'] ?? 'DentalCare Pro';
        $workStart = $_POST['workStart'] ?? '08:00';
        $workEnd = $_POST['workEnd'] ?? '18:00';
        $appointmentDuration = (int)($_POST['appointmentDuration'] ?? 60);
        $timezone = '+8'; // Иркутск UTC+8

        try {
            $stmt = $pdo->prepare("
                UPDATE settings 
                SET clinic_name = ?, work_start = ?, work_end = ?, 
                    appointment_duration = ?, timezone = ?
                WHERE id = 1
            ");
            $stmt->execute([$clinicName, $workStart, $workEnd, $appointmentDuration, $timezone]);
            $success = "Основные настройки сохранены!";
        } catch (PDOException $e) {
            $error = "Ошибка при сохранении: " . $e->getMessage();
        }
    }
    elseif ($action === 'export_data') {
        // Экспорт данных в JSON
        $tables = ['patients', 'doctors', 'appointments', 'settings'];
        $export = [];
        foreach ($tables as $table) {
            $export[$table] = $pdo->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
        }
        $export['export_date'] = date('c');
        $export['version'] = 'DentalCare Pro v2.0';

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="backup_' . date('Y-m-d') . '.json"');
        echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    elseif ($action === 'import_data') {
        if (isset($_FILES['backupFile']) && $_FILES['backupFile']['error'] === UPLOAD_ERR_OK) {
            $fileContent = file_get_contents($_FILES['backupFile']['tmp_name']);
            $data = json_decode($fileContent, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                try {
                    // Очистка текущих данных
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                    $pdo->exec("TRUNCATE TABLE appointments");
                    $pdo->exec("TRUNCATE TABLE patients");
                    $pdo->exec("TRUNCATE TABLE doctors");
                    $pdo->exec("TRUNCATE TABLE settings");
                    
                    // Импорт данных
                    if (isset($data['patients'])) {
                        foreach ($data['patients'] as $patient) {
                            $stmt = $pdo->prepare("
                                INSERT INTO patients (id, first_name, last_name, phone, email, birth_date, address, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $patient['id'] ?? null,
                                $patient['first_name'] ?? '',
                                $patient['last_name'] ?? '',
                                $patient['phone'] ?? '',
                                $patient['email'] ?? '',
                                $patient['birth_date'] ?? null,
                                $patient['address'] ?? '',
                                $patient['created_at'] ?? date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                    
                    if (isset($data['doctors'])) {
                        foreach ($data['doctors'] as $doctor) {
                            $stmt = $pdo->prepare("
                                INSERT INTO doctors (id, first_name, last_name, specialization, phone, email, status, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $doctor['id'] ?? null,
                                $doctor['first_name'] ?? '',
                                $doctor['last_name'] ?? '',
                                $doctor['specialization'] ?? '',
                                $doctor['phone'] ?? '',
                                $doctor['email'] ?? '',
                                $doctor['status'] ?? 'active',
                                $doctor['created_at'] ?? date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                    
                    if (isset($data['settings'])) {
                        foreach ($data['settings'] as $setting) {
                            $stmt = $pdo->prepare("
                                INSERT INTO settings (id, clinic_name, work_start, work_end, appointment_duration, timezone, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $setting['id'] ?? 1,
                                $setting['clinic_name'] ?? 'DentalCare Pro',
                                $setting['work_start'] ?? '08:00:00',
                                $setting['work_end'] ?? '18:00:00',
                                $setting['appointment_duration'] ?? 60,
                                $setting['timezone'] ?? '+8',
                                $setting['created_at'] ?? date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                    
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                    $success = "Данные успешно импортированы!";
                    
                    // Перезагружаем страницу для применения новых данных
                    echo "<script>setTimeout(function(){ location.reload(); }, 1500);</script>";
                    
                } catch (PDOException $e) {
                    $error = "Ошибка при импорте данных: " . $e->getMessage();
                }
            } else {
                $error = "Неверный формат файла резервной копии";
            }
        } else {
            $error = "Выберите файл для импорта";
        }
    }
    elseif ($action === 'reset_settings') {
        try {
            $pdo->exec("DELETE FROM settings WHERE id = 1");
            $pdo->exec("
                INSERT INTO settings (id, clinic_name, work_start, work_end, appointment_duration, timezone) 
                VALUES (1, 'DentalCare Pro', '08:00:00', '18:00:00', 60, '+8')
            ");
            $success = "Настройки сброшены к значениям по умолчанию!";
            // Перезагружаем страницу
            echo "<script>setTimeout(function(){ location.reload(); }, 1500);</script>";
        } catch (PDOException $e) {
            $error = "Ошибка при сбросе: " . $e->getMessage();
        }
    }
}

// Загрузка текущих настроек
$settings = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

// Статистика
$totalPatients = $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
$totalDoctors = $pdo->query("SELECT COUNT(*) FROM doctors")->fetchColumn();
$totalAppointments = $pdo->query("SELECT COUNT(*) FROM appointments")->fetchColumn();
$dataSize = ($totalPatients * 1024) + ($totalDoctors * 512) + ($totalAppointments * 2048);

function formatBytes($bytes) {
    $units = ['Б', 'КБ', 'МБ', 'ГБ'];
    for ($i = 0; $bytes >= 1024 && $i < 3; $i++) {
        $bytes /= 1024;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

$dataSizeFormatted = formatBytes($dataSize);
?>

<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройки системы</title>
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
            --gradient: linear-gradient(135deg, #6366f1, #8b5cf6);
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
            font-size: 1.5rem;
            font-weight: 800;
        }
        
        .logo-icon {
            background: var(--gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-size: 2rem;
        }
        
        .brand-text {
            background: var(--gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 800;
            font-size: 1.5rem;
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
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-block {
            width: 100%;
            justify-content: center;
        }
        
        .settings-layout {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 2rem;
        }
        
        .settings-sidebar {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            height: fit-content;
            position: sticky;
            top: 2rem;
        }
        
        .settings-nav {
            list-style: none;
        }
        
        .settings-nav-item {
            padding: 1rem;
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }
        
        .settings-nav-item:hover {
            background: var(--bg-secondary);
        }
        
        .settings-nav-item.active {
            background: var(--gradient);
            color: white;
        }
        
        .settings-content {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        
        .settings-section {
            margin-bottom: 2rem;
        }
        
        .section-title {
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border);
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
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .form-group input, .form-group select, .form-group textarea {
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
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .form-help {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-top: 0.5rem;
        }
        
        .backup-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }
        
        .backup-card {
            background: var(--bg-secondary);
            padding: 1.5rem;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            transition: var(--transition);
        }
        
        .backup-icon {
            width: 60px;
            height: 60px;
            background: var(--gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: white;
            font-size: 1.5rem;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .info-item {
            background: var(--bg-secondary);
            padding: 1rem;
            border-radius: var(--radius);
        }
        
        .info-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }
        
        .info-value {
            font-weight: 600;
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
            .settings-layout { 
                grid-template-columns: 1fr; 
            }
            
            .settings-sidebar {
                position: static;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard { 
                grid-template-columns: 1fr; 
            }
            
            .sidebar { 
                display: none; 
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        .file-upload {
            border: 2px dashed var(--border);
            border-radius: var(--radius);
            padding: 2rem;
            text-align: center;
            margin-bottom: 1.5rem;
        }
        
        .file-upload input {
            display: none;
        }
        
        .file-upload label {
            display: block;
            cursor: pointer;
            padding: 1rem;
        }
        
        /* Кнопка администратора */
        .user-menu .btn-outline {
            padding: 0.5rem 1rem;
            background: var(--bg-card);
            font-weight: 500;
        }
        
        .user-menu .btn-outline:hover {
            background: var(--primary);
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="nav-brand">
                    <i class="fas fa-tooth logo-icon"></i>
                    <span class="brand-text">Здоровье</span>
                </div>
            </div>
            <ul class="sidebar-menu">
                <li><a href="index.php" class="menu-item"><i class="fas fa-home"></i> Главная</a></li>
                <li><a href="запись.php" class="menu-item"><i class="fas fa-calendar-plus"></i> Запись</a></li>
                <li><a href="пациенты.php" class="menu-item"><i class="fas fa-users"></i> Пациенты</a></li>
                <li><a href="расписание.php" class="menu-item"><i class="fas fa-calendar-alt"></i> Расписание</a></li>
                <li><a href="врачи.php" class="menu-item"><i class="fas fa-user-md"></i> Врачи</a></li>
                <li><a href="настройки.php" class="menu-item active"><i class="fas fa-cog"></i> Настройки</a></li>
            </ul>
        </aside>
        
        <main class="main-content">
            <div class="content-header">
                <div class="header-title">
                    <h1>⚙️ Настройки системы</h1>
                    <p>Управление настройками клиники и резервным копированием</p>
                </div>
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

            <div class="settings-layout">
                <div class="settings-sidebar">
                    <ul class="settings-nav">
                        <li class="settings-nav-item active" data-tab="general">
                            <i class="fas fa-cog"></i> <span>Основные</span>
                        </li>
                        <li class="settings-nav-item" data-tab="backup">
                            <i class="fas fa-database"></i> <span>Резервные копии</span>
                        </li>
                        <li class="settings-nav-item" data-tab="system">
                            <i class="fas fa-info-circle"></i> <span>О системе</span>
                        </li>
                    </ul>
                </div>
                
                <div class="settings-content">
                    <!-- Основные настройки -->
                    <div class="settings-section" id="general-tab">
                        <h2 class="section-title"><i class="fas fa-cog"></i> Основные настройки</h2>
                        <form method="POST">
                            <input type="hidden" name="action" value="save_general">
                            <div class="form-group">
                                <label>Название клиники</label>
                                <input type="text" name="clinicName" value="<?= htmlspecialchars($settings['clinic_name'] ?? 'DentalCare Pro') ?>" required>
                                <div class="form-help">Отображается в шапке сайта и уведомлениях</div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Начало рабочего дня</label>
                                    <input type="time" name="workStart" value="<?= substr($settings['work_start'] ?? '08:00:00', 0, 5) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Конец рабочего дня</label>
                                    <input type="time" name="workEnd" value="<?= substr($settings['work_end'] ?? '18:00:00', 0, 5) ?>" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Длительность приёма (минут)</label>
                                <select name="appointmentDuration" required>
                                    <option value="30" <?= ($settings['appointment_duration'] ?? 60) == 30 ? 'selected' : '' ?>>30 минут</option>
                                    <option value="45" <?= ($settings['appointment_duration'] ?? 60) == 45 ? 'selected' : '' ?>>45 минут</option>
                                    <option value="60" <?= ($settings['appointment_duration'] ?? 60) == 60 ? 'selected' : '' ?>>60 минут</option>
                                    <option value="90" <?= ($settings['appointment_duration'] ?? 60) == 90 ? 'selected' : '' ?>>90 минут</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Часовой пояс</label>
                                <select name="timezone" disabled>
                                    <option value="+8" selected>Иркутск (UTC+8)</option>
                                </select>
                                <div class="form-help">Часовой пояс фиксирован для города Иркутск</div>
                            </div>
                            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Сохранить настройки
                                </button>
                                <button type="button" class="btn btn-outline" onclick="confirmReset()">
                                    <i class="fas fa-undo"></i> Сбросить к умолчаниям
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Резервные копии -->
                    <div class="settings-section" id="backup-tab" style="display:none;">
                        <h2 class="section-title"><i class="fas fa-database"></i> Резервные копии</h2>
                        
                        <div class="backup-cards">
                            <div class="backup-card">
                                <div class="backup-icon"><i class="fas fa-file-export"></i></div>
                                <h3>Экспорт данных</h3>
                                <p>Создать резервную копию всех данных в формате JSON</p>
                                <form method="POST">
                                    <input type="hidden" name="action" value="export_data">
                                    <button type="submit" class="btn btn-primary btn-block">
                                        <i class="fas fa-download"></i> Экспорт данных
                                    </button>
                                </form>
                            </div>
                            
                            <div class="backup-card">
                                <div class="backup-icon"><i class="fas fa-file-import"></i></div>
                                <h3>Импорт данных</h3>
                                <p>Восстановление из файла резервной копии</p>
                                <form method="POST" enctype="multipart/form-data" onsubmit="return confirmImport()">
                                    <input type="hidden" name="action" value="import_data">
                                    <div class="file-upload">
                                        <input type="file" name="backupFile" id="backupFile" accept=".json" required>
                                        <label for="backupFile" style="cursor: pointer;">
                                            <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; margin-bottom: 1rem; color: var(--primary);"></i>
                                            <div style="font-weight: 600; margin-bottom: 0.5rem;">Нажмите для выбора файла</div>
                                            <div style="font-size: 0.875rem; color: var(--text-secondary);">
                                                Поддерживается только формат .json
                                            </div>
                                        </label>
                                    </div>
                                    <div id="fileName" style="margin-bottom: 1rem;"></div>
                                    <button type="submit" class="btn btn-success btn-block">
                                        <i class="fas fa-upload"></i> Импорт данных
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <div class="form-help" style="margin-top: 2rem; padding: 1rem; background: var(--bg-secondary); border-radius: var(--radius);">
                            <p><strong>⚠️ Важно:</strong> Импорт данных полностью заменит текущие данные в системе.</p>
                            <p>Рекомендуется перед импортом создать резервную копию текущих данных.</p>
                        </div>
                    </div>

                    <!-- О системе -->
                    <div class="settings-section" id="system-tab" style="display:none;">
                        <h2 class="section-title"><i class="fas fa-info-circle"></i> О системе</h2>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Версия системы</div>
                                <div class="info-value">DentalCare Pro v2.0</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Дата сборки</div>
                                <div class="info-value"><?= date('d.m.Y') ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Разработчик</div>
                                <div class="info-value">Дамбиев Бэлигто Д.</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Часовой пояс</div>
                                <div class="info-value">Иркутск (UTC+8)</div>
                            </div>
                        </div>
                        
                        <div class="section-title" style="margin-top: 2rem;">
                            <i class="fas fa-database"></i> Статистика данных
                        </div>
                        
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Пациенты</div>
                                <div class="info-value"><?= $totalPatients ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Врачи</div>
                                <div class="info-value"><?= $totalDoctors ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Записи</div>
                                <div class="info-value"><?= $totalAppointments ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Объём данных</div>
                                <div class="info-value"><?= $dataSizeFormatted ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Навигация по вкладкам
            document.querySelectorAll('.settings-nav-item').forEach(item => {
                item.addEventListener('click', () => {
                    document.querySelectorAll('.settings-nav-item').forEach(i => i.classList.remove('active'));
                    document.querySelectorAll('.settings-section').forEach(s => s.style.display = 'none');
                    item.classList.add('active');
                    const tab = item.dataset.tab;
                    document.getElementById(tab + '-tab').style.display = 'block';
                });
            });

            // Переключение темы
            const themeToggle = document.getElementById('themeToggle');
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
            updateThemeIcon(savedTheme);
            
            themeToggle.addEventListener('click', () => {
                const currentTheme = document.documentElement.getAttribute('data-theme');
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', newTheme);
                localStorage.setItem('theme', newTheme);
                updateThemeIcon(newTheme);
            });

            function updateThemeIcon(theme) {
                themeToggle.innerHTML = theme === 'dark' ? 
                    '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
            }

            // Отображение имени выбранного файла
            const fileInput = document.getElementById('backupFile');
            const fileNameDisplay = document.getElementById('fileName');
            
            if (fileInput) {
                fileInput.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        fileNameDisplay.innerHTML = `
                            <div style="background: var(--bg-secondary); padding: 0.75rem; border-radius: var(--radius); border: 1px solid var(--border); display: flex; align-items: center; gap: 0.5rem;">
                                <i class="fas fa-file" style="color: var(--primary);"></i>
                                <div>
                                    <div style="font-weight: 600;">Выбран файл:</div>
                                    <div style="font-size: 0.875rem; color: var(--text-secondary);">${this.files[0].name}</div>
                                </div>
                            </div>
                        `;
                    } else {
                        fileNameDisplay.innerHTML = '';
                    }
                });
            }

            // Автоскрытие уведомлений
            setTimeout(() => {
                document.querySelectorAll('.notification').forEach(notification => {
                    notification.remove();
                });
            }, 5000);
        });

        function confirmReset() {
            if (confirm('Вы уверены, что хотите сбросить все настройки к значениям по умолчанию?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="reset_settings">';
                document.body.appendChild(form);
                form.submit();
            }
        }

        function confirmImport() {
            const fileInput = document.getElementById('backupFile');
            if (!fileInput.value) {
                alert('Пожалуйста, выберите файл для импорта');
                return false;
            }
            
            return confirm('⚠️ ВНИМАНИЕ!\n\nИмпорт данных полностью заменит все текущие данные в системе.\nЭто действие необратимо.\n\nПродолжить?');
        }
    </script>
</body>
</html>