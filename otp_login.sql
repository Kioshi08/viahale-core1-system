-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost: 3307
-- Generation Time: Aug 16, 2025 at 04:28 AM
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
  `rating_average` decimal(3,2) DEFAULT 5.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `drivers`
--

INSERT INTO `drivers` (`id`, `name`, `phone`, `status`, `current_location_lat`, `current_location_lng`, `shift_end_time`, `rating_average`) VALUES
(1, 'John Doe', '09171234567', 'on_trip', NULL, NULL, NULL, 5.00),
(2, 'Jane Smith', '09179876543', 'on_trip', NULL, NULL, NULL, 5.00),
(3, 'Patrick Martinez', '09171234567', 'on_trip', NULL, NULL, NULL, 5.00),
(4, 'Fred Troy', '09914558732', 'on_trip', NULL, NULL, NULL, 5.00);

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
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `issued_items`
--

INSERT INTO `issued_items` (`issued_id`, `supply_id`, `issued_to`, `quantity_issued`, `issued_by`, `issued_at`) VALUES
(1, 1, 'Fleet Maintenance', 10, 'storeclerk', '2025-08-10 13:59:21');

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
  `approved_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `maintenance_schedule`
--

INSERT INTO `maintenance_schedule` (`id`, `vehicle_id`, `scheduled_date`, `type`, `notes`, `status`, `created_by`, `created_at`, `approval_status`, `approved_by`, `approved_at`) VALUES
(1, 1, '2025-08-16', 'Oil Change', 'Priority', 'done', 'fleetstaff', '2025-08-10 13:39:11', 'pending', NULL, NULL);

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `repair_logs`
--

INSERT INTO `repair_logs` (`id`, `vehicle_id`, `log_date`, `description`, `cost`, `performed_by`, `created_by`, `created_at`) VALUES
(1, 2, '2025-08-10 21:39:51', 'Change Tires', 2500.00, 'Mechanic', 'fleetstaff', '2025-08-10 13:39:51');

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
-- Table structure for table `trips`
--

CREATE TABLE `trips` (
  `id` int(11) NOT NULL,
  `trip_code` varchar(20) NOT NULL,
  `passenger_name` varchar(100) DEFAULT NULL,
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

INSERT INTO `trips` (`id`, `trip_code`, `passenger_name`, `driver_id`, `vehicle_id`, `origin`, `pickup_lat`, `pickup_lng`, `destination`, `dropoff_lat`, `dropoff_lng`, `scheduled_time`, `status`, `created_at`, `priority`) VALUES
(1, 'TRP68983F56C7FC2', 'Teddy Mangyan', 2, NULL, 'Taguig', NULL, NULL, 'Cavite', NULL, NULL, '2025-08-10 16:43:00', 'ongoing', '2025-08-10 06:42:30', 0),
(2, 'TRP1AFDE3500E88', 'Jeff Cortez', 1, NULL, 'Bldg. H 123 Pasong Putik, Kaligayan, Quezon City', NULL, NULL, 'Robinson', NULL, NULL, '2025-08-14 18:32:06', 'ongoing', '2025-08-14 16:32:06', 0),
(3, 'TRPA2506AA924A2', 'Teddy Mangyan', 3, NULL, 'Taguig', NULL, NULL, 'Cavite', NULL, NULL, '2025-08-14 18:55:37', 'ongoing', '2025-08-14 16:55:37', 1),
(4, 'TRP991593845121', 'Jennie Lopez', 4, NULL, 'MOA', 14.53518180, 120.98159940, 'PITX', 14.50847750, 120.99127120, '2025-08-15 02:53:50', 'ongoing', '2025-08-14 18:53:50', 0),
(8, 'TRP20FC082B27D6', 'Kyle', NULL, NULL, 'SM Fairview', 14.73388760, 121.05613700, 'SM Bacoor', 14.40803830, 120.97331850, '2025-08-15 03:14:34', 'pending', '2025-08-14 19:14:34', 1),
(9, 'TRP61EA98745489', 'Daniella', NULL, NULL, 'Bldg. L-131 Smile Citihomes 1', NULL, NULL, 'SM Bacoor Entrance', NULL, NULL, '2025-08-15 03:19:15', 'pending', '2025-08-14 19:19:15', 0),
(10, 'TRP7444D3FEDB12', 'Kervin', NULL, NULL, 'Bldg. L-131 Smile Citihomes 1', NULL, NULL, 'SM Bacoor Entrance', NULL, NULL, '2025-08-15 03:21:11', 'pending', '2025-08-14 19:21:11', 1);

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
  `make_year` year(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`id`, `plate_no`, `model`, `status`, `make_year`) VALUES
(1, 'ABC-123', 'Toyota Vios', 'available', '2018'),
(2, 'XYZ-987', 'Mitsubishi L300', 'available', '2016');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `drivers`
--
ALTER TABLE `drivers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_drivers_location` (`current_location_lat`,`current_location_lng`);

--
-- Indexes for table `issued_items`
--
ALTER TABLE `issued_items`
  ADD PRIMARY KEY (`issued_id`),
  ADD KEY `supply_id` (`supply_id`);

--
-- Indexes for table `maintenance_schedule`
--
ALTER TABLE `maintenance_schedule`
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
-- Indexes for table `supplies`
--
ALTER TABLE `supplies`
  ADD PRIMARY KEY (`supply_id`);

--
-- Indexes for table `trips`
--
ALTER TABLE `trips`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `trip_code` (`trip_code`),
  ADD KEY `driver_id` (`driver_id`),
  ADD KEY `vehicle_id` (`vehicle_id`),
  ADD KEY `idx_trips_priority` (`priority`);

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
-- AUTO_INCREMENT for table `drivers`
--
ALTER TABLE `drivers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `issued_items`
--
ALTER TABLE `issued_items`
  MODIFY `issued_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `maintenance_schedule`
--
ALTER TABLE `maintenance_schedule`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `repair_logs`
--
ALTER TABLE `repair_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `stock_history`
--
ALTER TABLE `stock_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `supplies`
--
ALTER TABLE `supplies`
  MODIFY `supply_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `trips`
--
ALTER TABLE `trips`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

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
-- Constraints for table `issued_items`
--
ALTER TABLE `issued_items`
  ADD CONSTRAINT `issued_items_ibfk_1` FOREIGN KEY (`supply_id`) REFERENCES `supplies` (`supply_id`) ON DELETE CASCADE;

--
-- Constraints for table `maintenance_schedule`
--
ALTER TABLE `maintenance_schedule`
  ADD CONSTRAINT `maintenance_schedule_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

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
-- Constraints for table `trips`
--
ALTER TABLE `trips`
  ADD CONSTRAINT `trips_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `trips_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
