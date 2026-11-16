-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 16, 2026 at 02:16 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `waste_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `collection_approval_history`
--

CREATE TABLE `collection_approval_history` (
  `id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `approved_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `collection_approval_history`
--

INSERT INTO `collection_approval_history` (`id`, `schedule_id`, `admin_id`, `approved_at`) VALUES
(1, 9, 1, '2026-11-11 23:28:47');

-- --------------------------------------------------------

--
-- Table structure for table `collection_routes`
--

CREATE TABLE `collection_routes` (
  `id` int(11) NOT NULL,
  `route_name` varchar(100) NOT NULL,
  `route_type` enum('regular','special') NOT NULL DEFAULT 'regular',
  `collector_id` int(11) DEFAULT NULL,
  `status` enum('pending','assigned','in_progress','completed') NOT NULL DEFAULT 'pending',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `group_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `collection_schedules`
--

CREATE TABLE `collection_schedules` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `collector_id` int(11) DEFAULT NULL,
  `notes` text NOT NULL,
  `collection_date` date NOT NULL,
  `requested_time` time NOT NULL,
  `collection_address` varchar(255) NOT NULL,
  `status` enum('Rejected','Approved','Completed','Pending') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `collector_locations`
--

CREATE TABLE `collector_locations` (
  `id` int(11) NOT NULL,
  `collector_id` int(11) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `collector_locations`
--

INSERT INTO `collector_locations` (`id`, `collector_id`, `latitude`, `longitude`, `updated_at`) VALUES
(4, 17, 15.20140600, 120.60507300, '2026-11-16 10:07:22');

-- --------------------------------------------------------

--
-- Table structure for table `eco_points`
--

CREATE TABLE `eco_points` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `collection_id` int(11) NOT NULL,
  `points` int(11) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_otps`
--

CREATE TABLE `email_otps` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `otp_code` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `email_otps`
--

INSERT INTO `email_otps` (`id`, `user_id`, `otp_code`, `expires_at`, `attempts`, `created_at`) VALUES
(2, 20, '$2y$10$MrJUCpLP7fQiF0eAtnn4Q.778SoR7VKl9UEnrD8u6F3/MZiu/9tru', '2026-11-09 03:30:26', 0, '2026-11-09 02:25:26');

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `feedback_type` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `households`
--

CREATE TABLE `households` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `house_address` varchar(255) NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `group_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `household_groups`
--

CREATE TABLE `household_groups` (
  `id` int(11) NOT NULL,
  `group_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
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
  `type` varchar(50) DEFAULT 'subscription',
  `related_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `resident_payments`
--

CREATE TABLE `resident_payments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('Paid','Unpaid') NOT NULL DEFAULT 'Unpaid',
  `paid_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `route_stops`
--

CREATE TABLE `route_stops` (
  `id` int(11) NOT NULL,
  `route_id` int(11) NOT NULL,
  `household_id` int(11) NOT NULL,
  `stop_order` int(11) NOT NULL,
  `status` enum('pending','collected') NOT NULL DEFAULT 'pending',
  `collected_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

CREATE TABLE `subscriptions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `service_fee` decimal(10,2) NOT NULL DEFAULT 150.00,
  `months` int(11) NOT NULL DEFAULT 1,
  `original_amount` decimal(10,2) NOT NULL DEFAULT 150.00,
  `points_redeemed` int(11) NOT NULL DEFAULT 0,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 150.00,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `balance_due` decimal(10,2) NOT NULL DEFAULT 150.00,
  `subscription_status` varchar(50) NOT NULL DEFAULT 'Pending Approval',
  `payment_status` varchar(50) NOT NULL DEFAULT 'Unpaid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscription_approval_history`
--

CREATE TABLE `subscription_approval_history` (
  `id` int(11) NOT NULL,
  `subscription_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `approved_at` timestamp NULL DEFAULT current_timestamp(),
  `action_status` varchar(30) NOT NULL DEFAULT 'Approved',
  `resident_name` varchar(150) DEFAULT NULL,
  `resident_username` varchar(100) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `balance_due` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subscription_created_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscription_point_redemptions`
--

CREATE TABLE `subscription_point_redemptions` (
  `id` int(11) NOT NULL,
  `subscription_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `points_redeemed` int(11) NOT NULL,
  `discount_amount` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `fullname` varchar(255) NOT NULL,
  `users` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(11) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `email_verified_at` datetime DEFAULT NULL,
  `address` varchar(500) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `fullname`, `users`, `password`, `role`, `email`, `email_verified`, `email_verified_at`, `address`) VALUES
(1, 'Micah galang', 'micahg', '$2y$10$/bzjTXhaS3gfBZqIfhZMyOgXhPq6v/s03Jc9VKuWAzztUVmEGm7Re', 'admin', NULL, 0, NULL, ''),
(7, 'Vincent Natividad', 'vincent', '$2y$10$2GAmEsQJ2e/A6NjZmXX1G.LekFkvf7tZpXtsy.qQJECOzimYce9u2', 'collector', NULL, 0, NULL, ''),
(12, 'Vincent Natividad', 'admin', '$2y$10$bUpkMh3ghhoYT1lxeBfwj.k4dXudnFo2euXvYHk7w5J7UCaf9q5z2', 'admin', NULL, 0, NULL, ''),
(17, 'collector', 'col', '$2y$10$Z6Bsw5I1do3oGf2ryTV8HeY8vu9DUPJeGOulxKZ0JmjqkmenHvEoi', 'collector', NULL, 0, NULL, '');

-- --------------------------------------------------------

--
-- Table structure for table `waste_collections`
--

CREATE TABLE `waste_collections` (
  `id` int(11) NOT NULL,
  `household_id` int(11) NOT NULL,
  `collector_id` int(11) DEFAULT NULL,
  `collection_date` date NOT NULL,
  `segregation_status` enum('Properly Segregated','Not Properly Segregated') DEFAULT NULL,
  `collection_status` enum('Pending','Collected') DEFAULT 'Pending',
  `eco_points_awarded` int(11) DEFAULT 0,
  `collected_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `collection_routes`
--
ALTER TABLE `collection_routes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `collection_routes_ibfk_1` (`collector_id`);

--
-- Indexes for table `collection_schedules`
--
ALTER TABLE `collection_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_collection_schedules_collector_id` (`collector_id`);

--
-- Indexes for table `collector_locations`
--
ALTER TABLE `collector_locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `collector_id` (`collector_id`);

--
-- Indexes for table `eco_points`
--
ALTER TABLE `eco_points`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `collection_id` (`collection_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `email_otps`
--
ALTER TABLE `email_otps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `households`
--
ALTER TABLE `households`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `household_groups`
--
ALTER TABLE `household_groups`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notifications_user_read` (`user_id`,`is_read`),
  ADD KEY `idx_notifications_related` (`related_id`);

--
-- Indexes for table `resident_payments`
--
ALTER TABLE `resident_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `route_stops`
--
ALTER TABLE `route_stops`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `route_id` (`route_id`,`stop_order`),
  ADD UNIQUE KEY `route_id_2` (`route_id`,`household_id`),
  ADD KEY `household_id` (`household_id`);

--
-- Indexes for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `subscription_point_redemptions`
--
ALTER TABLE `subscription_point_redemptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_redemption_user` (`user_id`),
  ADD KEY `idx_redemption_subscription` (`subscription_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `waste_collections`
--
ALTER TABLE `waste_collections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_household_collection` (`household_id`,`collection_date`),
  ADD KEY `collector_id` (`collector_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `collection_routes`
--
ALTER TABLE `collection_routes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `collection_schedules`
--
ALTER TABLE `collection_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `collector_locations`
--
ALTER TABLE `collector_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=81;

--
-- AUTO_INCREMENT for table `eco_points`
--
ALTER TABLE `eco_points`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_otps`
--
ALTER TABLE `email_otps`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `households`
--
ALTER TABLE `households`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `household_groups`
--
ALTER TABLE `household_groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `resident_payments`
--
ALTER TABLE `resident_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `route_stops`
--
ALTER TABLE `route_stops`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=63;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subscription_point_redemptions`
--
ALTER TABLE `subscription_point_redemptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `waste_collections`
--
ALTER TABLE `waste_collections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `collection_routes`
--
ALTER TABLE `collection_routes`
  ADD CONSTRAINT `collection_routes_ibfk_1` FOREIGN KEY (`collector_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `collection_routes_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `collection_schedules`
--
ALTER TABLE `collection_schedules`
  ADD CONSTRAINT `fk_collection_schedules_collector` FOREIGN KEY (`collector_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `collector_locations`
--
ALTER TABLE `collector_locations`
  ADD CONSTRAINT `collector_locations_ibfk_1` FOREIGN KEY (`collector_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `eco_points`
--
ALTER TABLE `eco_points`
  ADD CONSTRAINT `eco_points_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `eco_points_ibfk_2` FOREIGN KEY (`collection_id`) REFERENCES `waste_collections` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `households`
--
ALTER TABLE `households`
  ADD CONSTRAINT `households_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `resident_payments`
--
ALTER TABLE `resident_payments`
  ADD CONSTRAINT `resident_payments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `route_stops`
--
ALTER TABLE `route_stops`
  ADD CONSTRAINT `route_stops_ibfk_1` FOREIGN KEY (`route_id`) REFERENCES `collection_routes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `route_stops_ibfk_2` FOREIGN KEY (`household_id`) REFERENCES `households` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD CONSTRAINT `subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subscription_point_redemptions`
--
ALTER TABLE `subscription_point_redemptions`
  ADD CONSTRAINT `fk_redemption_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_redemption_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `waste_collections`
--
ALTER TABLE `waste_collections`
  ADD CONSTRAINT `waste_collections_ibfk_1` FOREIGN KEY (`household_id`) REFERENCES `households` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `waste_collections_ibfk_2` FOREIGN KEY (`collector_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
