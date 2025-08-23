-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost: 3307
-- Generation Time: Aug 22, 2025 at 05:27 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `otp_login`
--

-- --------------------------------------------------------

--
-- Table structure for table `approvals`
--

CREATE TABLE `approvals` (
  `id` int(11) NOT NULL,
  `entity_type` enum('fuel','supply') NOT NULL,
  `entity_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `requested_by` varchar(100) DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `decided_by` varchar(100) DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `budgets`
--

CREATE TABLE `budgets` (
  `id` int(11) NOT NULL,
  `month_year` char(7) NOT NULL,
  `category` enum('fuel','repairs','supplies') NOT NULL,
  `amount` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `budgets`
--

INSERT INTO `budgets` (`id`, `month_year`, `category`, `amount`) VALUES
(1, '2025-07', 'fuel', 0.00),
(2, '2025-07', 'repairs', 0.00),
(3, '2025-07', 'supplies', 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `drivers`
--

CREATE TABLE `drivers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `status` enum('available','on_trip','unavailable') DEFAULT 'available',
  `current_location_lat` decimal(10,8) DEFAULT NULL,
  `current_location_lng` decimal(11,8) DEFAULT NULL,
  `shift_end_time` time DEFAULT NULL,
  `rating_average` decimal(3,2) DEFAULT 5.00,
  `profile_id` int(11) DEFAULT NULL,
  `shift_end` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `drivers`
--

INSERT INTO `drivers` (`id`, `name`, `phone`, `status`, `current_location_lat`, `current_location_lng`, `shift_end_time`, `rating_average`, `profile_id`, `shift_end`) VALUES
(1, 'John Doe', '09171234567', 'on_trip', NULL, NULL, NULL, 5.00, NULL, NULL),
(2, 'Jane Smith', '09179876543', 'on_trip', NULL, NULL, NULL, 5.00, NULL, NULL),
(3, 'Patrick Martinez', '09171234567', 'available', NULL, NULL, NULL, 5.00, NULL, NULL),
(4, 'Fred Troy', '09914558732', 'available', NULL, NULL, NULL, 5.00, NULL, NULL),
(5, 'Sebastian Perez', '09977234569', 'on_trip', NULL, NULL, NULL, 5.00, NULL, NULL),
(6, 'Yuzo Torres', '09969558732', 'on_trip', NULL, NULL, NULL, 5.00, NULL, NULL),
(7, 'George Hernandez', '09913693577', 'on_trip', NULL, NULL, NULL, 5.00, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `driver_compliance`
--

CREATE TABLE `driver_compliance` (
  `id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `nbi_clearance_expiry` date DEFAULT NULL,
  `medical_clearance_expiry` date DEFAULT NULL,
  `training_cert_expiry` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `driver_documents`
--

CREATE TABLE `driver_documents` (
  `document_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `doc_type` enum('license','nbi','medical','training','other') NOT NULL,
  `doc_number` varchar(100) DEFAULT NULL,
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `status` enum('valid','expired','pending') DEFAULT 'valid',
  `uploaded_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `driver_events`
--

CREATE TABLE `driver_events` (
  `id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `event_type` enum('accident','complaint','commendation') NOT NULL,
  `event_date` date DEFAULT curdate(),
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(100) DEFAULT 'admin1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `driver_incidents`
--

CREATE TABLE `driver_incidents` (
  `id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `incident_type` enum('accident','complaint','commendation') NOT NULL,
  `description` text DEFAULT NULL,
  `severity` enum('low','medium','high') DEFAULT 'low',
  `status` enum('open','in_review','closed') DEFAULT 'open',
  `reported_by` varchar(100) DEFAULT NULL,
  `reported_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `driver_performance`
--

CREATE TABLE `driver_performance` (
  `performance_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `total_trips` int(11) DEFAULT 0,
  `completed_trips` int(11) DEFAULT 0,
  `cancelled_trips` int(11) DEFAULT 0,
  `accident_reports` int(11) DEFAULT 0,
  `customer_rating` decimal(3,2) DEFAULT 0.00,
  `last_trip_date` date DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `driver_profiles`
--

CREATE TABLE `driver_profiles` (
  `driver_id` int(11) NOT NULL,
  `hr_employee_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `license_number` varchar(50) DEFAULT NULL,
  `license_expiry` date DEFAULT NULL,
  `status` enum('active','inactive','suspended','terminated') DEFAULT 'active',
  `rating` decimal(3,2) DEFAULT 5.00,
  `conflict_count` int(11) DEFAULT 0,
  `date_hired` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `driver_profiles`
--

INSERT INTO `driver_profiles` (`driver_id`, `hr_employee_id`, `first_name`, `last_name`, `contact_number`, `email`, `address`, `license_number`, `license_expiry`, `status`, `rating`, `conflict_count`, `date_hired`, `created_at`, `updated_at`) VALUES
(1, NULL, 'Juan', 'Dela Cruz', '09171234567', 'juan@example.com', 'Quezon City', 'ABC12345', '2026-05-20', 'active', 4.80, 1, '2022-01-15', '2025-08-17 05:20:00', '2025-08-17 05:20:00'),
(2, NULL, 'Maria', 'Santos', '09179876543', 'maria@example.com', 'Makati', 'XYZ98765', '2025-11-10', 'active', 4.50, 0, '2021-09-10', '2025-08-17 05:20:00', '2025-08-17 05:20:00'),
(3, NULL, 'Pedro', 'Reyes', '09991234567', 'pedro@example.com', 'Pasig', 'LMN45678', '2025-01-05', 'active', 3.90, 3, '2020-05-30', '2025-08-17 05:20:00', '2025-08-17 05:20:00'),
(5, 101, 'Juan', 'Dela Cruz', '09171234567', 'cruz@example.com', 'Manila', 'LIC12345', '2026-01-01', 'active', 5.00, 0, '2023-06-01', '2025-08-17 06:42:31', '2025-08-17 06:42:31'),
(6, 102, 'Maria', 'Santos', '09987654321', 'polis@example.com', 'Quezon City', 'LIC54321', '2025-12-31', 'active', 5.00, 0, '2024-01-15', '2025-08-17 06:42:31', '2025-08-17 06:42:31');

-- --------------------------------------------------------

--
-- Table structure for table `fuel_logs`
--

CREATE TABLE `fuel_logs` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `filled_at` datetime NOT NULL DEFAULT current_timestamp(),
  `liters` decimal(10,2) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `odometer_km` int(11) DEFAULT NULL,
  `station` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `issued_items`
--

CREATE TABLE `issued_items` (
  `issued_id` int(11) NOT NULL,
  `supply_id` int(11) NOT NULL,
  `issued_to` varchar(255) NOT NULL,
  `quantity_issued` int(11) NOT NULL,
  `issued_by` varchar(100) DEFAULT NULL,
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `vehicle_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `issued_items`
--

INSERT INTO `issued_items` (`issued_id`, `supply_id`, `issued_to`, `quantity_issued`, `issued_by`, `issued_at`, `vehicle_id`) VALUES
(1, 1, 'Fleet Maintenance', 10, 'storeclerk', '2025-08-10 13:59:21', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `items`
--

CREATE TABLE `items` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `unit` varchar(50) DEFAULT 'pcs',
  `description` text DEFAULT NULL,
  `stock_quantity` int(11) DEFAULT 0,
  `reorder_level` int(11) DEFAULT 5,
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `items`
--

INSERT INTO `items` (`id`, `name`, `unit`, `description`, `stock_quantity`, `reorder_level`, `unit_cost`, `supplier_id`, `created_at`, `updated_at`) VALUES
(1, 'Engine Oil 1L', 'pcs', 'High-quality synthetic engine oil.', 120, 20, 350.00, 1, '2025-08-18 11:09:31', '2025-08-18 11:57:12'),
(2, 'Fuel Filter', 'pcs', 'Standard diesel fuel filter.', 50, 10, 600.00, 1, '2025-08-18 11:09:31', '2025-08-18 11:57:12'),
(3, 'Diesel Fuel', 'pcs', 'Diesel supply for fleet refueling.', 500, 100, 65.00, 2, '2025-08-18 11:09:31', '2025-08-18 11:57:12'),
(4, 'Tires 16in', 'pcs', 'Heavy-duty 16 inch tires.', 30, 5, 3000.00, 3, '2025-08-18 11:09:31', '2025-08-18 11:57:12'),
(5, 'Brake Pads', 'pcs', 'Front and rear brake pads.', 75, 15, 1500.00, 1, '2025-08-18 11:09:31', '2025-08-18 11:57:12'),
(6, 'Engine Oil 5L', '30', '', 10, 7, 2500.00, 5, '2025-08-18 11:57:16', '2025-08-18 11:57:16');

-- --------------------------------------------------------

--
-- Table structure for table `item_usage`
--

CREATE TABLE `item_usage` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `used_for_trip` int(11) DEFAULT NULL,
  `used_by_driver` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `item_usage`
--

INSERT INTO `item_usage` (`id`, `item_id`, `quantity`, `used_for_trip`, `used_by_driver`, `created_at`) VALUES
(1, 1, 10, 101, 201, '2025-08-18 11:09:31'),
(2, 2, 5, 102, 202, '2025-08-18 11:09:31'),
(3, 3, 100, 103, 203, '2025-08-18 11:09:31'),
(4, 5, 8, 104, 204, '2025-08-18 11:09:31');

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_records`
--

CREATE TABLE `maintenance_records` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `details` text NOT NULL,
  `cost` decimal(10,2) DEFAULT 0.00,
  `scheduled_date` date DEFAULT NULL,
  `completed_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_rules`
--

CREATE TABLE `maintenance_rules` (
  `id` int(11) NOT NULL,
  `rule_name` varchar(100) NOT NULL,
  `type` enum('km','time','mixed') NOT NULL DEFAULT 'km',
  `interval_km` int(11) DEFAULT NULL,
  `interval_days` int(11) DEFAULT NULL,
  `service_type` varchar(100) NOT NULL,
  `notes` text DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `maintenance_rules`
--

INSERT INTO `maintenance_rules` (`id`, `rule_name`, `type`, `interval_km`, `interval_days`, `service_type`, `notes`, `active`) VALUES
(1, 'Oil Change 5k', 'km', 5000, NULL, 'Oil Change', 'Change oil & filter', 1),
(2, 'Brake Inspection 6mo', 'time', NULL, 180, 'Brake Inspection', 'Check pads, fluid, rotors', 1),
(3, 'General PM 10k/12mo', 'mixed', 10000, 365, 'Periodic Maintenance', 'Full PM', 1);

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_schedule`
--

CREATE TABLE `maintenance_schedule` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `scheduled_date` date NOT NULL,
  `type` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('scheduled','done','missed','cancelled') DEFAULT 'scheduled',
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `approval_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` varchar(100) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `auto_generated` tinyint(1) DEFAULT 0,
  `cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `estimated_cost` decimal(12,2) DEFAULT NULL,
  `actual_cost` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `maintenance_schedule`
--

INSERT INTO `maintenance_schedule` (`id`, `vehicle_id`, `scheduled_date`, `type`, `notes`, `status`, `created_by`, `created_at`, `approval_status`, `approved_by`, `approved_at`, `auto_generated`, `cost`, `estimated_cost`, `actual_cost`) VALUES
(1, 1, '2025-08-16', 'Oil Change', 'Priority', 'done', 'fleetstaff', '2025-08-10 13:39:11', 'pending', NULL, NULL, 0, 0.00, NULL, NULL),
(2, 2, '2025-08-23', 'Change Tires', '', 'missed', 'fleetstaff', '2025-08-18 08:49:17', 'pending', NULL, NULL, 0, 0.00, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `odometer_logs`
--

CREATE TABLE `odometer_logs` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `reading_km` int(11) NOT NULL,
  `logged_at` datetime NOT NULL DEFAULT current_timestamp(),
  `source` varchar(60) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `parts_history`
--

CREATE TABLE `parts_history` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `part_name` varchar(100) NOT NULL,
  `replacement_date` date NOT NULL,
  `lifespan_km` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `parts_replacements`
--

CREATE TABLE `parts_replacements` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `part_name` varchar(120) NOT NULL,
  `replaced_at` date NOT NULL,
  `odometer_km` int(11) DEFAULT NULL,
  `cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `expected_life_km` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `repair_logs`
--

CREATE TABLE `repair_logs` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `log_date` datetime DEFAULT current_timestamp(),
  `description` text DEFAULT NULL,
  `cost` decimal(12,2) DEFAULT 0.00,
  `performed_by` varchar(150) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `part_lifespan_months` int(11) DEFAULT NULL,
  `parts_used` varchar(255) DEFAULT NULL,
  `next_replacement_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `repair_logs`
--

INSERT INTO `repair_logs` (`id`, `vehicle_id`, `log_date`, `description`, `cost`, `performed_by`, `created_by`, `created_at`, `part_lifespan_months`, `parts_used`, `next_replacement_date`) VALUES
(1, 2, '2025-08-10 21:39:51', 'Change Tires', 2500.00, 'Mechanic', 'fleetstaff', '2025-08-10 13:39:51', NULL, NULL, NULL),
(2, 1, '2025-08-18 16:48:00', 'Change Oil', 1350.00, '', 'fleetstaff', '2025-08-18 08:48:45', NULL, NULL, NULL),
(3, 1, '2025-08-23 08:00:00', 'replacing brake pads', 5600.00, 'mechanic', 'fleetstaff', '2025-08-18 10:17:24', 6, 'brake pads', '2026-02-23');

-- --------------------------------------------------------

--
-- Table structure for table `stock_history`
--

CREATE TABLE `stock_history` (
  `history_id` int(11) NOT NULL,
  `supply_id` int(11) NOT NULL,
  `action_type` enum('Added','Issued','Updated') NOT NULL,
  `quantity_change` int(11) NOT NULL,
  `action_by` varchar(100) DEFAULT NULL,
  `action_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_history`
--

INSERT INTO `stock_history` (`history_id`, `supply_id`, `action_type`, `quantity_change`, `action_by`, `action_at`) VALUES
(1, 1, 'Issued', -10, 'storeclerk', '2025-08-10 13:59:21');

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `name`, `contact_person`, `phone`, `email`, `address`, `category`, `created_at`) VALUES
(1, 'AutoParts Plus', 'Carlos Reyes', '09171234567', 'autoparts@example.com', 'Quezon City', NULL, '2025-08-18 11:06:15'),
(2, 'FuelMasters Inc.', 'Maria Santos', '09998887777', 'fuelmasters@example.com', 'Makati City', NULL, '2025-08-18 11:06:15'),
(3, 'TireWorks Trading', 'James Cruz', '09223334444', 'tireworks@example.com', 'Pasig City', NULL, '2025-08-18 11:06:15'),
(4, 'AutoParts Plus', 'Carlos Reyes', '09171234567', 'autoparts@example.com', 'Quezon City', 'Parts', '2025-08-18 11:09:31'),
(5, 'FuelMasters Inc.', 'Maria Santos', '09998887777', 'fuelmasters@example.com', 'Makati City', 'Fuel', '2025-08-18 11:09:31'),
(6, 'TireWorks Trading', 'James Cruz', '09223334444', 'tireworks@example.com', 'Pasig City', 'Tires', '2025-08-18 11:09:31'),
(7, 'AutoParts Makati', 'Jeffrey Manzano', '09912006841', 'automakati@gmail.com', 'Makati City', NULL, '2025-08-18 12:03:43');

-- --------------------------------------------------------

--
-- Table structure for table `supplies`
--

CREATE TABLE `supplies` (
  `supply_id` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `unit` varchar(50) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `supplies`
--

INSERT INTO `supplies` (`supply_id`, `item_name`, `description`, `quantity`, `unit`, `created_by`, `created_at`) VALUES
(1, 'Engine Oil 5W-30', 'Synthetic oil 1L', 40, 'bottle', 'system', '2025-08-10 13:58:55'),
(2, 'Brake Pads', 'Front brake pads', 20, 'set', 'system', '2025-08-10 13:58:55');

-- --------------------------------------------------------

--
-- Table structure for table `supply_prices`
--

CREATE TABLE `supply_prices` (
  `id` int(11) NOT NULL,
  `supply_id` int(11) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `currency` char(3) DEFAULT 'PHP'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supply_requests`
--

CREATE TABLE `supply_requests` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `requested_by` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `priority` enum('high','medium','low') DEFAULT 'medium',
  `status` enum('pending','approved','issued') DEFAULT 'pending',
  `note` text DEFAULT NULL,
  `trip_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `supply_requests`
--

INSERT INTO `supply_requests` (`id`, `item_id`, `requested_by`, `quantity`, `priority`, `status`, `note`, `trip_id`, `created_at`) VALUES
(1, 1, 'Dispatcher', 20, 'high', 'pending', NULL, 101, '2025-08-18 11:09:31'),
(2, 3, 'Fleet Staff', 200, 'high', 'approved', NULL, 102, '2025-08-18 11:09:31'),
(3, 4, 'Operations Manager', 4, 'medium', 'pending', NULL, 103, '2025-08-18 11:09:31'),
(4, 2, 'Fleet Staff', 10, 'low', 'issued', NULL, 104, '2025-08-18 11:09:31'),
(5, 4, 'storeclerk', 15, 'medium', 'pending', 'immediately', NULL, '2025-08-18 12:01:40');

-- --------------------------------------------------------

--
-- Table structure for table `trips`
--

CREATE TABLE `trips` (
  `id` int(11) NOT NULL,
  `trip_code` varchar(20) NOT NULL,
  `passenger_name` varchar(100) DEFAULT NULL,
  `customer_contact` varchar(50) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `vehicle_id` int(11) DEFAULT NULL,
  `origin` varchar(255) DEFAULT NULL,
  `pickup_lat` decimal(10,8) DEFAULT NULL,
  `pickup_lng` decimal(11,8) DEFAULT NULL,
  `destination` varchar(255) DEFAULT NULL,
  `dropoff_lat` decimal(10,8) DEFAULT NULL,
  `dropoff_lng` decimal(11,8) DEFAULT NULL,
  `scheduled_time` datetime DEFAULT NULL,
  `status` enum('pending','ongoing','completed','cancelled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `priority` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `trips`
--

INSERT INTO `trips` (`id`, `trip_code`, `passenger_name`, `customer_contact`, `driver_id`, `vehicle_id`, `origin`, `pickup_lat`, `pickup_lng`, `destination`, `dropoff_lat`, `dropoff_lng`, `scheduled_time`, `status`, `created_at`, `priority`) VALUES
(1, 'TRP68983F56C7FC2', 'Teddy Mangyan', NULL, 2, NULL, 'Taguig', NULL, NULL, 'Cavite', NULL, NULL, '2025-08-10 16:43:00', 'ongoing', '2025-08-10 06:42:30', 0),
(2, 'TRP1AFDE3500E88', 'Jeff Cortez', NULL, 1, NULL, 'Bldg. H 123 Pasong Putik, Kaligayan, Quezon City', NULL, NULL, 'Robinson', NULL, NULL, '2025-08-14 18:32:06', 'ongoing', '2025-08-14 16:32:06', 0),
(3, 'TRPA2506AA924A2', 'Teddy Mangyan', NULL, 3, NULL, 'Taguig', NULL, NULL, 'Cavite', NULL, NULL, '2025-08-14 18:55:37', 'completed', '2025-08-14 16:55:37', 1),
(4, 'TRP991593845121', 'Jennie Lopez', NULL, 4, NULL, 'MOA', 14.53518180, 120.98159940, 'PITX', 14.50847750, 120.99127120, '2025-08-15 02:53:50', 'cancelled', '2025-08-14 18:53:50', 0),
(8, 'TRP20FC082B27D6', 'Kyle', NULL, 3, NULL, 'SM Fairview', 14.73388760, 121.05613700, 'SM Bacoor', 14.40803830, 120.97331850, '2025-08-15 03:14:34', 'completed', '2025-08-14 19:14:34', 1),
(9, 'TRP61EA98745489', 'Daniella', NULL, 5, NULL, 'Bldg. L-131 Smile Citihomes 1', NULL, NULL, 'SM Bacoor Entrance', NULL, NULL, '2025-08-15 03:19:15', 'ongoing', '2025-08-14 19:19:15', 0),
(10, 'TRP7444D3FEDB12', 'Kervin', NULL, 4, NULL, 'Bldg. L-131 Smile Citihomes 1', NULL, NULL, 'SM Bacoor Entrance', NULL, NULL, '2025-08-15 03:21:11', 'completed', '2025-08-14 19:21:11', 1),
(11, 'TRPE1E2420EF044', 'Keirye', '09912564113', 6, NULL, 'Bldg. L-131 Smile Citihomes 1', NULL, NULL, 'SM Bacoor', 14.40803830, 120.97331850, '2025-08-17 12:14:55', 'ongoing', '2025-08-17 04:14:55', 0),
(12, 'TRPFBD3BB29BAE4', 'Henry Soriano', '09913597029', 7, NULL, 'Parqal', 14.52697280, 120.98753860, 'SM Tungko', 14.78574380, 121.07575130, '2025-08-20 19:39:26', 'ongoing', '2025-08-20 11:39:26', 0);

-- --------------------------------------------------------

--
-- Table structure for table `trip_assignments`
--

CREATE TABLE `trip_assignments` (
  `id` int(11) NOT NULL,
  `trip_id` int(11) NOT NULL,
  `driver_id` int(11) NOT NULL,
  `assigned_by` varchar(100) DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('assigned','reassigned','cancelled') DEFAULT 'assigned'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `trip_assignments`
--

INSERT INTO `trip_assignments` (`id`, `trip_id`, `driver_id`, `assigned_by`, `assigned_at`, `status`) VALUES
(1, 8, 3, NULL, '2025-08-17 04:13:27', 'assigned'),
(2, 4, 4, NULL, '2025-08-17 04:15:33', 'cancelled'),
(3, 10, 4, NULL, '2025-08-17 06:56:42', 'assigned'),
(4, 9, 5, NULL, '2025-08-20 11:18:59', 'assigned'),
(5, 11, 6, 'dispatcher', '2025-08-20 11:32:59', 'assigned'),
(6, 12, 7, 'dispatcher', '2025-08-20 11:40:50', 'assigned');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `otp` varchar(6) DEFAULT NULL,
  `otp_expiry` datetime DEFAULT NULL,
  `role` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `otp`, `otp_expiry`, `role`) VALUES
(1, 'admin1', 'pyketyson42@gmail.com', 'ef92b778bafe771e89245b89ecbc08a44a4e166c06659911881f383d4473e94f', NULL, NULL, 'admin1'),
(2, 'dispatcher', 'dispatcher718@gmail.com', '62a39df87b501ad40b6fc145820756ccedcab952c64626968e83ccbae5beae63', NULL, NULL, 'dispatcher'),
(3, 'fleetstaff', 'viahaledriver@gmail.com', '62a39df87b501ad40b6fc145820756ccedcab952c64626968e83ccbae5beae63', NULL, NULL, 'fleetstaff'),
(4, 'storeclerk', 'kioshikeshin@gmail.com', '62a39df87b501ad40b6fc145820756ccedcab952c64626968e83ccbae5beae63', NULL, NULL, 'storeclerk');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int(11) NOT NULL,
  `plate_no` varchar(30) NOT NULL,
  `model` varchar(100) DEFAULT NULL,
  `status` enum('available','in_use','maintenance') DEFAULT 'available',
  `make_year` year(4) DEFAULT NULL,
  `odometer_km` int(11) DEFAULT NULL,
  `odometer` int(11) DEFAULT NULL,
  `last_service_odometer` int(11) DEFAULT NULL,
  `last_service_date` date DEFAULT NULL,
  `service_interval_km` int(11) DEFAULT NULL,
  `service_interval_days` int(11) DEFAULT NULL,
  `mileage` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`id`, `plate_no`, `model`, `status`, `make_year`, `odometer_km`, `odometer`, `last_service_odometer`, `last_service_date`, `service_interval_km`, `service_interval_days`, `mileage`) VALUES
(1, 'ABC-123', 'Toyota Vios', 'available', '2018', NULL, NULL, NULL, NULL, NULL, NULL, 0),
(2, 'XYZ-987', 'Mitsubishi L300', 'available', '2016', NULL, NULL, NULL, NULL, NULL, NULL, 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `approvals`
--
ALTER TABLE `approvals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_entity` (`entity_type`,`entity_id`);

--
-- Indexes for table `budgets`
--
ALTER TABLE `budgets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_budget` (`month_year`,`category`);

--
-- Indexes for table `drivers`
--
ALTER TABLE `drivers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_drivers_location` (`current_location_lat`,`current_location_lng`),
  ADD KEY `idx_drivers_status` (`status`),
  ADD KEY `idx_driver_location` (`current_location_lat`,`current_location_lng`),
  ADD KEY `profile_id` (`profile_id`);

--
-- Indexes for table `driver_compliance`
--
ALTER TABLE `driver_compliance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dc_driver` (`driver_id`);

--
-- Indexes for table `driver_documents`
--
ALTER TABLE `driver_documents`
  ADD PRIMARY KEY (`document_id`),
  ADD KEY `driver_id` (`driver_id`);

--
-- Indexes for table `driver_events`
--
ALTER TABLE `driver_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_driver_events_type` (`event_type`),
  ADD KEY `idx_driver_events_driver` (`driver_id`,`event_date`);

--
-- Indexes for table `driver_incidents`
--
ALTER TABLE `driver_incidents`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `driver_performance`
--
ALTER TABLE `driver_performance`
  ADD PRIMARY KEY (`performance_id`),
  ADD KEY `driver_id` (`driver_id`);

--
-- Indexes for table `driver_profiles`
--
ALTER TABLE `driver_profiles`
  ADD PRIMARY KEY (`driver_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `license_number` (`license_number`);

--
-- Indexes for table `fuel_logs`
--
ALTER TABLE `fuel_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `driver_id` (`driver_id`),
  ADD KEY `idx_fuel_vehicle_date` (`vehicle_id`,`filled_at`);

--
-- Indexes for table `issued_items`
--
ALTER TABLE `issued_items`
  ADD PRIMARY KEY (`issued_id`),
  ADD KEY `supply_id` (`supply_id`);

--
-- Indexes for table `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `item_usage`
--
ALTER TABLE `item_usage`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `maintenance_records`
--
ALTER TABLE `maintenance_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vehicle_id` (`vehicle_id`);

--
-- Indexes for table `maintenance_rules`
--
ALTER TABLE `maintenance_rules`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `maintenance_schedule`
--
ALTER TABLE `maintenance_schedule`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vehicle_id` (`vehicle_id`),
  ADD KEY `idx_ms_vehicle_date` (`vehicle_id`,`scheduled_date`);

--
-- Indexes for table `odometer_logs`
--
ALTER TABLE `odometer_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_odo_vehicle_date` (`vehicle_id`,`logged_at`);

--
-- Indexes for table `parts_history`
--
ALTER TABLE `parts_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vehicle_id` (`vehicle_id`);

--
-- Indexes for table `parts_replacements`
--
ALTER TABLE `parts_replacements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vehicle_id` (`vehicle_id`);

--
-- Indexes for table `repair_logs`
--
ALTER TABLE `repair_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vehicle_id` (`vehicle_id`);

--
-- Indexes for table `stock_history`
--
ALTER TABLE `stock_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `supply_id` (`supply_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `supplies`
--
ALTER TABLE `supplies`
  ADD PRIMARY KEY (`supply_id`);

--
-- Indexes for table `supply_prices`
--
ALTER TABLE `supply_prices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_supply` (`supply_id`);

--
-- Indexes for table `supply_requests`
--
ALTER TABLE `supply_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `trips`
--
ALTER TABLE `trips`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `trip_code` (`trip_code`),
  ADD KEY `driver_id` (`driver_id`),
  ADD KEY `vehicle_id` (`vehicle_id`),
  ADD KEY `idx_trips_priority` (`priority`),
  ADD KEY `idx_trips_status_created` (`status`,`created_at`),
  ADD KEY `idx_trip_priority` (`priority`);

--
-- Indexes for table `trip_assignments`
--
ALTER TABLE `trip_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `driver_id` (`driver_id`),
  ADD KEY `idx_trip_assignments_trip` (`trip_id`,`assigned_at`),
  ADD KEY `idx_ta_trip` (`trip_id`,`assigned_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `approvals`
--
ALTER TABLE `approvals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `budgets`
--
ALTER TABLE `budgets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `drivers`
--
ALTER TABLE `drivers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `driver_compliance`
--
ALTER TABLE `driver_compliance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `driver_documents`
--
ALTER TABLE `driver_documents`
  MODIFY `document_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `driver_events`
--
ALTER TABLE `driver_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `driver_incidents`
--
ALTER TABLE `driver_incidents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `driver_performance`
--
ALTER TABLE `driver_performance`
  MODIFY `performance_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `driver_profiles`
--
ALTER TABLE `driver_profiles`
  MODIFY `driver_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `fuel_logs`
--
ALTER TABLE `fuel_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `issued_items`
--
ALTER TABLE `issued_items`
  MODIFY `issued_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `items`
--
ALTER TABLE `items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `item_usage`
--
ALTER TABLE `item_usage`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `maintenance_records`
--
ALTER TABLE `maintenance_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_rules`
--
ALTER TABLE `maintenance_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `maintenance_schedule`
--
ALTER TABLE `maintenance_schedule`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `odometer_logs`
--
ALTER TABLE `odometer_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `parts_history`
--
ALTER TABLE `parts_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `parts_replacements`
--
ALTER TABLE `parts_replacements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `repair_logs`
--
ALTER TABLE `repair_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `stock_history`
--
ALTER TABLE `stock_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `supplies`
--
ALTER TABLE `supplies`
  MODIFY `supply_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `supply_prices`
--
ALTER TABLE `supply_prices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supply_requests`
--
ALTER TABLE `supply_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `trips`
--
ALTER TABLE `trips`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `trip_assignments`
--
ALTER TABLE `trip_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `drivers`
--
ALTER TABLE `drivers`
  ADD CONSTRAINT `drivers_ibfk_1` FOREIGN KEY (`profile_id`) REFERENCES `driver_profiles` (`driver_id`) ON DELETE SET NULL;

--
-- Constraints for table `driver_compliance`
--
ALTER TABLE `driver_compliance`
  ADD CONSTRAINT `driver_compliance_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `driver_documents`
--
ALTER TABLE `driver_documents`
  ADD CONSTRAINT `driver_documents_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `driver_profiles` (`driver_id`) ON DELETE CASCADE;

--
-- Constraints for table `driver_events`
--
ALTER TABLE `driver_events`
  ADD CONSTRAINT `driver_events_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `driver_performance`
--
ALTER TABLE `driver_performance`
  ADD CONSTRAINT `driver_performance_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `driver_profiles` (`driver_id`) ON DELETE CASCADE;

--
-- Constraints for table `fuel_logs`
--
ALTER TABLE `fuel_logs`
  ADD CONSTRAINT `fuel_logs_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fuel_logs_ibfk_2` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `issued_items`
--
ALTER TABLE `issued_items`
  ADD CONSTRAINT `issued_items_ibfk_1` FOREIGN KEY (`supply_id`) REFERENCES `supplies` (`supply_id`) ON DELETE CASCADE;

--
-- Constraints for table `items`
--
ALTER TABLE `items`
  ADD CONSTRAINT `items_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `item_usage`
--
ALTER TABLE `item_usage`
  ADD CONSTRAINT `item_usage_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`);

--
-- Constraints for table `maintenance_records`
--
ALTER TABLE `maintenance_records`
  ADD CONSTRAINT `maintenance_records_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `maintenance_schedule`
--
ALTER TABLE `maintenance_schedule`
  ADD CONSTRAINT `maintenance_schedule_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `odometer_logs`
--
ALTER TABLE `odometer_logs`
  ADD CONSTRAINT `odometer_logs_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `parts_history`
--
ALTER TABLE `parts_history`
  ADD CONSTRAINT `parts_history_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `parts_replacements`
--
ALTER TABLE `parts_replacements`
  ADD CONSTRAINT `parts_replacements_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `repair_logs`
--
ALTER TABLE `repair_logs`
  ADD CONSTRAINT `repair_logs_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_history`
--
ALTER TABLE `stock_history`
  ADD CONSTRAINT `stock_history_ibfk_1` FOREIGN KEY (`supply_id`) REFERENCES `supplies` (`supply_id`) ON DELETE CASCADE;

--
-- Constraints for table `supply_requests`
--
ALTER TABLE `supply_requests`
  ADD CONSTRAINT `supply_requests_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`);

--
-- Constraints for table `trips`
--
ALTER TABLE `trips`
  ADD CONSTRAINT `trips_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `trips_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `trip_assignments`
--
ALTER TABLE `trip_assignments`
  ADD CONSTRAINT `trip_assignments_ibfk_1` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `trip_assignments_ibfk_2` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
