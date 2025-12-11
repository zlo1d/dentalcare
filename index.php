<?php
session_start();
include 'config.php';

// Обработка формы входа
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            // Успешная авторизация
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            // Перенаправляем на эту же страницу для отображения дашборда
            header('Location: index.php');
            exit;
        } else {
            $error = "Неверный логин или пароль";
        }
    } catch (PDOException $e) {
        $error = "Ошибка при входе";
    }
}

// Проверка авторизации
$loggedIn = isset($_SESSION['user_id']);

// Загрузка данных для дашборда (только если авторизован)
$todayAppointments = 0;
$totalPatients = 0;
$totalDoctors = 0;
$recentActivities = [];

if ($loggedIn) {
    try {
        // Пациентов сегодня
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ?");
        $stmt->execute([$today]);
        $todayAppointments = (int)$stmt->fetchColumn();

        // Всего пациентов
        $totalPatients = (int)$pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();

        // Врачей в системе
        $totalDoctors = (int)$pdo->query("SELECT COUNT(*) FROM doctors")->fetchColumn();

        // Последняя активность
        $stmt = $pdo->query("
            SELECT a.created_at, p.first_name, p.last_name 
            FROM appointments a 
            JOIN patients p ON a.patient_id = p.id 
            ORDER BY a.created_at DESC 
            LIMIT 5
        ");
        $recentActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // При ошибке используем демо-данные
        $todayAppointments = 7;
        $totalPatients = 15800;
        $totalDoctors = 7;
        $recentActivities = [];
    }
}
?><!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Здоровье</title>
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
        /* Preloader - УПРОЩЕННЫЙ */
        .preloader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--bg-primary);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            transition: opacity 0.3s ease;
        }
        .loader i {
            font-size: 3rem;
            color: var(--primary);
            margin-bottom: 1rem;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .loader p {
            color: var(--text-secondary);
            font-size: 1.1rem;
        }
        /* Header */
        .main-header {
            padding: 1rem 0;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            background: var(--bg-primary);
            z-index: 100;
        }
        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .nav-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.5rem;
            font-weight: 700;
        }
        .logo-icon {
            color: var(--primary);
            font-size: 2rem;
        }
        .brand-text {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .nav-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
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
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: var(--shadow-lg); }
        .btn-outline { background: transparent; border: 2px solid var(--primary); color: var(--primary); }
        .btn-outline:hover { background: var(--primary); color: white; }
        .btn-block { width: 100%; justify-content: center; }
        .btn-large { padding: 1rem 2rem; font-size: 1rem; }
        /* Hero Section */
        .hero {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            padding: 4rem 0;
            align-items: center;
        }
        .hero-title {
            font-size: 3rem;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 1.5rem;
        }
        .text-gradient {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .hero-description {
            font-size: 1.25rem;
            color: var(--text-secondary);
            margin-bottom: 2rem;
        }
        .hero-features {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .feature {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: var(--bg-secondary);
            border-radius: var(--radius);
        }
        .feature i { color: var(--primary); }
        .dashboard-preview {
            background: var(--bg-card);
            border-radius: var(--radius);
            box-shadow: var(--shadow-xl);
            overflow: hidden;
        }
        .preview-header {
            background: var(--primary);
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .preview-dots {
            display: flex;
            gap: 0.5rem;
        }
        .preview-dots span {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: rgba(255,255,255,0.3);
        }
        .preview-content {
            padding: 1.5rem;
        }
        .preview-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .preview-stat {
            text-align: center;
            padding: 1rem;
            background: var(--bg-secondary);
            border-radius: var(--radius);
        }
        .preview-stat small {
            display: block;
            color: var(--text-secondary);
            font-size: 0.75rem;
            margin-bottom: 0.5rem;
        }
        .preview-stat strong {
            font-size: 1.5rem;
            color: var(--primary);
        }
        .preview-calendar {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
        }
        .calendar-day {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius);
            background: var(--bg-secondary);
            font-weight: 600;
        }
        .calendar-day.current {
            background: var(--primary);
            color: white;
        }
        .calendar-day.busy {
            background: var(--warning);
            color: white;
        }
        /* Features Section */
        .features-section {
            padding: 4rem 0;
        }
        .features-section h2 {
            text-align: center;
            font-size: 2.5rem;
            margin-bottom: 3rem;
        }
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }
        .feature-card {
            background: var(--bg-card);
            padding: 2rem;
            border-radius: var(--radius);
            text-align: center;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }
        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        .feature-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            color: white;
            font-size: 2rem;
        }
        .feature-card h3 {
            margin-bottom: 1rem;
            font-size: 1.25rem;
        }
        .feature-card p {
            color: var(--text-secondary);
        }
        /* Dashboard Layout */
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
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: var(--bg-card);
            padding: 1.5rem;
            border-radius: var(--radius);
            border-left: 4px solid var(--primary);
            box-shadow: var(--shadow);
        }
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        .recent-activity {
            background: var(--bg-card);
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
        }
        .activity-list {
            list-style: none;
        }
        .activity-item {
            padding: 1rem 0;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .activity-item:last-child { border-bottom: none; }
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--bg-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }
        .activity-content { flex: 1; }
        .activity-title { font-weight: 500; margin-bottom: 0.25rem; }
        .activity-time { color: var(--text-secondary); font-size: 0.875rem; }
        /* Auth Modal */
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
            max-width: 400px;
            box-shadow: var(--shadow-xl);
        }
        .auth-tabs {
            display: flex;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--border);
        }
        .tab-btn {
            flex: 1;
            padding: 1rem;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 500;
            color: var(--text-secondary);
            border-bottom: 2px solid transparent;
        }
        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        .auth-form {
            display: none;
        }
        .auth-form.active {
            display: block;
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
        .form-group input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 1rem;
            transition: var(--transition);
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        /* Notifications */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            color: white;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            z-index: 1001;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            box-shadow: var(--shadow-lg);
        }
        .notification.show {
            transform: translateX(0);
        }
        .notification.success {
            background: var(--success);
        }
        .notification.error {
            background: var(--danger);
        }
        .notification-close {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            padding: 0;
            margin-left: auto;
        }
        @media (max-width: 768px) {
            .dashboard { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .hero { grid-template-columns: 1fr; gap: 2rem; padding: 2rem 0; }
            .hero-title { font-size: 2rem; }
            .features-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <!-- Preloader - УПРОЩЕННЫЙ -->
    <div class="preloader" id="preloader">
        <div class="loader">
            <i class="fas fa-tooth"></i>
            <p>Загрузка системы...</p>
        </div>
    </div>

    <!-- Login Modal -->
    <div class="modal" id="loginModal">
        <div class="modal-content">
            <div class="auth-tabs">
                <button class="tab-btn active" data-tab="login">Вход</button>
            </div>
            <form method="POST" class="auth-form active">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <label for="username"><i class="fas fa-user"></i> Логин</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password"><i class="fas fa-lock"></i> Пароль</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-sign-in-alt"></i> Войти в систему
                </button>
            </form>
        </div>
    </div>

    <!-- Main App -->
    <div id="app">
        <?php if (!$loggedIn): ?>
        <!-- Landing Page -->
        <div id="landingPage">
            <div class="container">
                <header class="main-header">
                    <nav class="navbar">
                        <div class="nav-brand">
                            <i class="fas fa-tooth logo-icon"></i>
                            <span class="brand-text">Здоровье<strong></strong></span>
                        </div>
                        <div class="nav-actions">
                            <button class="theme-toggle" id="themeToggle">
                                <i class="fas fa-moon"></i>
                            </button>
                            <button class="btn btn-outline" id="loginBtn">
                                <i class="fas fa-sign-in-alt"></i> Вход для персонала
                            </button>
                        </div>
                    </nav>
                </header>
                <section class="hero">
                    <div class="hero-content">
                        <h1 class="hero-title">
                            Профессиональная система управления 
                            <span class="text-gradient">стоматологической клиникой</span>
                        </h1>
                        <p class="hero-description">
                            Полный контроль над записями, пациентами, расписанием и финансами. 
                            Умная аналитика и удобный интерфейс для эффективной работы вашей клиники.
                        </p>
                        <div class="hero-features">
                            <div class="feature">
                                <i class="fas fa-calendar-check"></i>
                                <span>Онлайн-запись</span>
                            </div>
                            <div class="feature">
                                <i class="fas fa-chart-line"></i>
                                <span>Аналитика</span>
                            </div>
                            <div class="feature">
                                <i class="fas fa-bell"></i>
                                <span>Уведомления</span>
                            </div>
                            <div class="feature">
                                <i class="fas fa-mobile-alt"></i>
                                <span>PWA</span>
                            </div>
                        </div>
                        <button class="btn btn-primary btn-large" id="demoBtn">
                            <i class="fas fa-play"></i> Посмотреть демо
                        </button>
                    </div>
                    <div class="hero-visual">
                        <div class="dashboard-preview">
                            <div class="preview-header">
                                <div class="preview-dots">
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                </div>
                            </div>
                            <div class="preview-content">
                                <div class="preview-stats">
                                    <div class="preview-stat">
                                        <small>Пациенты сегодня</small>
                                        <strong><?= $todayAppointments ?></strong>
                                    </div>
                                    <div class="preview-stat">
                                        <small>Доход</small>
                                        <strong><?= number_format($totalPatients, 0, ' ', ' ') ?> ₽</strong>
                                    </div>
                                </div>
                                <div class="preview-calendar">
                                    <div class="calendar-day busy">15</div>
                                    <div class="calendar-day current">16</div>
                                    <div class="calendar-day">17</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
                <section class="features-section">
                    <h2>Возможности системы</h2>
                    <div class="features-grid">
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <h3>Умное расписание</h3>
                            <p>Интеллектуальное планирование с учетом занятости врачей и приоритетов</p>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-user-injured"></i>
                            </div>
                            <h3>Карты пациентов</h3>
                            <p>Полная медицинская история, аллергии, предыдущие лечения</p>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                            <h3>Аналитика</h3>
                            <p>Детальные отчеты по доходам, популярным услугам и загрузке врачей</p>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-bell"></i>
                            </div>
                            <h3>Напоминания</h3>
                            <p>Автоматические SMS и email уведомления пациентам</p>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </div>
                            <h3>Финансы</h3>
                            <p>Учет расходов, формирование счетов и финансовые отчеты</p>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-mobile-alt"></i>
                            </div>
                            <h3>Мобильная версия</h3>
                            <p>Полная функциональность на всех устройствах, оффлайн-режим</p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
        <?php else: ?>
        <!-- Dashboard -->
        <div class="dashboard">
            <aside class="sidebar">
                <div class="nav-brand" style="padding: 0 1.5rem 1.5rem;">
                    <i class="fas fa-tooth logo-icon"></i>
                    <span class="brand-text">Здоровье <strong></strong></span>
                </div>
                <ul class="sidebar-menu">
                    <li><a href="index.php" class="menu-item active"><i class="fas fa-home"></i> Главная</a></li>
                    <li><a href="запись.php" class="menu-item"><i class="fas fa-calendar-plus"></i> Запись</a></li>
                    <li><a href="пациенты.php" class="menu-item"><i class="fas fa-users"></i> Пациенты</a></li>
                    <li><a href="расписание.php" class="menu-item"><i class="fas fa-calendar-alt"></i> Расписание</a></li>
                    <li><a href="врачи.php" class="menu-item"><i class="fas fa-user-md"></i> Врачи</a></li>
                    <li><a href="настройки.php" class="menu-item"><i class="fas fa-cog"></i> Настройки</a></li>
                </ul>
            </aside>
            <main class="main-content">
                <div class="content-header">
                    <h1>Главная</h1>
                    <div class="nav-actions">
                        <button class="theme-toggle" id="dashboardThemeToggle">
                            <i class="fas fa-moon"></i>
                        </button>
                        <div class="user-menu">
                            <button class="btn btn-outline">
                                <i class="fas fa-user-circle"></i> <?= htmlspecialchars($_SESSION['username'] ?? 'Администратор') ?>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?= $todayAppointments ?></div>
                        <div class="stat-label">Пациентов сегодня</div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-value"><?= number_format($totalPatients, 0, ' ', ' ') ?> ₽</div>
                        <div class="stat-label">Доход за сегодня</div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-value"><?= $totalDoctors ?></div>
                        <div class="stat-label">Врачей в системе</div>
                    </div>
                    <div class="stat-card danger">
                        <div class="stat-value">0</div>
                        <div class="stat-label">Отмененные записи</div>
                    </div>
                </div>
                <div class="recent-activity">
                    <h3>Последняя активность</h3>
                    <ul class="activity-list">
                        <?php if (empty($recentActivities)): ?>
                        <li class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-info-circle"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Нет активностей</div>
                                <div class="activity-time">Создайте первую запись</div>
                            </div>
                        </li>
                        <?php else: ?>
                        <?php foreach($recentActivities as $activity): ?>
                        <li class="activity-item">
                            <div class="activity-icon">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">Запись создана - <?= htmlspecialchars($activity['first_name'] . ' ' . $activity['last_name']) ?></div>
                                <div class="activity-time"><?= date('H:i', strtotime($activity['created_at'])) ?></div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </main>
        </div>
        <?php endif; ?>
    </div>

    <?php if (isset($error)): ?>
    <div class="notification error show">
        <i class="fas fa-exclamation-circle"></i>
        <span><?= htmlspecialchars($error) ?></span>
        <button class="notification-close" onclick="this.parentElement.remove()">&times;</button>
    </div>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            console.log('DOM загружен');

            // Сразу скрываем прелоадер
            const preloader = document.getElementById('preloader');
            if (preloader) {
                console.log('Скрываем прелоадер');
                preloader.style.opacity = '0';
                setTimeout(() => {
                    preloader.style.display = 'none';
                }, 300);
            }

            // Тема
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
            updateThemeIcon(savedTheme);
            
            document.getElementById('themeToggle').addEventListener('click', () => {
                const current = document.documentElement.getAttribute('data-theme');
                const next = current === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', next);
                localStorage.setItem('theme', next);
                updateThemeIcon(next);
            });
            
            document.getElementById('dashboardThemeToggle')?.addEventListener('click', () => {
                const current = document.documentElement.getAttribute('data-theme');
                const next = current === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', next);
                localStorage.setItem('theme', next);
                updateThemeIcon(next);
            });

            // Модальное окно входа
            document.getElementById('loginBtn')?.addEventListener('click', () => {
                document.getElementById('loginModal').style.display = 'flex';
            });
            
            document.getElementById('demoBtn')?.addEventListener('click', () => {
                document.getElementById('username').value = 'admin';
                document.getElementById('password').value = 'admin123';
                document.getElementById('loginModal').style.display = 'flex';
            });
            
            document.getElementById('loginModal')?.addEventListener('click', (e) => {
                if (e.target === document.getElementById('loginModal')) {
                    document.getElementById('loginModal').style.display = 'none';
                }
            });

            // Автоматическое скрытие уведомлений через 5 секунд
            const notifications = document.querySelectorAll('.notification.show');
            notifications.forEach(notification => {
                setTimeout(() => {
                    notification.remove();
                }, 5000);
            });
        });

        function updateThemeIcon(theme) {
            const icons = document.querySelectorAll('.theme-toggle i');
            icons.forEach(icon => {
                icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
            });
        }

        // Дополнительная гарантия скрытия прелоадера
        window.addEventListener('load', () => {
            console.log('Window loaded');
            const preloader = document.getElementById('preloader');
            if (preloader) {
                setTimeout(() => {
                    preloader.style.opacity = '0';
                    setTimeout(() => {
                        preloader.style.display = 'none';
                    }, 300);
                }, 100);
            }
        });
    </script>
</body>
</html>