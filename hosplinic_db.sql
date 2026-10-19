-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 19, 2026 at 06:19 AM
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
-- Database: `hosplinic_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `doctor_id` int(11) DEFAULT NULL,
  `booking_code` varchar(30) DEFAULT NULL,
  `department` varchar(100) NOT NULL,
  `doctor_name` varchar(100) NOT NULL,
  `appointment_datetime` datetime NOT NULL,
  `reason` text DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `status` enum('Confirmed','Pending','Completed','Cancelled') DEFAULT 'Confirmed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`id`, `user_id`, `doctor_id`, `booking_code`, `department`, `doctor_name`, `appointment_datetime`, `reason`, `attachment_path`, `attachment_name`, `cancellation_reason`, `status`, `created_at`) VALUES
(8, 15, NULL, 'HSP-2610-2838', 'General Medicine', 'Ralph Cervera', '2026-10-16 23:30:00', 'symptoms', NULL, NULL, 'No reason provided by patient', 'Cancelled', '2026-10-14 15:30:18'),
(9, 15, 14, 'HSP-2610-1700', 'General Medicine', 'Ralph Cervera', '2026-10-16 23:51:00', '1', NULL, NULL, 'No reason provided by patient', 'Cancelled', '2026-10-14 15:51:13'),
(10, 15, 14, 'HSP-2610-7265', 'General Medicine', 'Ralph Cervera', '2026-10-15 23:58:00', '1', NULL, NULL, 'No reason provided by patient', 'Cancelled', '2026-10-14 15:58:41'),
(11, 15, 14, 'HSP-2610-2613', 'General Medicine', 'Ralph Cervera', '2026-10-16 00:34:00', 'concerned about this', 'uploads/attach_6acfaf3e9d3ba.jpg', 'images.jpg', 'No reason provided by patient', 'Cancelled', '2026-10-14 16:35:10'),
(12, 15, NULL, 'HSP-2610-1737', 'General Medicine', 'On-Duty Clinical Staff (General Medicine)', '2026-10-19 00:43:00', 'illness', NULL, NULL, 'No reason provided by patient', 'Cancelled', '2026-10-14 16:43:55'),
(13, 15, 17, 'HSP-2610-4109', 'General Medicine', 'Jansen Quinones', '2026-10-20 13:00:00', '1', 'uploads/attach_6ad0135370e84.jpg', 'images.jpg', NULL, 'Confirmed', '2026-10-14 23:42:11'),
(0, 23, 20, 'HSP-2610-2336', 'Cardiology', 'John', '2026-10-22 09:00:00', '', NULL, NULL, NULL, 'Pending', '2026-10-19 03:57:13');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `billings`
--

CREATE TABLE `billings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `description` varchar(255) NOT NULL,
  `base_fee` decimal(10,2) DEFAULT 500.00,
  `lab_name` varchar(150) DEFAULT NULL,
  `lab_fee` decimal(10,2) DEFAULT 0.00,
  `lab_status` enum('none','pending','accepted','declined') DEFAULT 'none',
  `total_amount` decimal(10,2) NOT NULL,
  `payment_status` varchar(100) DEFAULT 'Unpaid',
  `payment_method` varchar(50) DEFAULT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `receipt_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `billings`
--

INSERT INTO `billings` (`id`, `user_id`, `appointment_id`, `invoice_number`, `description`, `base_fee`, `lab_name`, `lab_fee`, `lab_status`, `total_amount`, `payment_status`, `payment_method`, `receipt_path`, `receipt_name`, `created_at`) VALUES
(7, 15, 8, 'INV-20261014-5443', 'Cancellation Penalty [HSP-2610-2838] - Ralph Cervera', 50.00, 'Complete Blood Count (CBC) & Urinalysis', 0.00, 'declined', 50.00, 'Paid', NULL, NULL, NULL, '2026-10-14 15:30:18'),
(8, 15, 9, 'INV-20261014-9698', 'Cancellation Penalty [HSP-2610-1700] - Ralph Cervera', 50.00, 'Complete Blood Count (CBC) & Urinalysis', 0.00, 'declined', 50.00, 'Paid', NULL, NULL, NULL, '2026-10-14 15:51:13'),
(9, 15, 10, 'INV-20261014-4359', 'Cancellation Penalty [HSP-2610-7265] - Ralph Cervera', 50.00, 'Complete Blood Count (CBC) & Urinalysis', 0.00, 'declined', 50.00, 'Paid', NULL, NULL, NULL, '2026-10-14 15:58:41'),
(10, 15, 11, 'INV-20261014-5534', 'Cancellation Penalty [HSP-2610-2613] - Ralph Cervera', 50.00, 'Complete Blood Count (CBC) & Urinalysis', 0.00, 'declined', 50.00, 'Paid', NULL, NULL, NULL, '2026-10-14 16:35:10'),
(11, 15, 12, 'INV-20261014-9958', 'Consultation [HSP-2610-1737] - General Medicine (Ralph Cervera)', 500.00, 'Complete Blood Count (CBC) & Urinalysis', 350.00, 'accepted', 850.00, 'Paid', NULL, NULL, NULL, '2026-10-14 16:43:55'),
(12, 15, 13, 'INV-20261015-2973', 'Consultation [HSP-2610-4109] - General Medicine (Jansen Quinones)', 500.00, 'Complete Blood Count (CBC) & Urinalysis', 350.00, 'pending', 500.00, 'Unpaid', NULL, NULL, NULL, '2026-10-14 23:42:11'),
(0, 23, 0, 'INV-20261019-2621', 'Consultation [HSP-2610-2336] - Cardiology (John)', 500.00, '12-Lead ECG & Cardiac Biomarker Panel', 650.00, 'pending', 500.00, 'Paid', 'Onsite Payment', NULL, NULL, '2026-10-19 03:57:13');

-- --------------------------------------------------------

--
-- Table structure for table `doctor_unavailability_logs`
--

CREATE TABLE `doctor_unavailability_logs` (
  `id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `reassigned_doctor_id` int(11) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `doctor_unavailability_logs`
--

INSERT INTO `doctor_unavailability_logs` (`id`, `doctor_id`, `appointment_id`, `reassigned_doctor_id`, `reason`, `created_at`) VALUES
(1, 14, 12, NULL, 'Emergency Procedure / Surgery', '2026-10-14 21:37:08');

-- --------------------------------------------------------

--
-- Table structure for table `medical_records`
--

CREATE TABLE `medical_records` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `diagnosis_notes` text DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `medical_records`
--

INSERT INTO `medical_records` (`id`, `patient_id`, `doctor_id`, `title`, `diagnosis_notes`, `file_name`, `file_path`, `file_size`, `created_at`) VALUES
(1, 15, 14, '1', '1', 'images.jpg', 'uploads/record_6acfaf9fb414b.jpg', '16.5 KB', '2026-10-14 16:36:47');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `type` enum('confirmed','comment','reminder') DEFAULT 'confirmed',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `is_read`, `created_at`) VALUES
(15, 15, 'Appointment Booked', 'Your visit with Ralph Cervera is confirmed. Reference Code: HSP-2610-2838.', 'confirmed', 0, '2026-10-14 15:30:18'),
(16, 15, 'Appointment Submitted (Pending)', 'Your visit with Ralph Cervera is currently Pending doctor confirmation. Reference: HSP-2610-1700.', 'reminder', 0, '2026-10-14 15:51:13'),
(17, 15, 'Appointment Confirmed', 'Dr. Ralph Cervera confirmed your consultation [HSP-2610-1700]. We look forward to seeing you!', 'confirmed', 0, '2026-10-14 15:51:42'),
(18, 15, 'Appointment Submitted (Pending)', 'Your visit with Ralph Cervera is currently Pending doctor confirmation. Reference: HSP-2610-7265.', 'reminder', 0, '2026-10-14 15:58:41'),
(19, 15, 'Appointment Confirmed', 'Dr. Ralph Cervera confirmed your consultation [HSP-2610-7265]. We look forward to seeing you!', 'confirmed', 0, '2026-10-14 15:59:15'),
(20, 15, 'Appointment Confirmed', 'Dr. Ralph Cervera confirmed your consultation [HSP-2610-7265]. We look forward to seeing you!', 'confirmed', 0, '2026-10-14 16:14:30'),
(21, 15, 'Appointment Submitted (Pending)', 'Your visit with Ralph Cervera is currently Pending doctor confirmation. Reference: HSP-2610-2613.', 'reminder', 0, '2026-10-14 16:35:10'),
(22, 15, 'Appointment Confirmed', 'Dr. Ralph Cervera confirmed your consultation [HSP-2610-2613]. We look forward to seeing you!', 'confirmed', 0, '2026-10-14 16:35:56'),
(23, 15, 'New Medical Document Available', 'Ralph Cervera uploaded a new record: 1. You can now download it under Diagnosis & Records.', 'confirmed', 0, '2026-10-14 16:36:47'),
(24, 15, 'Appointment Submitted (Pending)', 'Your visit with Ralph Cervera is currently Pending doctor confirmation. Reference: HSP-2610-1737.', 'reminder', 0, '2026-10-14 16:43:55'),
(25, 14, 'Appointment Cancelled by Patient', 'Patient 1 cancelled their consultation [HSP-2610-2613] scheduled for Oct 16, 2026 12:34 AM. Reason: No reason provided by patient', 'comment', 0, '2026-10-14 16:45:54'),
(26, 15, 'Appointment Cancelled (Fine Applied)', 'Cancelled booking [HSP-2610-2613] with Ralph Cervera. A ₱50.00 reservation fee was applied to your billing.', 'comment', 0, '2026-10-14 16:45:54'),
(27, 1, 'Doctor Unavailability Alert', 'Incident Alert: Ralph Cervera declared unavailable for booking [HSP-2610-1737]. Reassigned to On-Duty Clinical Staff (General Medicine). (Total Doctor Strikes: 1). Reason: Emergency Procedure / Surgery', 'comment', 0, '2026-10-14 21:37:08'),
(28, 15, 'Physician Reassigned', 'Dr. Ralph Cervera was unavailable for booking [HSP-2610-1737]. You have been successfully reassigned to On-Duty Clinical Staff (General Medicine).', 'reminder', 0, '2026-10-14 21:37:08'),
(29, 15, 'Appointment Submitted (Pending)', 'Your consultation with Jansen Quinones is Pending doctor confirmation. Reference: HSP-2610-4109.', 'reminder', 0, '2026-10-14 23:42:11'),
(30, 15, 'Appointment Confirmed', 'Dr. Jansen Quinones confirmed your consultation [HSP-2610-4109]. We look forward to seeing you!', 'confirmed', 0, '2026-10-14 23:53:43'),
(0, 23, 'Appointment Submitted (Pending)', 'Your consultation with John is Pending doctor confirmation. Reference: HSP-2610-2336.', 'reminder', 0, '2026-10-19 03:57:13'),
(0, 1, 'New Payment Submission', 'Patient submitted a payment via Onsite Payment for Invoice #0. Please verify.', 'reminder', 0, '2026-10-19 03:57:17'),
(0, 16, 'New Payment Submission', 'Patient submitted a payment via Onsite Payment for Invoice #0. Please verify.', 'reminder', 0, '2026-10-19 03:57:17'),
(0, 23, 'Payment Verified & Confirmed', 'Your payment for invoice [INV-20261019-2621] has been verified and marked as Paid by Administration.', 'confirmed', 0, '2026-10-19 03:57:35');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `identifier` varchar(100) NOT NULL,
  `phone_number` varchar(30) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('patient','doctor','admin') DEFAULT 'patient',
  `department` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `unavailable_count` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `identifier`, `phone_number`, `password_hash`, `role`, `department`, `created_at`, `unavailable_count`) VALUES
(1, 'Master Administrator', 'admin@hosplinic', '+1 800-555-0199', '$2y$10$oUKzx//9fnLu6OCDtYfAEOTyo6U7FA9U0RdrD1yxP5jd6gBvKfhpm', 'admin', NULL, '2026-10-14 03:46:17', 0),
(14, 'Ralph Cervera', 'raincee', '0912345678', '$2y$10$l4u8fC9ex17bC27j43GQfegbE31aJ3wGGk39bei3AIOclUX.AYnbe', 'doctor', 'General Medicine', '2026-10-14 15:29:23', 3),
(15, '1', '1', '1', '$2y$10$pZd2bkkw219Cau252n/G7uE9N56oYt91WPdZnsRFZD77MhQhk0Giu', 'patient', NULL, '2026-10-14 15:29:38', 0),
(16, 'admin', 'admin', '000000', '$2y$10$kpks7tCUtAIdz/0S3WfNgOwVG/G/jIEVcKvBmrpl2.oxxbg8sOOUu', 'admin', NULL, '2026-10-14 21:39:07', 0),
(17, 'Jansen Quinones', 'sen', '0000000', '$2y$10$u4H0lxEC5kbjfqcgWw8SquL4Wps2RBMv1sT3YBmCkN29ZKaDWlTLK', 'doctor', 'General Medicine', '2026-10-14 21:50:38', 4),
(18, 'Ralph Cervera', 'cerveraralph6@gmail.com', '', '$2y$10$o1IhVONsORGE.kE/d8AWWuplxRMaeQmtUWTl4twWZdZ5F/eshogYi', 'patient', NULL, '2026-10-14 22:14:06', 0),
(19, 'b', 'b@gmail.com', '0912345678', '$2y$10$xkJCw6nPDsllAoQhfZViLOcYszYhVnqHxK0kbXUfXgx2CnpoabxXy', 'patient', NULL, '2026-10-15 08:13:22', 0),
(20, 'John', 'marvin', '123123', '$2y$10$s.kjoOGcIGUh32IEyAiFv.T10jrPOQaOVBLLtxesVXRVrPAJxFX6q', 'doctor', 'Cardiology', '2026-10-19 03:43:38', 0),
(21, 'Marvin', 'john', '12341234', '$2y$10$.6lQx6BGNRjGmMAkbnRuzOyXPll14hhY0e0N5bfGFqroVIeJDv6Re', 'doctor', 'Pediatrics', '2026-10-19 03:44:11', 0),
(22, 'Christian', 'Christian', '123123123', '$2y$10$/qL6zDDbeyjZ.ddQtOBNmesmq3yL/FU2noBAN6X30Bf.nlAo4aM8S', 'doctor', 'Dermatology', '2026-10-19 03:44:32', 0),
(23, 'John Marvin Vicente', 'johnvictus1@gmail.com', '', '$2y$10$HpMVJ.2d7.7AXOKivGFd4.7QjR0fAq8C/HVCD.uhPNP6FamL0.aAm', 'patient', NULL, '2026-10-19 03:57:04', 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `identifier` (`identifier`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
