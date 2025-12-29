-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 29, 2025 at 04:54 AM
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
-- Database: `pos_backend`
--

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `unit_id` bigint(20) UNSIGNED DEFAULT NULL,
  `sec_prop` varchar(255) DEFAULT NULL,
  `category_id` bigint(20) UNSIGNED DEFAULT NULL,
  `purchase_price` decimal(11,2) NOT NULL,
  `old_purchase_price` decimal(11,2) NOT NULL,
  `price` decimal(11,2) NOT NULL,
  `old_price` decimal(8,2) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `barcode` varchar(255) DEFAULT NULL,
  `status_id` bigint(20) UNSIGNED NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `updated_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `name`, `unit_id`, `sec_prop`, `category_id`, `purchase_price`, `old_purchase_price`, `price`, `old_price`, `image`, `barcode`, `status_id`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 'Pao 400g', 1, NULL, 2, 10000.00, 8000.00, 9000.00, 100000.00, NULL, 'FM-2510280001', 1, 1, 1, '2025-11-19 03:41:27', '2025-12-29 02:53:15'),
(2, 'Pro Washing Powder 2700g', 1, NULL, 1, 19000.00, 20000.00, 25000.00, 20000.00, NULL, NULL, 2, 1, 1, '2025-11-19 03:44:56', '2025-12-29 02:53:15'),
(3, 'Rejoice Shampoo', NULL, NULL, NULL, 12500.00, 12500.00, 13000.00, 13000.00, NULL, 'FM-2510280003', 1, 1, 1, '2025-11-19 03:54:21', '2025-12-27 07:48:56'),
(4, 'Rim Thip Vegetable Oil', NULL, NULL, NULL, 500.00, 500.00, 12500.00, 12500.00, NULL, 'FM-2510280004', 1, 1, 1, '2025-11-19 03:57:21', '2025-12-27 07:48:56'),
(5, 'Imperial Leather Soap', NULL, NULL, NULL, 4200.00, 4200.00, 4500.00, 4500.00, NULL, 'FM-2510280005', 1, 1, 1, '2025-11-19 03:58:16', '2025-11-19 03:58:16');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `products_barcode_unique` (`barcode`),
  ADD KEY `products_status_id_foreign` (`status_id`),
  ADD KEY `products_category_id_foreign` (`category_id`),
  ADD KEY `products_unit_id_foreign` (`unit_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `products_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `statuses` (`id`),
  ADD CONSTRAINT `products_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
