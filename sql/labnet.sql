-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 16, 2026 at 03:28 PM
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
-- Database: `labnet`
--
CREATE DATABASE IF NOT EXISTS `labnet` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `labnet`;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(80) DEFAULT NULL,
  `module` varchar(80) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `equipment`
--

CREATE TABLE `equipment` (
  `id` int(11) NOT NULL,
  `lab_id` int(11) DEFAULT NULL,
  `name` varchar(180) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `serial_number` varchar(120) DEFAULT NULL,
  `hourly_price` decimal(10,2) DEFAULT NULL,
  `status` enum('Available','Reserved','Under maintenance','Broken','Expired') DEFAULT 'Available',
  `maintenance_start_date` date DEFAULT NULL,
  `usage_instructions` text DEFAULT NULL,
  `safety_guidelines` text DEFAULT NULL,
  `last_maintenance` date DEFAULT NULL,
  `next_maintenance` date DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `laboratories`
--

CREATE TABLE `laboratories` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `code` varchar(50) NOT NULL,
  `location` varchar(180) DEFAULT NULL,
  `capacity` int(11) DEFAULT 1,
  `responsible_supervisor_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_acknowledgments`
--

CREATE TABLE `maintenance_acknowledgments` (
  `id` int(11) NOT NULL,
  `equipment_id` int(11) NOT NULL,
  `confirmed_by` int(11) NOT NULL,
  `confirmed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `next_due_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_notification_tracking`
--

CREATE TABLE `maintenance_notification_tracking` (
  `id` int(11) NOT NULL,
  `equipment_id` int(11) NOT NULL,
  `notification_key` varchar(100) NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_records`
--

CREATE TABLE `maintenance_records` (
  `id` int(11) NOT NULL,
  `equipment_id` int(11) NOT NULL,
  `type` varchar(50) DEFAULT NULL,
  `technician` varchar(120) DEFAULT NULL,
  `problem` text DEFAULT NULL,
  `action_taken` text DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` varchar(40) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `materials`
--

CREATE TABLE `materials` (
  `id` int(11) NOT NULL,
  `lab_id` int(11) DEFAULT NULL,
  `name` varchar(180) NOT NULL,
  `type` enum('Gas','Liquid','Solid') NOT NULL,
  `description` text DEFAULT NULL,
  `available_quantity` decimal(15,2) DEFAULT 0.00,
  `unit` varchar(20) NOT NULL,
  `low_stock_threshold` decimal(15,2) DEFAULT 0.00,
  `max_stock_level` decimal(15,2) DEFAULT 0.00,
  `safety_notes` text DEFAULT NULL,
  `expiry_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `id` int(11) NOT NULL,
  `reservation_group` varchar(40) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('Equipment','Laboratory','Material','Supply','Storage Space') NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `laboratory_id` int(11) DEFAULT NULL,
  `sample_type` varchar(180) DEFAULT NULL,
  `quantity` decimal(15,2) DEFAULT 1.00,
  `unit` varchar(20) DEFAULT NULL,
  `time_slot` varchar(50) DEFAULT NULL,
  `date_needed` date NOT NULL,
  `end_date` date NOT NULL,
  `purpose` text DEFAULT NULL,
  `status` enum('Pending','Approved','In Use','Completed','Rejected') DEFAULT 'Pending',
  `rejection_reason` text DEFAULT NULL,
  `signed_by` varchar(120) DEFAULT NULL,
  `signed_at` datetime DEFAULT NULL,
  `checked_in_at` datetime DEFAULT NULL,
  `checked_out_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `storage_reservations`
--

CREATE TABLE `storage_reservations` (
  `id` int(11) NOT NULL,
  `storage_space_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `sample_type` varchar(180) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit` varchar(50) NOT NULL DEFAULT 'Sample',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `purpose` text NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected','In Use','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `storage_spaces`
--

CREATE TABLE `storage_spaces` (
  `id` int(11) NOT NULL,
  `name` varchar(180) NOT NULL,
  `type` varchar(50) NOT NULL,
  `lab_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `capacity` int(11) NOT NULL DEFAULT 0,
  `used_capacity` int(11) NOT NULL DEFAULT 0,
  `temp_min` decimal(6,2) DEFAULT NULL,
  `temp_max` decimal(6,2) DEFAULT NULL,
  `status` enum('Available','Partially Available','Full','Under Maintenance') NOT NULL DEFAULT 'Available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplies`
--

CREATE TABLE `supplies` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `category` varchar(100) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(30) NOT NULL DEFAULT 'Piece',
  `lab_id` int(11) DEFAULT NULL,
  `low_stock_threshold` decimal(10,2) NOT NULL DEFAULT 5.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `temperature_logs`
--

CREATE TABLE `temperature_logs` (
  `id` int(11) NOT NULL,
  `storage_name` varchar(180) DEFAULT NULL,
  `temperature` decimal(6,2) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `signed_by` varchar(120) DEFAULT NULL,
  `logged_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `usage_guides`
--

CREATE TABLE `usage_guides` (
  `id` int(11) NOT NULL,
  `item_type` enum('Equipment','Material','Laboratory') NOT NULL,
  `item_id` int(11) NOT NULL,
  `usage_instructions` text NOT NULL,
  `safety_guidelines` text NOT NULL,
  `manual_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `email` varchar(160) NOT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `research_title` varchar(255) DEFAULT NULL,
  `college` varchar(180) DEFAULT NULL,
  `department` varchar(180) DEFAULT NULL,
  `specialization` varchar(180) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('Student','Supervisor','Admin') NOT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `equipment`
--
ALTER TABLE `equipment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lab_id` (`lab_id`);

--
-- Indexes for table `laboratories`
--
ALTER TABLE `laboratories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `responsible_supervisor_id` (`responsible_supervisor_id`);

--
-- Indexes for table `maintenance_acknowledgments`
--
ALTER TABLE `maintenance_acknowledgments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ack_user` (`confirmed_by`),
  ADD KEY `idx_ack_equipment_date` (`equipment_id`,`confirmed_at`);

--
-- Indexes for table `maintenance_notification_tracking`
--
ALTER TABLE `maintenance_notification_tracking`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_maintenance_notification` (`equipment_id`,`notification_key`);

--
-- Indexes for table `maintenance_records`
--
ALTER TABLE `maintenance_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `equipment_id` (`equipment_id`);

--
-- Indexes for table `materials`
--
ALTER TABLE `materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lab_id` (`lab_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_notifications_user` (`user_id`);

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_reservation_group` (`reservation_group`);

--
-- Indexes for table `storage_reservations`
--
ALTER TABLE `storage_reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_storage_reservation_space` (`storage_space_id`),
  ADD KEY `fk_storage_reservation_user` (`user_id`),
  ADD KEY `fk_storage_reservation_reviewer` (`reviewed_by`);

--
-- Indexes for table `storage_spaces`
--
ALTER TABLE `storage_spaces`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_storage_spaces_lab` (`lab_id`);

--
-- Indexes for table `supplies`
--
ALTER TABLE `supplies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `fk_supplies_laboratory` (`lab_id`);

--
-- Indexes for table `temperature_logs`
--
ALTER TABLE `temperature_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `usage_guides`
--
ALTER TABLE `usage_guides`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_item_guide` (`item_type`,`item_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `equipment`
--
ALTER TABLE `equipment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `laboratories`
--
ALTER TABLE `laboratories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_acknowledgments`
--
ALTER TABLE `maintenance_acknowledgments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_notification_tracking`
--
ALTER TABLE `maintenance_notification_tracking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_records`
--
ALTER TABLE `maintenance_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `materials`
--
ALTER TABLE `materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `storage_reservations`
--
ALTER TABLE `storage_reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `storage_spaces`
--
ALTER TABLE `storage_spaces`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplies`
--
ALTER TABLE `supplies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `temperature_logs`
--
ALTER TABLE `temperature_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `usage_guides`
--
ALTER TABLE `usage_guides`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `equipment`
--
ALTER TABLE `equipment`
  ADD CONSTRAINT `equipment_ibfk_1` FOREIGN KEY (`lab_id`) REFERENCES `laboratories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `laboratories`
--
ALTER TABLE `laboratories`
  ADD CONSTRAINT `laboratories_ibfk_1` FOREIGN KEY (`responsible_supervisor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `maintenance_acknowledgments`
--
ALTER TABLE `maintenance_acknowledgments`
  ADD CONSTRAINT `fk_ack_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ack_user` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `maintenance_notification_tracking`
--
ALTER TABLE `maintenance_notification_tracking`
  ADD CONSTRAINT `fk_tracking_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `maintenance_records`
--
ALTER TABLE `maintenance_records`
  ADD CONSTRAINT `maintenance_records_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `materials`
--
ALTER TABLE `materials`
  ADD CONSTRAINT `materials_ibfk_1` FOREIGN KEY (`lab_id`) REFERENCES `laboratories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `storage_reservations`
--
ALTER TABLE `storage_reservations`
  ADD CONSTRAINT `fk_storage_reservation_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_storage_reservation_space` FOREIGN KEY (`storage_space_id`) REFERENCES `storage_spaces` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_storage_reservation_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `storage_spaces`
--
ALTER TABLE `storage_spaces`
  ADD CONSTRAINT `fk_storage_spaces_lab` FOREIGN KEY (`lab_id`) REFERENCES `laboratories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `supplies`
--
ALTER TABLE `supplies`
  ADD CONSTRAINT `fk_supplies_laboratory` FOREIGN KEY (`lab_id`) REFERENCES `laboratories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
