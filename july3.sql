-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3307
-- Generation Time: Jul 08, 2026 at 05:57 AM
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
-- Database: `july3`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `aid` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`aid`, `email`, `full_name`, `phone`, `created_at`) VALUES
(1, 'mrcsmangubat@gmail.com', 'Admin', '09309444416', '2026-03-21 04:34:51');

-- --------------------------------------------------------

--
-- Table structure for table `applications`
--

CREATE TABLE `applications` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `rsf_id` int(11) NOT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `current_evaluator_id` int(11) DEFAULT NULL,
  `handling_party` enum('evaluator','admin') DEFAULT NULL,
  `locked_at` timestamp NULL DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `applications`
--

INSERT INTO `applications` (`id`, `client_id`, `rsf_id`, `status`, `current_evaluator_id`, `handling_party`, `locked_at`, `submitted_at`, `updated_at`, `notes`) VALUES
(57, 1, 38, 'disapproved', 3, NULL, '2026-07-03 00:01:09', '2026-07-03 00:00:44', '2026-07-03 12:49:43', ''),
(58, 7, 37, 'approved', 4, NULL, '2026-07-03 08:26:26', '2026-07-03 00:08:23', '2026-07-03 13:52:17', 'goods na punta ka na dito pre'),
(59, 1, 39, 'approved', 3, NULL, '2026-07-04 13:42:35', '2026-07-03 08:32:09', '2026-07-06 02:27:12', ''),
(60, 18, 39, 'approved', 4, NULL, '2026-07-04 14:09:29', '2026-07-04 09:26:15', '2026-07-07 06:25:58', 'caleb'),
(61, 18, 36, 'disapproved', 3, NULL, '2026-07-04 09:37:37', '2026-07-04 09:37:03', '2026-07-04 09:37:40', ''),
(62, 18, 33, 'approved', 3, NULL, '2026-07-04 14:18:11', '2026-07-04 14:13:39', '2026-07-06 12:18:40', 'best part'),
(63, 1, 36, 'disapproved', 4, NULL, '2026-07-05 09:20:38', '2026-07-05 09:19:32', '2026-07-05 09:20:42', ''),
(64, 19, 36, 'disapproved', 11, NULL, '2026-07-05 09:28:36', '2026-07-05 09:27:39', '2026-07-05 09:31:04', 'ayaw ko sayu'),
(65, 18, 38, 'disapproved', 4, NULL, '2026-07-06 03:29:52', '2026-07-06 03:29:04', '2026-07-06 03:30:08', ''),
(66, 18, 37, 'resubmitted', 11, 'admin', '2026-07-06 13:50:59', '2026-07-06 12:33:46', '2026-07-06 13:51:43', ''),
(67, 18, 40, 'under_evaluation', 4, 'evaluator', '2026-07-07 12:42:11', '2026-07-07 12:07:19', '2026-07-07 12:42:11', NULL),
(68, 1, 40, 'pending', NULL, NULL, NULL, '2026-07-07 14:42:16', '2026-07-07 14:42:16', NULL),
(69, 1, 41, 'approved', 4, NULL, '2026-07-08 00:47:53', '2026-07-08 00:41:17', '2026-07-08 00:48:14', 'ok na'),
(70, 18, 41, 'pending', NULL, NULL, NULL, '2026-07-08 00:50:48', '2026-07-08 00:50:48', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `application_answers`
--

CREATE TABLE `application_answers` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `answer` text DEFAULT NULL,
  `answered_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `application_answers`
--

INSERT INTO `application_answers` (`id`, `application_id`, `question_id`, `answer`, `answered_at`) VALUES
(64, 62, 53, 'opo ngani', '2026-07-05 11:56:27'),
(65, 62, 54, 'yes po', '2026-07-05 11:56:27');

-- --------------------------------------------------------

--
-- Table structure for table `application_documents`
--

CREATE TABLE `application_documents` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `document_type` varchar(100) DEFAULT NULL,
  `required_doc_id` int(11) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `application_documents`
--

INSERT INTO `application_documents` (`id`, `application_id`, `document_type`, `required_doc_id`, `file_name`, `file_path`, `uploaded_at`) VALUES
(103, 57, 'passport', NULL, 'f5b697da-b753-4c00-8b21-1b1ce9bda239.jpg', 'uploads/app_documents/57_1783036844_type_passport_cb477c.jpg', '2026-07-03 00:00:44'),
(104, 57, 'resume', NULL, 'f2c81a58-576d-4687-be42-dec080429bb3.jpg', 'uploads/app_documents/57_1783036844_type_resume_ae3bd8.jpg', '2026-07-03 00:00:44'),
(105, 57, 'diploma', NULL, '812adb01-c63a-4190-b47c-38c5444c71bc.jpg', 'uploads/app_documents/57_1783036844_type_diploma_a5af7a.jpg', '2026-07-03 00:00:44'),
(106, 57, 'certificate', NULL, '686ea758-4a44-4f8c-af70-a39e20e8ba96.jpg', 'uploads/app_documents/57_1783036844_type_certificate_866b1f.jpg', '2026-07-03 00:00:44'),
(107, 58, 'passport', NULL, 'Gray and Green Simple Professional  CV Resume.png', 'uploads/app_documents/58_1783037303_doc_228_ef1431.png', '2026-07-03 00:08:23'),
(109, 60, 'passport', NULL, '01138f6b-ecb2-49df-a30e-58e1182f5996.jpg', 'uploads/app_documents/60_1783157175_doc_233_1a8adf.jpg', '2026-07-04 09:26:15'),
(110, 61, 'resume', NULL, '613725179_18550189750015367_2869925063412594507_n.jpg', 'uploads/app_documents/61_1783157823_doc_234_ee6bdf.jpg', '2026-07-04 09:37:03'),
(111, 59, 'passport', NULL, '375702833_2261722017552234_1980235257266379789_n.jpg', 'uploads/app_documents/59_1783172381_doc_233_7b71e4.jpg', '2026-07-04 13:39:41'),
(112, 62, 'passport', NULL, '5bbf8709-7a20-48ff-80c7-d0cdcf4c96a4.jpg', 'uploads/app_documents/62_1783174419_type_passport_320975.jpg', '2026-07-04 14:13:39'),
(113, 62, 'resume', NULL, 'Screenshot 2026-02-07 232628.png', 'uploads/app_documents/62_1783174419_type_resume_cbc315.png', '2026-07-04 14:13:39'),
(114, 62, 'diploma', NULL, 'Screenshot (87).png', 'uploads/app_documents/62_1783174419_type_diploma_c0a6fd.png', '2026-07-04 14:13:39'),
(115, 62, 'certificate', NULL, 'Screenshot (266).png', 'uploads/app_documents/62_1783174419_type_certificate_ab14ca.png', '2026-07-04 14:13:39'),
(116, 63, 'resume', NULL, 'c8751a04-b4a2-4c2f-ad24-aecec78efeec.jpg', 'uploads/app_documents/63_1783243172_doc_234_700ce1.jpg', '2026-07-05 09:19:32'),
(117, 64, 'resume', NULL, 'IMG20260203201112[1].jpg', 'uploads/app_documents/64_1783243659_doc_234_6bd88e.jpg', '2026-07-05 09:27:39'),
(118, 65, 'passport', NULL, 'Screenshot 2026-06-23 094215.png', 'uploads/app_documents/65_1783308544_type_passport_bf41b2.png', '2026-07-06 03:29:04'),
(119, 65, 'resume', NULL, 'ca709706-9504-4268-bb5f-71bb75418b6d.jpg', 'uploads/app_documents/65_1783308544_type_resume_2a157a.jpg', '2026-07-06 03:29:04'),
(120, 65, 'diploma', NULL, '3bbd76b1-eb20-4374-8dae-d94e9680a41e.jpg', 'uploads/app_documents/65_1783308544_type_diploma_8beb32.jpg', '2026-07-06 03:29:04'),
(121, 65, 'certificate', NULL, 'c8751a04-b4a2-4c2f-ad24-aecec78efeec.jpg', 'uploads/app_documents/65_1783308544_type_certificate_8d5092.jpg', '2026-07-06 03:29:04'),
(122, 66, 'passport', NULL, 'dmw_logo.png', 'uploads/app_documents/66_1783341226_doc_228_8484f5.png', '2026-07-06 12:33:46'),
(124, 69, 'resume', 247, 'dmw_logo.png', 'uploads/app_documents/69_1783471632_doc_247_f1b7be.png', '2026-07-08 00:47:12'),
(125, 70, 'resume', 247, '6181f651-52d1-4547-a086-f805c707867c.jpg', 'uploads/app_documents/70_1783471848_doc_247_b9a38a.jpg', '2026-07-08 00:50:48');

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `evaluator_id` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(20) DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assignments`
--

INSERT INTO `assignments` (`id`, `application_id`, `evaluator_id`, `assigned_at`, `status`) VALUES
(37, 57, 3, '2026-07-03 00:01:09', 'completed'),
(38, 58, 4, '2026-07-03 00:09:37', 'completed'),
(39, 59, 3, '2026-07-04 07:49:58', 'completed'),
(40, 60, 3, '2026-07-04 09:26:43', 'reassigned'),
(41, 61, 3, '2026-07-04 09:37:37', 'completed'),
(42, 60, 4, '2026-07-04 13:44:57', 'completed'),
(43, 62, 4, '2026-07-04 14:14:07', 'reassigned'),
(44, 62, 3, '2026-07-04 14:18:11', 'completed'),
(45, 63, 4, '2026-07-05 09:20:38', 'completed'),
(46, 64, 11, '2026-07-05 09:28:36', 'completed'),
(47, 65, 4, '2026-07-06 03:29:52', 'completed'),
(48, 66, 3, '2026-07-06 12:43:22', 'reassigned'),
(49, 66, 11, '2026-07-06 12:52:43', 'active'),
(50, 67, 4, '2026-07-07 12:42:11', 'active'),
(51, 69, 4, '2026-07-08 00:43:12', 'completed');

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

CREATE TABLE `clients` (
  `cid` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `first_name` varchar(80) NOT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) NOT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `barangay` varchar(150) NOT NULL,
  `city_municipality` varchar(150) NOT NULL,
  `province` varchar(100) NOT NULL,
  `dob` date DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `clients`
--

INSERT INTO `clients` (`cid`, `email`, `first_name`, `middle_name`, `last_name`, `suffix`, `phone`, `address`, `barangay`, `city_municipality`, `province`, `dob`, `gender`, `created_at`) VALUES
(1, 'mgmangubat@ccc.edu.ph', 'Marcus', 'Fornis', 'Mangubat', NULL, '09994042898', 'Ssirang Lupa', '', '', '', '2004-03-31', 'Male', '2026-03-21 04:32:14'),
(7, 'laguantero@ccc.edu.ph', 'Lorens', 'Alindogan', 'Guantero', NULL, '09309444418', 'Cabuyao', 'Pandalla', 'Science City of MuÃ±oz', 'Nueva Ecija', '2004-11-06', 'Female', '2026-04-11 01:49:57'),
(18, 'mangubatcoco6@gmail.com', 'Coco', 'Martin', 'Mangubat', 'Jr', '+639309444418', 'Sirang Lupa', 'Centro II', 'Claveria', 'Cagayan', '1980-03-01', 'Male', '2026-06-29 09:06:33'),
(19, 'justinemangubat1234@gmail.com', 'Justine', 'Bieber', 'Mangubat', NULL, '+639764669635', 'purok 2', 'Barangay 3', 'City of Calamba', 'Laguna', '2000-12-29', 'Male', '2026-06-30 02:54:51'),
(20, 'mangubatjuliet467@gmail.com', 'Jian', NULL, 'Jeong', NULL, '09309444418', 'Purok 1', 'Sirang Lupa', 'City of Calamba', 'Laguna', '2002-02-05', 'Female', '2026-07-08 03:45:31');

-- --------------------------------------------------------

--
-- Table structure for table `client_profiles`
--

CREATE TABLE `client_profiles` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `citizenship` varchar(100) DEFAULT NULL,
  `civil_status` enum('Single','Married','Widowed','Separated','Annulled') DEFAULT NULL,
  `religion` varchar(100) DEFAULT NULL,
  `height_cm` decimal(5,2) DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `educational_attainment` enum('Elementary Graduate','High School Graduate','Senior High School Graduate','Vocational/Technical','College Undergraduate','College Graduate','Post Graduate') DEFAULT NULL,
  `course_degree` varchar(150) DEFAULT NULL,
  `school_name` varchar(255) DEFAULT NULL,
  `school_address` varchar(255) DEFAULT NULL,
  `year_graduated` year(4) DEFAULT NULL,
  `work_experience_years` int(11) DEFAULT NULL,
  `last_employer` varchar(255) DEFAULT NULL,
  `last_position` varchar(150) DEFAULT NULL,
  `profile_completed_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `client_profiles`
--

INSERT INTO `client_profiles` (`id`, `client_id`, `citizenship`, `civil_status`, `religion`, `height_cm`, `weight_kg`, `educational_attainment`, `course_degree`, `school_name`, `school_address`, `year_graduated`, `work_experience_years`, `last_employer`, `last_position`, `profile_completed_at`, `updated_at`) VALUES
(3, 1, 'Filipino', 'Single', 'Catholic', 100.50, 37.00, 'College Graduate', 'BSIT', 'City College of Calamba', '0', '2022', 1, NULL, NULL, '2026-06-24 08:04:32', '2026-06-24 14:04:32'),
(4, 18, 'Filipino', 'Single', 'Catholic', 115.00, 60.00, 'College Graduate', 'Bachelor of Science in Information Technology', 'City College of Calamba', '0', '2024', NULL, NULL, NULL, '2026-06-29 03:08:57', '2026-06-29 09:08:57'),
(5, 19, 'Filipino', 'Single', 'Catholic', 103.50, 45.00, 'College Undergraduate', 'Bachelor of Science in Nursing', 'Calamba Nursing College', '0', '2023', NULL, NULL, NULL, '2026-06-29 20:56:11', '2026-06-30 02:56:11'),
(6, 7, 'Filipino', 'Single', 'Iglesia ni Cristo', 102.00, 50.00, 'College Graduate', 'BSIT', 'City College of Calamba', '0', '2022', NULL, NULL, NULL, '2026-07-02 07:11:16', '2026-07-02 13:11:16');

-- --------------------------------------------------------

--
-- Table structure for table `document_comments`
--

CREATE TABLE `document_comments` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `commented_by` varchar(100) NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_comments`
--

INSERT INTO `document_comments` (`id`, `document_id`, `commented_by`, `comment`, `created_at`) VALUES
(9, 107, 'mrcsmangubat@gmail.com', 'pabago', '2026-07-03 08:25:30'),
(10, 109, 'alexgonzalvo3@gmail.com', 'mali to', '2026-07-04 13:26:53'),
(11, 109, 'mrcsmangubat@gmail.com', 'kiana', '2026-07-04 14:09:23'),
(12, 112, 'mrcsmangubat@gmail.com', 'okay na', '2026-07-04 14:17:33'),
(13, 113, 'mrcsmangubat@gmail.com', 'oki na', '2026-07-04 14:18:03');

-- --------------------------------------------------------

--
-- Table structure for table `evaluations`
--

CREATE TABLE `evaluations` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `evaluator_id` int(11) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `evaluation_type` varchar(20) DEFAULT NULL,
  `decision` varchar(20) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `evaluated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `evaluations`
--

INSERT INTO `evaluations` (`id`, `application_id`, `evaluator_id`, `admin_id`, `evaluation_type`, `decision`, `comments`, `evaluated_at`) VALUES
(32, 58, 4, NULL, 'compliance', 'for_compliance', 'kulang', '2026-07-03 00:11:21'),
(33, 58, 4, NULL, 'compliance', 'for_compliance', 'kulang parin', '2026-07-03 08:00:57'),
(34, 58, 4, NULL, 'compliance', 'for_compliance', 'kulang parin', '2026-07-03 08:04:49'),
(35, 58, 4, NULL, 'compliance', 'for_compliance', 'kulang parin', '2026-07-03 08:05:31'),
(36, 57, 3, NULL, 'initial', 'disapproved', '', '2026-07-03 12:49:43'),
(37, 58, 4, NULL, 'initial', 'approved', 'goods na punta ka na dito pre', '2026-07-03 13:52:17'),
(38, 61, 3, NULL, 'initial', 'disapproved', '', '2026-07-04 09:37:40'),
(39, 60, 3, NULL, 'compliance', 'for_compliance', '', '2026-07-04 13:27:04'),
(40, 59, 3, NULL, 'compliance', 'for_compliance', '', '2026-07-04 13:36:48'),
(41, 60, 4, NULL, 'compliance', 'for_compliance', 'caleb', '2026-07-04 13:48:45'),
(42, 62, 4, NULL, 'compliance', 'for_compliance', 'best part', '2026-07-04 14:14:39'),
(43, 63, 4, NULL, 'initial', 'disapproved', '', '2026-07-05 09:20:42'),
(44, 64, 11, NULL, 'initial', 'disapproved', 'ayaw ko sayu', '2026-07-05 09:31:04'),
(45, 62, 3, NULL, 'compliance', 'for_compliance', 'best part', '2026-07-05 09:34:04'),
(46, 59, 3, NULL, 'initial', 'approved', '', '2026-07-06 02:27:12'),
(47, 65, 4, NULL, 'initial', 'disapproved', '', '2026-07-06 03:30:08'),
(48, 62, NULL, 1, 'admin', 'approved', NULL, '2026-07-06 12:18:40'),
(49, 66, 3, NULL, 'compliance', 'for_compliance', '', '2026-07-06 12:43:25'),
(50, 66, 11, NULL, 'compliance', 'for_compliance', '', '2026-07-06 12:53:41'),
(51, 66, 11, NULL, 'compliance', 'for_compliance', '', '2026-07-06 12:59:58'),
(52, 66, 11, NULL, 'compliance', 'for_compliance', '', '2026-07-06 13:03:46'),
(53, 66, 11, NULL, 'compliance', 'for_compliance', '', '2026-07-06 13:06:57'),
(54, 66, 11, NULL, 'compliance', 'for_compliance', '', '2026-07-06 13:12:01'),
(55, 66, 11, NULL, 'compliance', 'for_compliance', '', '2026-07-06 13:22:38'),
(56, 66, 11, NULL, 'compliance', 'for_compliance', '', '2026-07-06 13:25:23'),
(57, 66, 11, NULL, 'compliance', 'for_compliance', '', '2026-07-06 13:25:42'),
(58, 66, 11, NULL, 'compliance', 'for_compliance', '', '2026-07-06 13:39:00'),
(59, 66, 11, NULL, 'compliance', 'for_compliance', '', '2026-07-06 13:41:24'),
(60, 66, 11, NULL, 'compliance', 'for_compliance', '', '2026-07-06 13:51:28'),
(61, 60, 4, NULL, 'initial', 'approved', 'caleb', '2026-07-07 06:25:58'),
(62, 69, 4, NULL, 'compliance', 'for_compliance', 'wala answer sa questions', '2026-07-08 00:43:57'),
(63, 69, 4, NULL, 'compliance', 'for_compliance', 'paayos na lang nun', '2026-07-08 00:46:39'),
(64, 69, 4, NULL, 'initial', 'approved', 'ok na', '2026-07-08 00:48:14');

-- --------------------------------------------------------

--
-- Table structure for table `evaluators`
--

CREATE TABLE `evaluators` (
  `eid` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `evaluators`
--

INSERT INTO `evaluators` (`eid`, `email`, `full_name`, `phone`, `created_at`) VALUES
(3, 'alexgonzalvo3@gmail.com', 'Alex Gonzalvo', '09309444418', '2026-03-25 01:45:40'),
(4, 'mangubatmarcus@gmail.com', 'Marcus Mangubat', '09309444413', '2026-03-28 01:20:02'),
(11, 'marcusmangubat953@gmail.com', 'Sucram Mangubat', '09309444413', '2026-07-02 03:33:27');

-- --------------------------------------------------------

--
-- Table structure for table `invitations`
--

CREATE TABLE `invitations` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `usertype` enum('client','evaluator') NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_by` varchar(100) NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invitations`
--

INSERT INTO `invitations` (`id`, `email`, `usertype`, `full_name`, `phone`, `token`, `expires_at`, `created_by`, `used`, `used_at`, `created_at`) VALUES
(1, 'mangubatmarcus@gmail.com', 'evaluator', 'Juan Dela Cruz', '09309444418', '4cf037111afc8befd5611848d5167cf4ae6a3a810681063fc13e76287aff343b', '2026-03-22 05:20:12', 'mrcsmangubat@gmail.com', 1, '2026-03-21 12:22:18', '2026-03-21 04:20:12'),
(2, 'alexgonzalvo3@gmail.com', 'evaluator', 'Justine Mangubat', '09309444410', '17d935ffebfefb4a3bfdcdf45d0c7cbfbd9ebc174b8920c529394028a68ad050', '2026-03-22 05:59:30', 'mrcsmangubat@gmail.com', 1, '2026-03-21 13:00:10', '2026-03-21 04:59:30'),
(3, 'mangubatjustine476@gmail.com', 'evaluator', 'Justine Mangubat', '09309444418', '1c0463468a893223b334ac733edb737d85195c7f9b13f635d4f248fa7c81a6fc', '2026-03-26 02:39:22', 'mrcsmangubat@gmail.com', 0, NULL, '2026-03-25 01:39:22'),
(4, 'alexgonzalvo3@gmail.com', 'evaluator', 'Alex Gonzalvo', '09309444418', '546d2d4e62483cf7736b1912ee3f41eb675a10789ebac733be1f3afd6dbf0957', '2026-03-26 02:45:08', 'mrcsmangubat@gmail.com', 1, '2026-03-25 09:45:40', '2026-03-25 01:45:08'),
(5, 'mangubatmarcus@gmail.com', 'evaluator', 'Marcus Mangubat', '09309444413', '83416c3f9900d88ec2f099125a6fa1a2821a4b32dd304afba64e4da399e82e8c', '2026-03-29 03:19:24', 'mrcsmangubat@gmail.com', 1, '2026-03-28 09:20:02', '2026-03-28 01:19:24'),
(16, 'mangubatcoco6@gmail.com', 'evaluator', 'Marcus Mangubat', '09309444413', 'f3c7e6bbaf6648027fae1bc9aac22ac2e1501f42c1db74f5527defc65aa2a057', '2026-04-18 18:10:27', 'mrcsmangubat@gmail.com', 0, NULL, '2026-04-17 16:10:27'),
(17, 'mangubatcoco6@gmail.com', 'evaluator', 'Juan Dela Cruz', '09309444413', '57454c52494bc57efb21e02af3389c160554b1d6f72c51d8b3dc8b253ffedca7', '2026-04-18 18:11:08', 'mrcsmangubat@gmail.com', 1, '2026-04-18 00:11:49', '2026-04-17 16:11:08'),
(18, 'justinemangubat1234@gmail.com', 'evaluator', 'Justine Mangubat', '09309656598', 'ea973d122b76b351fa28948a62be69d538c96d10b47eb2ca317f30f98447bfdd', '2026-04-20 04:37:07', 'mrcsmangubat@gmail.com', 1, '2026-04-19 10:38:05', '2026-04-19 02:37:07'),
(19, 'justinemangubat1234@gmail.com', 'evaluator', 'Justine Mangubat', '09309656598', 'f0140bf77e61d7245038fe453de0fe1a67b3b5cea072ab5b28883f84731c2ce5', '2026-04-20 04:37:40', 'mrcsmangubat@gmail.com', 1, '2026-04-19 10:45:52', '2026-04-19 02:37:40'),
(20, 'mangubatcoco6@gmail.com', 'evaluator', 'Coco Martin', '09124356452', '69deb80ee83602686db983d594b3879448f57726f1e34c49b5a85527a65ea9c9', '2026-04-24 16:44:44', 'mrcsmangubat@gmail.com', 1, '2026-04-23 22:45:49', '2026-04-23 14:44:44'),
(21, 'mangubatcoco6@gmail.com', 'evaluator', 'Coco Martin', '09124356452', 'bb5c4de7a87a765a1d881e285884e2a38c8a86a73a1bc1b9f8cb802ec99716ab', '2026-04-24 16:46:47', 'mrcsmangubat@gmail.com', 0, NULL, '2026-04-23 14:46:47'),
(22, 'marcusmangubat953@gmail.com', 'evaluator', 'Marcus Mangubat', '09309444413', '5ec37ae13e0236ac1a545a01a1424a1bbefde9a9a6d6e7ea5abfc923fd7406e2', '2026-06-07 05:15:24', 'mrcsmangubat@gmail.com', 1, '2026-06-06 11:16:10', '2026-06-06 03:15:24'),
(23, 'marcusmangubat953@gmail.com', 'evaluator', 'Marcus Mangubat', '09309444413', 'f7aedce2d96dc76a1c894acfdd099318a3a24b2a3d99f11b32c9dec43efdc0d5', '2026-06-07 05:31:54', 'mrcsmangubat@gmail.com', 0, NULL, '2026-06-06 03:31:54'),
(24, 'marcusmangubat953@gmail.com', 'evaluator', 'Marcus Mangubat', '09309444413', 'f09c214cb2223e2bbbe553c6d44685ea5a13d4a83b13366a158284ec61fc9544', '2026-06-07 05:32:15', 'mrcsmangubat@gmail.com', 1, '2026-06-06 11:33:49', '2026-06-06 03:32:15'),
(25, 'marcusmangubat953@gmail.com', 'evaluator', 'Marcus Mangubat', '09309444413', '804c03abf64196c9371bc63cbe59133f996357eaad4ed56d51e4738ed87865c6', '2026-07-03 05:32:50', 'mrcsmangubat@gmail.com', 1, '2026-07-02 11:33:27', '2026-07-02 03:32:50');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_email` varchar(100) NOT NULL,
  `usertype` enum('client','evaluator','admin') NOT NULL,
  `application_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_email`, `usertype`, `application_id`, `title`, `message`, `type`, `is_read`, `read_at`, `created_at`) VALUES
(84, 'mrcsmangubat@gmail.com', 'admin', 57, 'New Application Submitted', 'A new application (#57) has been submitted for review.', 'new_application', 1, '2026-07-03 05:11:06', '2026-07-03 00:00:44'),
(85, 'mgmangubat@ccc.edu.ph', 'client', 57, 'Application Under Evaluation', 'An evaluator has started reviewing your application.', 'application_status', 1, '2026-07-03 00:18:00', '2026-07-03 00:01:09'),
(86, 'mrcsmangubat@gmail.com', 'admin', 58, 'New Application Submitted', 'A new application (#58) has been submitted for review.', 'new_application', 1, '2026-07-03 05:11:06', '2026-07-03 00:08:23'),
(87, 'laguantero@ccc.edu.ph', 'client', 58, 'Application Under Evaluation', 'An evaluator has started reviewing your application.', 'application_status', 1, '2026-07-03 08:24:02', '2026-07-03 00:09:37'),
(88, 'laguantero@ccc.edu.ph', 'client', 58, 'Application For Compliance', 'Your application status is now For Compliance. Evaluator note: kulang', 'application_status', 1, '2026-07-03 08:24:02', '2026-07-03 00:11:21'),
(89, 'mrcsmangubat@gmail.com', 'admin', 58, 'Application Needs Compliance Review', 'Application #58 has been For Compliance by evaluator. Note: kulang', 'compliance_handoff', 1, '2026-07-03 05:11:06', '2026-07-03 00:11:21'),
(90, 'laguantero@ccc.edu.ph', 'client', 58, 'Application Assigned', 'Your resubmitted application has been assigned back to an evaluator for review.', 'application_status', 1, '2026-07-03 08:24:02', '2026-07-03 05:11:56'),
(91, 'mangubatmarcus@gmail.com', 'evaluator', 58, 'Application Resubmitted', 'Application #58 has been resubmitted by the client and returned to your queue.', 'application_update', 1, '2026-07-03 05:12:09', '2026-07-03 05:11:56'),
(92, 'laguantero@ccc.edu.ph', 'client', 58, 'Application For Compliance', 'Your application status is now For Compliance. Evaluator note: kulang parin', 'application_status', 1, '2026-07-03 08:24:02', '2026-07-03 08:00:57'),
(93, 'mrcsmangubat@gmail.com', 'admin', 58, 'Application Needs Compliance Review', 'Application #58 has been For Compliance by evaluator. Note: kulang parin', 'compliance_handoff', 1, '2026-07-04 07:35:01', '2026-07-03 08:00:57'),
(94, 'mangubatmarcus@gmail.com', 'evaluator', 58, 'Application Reopened', 'Application #58 has been reopened by Admin and returned to your queue.', 'application_update', 1, '2026-07-03 08:26:42', '2026-07-03 08:04:38'),
(95, 'laguantero@ccc.edu.ph', 'client', 58, 'Application Under Review Again', 'Your application has been returned to the evaluator for continued review.', 'application_status', 1, '2026-07-03 08:24:02', '2026-07-03 08:04:38'),
(96, 'laguantero@ccc.edu.ph', 'client', 58, 'Application For Compliance', 'Your application status is now For Compliance. Evaluator note: kulang parin', 'application_status', 1, '2026-07-03 08:24:02', '2026-07-03 08:04:49'),
(97, 'mrcsmangubat@gmail.com', 'admin', 58, 'Application Needs Compliance Review', 'Application #58 has been For Compliance by evaluator. Note: kulang parin', 'compliance_handoff', 1, '2026-07-04 07:35:01', '2026-07-03 08:04:49'),
(98, 'mangubatmarcus@gmail.com', 'evaluator', 58, 'Application Reopened', 'Application #58 has been reopened by Admin and returned to your queue.', 'application_update', 1, '2026-07-03 08:26:42', '2026-07-03 08:04:53'),
(99, 'laguantero@ccc.edu.ph', 'client', 58, 'Application Under Review Again', 'Your application has been returned to the evaluator for continued review.', 'application_status', 1, '2026-07-03 08:24:02', '2026-07-03 08:04:53'),
(100, 'laguantero@ccc.edu.ph', 'client', 58, 'Application For Compliance', 'Your application status is now For Compliance. Evaluator note: kulang parin', 'application_status', 1, '2026-07-03 08:24:02', '2026-07-03 08:05:31'),
(101, 'mrcsmangubat@gmail.com', 'admin', 58, 'Application Needs Compliance Review', 'Application #58 has been For Compliance by evaluator. Note: kulang parin', 'compliance_handoff', 1, '2026-07-04 07:35:01', '2026-07-03 08:05:31'),
(102, 'laguantero@ccc.edu.ph', 'client', 58, 'New Document Comment', 'Admin added a comment to one of your uploaded documents. Please review your application details.', 'document_comment', 0, NULL, '2026-07-03 08:25:30'),
(103, 'mangubatmarcus@gmail.com', 'evaluator', 58, 'New Document Comment from Admin', 'Admin commented on a document for application #58.', 'application_update', 1, '2026-07-03 08:26:42', '2026-07-03 08:25:30'),
(104, 'mangubatmarcus@gmail.com', 'evaluator', 58, 'Application Reopened', 'Application #58 has been reopened by Admin and returned to your queue.', 'application_update', 1, '2026-07-03 08:26:42', '2026-07-03 08:26:26'),
(105, 'laguantero@ccc.edu.ph', 'client', 58, 'Application Under Review Again', 'Your application has been returned to the evaluator for continued review.', 'application_status', 0, NULL, '2026-07-03 08:26:26'),
(106, 'mrcsmangubat@gmail.com', 'admin', 59, 'New Application Submitted', 'A new application (#59) has been submitted for review.', 'new_application', 1, '2026-07-04 07:35:01', '2026-07-03 08:32:09'),
(107, 'mgmangubat@ccc.edu.ph', 'client', 57, 'Application Disapproved', 'Your application status is now Disapproved.', 'application_status', 1, '2026-07-04 12:50:32', '2026-07-03 12:49:43'),
(108, 'mrcsmangubat@gmail.com', 'admin', 57, 'Application Disapproved', 'Application #57 has been Disapproved by evaluator.', 'evaluation_complete', 1, '2026-07-04 07:35:01', '2026-07-03 12:49:43'),
(109, 'laguantero@ccc.edu.ph', 'client', 58, 'Application Approved', 'Your application status is now Approved. Evaluator note: goods na punta ka na dito pre', 'application_status', 0, NULL, '2026-07-03 13:52:17'),
(110, 'mrcsmangubat@gmail.com', 'admin', 58, 'Application Approved', 'Application #58 has been Approved by evaluator. Note: goods na punta ka na dito pre', 'evaluation_complete', 1, '2026-07-04 07:35:01', '2026-07-03 13:52:17'),
(111, 'mrcsmangubat@gmail.com', 'admin', 60, 'New Application Submitted', 'A new application (#60) has been submitted for review.', 'new_application', 1, '2026-07-04 09:26:31', '2026-07-04 09:26:15'),
(112, 'mangubatcoco6@gmail.com', 'client', 60, 'Application Under Evaluation', 'An evaluator has started reviewing your application.', 'application_status', 1, '2026-07-04 13:48:54', '2026-07-04 09:26:43'),
(113, 'mrcsmangubat@gmail.com', 'admin', 61, 'New Application Submitted', 'A new application (#61) has been submitted for review.', 'new_application', 1, '2026-07-04 13:12:22', '2026-07-04 09:37:03'),
(114, 'mangubatcoco6@gmail.com', 'client', 61, 'Application Under Evaluation', 'An evaluator has started reviewing your application.', 'application_status', 1, '2026-07-04 13:48:54', '2026-07-04 09:37:37'),
(115, 'mangubatcoco6@gmail.com', 'client', 61, 'Application Disapproved', 'Your application status is now Disapproved.', 'application_status', 1, '2026-07-04 13:48:54', '2026-07-04 09:37:40'),
(116, 'mrcsmangubat@gmail.com', 'admin', 61, 'Application Disapproved', 'Application #61 has been Disapproved by evaluator.', 'evaluation_complete', 1, '2026-07-04 13:12:22', '2026-07-04 09:37:40'),
(117, 'mangubatcoco6@gmail.com', 'client', 60, 'New Document Comment', 'An evaluator added a comment to one of your uploaded documents. Please review your application details.', 'document_comment', 1, '2026-07-04 13:48:54', '2026-07-04 13:26:53'),
(118, 'mangubatcoco6@gmail.com', 'client', 60, 'Application For Compliance', 'Your application status is now For Compliance.', 'application_status', 1, '2026-07-04 13:48:54', '2026-07-04 13:27:04'),
(119, 'mrcsmangubat@gmail.com', 'admin', 60, 'Application Needs Compliance Review', 'Application #60 has been For Compliance by evaluator.', 'compliance_handoff', 1, '2026-07-04 14:14:54', '2026-07-04 13:27:04'),
(120, 'mgmangubat@ccc.edu.ph', 'client', 59, 'Application For Compliance', 'Your application status is now For Compliance.', 'application_status', 1, '2026-07-06 12:02:51', '2026-07-04 13:36:48'),
(121, 'mrcsmangubat@gmail.com', 'admin', 59, 'Application Needs Compliance Review', 'Application #59 has been For Compliance by evaluator.', 'compliance_handoff', 1, '2026-07-04 14:14:54', '2026-07-04 13:36:48'),
(122, 'mgmangubat@ccc.edu.ph', 'client', 59, 'Application Assigned', 'Your resubmitted application has been assigned back to an evaluator for review.', 'application_status', 1, '2026-07-06 12:02:51', '2026-07-04 13:42:35'),
(123, 'alexgonzalvo3@gmail.com', 'evaluator', 59, 'Application Resubmitted', 'Application #59 has been resubmitted by the client and returned to your queue.', 'application_update', 1, '2026-07-06 01:59:40', '2026-07-04 13:42:35'),
(124, 'mangubatmarcus@gmail.com', 'evaluator', 60, 'Application Reassigned to You', 'Application #60 has been reassigned to you by Admin.', 'application_update', 1, '2026-07-04 13:45:21', '2026-07-04 13:44:57'),
(125, 'mangubatcoco6@gmail.com', 'client', 60, 'Application Reassigned', 'Your application has been reassigned to evaluator Marcus Mangubat.', 'application_status', 1, '2026-07-04 13:48:54', '2026-07-04 13:44:57'),
(126, 'mangubatcoco6@gmail.com', 'client', 60, 'Application For Compliance', 'Your application status is now For Compliance. Evaluator note: caleb', 'application_status', 1, '2026-07-04 13:48:54', '2026-07-04 13:48:45'),
(127, 'mrcsmangubat@gmail.com', 'admin', 60, 'Application Needs Compliance Review', 'Application #60 has been For Compliance by evaluator. Note: caleb', 'compliance_handoff', 1, '2026-07-04 14:14:54', '2026-07-04 13:48:45'),
(128, 'mangubatcoco6@gmail.com', 'client', 60, 'New Document Comment', 'Admin added a comment to one of your uploaded documents. Please review your application details.', 'document_comment', 1, '2026-07-05 11:55:31', '2026-07-04 14:09:23'),
(129, 'mangubatmarcus@gmail.com', 'evaluator', 60, 'New Document Comment from Admin', 'Admin commented on a document for application #60.', 'application_update', 1, '2026-07-05 09:17:20', '2026-07-04 14:09:23'),
(130, 'mangubatmarcus@gmail.com', 'evaluator', 60, 'Application Reopened', 'Application #60 has been reopened by Admin and returned to your queue.', 'application_update', 1, '2026-07-05 09:17:20', '2026-07-04 14:09:29'),
(131, 'mangubatcoco6@gmail.com', 'client', 60, 'Application Under Review Again', 'Your application has been returned to the evaluator for continued review.', 'application_status', 1, '2026-07-05 11:55:31', '2026-07-04 14:09:29'),
(132, 'mrcsmangubat@gmail.com', 'admin', 62, 'New Application Submitted', 'A new application (#62) has been submitted for review.', 'new_application', 1, '2026-07-04 14:14:54', '2026-07-04 14:13:39'),
(133, 'mangubatcoco6@gmail.com', 'client', 62, 'Application Under Evaluation', 'An evaluator has started reviewing your application.', 'application_status', 1, '2026-07-05 11:55:31', '2026-07-04 14:14:07'),
(134, 'mangubatcoco6@gmail.com', 'client', 62, 'Application For Compliance', 'Your application status is now For Compliance. Evaluator note: best part', 'application_status', 1, '2026-07-05 11:55:31', '2026-07-04 14:14:39'),
(135, 'mrcsmangubat@gmail.com', 'admin', 62, 'Application Needs Compliance Review', 'Application #62 has been For Compliance by evaluator. Note: best part', 'compliance_handoff', 1, '2026-07-04 14:14:54', '2026-07-04 14:14:39'),
(136, 'mangubatcoco6@gmail.com', 'client', 62, 'New Document Comment', 'Admin added a comment to one of your uploaded documents. Please review your application details.', 'document_comment', 1, '2026-07-05 11:55:31', '2026-07-04 14:17:33'),
(137, 'mangubatmarcus@gmail.com', 'evaluator', 62, 'New Document Comment from Admin', 'Admin commented on a document for application #62.', 'application_update', 1, '2026-07-05 09:17:20', '2026-07-04 14:17:33'),
(138, 'mangubatcoco6@gmail.com', 'client', 62, 'New Document Comment', 'Admin added a comment to one of your uploaded documents. Please review your application details.', 'document_comment', 1, '2026-07-05 11:55:31', '2026-07-04 14:18:03'),
(139, 'mangubatmarcus@gmail.com', 'evaluator', 62, 'New Document Comment from Admin', 'Admin commented on a document for application #62.', 'application_update', 1, '2026-07-05 09:17:20', '2026-07-04 14:18:03'),
(140, 'alexgonzalvo3@gmail.com', 'evaluator', 62, 'Application Reassigned to You', 'Application #62 has been reassigned to you by Admin.', 'application_update', 1, '2026-07-06 01:59:40', '2026-07-04 14:18:11'),
(141, 'mangubatcoco6@gmail.com', 'client', 62, 'Application Reassigned', 'Your application has been reassigned to evaluator Alex Gonzalvo.', 'application_status', 1, '2026-07-05 11:55:31', '2026-07-04 14:18:11'),
(142, 'mrcsmangubat@gmail.com', 'admin', 63, 'New Application Submitted', 'A new application (#63) has been submitted for review.', 'new_application', 1, '2026-07-05 09:39:02', '2026-07-05 09:19:32'),
(143, 'mgmangubat@ccc.edu.ph', 'client', 63, 'Application Under Evaluation', 'An evaluator has started reviewing your application.', 'application_status', 1, '2026-07-06 12:02:51', '2026-07-05 09:20:38'),
(144, 'mgmangubat@ccc.edu.ph', 'client', 63, 'Application Disapproved', 'Your application status is now Disapproved.', 'application_status', 1, '2026-07-06 12:02:51', '2026-07-05 09:20:42'),
(145, 'mrcsmangubat@gmail.com', 'admin', 63, 'Application Disapproved', 'Application #63 has been Disapproved by evaluator.', 'evaluation_complete', 1, '2026-07-05 09:39:02', '2026-07-05 09:20:42'),
(146, 'mrcsmangubat@gmail.com', 'admin', 64, 'New Application Submitted', 'A new application (#64) has been submitted for review.', 'new_application', 1, '2026-07-05 09:39:02', '2026-07-05 09:27:39'),
(147, 'justinemangubat1234@gmail.com', 'client', 64, 'Application Under Evaluation', 'An evaluator has started reviewing your application.', 'application_status', 0, NULL, '2026-07-05 09:28:36'),
(148, 'justinemangubat1234@gmail.com', 'client', 64, 'Application Disapproved', 'Your application status is now Disapproved. Evaluator note: ayaw ko sayu', 'application_status', 0, NULL, '2026-07-05 09:31:04'),
(149, 'mrcsmangubat@gmail.com', 'admin', 64, 'Application Disapproved', 'Application #64 has been Disapproved by evaluator. Note: ayaw ko sayu', 'evaluation_complete', 1, '2026-07-05 09:39:02', '2026-07-05 09:31:04'),
(150, 'mangubatcoco6@gmail.com', 'client', 62, 'Application For Compliance', 'Your application status is now For Compliance. Evaluator note: best part', 'application_status', 1, '2026-07-05 11:55:31', '2026-07-05 09:34:04'),
(151, 'mrcsmangubat@gmail.com', 'admin', 62, 'Application Needs Compliance Review', 'Application #62 has been For Compliance by evaluator. Note: best part', 'compliance_handoff', 1, '2026-07-05 09:39:02', '2026-07-05 09:34:04'),
(152, 'mgmangubat@ccc.edu.ph', 'client', 59, 'Application Approved', 'Your application status is now Approved.', 'application_status', 1, '2026-07-06 12:02:51', '2026-07-06 02:27:12'),
(153, 'mrcsmangubat@gmail.com', 'admin', 59, 'Application Approved', 'Application #59 has been Approved by evaluator.', 'evaluation_complete', 1, '2026-07-06 13:11:18', '2026-07-06 02:27:12'),
(154, 'mrcsmangubat@gmail.com', 'admin', 65, 'New Application Submitted', 'A new application (#65) has been submitted for review.', 'new_application', 1, '2026-07-06 13:11:18', '2026-07-06 03:29:04'),
(155, 'mangubatcoco6@gmail.com', 'client', 65, 'Application Under Evaluation', 'An evaluator has started reviewing your application.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 03:29:52'),
(156, 'mangubatcoco6@gmail.com', 'client', 65, 'Application Disapproved', 'Your application status is now Disapproved.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 03:30:08'),
(157, 'mrcsmangubat@gmail.com', 'admin', 65, 'Application Disapproved', 'Application #65 has been Disapproved by evaluator.', 'evaluation_complete', 1, '2026-07-06 13:11:18', '2026-07-06 03:30:08'),
(158, 'mangubatcoco6@gmail.com', 'client', 62, 'Application Approved', 'Your application status is now Approved.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 12:18:40'),
(159, 'mrcsmangubat@gmail.com', 'admin', 66, 'New Application Submitted', 'A new application (#66) has been submitted for review.', 'new_application', 1, '2026-07-06 13:11:18', '2026-07-06 12:33:46'),
(160, 'mangubatcoco6@gmail.com', 'client', 66, 'Application Under Evaluation', 'An evaluator has started reviewing your application.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 12:43:22'),
(161, 'mangubatcoco6@gmail.com', 'client', 66, 'Application For Compliance', 'Your application status is now For Compliance.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 12:43:25'),
(162, 'mrcsmangubat@gmail.com', 'admin', 66, 'Application Needs Compliance Review', 'Application #66 has been For Compliance by evaluator.', 'compliance_handoff', 1, '2026-07-06 13:11:18', '2026-07-06 12:43:25'),
(163, 'marcusmangubat953@gmail.com', 'evaluator', 66, 'Application Reassigned to You', 'Application #66 has been reassigned to you by Admin.', 'application_update', 1, '2026-07-06 12:52:54', '2026-07-06 12:52:43'),
(164, 'mangubatcoco6@gmail.com', 'client', 66, 'Application Reassigned', 'Your application has been reassigned to evaluator Sucram Mangubat.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 12:52:43'),
(165, 'mangubatcoco6@gmail.com', 'client', 66, 'Application For Compliance', 'Your application status is now For Compliance.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 12:53:41'),
(166, 'mrcsmangubat@gmail.com', 'admin', 66, 'Application Needs Compliance Review', 'Application #66 has been For Compliance by evaluator.', 'compliance_handoff', 1, '2026-07-06 13:11:18', '2026-07-06 12:53:41'),
(167, 'marcusmangubat953@gmail.com', 'evaluator', 66, 'Application Reopened', 'Application #66 has been reopened by Admin and returned to your queue.', 'application_update', 1, '2026-07-06 13:11:27', '2026-07-06 12:56:04'),
(168, 'mangubatcoco6@gmail.com', 'client', 66, 'Application Under Review Again', 'Your application has been returned to the evaluator for continued review.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 12:56:04'),
(169, 'mangubatcoco6@gmail.com', 'client', 66, 'Application For Compliance', 'Your application status is now For Compliance.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 12:59:58'),
(170, 'mrcsmangubat@gmail.com', 'admin', 66, 'Application Needs Compliance Review', 'Application #66 has been For Compliance by evaluator.', 'compliance_handoff', 1, '2026-07-06 13:11:18', '2026-07-06 12:59:58'),
(171, 'marcusmangubat953@gmail.com', 'evaluator', 66, 'Application Reopened', 'Application #66 has been reopened by Admin and returned to your queue.', 'application_update', 1, '2026-07-06 13:11:27', '2026-07-06 13:03:40'),
(172, 'mangubatcoco6@gmail.com', 'client', 66, 'Application Under Review Again', 'Your application has been returned to the evaluator for continued review.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 13:03:40'),
(173, 'mangubatcoco6@gmail.com', 'client', 66, 'Application For Compliance', 'Your application status is now For Compliance.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 13:03:47'),
(174, 'mrcsmangubat@gmail.com', 'admin', 66, 'Application Needs Compliance Review', 'Application #66 has been For Compliance by evaluator.', 'compliance_handoff', 1, '2026-07-06 13:11:18', '2026-07-06 13:03:47'),
(175, 'marcusmangubat953@gmail.com', 'evaluator', 66, 'Application Reopened', 'Application #66 has been reopened by Admin and returned to your queue.', 'application_update', 1, '2026-07-06 13:11:27', '2026-07-06 13:04:26'),
(176, 'mangubatcoco6@gmail.com', 'client', 66, 'Application Under Review Again', 'Your application has been returned to the evaluator for continued review.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 13:04:26'),
(177, 'mangubatcoco6@gmail.com', 'client', 66, 'Application For Compliance', 'Your application status is now For Compliance.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 13:06:57'),
(178, 'mrcsmangubat@gmail.com', 'admin', 66, 'Application Needs Compliance Review', 'Application #66 has been For Compliance by evaluator.', 'compliance_handoff', 1, '2026-07-06 13:11:18', '2026-07-06 13:06:57'),
(179, 'marcusmangubat953@gmail.com', 'evaluator', 66, 'Application Reopened', 'Application #66 has been reopened by Admin and returned to your queue.', 'application_update', 1, '2026-07-06 13:11:27', '2026-07-06 13:07:19'),
(180, 'mangubatcoco6@gmail.com', 'client', 66, 'Application Under Review Again', 'Your application has been returned to the evaluator for continued review.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 13:07:19'),
(181, 'mangubatcoco6@gmail.com', 'client', 66, 'Application For Compliance', 'Your application status is now For Compliance.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 13:12:01'),
(182, 'mrcsmangubat@gmail.com', 'admin', 66, 'Application Needs Compliance Review', 'Application #66 has been For Compliance by evaluator.', 'compliance_handoff', 1, '2026-07-07 07:12:56', '2026-07-06 13:12:01'),
(183, 'marcusmangubat953@gmail.com', 'evaluator', 66, 'Application Reopened', 'Application #66 has been reopened by Admin and returned to your queue.', 'application_update', 0, NULL, '2026-07-06 13:22:23'),
(184, 'mangubatcoco6@gmail.com', 'client', 66, 'Application Under Review Again', 'Your application has been returned to the evaluator for continued review.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 13:22:23'),
(185, 'mangubatcoco6@gmail.com', 'client', 66, 'Application For Compliance', 'Your application status is now For Compliance.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 13:22:38'),
(186, 'mrcsmangubat@gmail.com', 'admin', 66, 'Application Needs Compliance Review', 'Application #66 has been For Compliance by evaluator.', 'compliance_handoff', 1, '2026-07-07 07:12:56', '2026-07-06 13:22:38'),
(187, 'marcusmangubat953@gmail.com', 'evaluator', 66, 'Application Reopened', 'Application #66 has been reopened by Admin and returned to your queue.', 'application_update', 0, NULL, '2026-07-06 13:22:58'),
(188, 'mangubatcoco6@gmail.com', 'client', 66, 'Application Under Review Again', 'Your application has been returned to the evaluator for continued review.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 13:22:58'),
(189, 'mangubatcoco6@gmail.com', 'client', 66, 'Application For Compliance', 'Your application status is now For Compliance.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 13:25:23'),
(190, 'mrcsmangubat@gmail.com', 'admin', 66, 'Application Needs Compliance Review', 'Application #66 has been For Compliance by evaluator.', 'compliance_handoff', 1, '2026-07-07 07:12:56', '2026-07-06 13:25:23'),
(191, 'marcusmangubat953@gmail.com', 'evaluator', 66, 'Application Reopened', 'Application #66 has been reopened by Admin and returned to your queue.', 'application_update', 0, NULL, '2026-07-06 13:25:28'),
(192, 'mangubatcoco6@gmail.com', 'client', 66, 'Application Under Review Again', 'Your application has been returned to the evaluator for continued review.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 13:25:28'),
(193, 'mangubatcoco6@gmail.com', 'client', 66, 'Application For Compliance', 'Your application status is now For Compliance.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 13:25:42'),
(194, 'mrcsmangubat@gmail.com', 'admin', 66, 'Application Needs Compliance Review', 'Application #66 has been For Compliance by evaluator.', 'compliance_handoff', 1, '2026-07-07 07:12:56', '2026-07-06 13:25:42'),
(195, 'marcusmangubat953@gmail.com', 'evaluator', 66, 'Application Reopened', 'Application #66 has been reopened by Admin and returned to your queue.', 'application_update', 0, NULL, '2026-07-06 13:26:00'),
(196, 'mangubatcoco6@gmail.com', 'client', 66, 'Application Under Review Again', 'Your application has been returned to the evaluator for continued review.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 13:26:00'),
(197, 'mangubatcoco6@gmail.com', 'client', 66, 'Application For Compliance', 'Your application status is now For Compliance.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 13:39:00'),
(198, 'mrcsmangubat@gmail.com', 'admin', 66, 'Application Needs Compliance Review', 'Application #66 has been For Compliance by evaluator.', 'compliance_handoff', 1, '2026-07-07 07:12:56', '2026-07-06 13:39:00'),
(199, 'marcusmangubat953@gmail.com', 'evaluator', 66, 'Application Reopened', 'Application #66 has been reopened by Admin and returned to your queue.', 'application_update', 0, NULL, '2026-07-06 13:41:09'),
(200, 'mangubatcoco6@gmail.com', 'client', 66, 'Application Under Review Again', 'Your application has been returned to the evaluator for continued review.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 13:41:09'),
(201, 'mangubatcoco6@gmail.com', 'client', 66, 'Application For Compliance', 'Your application status is now For Compliance.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 13:41:24'),
(202, 'mrcsmangubat@gmail.com', 'admin', 66, 'Application Needs Compliance Review', 'Application #66 has been For Compliance by evaluator.', 'compliance_handoff', 1, '2026-07-07 07:12:56', '2026-07-06 13:41:24'),
(203, 'marcusmangubat953@gmail.com', 'evaluator', 66, 'Application Reopened', 'Application #66 has been reopened by Admin and returned to your queue.', 'application_update', 0, NULL, '2026-07-06 13:50:59'),
(204, 'mangubatcoco6@gmail.com', 'client', 66, 'Application Under Review Again', 'Your application has been returned to the evaluator for continued review.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 13:50:59'),
(205, 'mangubatcoco6@gmail.com', 'client', 66, 'Application For Compliance', 'Your application status is now For Compliance.', 'application_status', 1, '2026-07-07 03:03:31', '2026-07-06 13:51:28'),
(206, 'mrcsmangubat@gmail.com', 'admin', 66, 'Application Needs Compliance Review', 'Application #66 has been For Compliance by evaluator.', 'compliance_handoff', 1, '2026-07-07 07:12:56', '2026-07-06 13:51:28'),
(207, 'mangubatcoco6@gmail.com', 'client', 60, 'Application Approved', 'Your application status is now Approved. Evaluator note: caleb', 'application_status', 1, '2026-07-07 10:36:11', '2026-07-07 06:25:58'),
(208, 'mrcsmangubat@gmail.com', 'admin', 60, 'Application Approved', 'Application #60 has been Approved by evaluator. Note: caleb', 'evaluation_complete', 1, '2026-07-07 07:12:56', '2026-07-07 06:25:58'),
(209, 'mrcsmangubat@gmail.com', 'admin', 67, 'New Application Submitted', 'A new application (#67) has been submitted for review.', 'new_application', 1, '2026-07-07 13:01:58', '2026-07-07 12:07:19'),
(210, 'mangubatcoco6@gmail.com', 'client', 67, 'Application Under Evaluation', 'An evaluator has started reviewing your application.', 'application_status', 0, NULL, '2026-07-07 12:42:11'),
(211, 'mrcsmangubat@gmail.com', 'admin', 68, 'New Application Submitted', 'A new application (#68) has been submitted for review.', 'new_application', 0, NULL, '2026-07-07 14:42:16'),
(212, 'mrcsmangubat@gmail.com', 'admin', 69, 'New Application Submitted', 'A new application (#69) has been submitted for review.', 'new_application', 0, NULL, '2026-07-08 00:41:17'),
(213, 'mgmangubat@ccc.edu.ph', 'client', 69, 'Application Under Evaluation', 'An evaluator has started reviewing your application.', 'application_status', 0, NULL, '2026-07-08 00:43:12'),
(214, 'mgmangubat@ccc.edu.ph', 'client', 69, 'Application For Compliance', 'Your application status is now For Compliance. Evaluator note: wala answer sa questions', 'application_status', 0, NULL, '2026-07-08 00:43:57'),
(215, 'mrcsmangubat@gmail.com', 'admin', 69, 'Application Needs Compliance Review', 'Application #69 has been For Compliance by evaluator. Note: wala answer sa questions', 'compliance_handoff', 0, NULL, '2026-07-08 00:43:57'),
(216, 'mangubatmarcus@gmail.com', 'evaluator', 69, 'Application Reopened', 'Application #69 has been reopened by Admin and returned to your queue.', 'application_update', 0, NULL, '2026-07-08 00:45:55'),
(217, 'mgmangubat@ccc.edu.ph', 'client', 69, 'Application Under Review Again', 'Your application has been returned to the evaluator for continued review.', 'application_status', 0, NULL, '2026-07-08 00:45:55'),
(218, 'mgmangubat@ccc.edu.ph', 'client', 69, 'New Document Comment', 'An evaluator added a comment to one of your uploaded documents. Please review your application details.', 'document_comment', 0, NULL, '2026-07-08 00:46:20'),
(219, 'mgmangubat@ccc.edu.ph', 'client', 69, 'Application For Compliance', 'Your application status is now For Compliance. Evaluator note: paayos na lang nun', 'application_status', 0, NULL, '2026-07-08 00:46:39'),
(220, 'mrcsmangubat@gmail.com', 'admin', 69, 'Application Needs Compliance Review', 'Application #69 has been For Compliance by evaluator. Note: paayos na lang nun', 'compliance_handoff', 0, NULL, '2026-07-08 00:46:39'),
(221, 'mangubatmarcus@gmail.com', 'evaluator', 69, 'Application Reopened', 'Application #69 has been reopened by Admin and returned to your queue.', 'application_update', 0, NULL, '2026-07-08 00:47:53'),
(222, 'mgmangubat@ccc.edu.ph', 'client', 69, 'Application Under Review Again', 'Your application has been returned to the evaluator for continued review.', 'application_status', 0, NULL, '2026-07-08 00:47:53'),
(223, 'mgmangubat@ccc.edu.ph', 'client', 69, 'Application Approved', 'Your application status is now Approved. Evaluator note: ok na', 'application_status', 0, NULL, '2026-07-08 00:48:14'),
(224, 'mrcsmangubat@gmail.com', 'admin', 69, 'Application Approved', 'Application #69 has been Approved by evaluator. Note: ok na', 'evaluation_complete', 0, NULL, '2026-07-08 00:48:14'),
(225, 'mrcsmangubat@gmail.com', 'admin', 70, 'New Application Submitted', 'A new application (#70) has been submitted for review.', 'new_application', 0, NULL, '2026-07-08 00:50:48');

-- --------------------------------------------------------

--
-- Table structure for table `questions`
--

CREATE TABLE `questions` (
  `id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `is_required` tinyint(1) DEFAULT 1,
  `order_no` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `rsf_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `questions`
--

INSERT INTO `questions` (`id`, `question_text`, `is_required`, `order_no`, `created_at`, `rsf_id`) VALUES
(53, 'tuli ka na ba?', 1, NULL, '2026-07-04 14:12:01', 33),
(54, 'okay ka lang?', 1, NULL, '2026-07-04 14:12:01', 33),
(56, 'ano?', 1, NULL, '2026-07-07 04:33:55', 39);

-- --------------------------------------------------------

--
-- Table structure for table `rsf_job_orders`
--

CREATE TABLE `rsf_job_orders` (
  `id` int(11) NOT NULL,
  `rsf_number` varchar(50) NOT NULL,
  `employer_name` varchar(255) NOT NULL,
  `worksite_country` varchar(100) DEFAULT NULL,
  `classification` varchar(50) DEFAULT NULL,
  `position_title` varchar(150) DEFAULT NULL,
  `slots_available` int(11) DEFAULT NULL,
  `qualifications` text DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `pdf_path` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rsf_job_orders`
--

INSERT INTO `rsf_job_orders` (`id`, `rsf_number`, `employer_name`, `worksite_country`, `classification`, `position_title`, `slots_available`, `qualifications`, `deadline`, `pdf_path`, `created_at`) VALUES
(33, 'RSF-260001', 'NAN YA PLASTICS CORPORATION', 'Taiwan', 'Government', 'Factory Worker (Machine Operation / Sampling Operation)', 43, 'At least High School Graduate\r\nAge: Not older than 45 years old', '2026-07-29', 'uploads/rsf_pdfs/RSF-260001_1776948872.pdf', '2026-04-23 12:54:32'),
(36, 'RSF-260005-A', 'CHANG GUNG INTERNATIONAL ENERGY INC.', 'Taiwan', 'Government', 'Factory Worker (Machine Operator)', 4, 'At least High School Graduate\r\nMale\r\nNot older than 45 years old', '2026-07-08', 'uploads/rsf_pdfs/RSF-260005-A_1781610628.pdf', '2026-06-16 11:50:28'),
(37, 'RSF-260003-2026', 'German Federal Employment Agency', 'Federal Republic of Germany', 'Government', 'Registered Nurse', 47, '', '2026-07-17', 'uploads/rsf_pdfs/RSF-260003-2026_1782616525.pdf', '2026-06-28 03:15:25'),
(38, '260003', 'NAN YA PLASTICS CORPORATION', 'Saudi Arabia', 'Government', 'Factory Worker (Machine Operation / Sampling Operation)', 88, '', '2029-10-16', 'uploads/rsf_pdfs/260003_1782998028.pdf', '2026-07-02 13:13:48'),
(39, '26000129', 'JICWELS', 'Japan', 'Government', 'Medical Technologist', 97, '', '2026-07-30', 'uploads/rsf_pdfs/26000129_1783067445.pdf', '2026-07-03 08:30:45'),
(40, '123', 'TechSolutions Co. Ltd.', 'Singapore', 'Government', 'Software Developer', 22, '', '2026-10-07', NULL, '2026-07-07 09:30:20'),
(41, 'RSF-26000143', 'Global Health Services Inc.', 'Canada', 'Government', 'Medical Technologist', 32, '', '2026-08-29', 'uploads/rsf_pdfs/RSF-26000143_1783470940.pdf', '2026-07-08 00:35:40');

-- --------------------------------------------------------

--
-- Table structure for table `rsf_qualifications`
--

CREATE TABLE `rsf_qualifications` (
  `id` int(11) NOT NULL,
  `rsf_id` int(11) NOT NULL,
  `qualification_type` enum('min_age','max_age','gender','min_height_cm','max_height_cm','min_weight_kg','max_weight_kg','min_education','civil_status') NOT NULL,
  `qualification_value` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rsf_qualifications`
--

INSERT INTO `rsf_qualifications` (`id`, `rsf_id`, `qualification_type`, `qualification_value`) VALUES
(24, 39, 'gender', 'Male'),
(25, 39, 'max_age', '30'),
(26, 37, 'gender', 'Female'),
(39, 40, 'gender', 'Male');

-- --------------------------------------------------------

--
-- Table structure for table `rsf_required_documents`
--

CREATE TABLE `rsf_required_documents` (
  `id` int(11) NOT NULL,
  `rsf_id` int(11) NOT NULL,
  `document_type` varchar(100) NOT NULL,
  `document_name` varchar(150) NOT NULL,
  `is_required` tinyint(1) DEFAULT 1,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rsf_required_documents`
--

INSERT INTO `rsf_required_documents` (`id`, `rsf_id`, `document_type`, `document_name`, `is_required`, `description`, `created_at`, `updated_at`) VALUES
(239, 39, 'passport', 'Valid Passport', 1, 'Valid for 60 days', '2026-07-07 04:33:55', '2026-07-07 04:33:55'),
(240, 39, 'resume', 'Resume', 1, '', '2026-07-07 04:33:55', '2026-07-07 04:33:55'),
(241, 36, 'resume', 'Resume', 1, '', '2026-07-07 07:09:04', '2026-07-07 07:09:04'),
(242, 37, 'passport', 'Valid Passport', 1, 'Valid for 6 Months', '2026-07-07 09:27:25', '2026-07-07 09:27:25'),
(246, 40, 'resume', 'Reume', 0, '', '2026-07-07 11:34:04', '2026-07-07 11:34:04'),
(247, 41, 'resume', 'Resume', 1, '', '2026-07-08 00:35:40', '2026-07-08 00:35:40');

-- --------------------------------------------------------

--
-- Table structure for table `status_history`
--

CREATE TABLE `status_history` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `from_status` varchar(50) DEFAULT NULL,
  `to_status` varchar(50) DEFAULT NULL,
  `changed_by` varchar(100) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `status_history`
--

INSERT INTO `status_history` (`id`, `application_id`, `from_status`, `to_status`, `changed_by`, `changed_at`, `reason`) VALUES
(149, 57, 'pending', 'under_evaluation', 'alexgonzalvo3@gmail.com', '2026-07-03 00:01:09', 'Evaluator claimed application'),
(150, 58, 'pending', 'under_evaluation', 'mangubatmarcus@gmail.com', '2026-07-03 00:09:37', 'Evaluator claimed application'),
(151, 58, 'under_evaluation', 'for_compliance', 'mangubatmarcus@gmail.com', '2026-07-03 00:11:21', 'kulang'),
(152, 58, 'for_compliance', 'resubmitted', 'laguantero@ccc.edu.ph', '2026-07-03 00:12:07', 'Client resubmitted application'),
(153, 58, 'resubmitted', 'assign', 'mrcsmangubat@gmail.com', '2026-07-03 05:11:56', 'Returned to evaluator after client resubmission'),
(154, 58, 'assign', 'for_compliance', 'mangubatmarcus@gmail.com', '2026-07-03 08:00:57', 'kulang parin'),
(155, 58, 'for_compliance', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-03 08:04:38', 'Admin reopened case to original evaluator'),
(156, 58, 'under_evaluation', 'for_compliance', 'mangubatmarcus@gmail.com', '2026-07-03 08:04:49', 'kulang parin'),
(157, 58, 'for_compliance', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-03 08:04:53', 'Admin reopened case to original evaluator'),
(158, 58, 'under_evaluation', 'for_compliance', 'mangubatmarcus@gmail.com', '2026-07-03 08:05:31', 'kulang parin'),
(159, 58, 'for_compliance', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-03 08:26:26', 'Admin reopened case to original evaluator'),
(160, 57, 'under_evaluation', 'disapproved', 'alexgonzalvo3@gmail.com', '2026-07-03 12:49:43', 'Evaluator decision: disapproved'),
(161, 58, 'under_evaluation', 'approved', 'mangubatmarcus@gmail.com', '2026-07-03 13:52:17', 'Evaluator decision: approved — goods na punta ka na dito pre'),
(162, 59, 'unassign', 'assigned', 'admin', '2026-07-04 07:49:58', 'Evaluator assigned by admin'),
(163, 60, 'pending', 'under_evaluation', 'alexgonzalvo3@gmail.com', '2026-07-04 09:26:43', 'Evaluator claimed application'),
(164, 61, 'pending', 'under_evaluation', 'alexgonzalvo3@gmail.com', '2026-07-04 09:37:37', 'Evaluator claimed application'),
(165, 61, 'under_evaluation', 'disapproved', 'alexgonzalvo3@gmail.com', '2026-07-04 09:37:40', 'Evaluator decision: disapproved'),
(166, 60, 'under_evaluation', 'for_compliance', 'alexgonzalvo3@gmail.com', '2026-07-04 13:27:04', NULL),
(167, 59, 'assigned', 'for_compliance', 'alexgonzalvo3@gmail.com', '2026-07-04 13:36:48', NULL),
(168, 59, 'for_compliance', 'resubmitted', 'mgmangubat@ccc.edu.ph', '2026-07-04 13:39:45', 'Client resubmitted application'),
(169, 59, 'resubmitted', 'assign', 'mrcsmangubat@gmail.com', '2026-07-04 13:42:35', 'Returned to evaluator after client resubmission'),
(170, 60, 'for_compliance', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-04 13:44:57', 'Reassigned by admin'),
(171, 60, 'under_evaluation', 'for_compliance', 'mangubatmarcus@gmail.com', '2026-07-04 13:48:45', 'caleb'),
(172, 60, 'for_compliance', 'resubmitted', 'mangubatcoco6@gmail.com', '2026-07-04 13:55:17', 'Client resubmitted application'),
(173, 60, 'resubmitted', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-04 14:09:29', 'Admin reopened case to original evaluator'),
(174, 62, 'pending', 'under_evaluation', 'mangubatmarcus@gmail.com', '2026-07-04 14:14:07', 'Evaluator claimed application'),
(175, 62, 'under_evaluation', 'for_compliance', 'mangubatmarcus@gmail.com', '2026-07-04 14:14:39', 'best part'),
(176, 62, 'for_compliance', 'resubmitted', 'mangubatcoco6@gmail.com', '2026-07-04 14:16:29', 'Client resubmitted application'),
(177, 62, 'resubmitted', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-04 14:18:11', 'Reassigned by admin'),
(178, 63, 'pending', 'under_evaluation', 'mangubatmarcus@gmail.com', '2026-07-05 09:20:38', 'Evaluator claimed application'),
(179, 63, 'under_evaluation', 'disapproved', 'mangubatmarcus@gmail.com', '2026-07-05 09:20:42', 'Evaluator decision: disapproved'),
(180, 64, 'pending', 'under_evaluation', 'marcusmangubat953@gmail.com', '2026-07-05 09:28:36', 'Evaluator claimed application'),
(181, 64, 'under_evaluation', 'disapproved', 'marcusmangubat953@gmail.com', '2026-07-05 09:31:04', 'Evaluator decision: disapproved — ayaw ko sayu'),
(182, 62, 'under_evaluation', 'for_compliance', 'alexgonzalvo3@gmail.com', '2026-07-05 09:34:04', 'best part'),
(183, 59, 'assign', 'approved', 'alexgonzalvo3@gmail.com', '2026-07-06 02:27:12', 'Evaluator decision: approved'),
(184, 65, 'pending', 'under_evaluation', 'mangubatmarcus@gmail.com', '2026-07-06 03:29:52', 'Evaluator claimed application'),
(185, 65, 'under_evaluation', 'disapproved', 'mangubatmarcus@gmail.com', '2026-07-06 03:30:08', 'Evaluator decision: disapproved'),
(186, 62, 'for_compliance', 'approved', 'mrcsmangubat@gmail.com', '2026-07-06 12:18:40', 'Admin decision: approved'),
(187, 66, 'pending', 'under_evaluation', 'alexgonzalvo3@gmail.com', '2026-07-06 12:43:22', 'Evaluator claimed application'),
(188, 66, 'under_evaluation', 'for_compliance', 'alexgonzalvo3@gmail.com', '2026-07-06 12:43:25', NULL),
(189, 66, 'for_compliance', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-06 12:52:43', 'Reassigned by admin'),
(190, 66, 'under_evaluation', 'for_compliance', 'marcusmangubat953@gmail.com', '2026-07-06 12:53:41', NULL),
(191, 66, 'for_compliance', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-06 12:56:04', 'Admin reopened case to original evaluator'),
(192, 66, 'under_evaluation', 'for_compliance', 'marcusmangubat953@gmail.com', '2026-07-06 12:59:58', NULL),
(193, 66, 'for_compliance', 'resubmitted', 'mangubatcoco6@gmail.com', '2026-07-06 13:01:54', 'Client resubmitted application'),
(194, 66, 'resubmitted', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-06 13:03:40', 'Admin reopened case to original evaluator'),
(195, 66, 'under_evaluation', 'for_compliance', 'marcusmangubat953@gmail.com', '2026-07-06 13:03:47', NULL),
(196, 66, 'for_compliance', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-06 13:04:26', 'Admin reopened case to original evaluator'),
(197, 66, 'under_evaluation', 'for_compliance', 'marcusmangubat953@gmail.com', '2026-07-06 13:06:57', NULL),
(198, 66, 'for_compliance', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-06 13:07:19', 'Admin reopened case to original evaluator'),
(199, 66, 'under_evaluation', 'for_compliance', 'marcusmangubat953@gmail.com', '2026-07-06 13:12:01', NULL),
(200, 66, 'for_compliance', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-06 13:22:23', 'Admin reopened case to original evaluator'),
(201, 66, 'under_evaluation', 'for_compliance', 'marcusmangubat953@gmail.com', '2026-07-06 13:22:38', NULL),
(202, 66, 'for_compliance', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-06 13:22:58', 'Admin reopened case to original evaluator'),
(203, 66, 'under_evaluation', 'for_compliance', 'marcusmangubat953@gmail.com', '2026-07-06 13:25:23', NULL),
(204, 66, 'for_compliance', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-06 13:25:28', 'Admin reopened case to original evaluator'),
(205, 66, 'under_evaluation', 'for_compliance', 'marcusmangubat953@gmail.com', '2026-07-06 13:25:42', NULL),
(206, 66, 'for_compliance', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-06 13:26:00', 'Admin reopened case to original evaluator'),
(207, 66, 'under_evaluation', 'for_compliance', 'marcusmangubat953@gmail.com', '2026-07-06 13:39:00', NULL),
(208, 66, 'for_compliance', 'resubmitted', 'mangubatcoco6@gmail.com', '2026-07-06 13:39:31', 'Client resubmitted application'),
(209, 66, 'resubmitted', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-06 13:41:09', 'Admin reopened case to original evaluator'),
(210, 66, 'under_evaluation', 'for_compliance', 'marcusmangubat953@gmail.com', '2026-07-06 13:41:24', NULL),
(211, 66, 'for_compliance', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-06 13:50:59', 'Admin reopened case to original evaluator'),
(212, 66, 'under_evaluation', 'for_compliance', 'marcusmangubat953@gmail.com', '2026-07-06 13:51:28', NULL),
(213, 66, 'for_compliance', 'resubmitted', 'mangubatcoco6@gmail.com', '2026-07-06 13:51:43', 'Client resubmitted application'),
(214, 60, 'under_evaluation', 'approved', 'mangubatmarcus@gmail.com', '2026-07-07 06:25:58', 'Evaluator decision: approved — caleb'),
(215, 67, 'pending', 'under_evaluation', 'mangubatmarcus@gmail.com', '2026-07-07 12:42:11', 'Evaluator claimed application'),
(216, 69, 'pending', 'under_evaluation', 'mangubatmarcus@gmail.com', '2026-07-08 00:43:12', 'Evaluator claimed application'),
(217, 69, 'under_evaluation', 'for_compliance', 'mangubatmarcus@gmail.com', '2026-07-08 00:43:57', 'wala answer sa questions'),
(218, 69, 'for_compliance', 'resubmitted', 'mgmangubat@ccc.edu.ph', '2026-07-08 00:45:16', 'Client resubmitted application'),
(219, 69, 'resubmitted', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-08 00:45:55', 'Admin reopened case to original evaluator'),
(220, 69, 'under_evaluation', 'for_compliance', 'mangubatmarcus@gmail.com', '2026-07-08 00:46:39', 'paayos na lang nun'),
(221, 69, 'for_compliance', 'resubmitted', 'mgmangubat@ccc.edu.ph', '2026-07-08 00:47:18', 'Client resubmitted application'),
(222, 69, 'resubmitted', 'under_evaluation', 'mrcsmangubat@gmail.com', '2026-07-08 00:47:53', 'Admin reopened case to original evaluator'),
(223, 69, 'under_evaluation', 'approved', 'mangubatmarcus@gmail.com', '2026-07-08 00:48:14', 'Evaluator decision: approved — ok na');

-- --------------------------------------------------------

--
-- Table structure for table `webuser`
--

CREATE TABLE `webuser` (
  `email` varchar(100) NOT NULL,
  `usertype` varchar(20) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `remember_token` varchar(64) DEFAULT NULL,
  `remember_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `webuser`
--

INSERT INTO `webuser` (`email`, `usertype`, `password`, `created_at`, `updated_at`, `remember_token`, `remember_expires`) VALUES
('alexgonzalvo3@gmail.com', 'evaluator', '$2y$10$FUYLnsRPclGSugTFS4hwLOe9qVR60bBUoWDRR0Zeh7QA4Mrd9.EHK', '2026-03-25 01:45:40', '2026-04-12 11:38:27', '14c48af45f6f27f55ee9381affafe4afb9f3f6b5835a6c25d7ce684fa6ac6aac', '2026-05-12 19:38:27'),
('justinemangubat1234@gmail.com', 'client', '$2y$10$qfOLUys/ReU5r3zoomi1SOkZmA/63LrAVtZb75WAoXpMs.GZKlFLy', '2026-06-30 02:54:51', '2026-06-30 02:54:51', NULL, NULL),
('laguantero@ccc.edu.ph', 'client', '$2y$10$U5lmVWoR8AxeiIYkTjwdFeB9iCCXHz108QR4Nk8YFqSSI8IgjbV.G', '2026-04-11 01:49:57', '2026-04-11 01:49:57', NULL, NULL),
('mangubatcoco6@gmail.com', 'client', '$2y$10$kAIPYV8c208ysfvEVJy4aOu3VrKYpXCALTIxwEN4zkE/Pi1qVBegC', '2026-06-29 09:06:33', '2026-06-30 11:31:20', 'cb6cfcd81b924a58c357c8031032f85f1fcffb354fe65fe1e0063f377d7df625', '2026-07-30 19:31:20'),
('mangubatjuliet467@gmail.com', 'client', '$2y$10$QyDH0z/xS5wC5Bzr3tAM4u47v4dBDA1mVf3cqtfteodQ3cgjky8AW', '2026-07-08 03:45:31', '2026-07-08 03:45:31', NULL, NULL),
('mangubatmarcus@gmail.com', 'evaluator', '$2y$10$X0WnN5mE5V17xvYKDy6aQevix9Tfnkmi791QhrQ/9u2rzybwUYyPK', '2026-03-28 01:20:02', '2026-07-03 04:09:08', '1066edd197b246fe2046645de59dd0407b9fac51b5515d68c35285bdd4d3a441', '2026-08-02 12:09:08'),
('marcusmangubat953@gmail.com', 'evaluator', '$2y$10$glxIO0LtqMzVMafwrV2L0e9SxSCehZn/rzqXqcSk19TUgHXfH4W0C', '2026-07-02 03:33:27', '2026-07-02 03:33:27', NULL, NULL),
('mgmangubat@ccc.edu.ph', 'client', '$2y$10$HsfubSvhsXLCu9xqvrO7bOyAPNktbknGkf0us5Kp6I/CdDnEaom4i', '2026-03-21 04:32:14', '2026-06-30 10:30:57', '8b5d6a7b1a6e5fabd3a487ac102fca4415d4ccd8b5235964e7c41d4888180b30', '2026-07-30 18:30:57'),
('mrcsmangubat@gmail.com', 'admin', '$2y$10$sHkm8LLayt3ncv.Kk5wFLO68PNw/ib4RvauaPXpPdatlq3pGO2Kim', '2026-03-21 04:34:00', '2026-06-30 11:42:02', '26b2b35fd23cdc52950eacf414d6c97c1c2832db8e6b19697b061b3b880ea085', '2026-07-30 19:42:02');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`aid`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `applications`
--
ALTER TABLE `applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `rsf_id` (`rsf_id`),
  ADD KEY `idx_applications_current_evaluator` (`current_evaluator_id`,`handling_party`);

--
-- Indexes for table `application_answers`
--
ALTER TABLE `application_answers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `question_id` (`question_id`);

--
-- Indexes for table `application_documents`
--
ALTER TABLE `application_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `application_documents_ibfk_2` (`required_doc_id`);

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `evaluator_id` (`evaluator_id`);

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`cid`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `client_profiles`
--
ALTER TABLE `client_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `client_id` (`client_id`);

--
-- Indexes for table `document_comments`
--
ALTER TABLE `document_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`),
  ADD KEY `commented_by` (`commented_by`);

--
-- Indexes for table `evaluations`
--
ALTER TABLE `evaluations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `evaluator_id` (`evaluator_id`),
  ADD KEY `fk_evaluations_admin` (`admin_id`);

--
-- Indexes for table `evaluators`
--
ALTER TABLE `evaluators`
  ADD PRIMARY KEY (`eid`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `invitations`
--
ALTER TABLE `invitations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_email` (`user_email`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `is_read` (`is_read`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `questions`
--
ALTER TABLE `questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `rsf_id` (`rsf_id`);

--
-- Indexes for table `rsf_job_orders`
--
ALTER TABLE `rsf_job_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `rsf_number` (`rsf_number`);

--
-- Indexes for table `rsf_qualifications`
--
ALTER TABLE `rsf_qualifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `rsf_id` (`rsf_id`);

--
-- Indexes for table `rsf_required_documents`
--
ALTER TABLE `rsf_required_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_rsf_document` (`rsf_id`,`document_type`),
  ADD KEY `rsf_id` (`rsf_id`);

--
-- Indexes for table `status_history`
--
ALTER TABLE `status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `application_id` (`application_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `webuser`
--
ALTER TABLE `webuser`
  ADD PRIMARY KEY (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `aid` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `applications`
--
ALTER TABLE `applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=71;

--
-- AUTO_INCREMENT for table `application_answers`
--
ALTER TABLE `application_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

--
-- AUTO_INCREMENT for table `application_documents`
--
ALTER TABLE `application_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=126;

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `cid` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `client_profiles`
--
ALTER TABLE `client_profiles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `document_comments`
--
ALTER TABLE `document_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `evaluations`
--
ALTER TABLE `evaluations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `evaluators`
--
ALTER TABLE `evaluators`
  MODIFY `eid` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `invitations`
--
ALTER TABLE `invitations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=226;

--
-- AUTO_INCREMENT for table `questions`
--
ALTER TABLE `questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=57;

--
-- AUTO_INCREMENT for table `rsf_job_orders`
--
ALTER TABLE `rsf_job_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `rsf_qualifications`
--
ALTER TABLE `rsf_qualifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `rsf_required_documents`
--
ALTER TABLE `rsf_required_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=248;

--
-- AUTO_INCREMENT for table `status_history`
--
ALTER TABLE `status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=224;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admins`
--
ALTER TABLE `admins`
  ADD CONSTRAINT `admins_ibfk_1` FOREIGN KEY (`email`) REFERENCES `webuser` (`email`);

--
-- Constraints for table `applications`
--
ALTER TABLE `applications`
  ADD CONSTRAINT `applications_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`cid`),
  ADD CONSTRAINT `applications_ibfk_2` FOREIGN KEY (`rsf_id`) REFERENCES `rsf_job_orders` (`id`),
  ADD CONSTRAINT `fk_applications_current_evaluator` FOREIGN KEY (`current_evaluator_id`) REFERENCES `evaluators` (`eid`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `application_answers`
--
ALTER TABLE `application_answers`
  ADD CONSTRAINT `application_answers_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`),
  ADD CONSTRAINT `application_answers_ibfk_2` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`);

--
-- Constraints for table `application_documents`
--
ALTER TABLE `application_documents`
  ADD CONSTRAINT `application_documents_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`),
  ADD CONSTRAINT `application_documents_ibfk_2` FOREIGN KEY (`required_doc_id`) REFERENCES `rsf_required_documents` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `assignments`
--
ALTER TABLE `assignments`
  ADD CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`),
  ADD CONSTRAINT `assignments_ibfk_2` FOREIGN KEY (`evaluator_id`) REFERENCES `evaluators` (`eid`);

--
-- Constraints for table `clients`
--
ALTER TABLE `clients`
  ADD CONSTRAINT `clients_ibfk_1` FOREIGN KEY (`email`) REFERENCES `webuser` (`email`);

--
-- Constraints for table `client_profiles`
--
ALTER TABLE `client_profiles`
  ADD CONSTRAINT `client_profiles_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`cid`) ON DELETE CASCADE;

--
-- Constraints for table `document_comments`
--
ALTER TABLE `document_comments`
  ADD CONSTRAINT `document_comments_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `application_documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `document_comments_ibfk_2` FOREIGN KEY (`commented_by`) REFERENCES `webuser` (`email`);

--
-- Constraints for table `evaluations`
--
ALTER TABLE `evaluations`
  ADD CONSTRAINT `fk_evaluations_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`aid`);

--
-- Constraints for table `evaluators`
--
ALTER TABLE `evaluators`
  ADD CONSTRAINT `evaluators_ibfk_1` FOREIGN KEY (`email`) REFERENCES `webuser` (`email`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`user_email`) REFERENCES `webuser` (`email`);

--
-- Constraints for table `questions`
--
ALTER TABLE `questions`
  ADD CONSTRAINT `questions_ibfk_1` FOREIGN KEY (`rsf_id`) REFERENCES `rsf_job_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `rsf_qualifications`
--
ALTER TABLE `rsf_qualifications`
  ADD CONSTRAINT `rsf_qualifications_ibfk_1` FOREIGN KEY (`rsf_id`) REFERENCES `rsf_job_orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `rsf_required_documents`
--
ALTER TABLE `rsf_required_documents`
  ADD CONSTRAINT `rsf_required_documents_ibfk_1` FOREIGN KEY (`rsf_id`) REFERENCES `rsf_job_orders` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
