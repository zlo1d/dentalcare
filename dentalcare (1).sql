-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Хост: 127.0.0.1:3306
-- Время создания: Ноя 19 2025 г., 07:06
-- Версия сервера: 8.0.30
-- Версия PHP: 8.1.9

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `dentalcare`
--

-- --------------------------------------------------------

--
-- Структура таблицы `activities`
--

CREATE TABLE `activities` (
  `id` int NOT NULL,
  `action` varchar(255) COLLATE utf8mb3_unicode_ci NOT NULL,
  `patient_name` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `appointment_id` int DEFAULT NULL,
  `type` varchar(50) COLLATE utf8mb3_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `appointments`
--

CREATE TABLE `appointments` (
  `id` int NOT NULL,
  `patient_id` int NOT NULL,
  `doctor_id` int NOT NULL,
  `service_id` int NOT NULL,
  `appointment_date` date NOT NULL,
  `appointment_time` time NOT NULL,
  `status` enum('confirmed','pending','cancelled','emergency') COLLATE utf8mb3_unicode_ci DEFAULT 'confirmed',
  `notes` text COLLATE utf8mb3_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

--
-- Дамп данных таблицы `appointments`
--

INSERT INTO `appointments` (`id`, `patient_id`, `doctor_id`, `service_id`, `appointment_date`, `appointment_time`, `status`, `notes`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, '2025-11-19', '10:00:00', 'confirmed', 'Первичная консультация', '2025-11-19 04:00:12', '2025-11-19 04:00:12'),
(2, 2, 3, 2, '2025-11-20', '14:30:00', 'pending', 'Плановый осмотр', '2025-11-19 04:00:12', '2025-11-19 04:00:12');

-- --------------------------------------------------------

--
-- Структура таблицы `doctors`
--

CREATE TABLE `doctors` (
  `id` int NOT NULL,
  `first_name` varchar(100) COLLATE utf8mb3_unicode_ci NOT NULL,
  `last_name` varchar(100) COLLATE utf8mb3_unicode_ci NOT NULL,
  `middle_name` varchar(100) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `specialization` varchar(100) COLLATE utf8mb3_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `experience` int DEFAULT '0',
  `rating` decimal(2,1) DEFAULT '4.5',
  `education` text COLLATE utf8mb3_unicode_ci,
  `skills` text COLLATE utf8mb3_unicode_ci,
  `schedule` text COLLATE utf8mb3_unicode_ci,
  `status` enum('available','busy','offline') COLLATE utf8mb3_unicode_ci DEFAULT 'available',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

--
-- Дамп данных таблицы `doctors`
--

INSERT INTO `doctors` (`id`, `first_name`, `last_name`, `middle_name`, `specialization`, `phone`, `email`, `experience`, `rating`, `education`, `skills`, `schedule`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Бэлигто', 'Дамбиев', 'Доржиевич', 'хирург-имплантолог', '+7 (924) 555-01-02', 'dambiev@dentalcare.ru', 8, '4.9', NULL, 'имплантация, костная пластика, синус-лифтинг', NULL, 'available', '2025-11-19 04:00:12', '2025-11-19 04:00:12'),
(2, 'Алексей', 'Иванов', 'Сергеевич', 'терапевт', '+7 (912) 345-67-89', 'ivanov@dentalcare.ru', 12, '4.8', NULL, 'терапевтическое лечение, эндодонтия', NULL, 'busy', '2025-11-19 04:00:12', '2025-11-19 04:00:12'),
(3, 'Мария', 'Сидорова', 'Константиновна', 'ортодонт', '+7 (934) 567-89-01', 'sidorova@dentalcare.ru', 10, '4.9', NULL, 'брекет-системы, элайнеры', NULL, 'available', '2025-11-19 04:00:12', '2025-11-19 04:00:12'),
(4, 'Петр', 'Кузнецов', 'Дмитриевич', 'имплантолог', '+7 (945) 678-90-12', 'kuznetsov@dentalcare.ru', 11, '4.8', NULL, 'дентальная имплантация', NULL, 'offline', '2025-11-19 04:00:12', '2025-11-19 04:00:12');

-- --------------------------------------------------------

--
-- Структура таблицы `patients`
--

CREATE TABLE `patients` (
  `id` int NOT NULL,
  `first_name` varchar(100) COLLATE utf8mb3_unicode_ci NOT NULL,
  `last_name` varchar(100) COLLATE utf8mb3_unicode_ci NOT NULL,
  `middle_name` varchar(100) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb3_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `medical_notes` text COLLATE utf8mb3_unicode_ci,
  `status` enum('active','inactive','new') COLLATE utf8mb3_unicode_ci DEFAULT 'new',
  `doctor_id` int DEFAULT NULL,
  `has_debt` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_visit` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

--
-- Дамп данных таблицы `patients`
--

INSERT INTO `patients` (`id`, `first_name`, `last_name`, `middle_name`, `phone`, `email`, `birth_date`, `address`, `medical_notes`, `status`, `doctor_id`, `has_debt`, `created_at`, `last_visit`) VALUES
(1, 'Иван', 'Петров', 'Сергеевич', '+7 (912) 345-67-89', 'ivan.petrov@example.com', '1985-03-15', 'ул. Ленина, д. 15, кв. 42', NULL, 'active', 1, 0, '2025-11-19 04:00:12', NULL),
(2, 'Мария', 'Сидорова', 'Ивановна', '+7 (923) 456-78-90', 'maria.sidorova@example.com', '1990-07-22', 'пр. Мира, д. 28, кв. 17', NULL, 'new', 3, 0, '2025-11-19 04:00:12', NULL);

-- --------------------------------------------------------

--
-- Структура таблицы `services`
--

CREATE TABLE `services` (
  `id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb3_unicode_ci NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

--
-- Дамп данных таблицы `services`
--

INSERT INTO `services` (`id`, `name`, `price`, `created_at`) VALUES
(1, 'Консультация', '1500.00', '2025-11-19 04:00:12'),
(2, 'Профессиональная чистка', '3500.00', '2025-11-19 04:00:12'),
(3, 'Пломбирование', '4000.00', '2025-11-19 04:00:12'),
(4, 'Отбеливание', '12000.00', '2025-11-19 04:00:12'),
(5, 'Имплантация', '35000.00', '2025-11-19 04:00:12'),
(6, 'Установка брекетов', '60000.00', '2025-11-19 04:00:12');

-- --------------------------------------------------------

--
-- Структура таблицы `settings`
--

CREATE TABLE `settings` (
  `id` int NOT NULL DEFAULT '1',
  `clinic_name` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT 'DentalCare Pro',
  `work_start` time DEFAULT '09:00:00',
  `work_end` time DEFAULT '18:00:00',
  `appointment_duration` int DEFAULT '60',
  `language` varchar(10) COLLATE utf8mb3_unicode_ci DEFAULT 'ru',
  `timezone` varchar(10) COLLATE utf8mb3_unicode_ci DEFAULT '+3',
  `theme` varchar(50) COLLATE utf8mb3_unicode_ci DEFAULT 'blue',
  `dark_mode` tinyint(1) DEFAULT '0',
  `compact_mode` tinyint(1) DEFAULT '0',
  `animations` tinyint(1) DEFAULT '1',
  `email_notifications` tinyint(1) DEFAULT '1',
  `sms_notifications` tinyint(1) DEFAULT '0',
  `appointment_reminders` tinyint(1) DEFAULT '1',
  `new_appointment_notifications` tinyint(1) DEFAULT '1',
  `reminder_time` int DEFAULT '24',
  `auto_backup` varchar(20) COLLATE utf8mb3_unicode_ci DEFAULT 'weekly',
  `last_backup` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

--
-- Дамп данных таблицы `settings`
--

INSERT INTO `settings` (`id`, `clinic_name`, `work_start`, `work_end`, `appointment_duration`, `language`, `timezone`, `theme`, `dark_mode`, `compact_mode`, `animations`, `email_notifications`, `sms_notifications`, `appointment_reminders`, `new_appointment_notifications`, `reminder_time`, `auto_backup`, `last_backup`, `created_at`, `updated_at`) VALUES
(1, 'DentalCare Pro', '09:00:00', '18:00:00', 60, 'ru', '+3', 'blue', 0, 0, 1, 1, 0, 1, 1, 24, 'weekly', NULL, '2025-11-19 04:00:12', '2025-11-19 04:00:12');

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `activities`
--
ALTER TABLE `activities`
  ADD PRIMARY KEY (`id`);

--
-- Индексы таблицы `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_slot` (`doctor_id`,`appointment_date`,`appointment_time`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `service_id` (`service_id`);

--
-- Индексы таблицы `doctors`
--
ALTER TABLE `doctors`
  ADD PRIMARY KEY (`id`);

--
-- Индексы таблицы `patients`
--
ALTER TABLE `patients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD KEY `phone_2` (`phone`),
  ADD KEY `doctor_id` (`doctor_id`);

--
-- Индексы таблицы `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`);

--
-- Индексы таблицы `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id` (`id`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `activities`
--
ALTER TABLE `activities`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT для таблицы `doctors`
--
ALTER TABLE `doctors`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT для таблицы `patients`
--
ALTER TABLE `patients`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT для таблицы `services`
--
ALTER TABLE `services`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `appointments_ibfk_3` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `patients`
--
ALTER TABLE `patients`
  ADD CONSTRAINT `patients_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
