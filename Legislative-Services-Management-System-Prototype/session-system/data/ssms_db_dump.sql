-- ============================================================
-- Full database export of the Session and Legislative Meeting
-- Management System — all 20 tables, with the current data
-- already in them (sessions, agenda, members, council members,
-- ordinances, committees, etc.)
--
-- This is a snapshot, not something the app reads automatically.
-- Use it to load a full working copy straight into a fresh
-- database instead of starting empty:
--
--   1. Create an empty database (see setup_database.sql, or just
--      create one named whatever you like in phpMyAdmin).
--   2. Import this file into it:
--        mysql -u your_user -p your_database < ssms_db_dump.sql
--      (or in phpMyAdmin: select the database → Import → choose
--      this file → Go)
--   3. Point includes/db_config.php at that database.
--
-- Re-exporting later: mysqldump -u your_user -p your_database > ssms_db_dump.sql
-- ============================================================

-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: ssms_db
-- ------------------------------------------------------
-- Server version	10.11.14-MariaDB-0ubuntu0.24.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `agenda`
--

DROP TABLE IF EXISTS `agenda`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agenda` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agenda`
--

LOCK TABLES `agenda` WRITE;
/*!40000 ALTER TABLE `agenda` DISABLE KEYS */;
INSERT INTO `agenda` VALUES
(1,'{\"session_id\":1,\"order\":1,\"item\":\"Call to order and roll call\",\"presenter\":\"Secretary\'s Office\",\"status\":\"Approved\"}'),
(2,'{\"session_id\":1,\"order\":2,\"item\":\"Reading and approval of previous minutes\",\"presenter\":\"Hon. R. Almazan\",\"status\":\"Discussed\"}'),
(3,'{\"session_id\":1,\"order\":3,\"item\":\"Proposed Ordinance No. 2026-014: Solid Waste Management Program\",\"presenter\":\"Hon. P. Villanueva\",\"status\":\"Pending\"}'),
(4,'{\"session_id\":1,\"order\":4,\"item\":\"Resolution: Endorsement of Barangay Health Worker Incentive\",\"presenter\":\"Hon. M. Reyes\",\"status\":\"Pending\"}'),
(5,'{\"session_id\":1,\"order\":5,\"item\":\"Privilege hour / other matters\",\"presenter\":\"Open Floor\",\"status\":\"Pending\"}'),
(6,'{\"session_id\":2,\"order\":1,\"item\":\"Call to order and roll call\",\"presenter\":\"Secretary\'s Office\",\"status\":\"Pending\"}'),
(7,'{\"session_id\":2,\"order\":2,\"item\":\"Second reading: Proposed Ordinance No. 2026-015\",\"presenter\":\"Hon. D. Santos\",\"status\":\"Pending\"}'),
(8,'{\"session_id\":3,\"order\":1,\"item\":\"Call to order and roll call\",\"presenter\":\"Secretary\'s Office\",\"status\":\"Approved\"}'),
(9,'{\"session_id\":3,\"order\":2,\"item\":\"Approval of Resolution No. 2026-041\",\"presenter\":\"Hon. J. Cruz\",\"status\":\"Approved\"}'),
(10,'{\"session_id\":3,\"order\":3,\"item\":\"Committee reports\",\"presenter\":\"Various\",\"status\":\"Approved\"}');
/*!40000 ALTER TABLE `agenda` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_agenda`
--

DROP TABLE IF EXISTS `app_agenda`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_agenda` (
  `id` varchar(64) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_agenda`
--

LOCK TABLES `app_agenda` WRITE;
/*!40000 ALTER TABLE `app_agenda` DISABLE KEYS */;
INSERT INTO `app_agenda` VALUES
('A-001','{\"title\":\"Second Reading of Ordinance No. 2024-003\",\"priority\":\"High\",\"sessionId\":\"S-001\",\"deadline\":\"2026-08-17\",\"status\":\"In Progress\",\"category\":\"Legislation\",\"responsible\":\"Committee on Public Works\"}'),
('A-002','{\"title\":\"Public Hearing Report \\u2014 Smoke-Free Zones\",\"priority\":\"High\",\"sessionId\":\"S-001\",\"deadline\":\"2026-08-17\",\"status\":\"Pending\",\"category\":\"Public Hearing\",\"responsible\":\"Committee on Health\"}'),
('A-003','{\"title\":\"Approval of Minutes \\u2014 41st Regular Session\",\"priority\":\"Medium\",\"sessionId\":\"S-001\",\"deadline\":\"2026-08-17\",\"status\":\"Pending\",\"category\":\"Administrative\",\"responsible\":\"Office of the Secretary\"}'),
('A-004','{\"title\":\"Budget Hearing for FY 2025\",\"priority\":\"Critical\",\"sessionId\":\"S-002\",\"deadline\":\"2026-08-20\",\"status\":\"Scheduled\",\"category\":\"Finance\",\"responsible\":\"Committee on Finance\"}'),
('A-005','{\"title\":\"Barangay Concerns Forum\",\"priority\":\"Medium\",\"sessionId\":\"S-003\",\"deadline\":\"2026-08-24\",\"status\":\"Scheduled\",\"category\":\"Community\",\"responsible\":\"Office of the Vice Mayor\"}'),
('A-006','{\"title\":\"Third Reading \\u2014 Scholarship Ordinance\",\"priority\":\"High\",\"sessionId\":\"S-004\",\"deadline\":\"2026-08-03\",\"status\":\"Completed\",\"category\":\"Legislation\",\"responsible\":\"Committee on Education\"}');
/*!40000 ALTER TABLE `app_agenda` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_archives`
--

DROP TABLE IF EXISTS `app_archives`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_archives` (
  `id` varchar(64) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_archives`
--

LOCK TABLES `app_archives` WRITE;
/*!40000 ALTER TABLE `app_archives` DISABLE KEYS */;
INSERT INTO `app_archives` VALUES
('AR-001','{\"title\":\"Ordinance No. 2023-018 \\u2014 FY 2024 Annual Budget\",\"category\":\"Ordinance\",\"year\":2023,\"dateArchived\":\"2025-12-05\",\"retention\":\"Permanent\",\"format\":\"Digital\",\"status\":\"Archived\",\"searchable\":true}'),
('AR-002','{\"title\":\"Session Minutes \\u2014 1st to 39th Regular Sessions (2023)\",\"category\":\"Minutes\",\"year\":2023,\"dateArchived\":\"2026-01-29\",\"retention\":\"10 years\",\"format\":\"Digital\",\"status\":\"Archived\",\"searchable\":true}'),
('AR-003','{\"title\":\"Resolution No. 2023-045 \\u2014 City Anniversary Proclamation\",\"category\":\"Resolution\",\"year\":2023,\"dateArchived\":\"2026-03-20\",\"retention\":\"Permanent\",\"format\":\"Digital\",\"status\":\"Archived\",\"searchable\":true}'),
('AR-004','{\"title\":\"Historical Map Collection \\u2014 1985 City Survey\",\"category\":\"Historical\",\"year\":1985,\"dateArchived\":\"2023-05-05\",\"retention\":\"Permanent\",\"format\":\"Digitized\",\"status\":\"Restored\",\"searchable\":true}'),
('AR-005','{\"title\":\"Ordinance No. 2022-009 \\u2014 Zoning Code Amendment\",\"category\":\"Ordinance\",\"year\":2022,\"dateArchived\":\"2024-09-16\",\"retention\":\"Permanent\",\"format\":\"Digital\",\"status\":\"Archived\",\"searchable\":true}'),
('AR-006','{\"title\":\"Council Proceedings 1998 \\u2014 Centennial Session\",\"category\":\"Minutes\",\"year\":1998,\"dateArchived\":\"2019-10-13\",\"retention\":\"Permanent\",\"format\":\"Digitized\",\"status\":\"Restored\",\"searchable\":true}');
/*!40000 ALTER TABLE `app_archives` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_committeeMembers`
--

DROP TABLE IF EXISTS `app_committeeMembers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_committeeMembers` (
  `id` varchar(64) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_committeeMembers`
--

LOCK TABLES `app_committeeMembers` WRITE;
/*!40000 ALTER TABLE `app_committeeMembers` DISABLE KEYS */;
INSERT INTO `app_committeeMembers` VALUES
('CM-001','{\"committeeId\":\"C-001\",\"memberId\":\"M-011\",\"role\":\"Chair\"}'),
('CM-002','{\"committeeId\":\"C-001\",\"memberId\":\"M-002\",\"role\":\"Vice Chair\"}'),
('CM-003','{\"committeeId\":\"C-001\",\"memberId\":\"M-006\",\"role\":\"Member\"}'),
('CM-004','{\"committeeId\":\"C-002\",\"memberId\":\"M-002\",\"role\":\"Chair\"}'),
('CM-005','{\"committeeId\":\"C-002\",\"memberId\":\"M-004\",\"role\":\"Vice Chair\"}'),
('CM-006','{\"committeeId\":\"C-002\",\"memberId\":\"M-008\",\"role\":\"Member\"}'),
('CM-007','{\"committeeId\":\"C-003\",\"memberId\":\"M-003\",\"role\":\"Chair\"}'),
('CM-008','{\"committeeId\":\"C-003\",\"memberId\":\"M-005\",\"role\":\"Member\"}'),
('CM-009','{\"committeeId\":\"C-003\",\"memberId\":\"M-007\",\"role\":\"Member\"}'),
('CM-010','{\"committeeId\":\"C-004\",\"memberId\":\"M-004\",\"role\":\"Chair\"}'),
('CM-011','{\"committeeId\":\"C-004\",\"memberId\":\"M-010\",\"role\":\"Member\"}'),
('CM-012','{\"committeeId\":\"C-005\",\"memberId\":\"M-010\",\"role\":\"Chair\"}'),
('CM-013','{\"committeeId\":\"C-005\",\"memberId\":\"M-006\",\"role\":\"Member\"}'),
('CM-014','{\"committeeId\":\"C-006\",\"memberId\":\"M-005\",\"role\":\"Chair\"}'),
('CM-015','{\"committeeId\":\"C-006\",\"memberId\":\"M-007\",\"role\":\"Vice Chair\"}'),
('CM-016','{\"committeeId\":\"C-006\",\"memberId\":\"M-012\",\"role\":\"Member\"}');
/*!40000 ALTER TABLE `app_committeeMembers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_committees`
--

DROP TABLE IF EXISTS `app_committees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_committees` (
  `id` varchar(64) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_committees`
--

LOCK TABLES `app_committees` WRITE;
/*!40000 ALTER TABLE `app_committees` DISABLE KEYS */;
INSERT INTO `app_committees` VALUES
('C-001','{\"name\":\"Finance & Appropriations\",\"chair\":\"M-011\",\"jurisdiction\":\"City budget, appropriations, revenue\",\"scope\":\"Financial legislation\",\"status\":\"active\",\"established\":\"2025-07-13\",\"workload\":85}'),
('C-002','{\"name\":\"Laws & Ordinances\",\"chair\":\"M-002\",\"jurisdiction\":\"Drafting and reviewing city ordinances\",\"scope\":\"Legal framework\",\"status\":\"active\",\"established\":\"2025-04-04\",\"workload\":92}'),
('C-003','{\"name\":\"Public Works & Infrastructure\",\"chair\":\"M-003\",\"jurisdiction\":\"Infrastructure projects, public works\",\"scope\":\"Physical development\",\"status\":\"active\",\"established\":\"2025-08-02\",\"workload\":78}'),
('C-004','{\"name\":\"Health & Sanitation\",\"chair\":\"M-004\",\"jurisdiction\":\"Public health programs, sanitation\",\"scope\":\"Health services\",\"status\":\"active\",\"established\":\"2025-08-22\",\"workload\":65}'),
('C-005','{\"name\":\"Education & Culture\",\"chair\":\"M-010\",\"jurisdiction\":\"Educational programs, cultural preservation\",\"scope\":\"Education\",\"status\":\"active\",\"established\":\"2025-09-01\",\"workload\":58}'),
('C-006','{\"name\":\"Peace & Order\",\"chair\":\"M-005\",\"jurisdiction\":\"Public safety, law enforcement oversight\",\"scope\":\"Safety\",\"status\":\"active\",\"established\":\"2025-09-11\",\"workload\":71}');
/*!40000 ALTER TABLE `app_committees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_councilMembers`
--

DROP TABLE IF EXISTS `app_councilMembers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_councilMembers` (
  `id` varchar(64) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_councilMembers`
--

LOCK TABLES `app_councilMembers` WRITE;
/*!40000 ALTER TABLE `app_councilMembers` DISABLE KEYS */;
INSERT INTO `app_councilMembers` VALUES
('M-001','{\"name\": \"Hon. EDITED FROM SERVER\", \"title\": \"City Secretary\", \"role\": \"Presiding\", \"ward\": \"City-Wide\", \"party\": \"Independent\", \"email\": \"almazan@council.gov\", \"phone\": \"+63 917 100 0001\", \"avatar\": \"RA\", \"status\": \"active\"}'),
('M-002','{\"name\":\"Hon. Maria Santos\",\"title\":\"Councilor\",\"role\":\"Member\",\"ward\":\"District 1\",\"party\":\"Progressive\",\"email\":\"santos@council.gov\",\"phone\":\"+63 917 100 0002\",\"avatar\":\"MS\",\"status\":\"active\"}'),
('M-003','{\"name\":\"Hon. Juan Dela Cruz\",\"title\":\"Councilor\",\"role\":\"Member\",\"ward\":\"District 2\",\"party\":\"Unity\",\"email\":\"delacruz@council.gov\",\"phone\":\"+63 917 100 0003\",\"avatar\":\"JD\",\"status\":\"active\"}'),
('M-004','{\"name\":\"Hon. Ana Reyes\",\"title\":\"Councilor\",\"role\":\"Member\",\"ward\":\"District 3\",\"party\":\"Progressive\",\"email\":\"reyes@council.gov\",\"phone\":\"+63 917 100 0004\",\"avatar\":\"AR\",\"status\":\"active\"}'),
('M-005','{\"name\":\"Hon. Carlos Mendoza\",\"title\":\"Councilor\",\"role\":\"Member\",\"ward\":\"District 4\",\"party\":\"Unity\",\"email\":\"mendoza@council.gov\",\"phone\":\"+63 917 100 0005\",\"avatar\":\"CM\",\"status\":\"active\"}'),
('M-006','{\"name\":\"Hon. Lourdes Tan\",\"title\":\"Councilor\",\"role\":\"Member\",\"ward\":\"District 5\",\"party\":\"Independent\",\"email\":\"tan@council.gov\",\"phone\":\"+63 917 100 0006\",\"avatar\":\"LT\",\"status\":\"active\"}'),
('M-007','{\"name\":\"Hon. Pedro Bautista\",\"title\":\"Councilor\",\"role\":\"Member\",\"ward\":\"District 6\",\"party\":\"Unity\",\"email\":\"bautista@council.gov\",\"phone\":\"+63 917 100 0007\",\"avatar\":\"PB\",\"status\":\"active\"}'),
('M-008','{\"name\":\"Hon. Cristina Lim\",\"title\":\"Councilor\",\"role\":\"Member\",\"ward\":\"District 7\",\"party\":\"Progressive\",\"email\":\"lim@council.gov\",\"phone\":\"+63 917 100 0008\",\"avatar\":\"CL\",\"status\":\"active\"}'),
('M-009','{\"name\":\"Hon. Felix Garcia\",\"title\":\"Councilor\",\"role\":\"Member\",\"ward\":\"District 8\",\"party\":\"Independent\",\"email\":\"garcia@council.gov\",\"phone\":\"+63 917 100 0009\",\"avatar\":\"FG\",\"status\":\"inactive\"}'),
('M-010','{\"name\":\"Hon. Grace Villanueva\",\"title\":\"Councilor\",\"role\":\"Member\",\"ward\":\"District 9\",\"party\":\"Progressive\",\"email\":\"villanueva@council.gov\",\"phone\":\"+63 917 100 0010\",\"avatar\":\"GV\",\"status\":\"active\"}'),
('M-011','{\"name\":\"Hon. Roberto Aguilar\",\"title\":\"Vice Mayor\",\"role\":\"Member\",\"ward\":\"City-Wide\",\"party\":\"Unity\",\"email\":\"aguilar@council.gov\",\"phone\":\"+63 917 100 0011\",\"avatar\":\"RA\",\"status\":\"active\"}'),
('M-012','{\"name\":\"Hon. Patricia Ong\",\"title\":\"Councilor\",\"role\":\"Member\",\"ward\":\"District 10\",\"party\":\"Unity\",\"email\":\"ong@council.gov\",\"phone\":\"+63 917 100 0012\",\"avatar\":\"PO\",\"status\":\"active\"}');
/*!40000 ALTER TABLE `app_councilMembers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_hearings`
--

DROP TABLE IF EXISTS `app_hearings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_hearings` (
  `id` varchar(64) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_hearings`
--

LOCK TABLES `app_hearings` WRITE;
/*!40000 ALTER TABLE `app_hearings` DISABLE KEYS */;
INSERT INTO `app_hearings` VALUES
('H-001','{\"title\":\"Public Hearing \\u2014 Smoke-Free Zones Ordinance\",\"ordinanceRef\":\"ORD-2024-004\",\"date\":\"2026-07-30\",\"time\":\"09:00\",\"venue\":\"City Gymnasium\",\"status\":\"Concluded\",\"registered\":142,\"attended\":118,\"issues\":6,\"feedbacks\":34}'),
('H-002','{\"title\":\"Public Hearing \\u2014 Traffic Management Code\",\"ordinanceRef\":\"ORD-2024-003\",\"date\":\"2026-08-22\",\"time\":\"14:00\",\"venue\":\"Session Hall\",\"status\":\"Scheduled\",\"registered\":67,\"attended\":0,\"issues\":0,\"feedbacks\":0}'),
('H-003','{\"title\":\"Public Hearing \\u2014 Socialized Housing Endorsement\",\"ordinanceRef\":\"RES-2024-002\",\"date\":\"2026-07-03\",\"time\":\"09:00\",\"venue\":\"Barangay Hall 4\",\"status\":\"Concluded\",\"registered\":89,\"attended\":76,\"issues\":3,\"feedbacks\":21}'),
('H-004','{\"title\":\"Public Hearing \\u2014 FY 2025 Budget Proposal\",\"ordinanceRef\":null,\"date\":\"2026-08-27\",\"time\":\"13:00\",\"venue\":\"City Gymnasium\",\"status\":\"Scheduled\",\"registered\":45,\"attended\":0,\"issues\":0,\"feedbacks\":0}');
/*!40000 ALTER TABLE `app_hearings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_ordinances`
--

DROP TABLE IF EXISTS `app_ordinances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_ordinances` (
  `id` varchar(64) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_ordinances`
--

LOCK TABLES `app_ordinances` WRITE;
/*!40000 ALTER TABLE `app_ordinances` DISABLE KEYS */;
INSERT INTO `app_ordinances` VALUES
('ORD-2023-018','{\"number\":\"Ordinance No. 2023-018\",\"title\":\"An Ordinance Approving the Annual City Budget for FY 2024\",\"author\":\"M-011\",\"category\":\"Finance\",\"committeeId\":\"C-001\",\"status\":\"Enacted\",\"stage\":\"Published\",\"dateIntroduced\":\"2025-10-21\",\"dateApproved\":\"2025-11-30\",\"datePublished\":\"2025-12-05\",\"summary\":\"Appropriates \\u20b12.4 billion for general operations, infrastructure, and social services for FY 2024.\",\"versions\":4,\"aiSummary\":\"FY2024 annual budget of \\u20b12.4B allocated across operations (40%), infrastructure (35%), and social services (25%). Largest line item is road networks.\"}'),
('ORD-2024-001','{\"number\":\"Ordinance No. 2024-001\",\"title\":\"An Ordinance Regulating Single-Use Plastics in Commercial Establishments\",\"author\":\"M-002\",\"category\":\"Environment\",\"committeeId\":\"C-002\",\"status\":\"Enacted\",\"stage\":\"Published\",\"dateIntroduced\":\"2026-04-19\",\"dateApproved\":\"2026-06-18\",\"datePublished\":\"2026-06-23\",\"summary\":\"Prohibits single-use plastic bags and utensils in retail, with phased penalties and a green-incentive program for compliant businesses.\",\"versions\":3,\"aiSummary\":\"This ordinance bans single-use plastics in commercial establishments, introduces a phased penalty schedule, and creates a green-business incentive program. Key stakeholders include retailers and environmental groups. Estimated enforcement cost is low with high environmental impact.\"}'),
('ORD-2024-002','{\"number\":\"Ordinance No. 2024-002\",\"title\":\"An Ordinance Establishing the City Scholarship Program for Underprivileged Students\",\"author\":\"M-010\",\"category\":\"Education\",\"committeeId\":\"C-005\",\"status\":\"Approved\",\"stage\":\"Approved\",\"dateIntroduced\":\"2026-05-19\",\"dateApproved\":\"2026-08-02\",\"summary\":\"Creates a scholarship fund for top graduates from low-income households, funded by 1% of the special education trust.\",\"versions\":2,\"aiSummary\":\"Establishes a need-and-merit scholarship funded by a 1% education trust allocation. Targets 200 scholars annually with a projected 3-year budget of \\u20b118M.\"}'),
('ORD-2024-003','{\"number\":\"Ordinance No. 2024-003\",\"title\":\"An Ordinance on the Comprehensive Traffic Management Code of the City\",\"author\":\"M-003\",\"category\":\"Transportation\",\"committeeId\":\"C-003\",\"status\":\"Pending Review\",\"stage\":\"Committee Review\",\"dateIntroduced\":\"2026-07-18\",\"summary\":\"Consolidates all traffic rules, introduces a demerit-point system, and designates bike lanes on all major thoroughfares.\",\"versions\":1,\"aiSummary\":\"A consolidated traffic code introducing a demerit-point system and mandatory bike lanes. High implementation complexity; requires inter-agency coordination with the transport office.\"}'),
('ORD-2024-004','{\"number\":\"Ordinance No. 2024-004\",\"title\":\"An Ordinance Requiring Smoke-Free Zones in All Public Places\",\"author\":\"M-004\",\"category\":\"Health\",\"committeeId\":\"C-004\",\"status\":\"Pending Review\",\"stage\":\"Committee Review\",\"dateIntroduced\":\"2026-07-28\",\"summary\":\"Declares all public parks, terminals, and government premises as smoke-free zones with signage and fines.\",\"versions\":1,\"aiSummary\":\"Designates smoke-free public zones with mandatory signage and graduated fines. Public health impact is high; enforcement depends on barangay participation.\"}'),
('ORD-2024-005','{\"number\":\"Ordinance No. 2024-005\",\"title\":\"An Ordinance Amending the City Revenue Code (Surcharges & Penalties)\",\"author\":\"M-011\",\"category\":\"Finance\",\"committeeId\":\"C-001\",\"status\":\"Drafting\",\"stage\":\"Drafting\",\"dateIntroduced\":\"2026-08-12\",\"summary\":\"Amends surcharge schedules and introduces an early-payment discount of 5% for business permits.\",\"versions\":1,\"aiSummary\":\"Revenue code amendment introducing an early-payment discount and revised surcharge tiers. Fiscal model projects a 2% increase in timely collections.\"}');
/*!40000 ALTER TABLE `app_ordinances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_proceedings`
--

DROP TABLE IF EXISTS `app_proceedings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_proceedings` (
  `id` varchar(64) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_proceedings`
--

LOCK TABLES `app_proceedings` WRITE;
/*!40000 ALTER TABLE `app_proceedings` DISABLE KEYS */;
/*!40000 ALTER TABLE `app_proceedings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_records`
--

DROP TABLE IF EXISTS `app_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_records` (
  `id` varchar(64) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_records`
--

LOCK TABLES `app_records` WRITE;
/*!40000 ALTER TABLE `app_records` DISABLE KEYS */;
INSERT INTO `app_records` VALUES
('D-001','{\"title\":\"FY 2024 Approved Budget Document\",\"category\":\"Budget\",\"type\":\"PDF\",\"size\":\"4.2 MB\",\"uploadedBy\":\"M-011\",\"dateUploaded\":\"2025-12-05\",\"version\":\"v3\",\"status\":\"Final\",\"tags\":[\"budget\",\"finance\",\"2024\"],\"audit\":[{\"action\":\"uploaded\",\"by\":\"M-011\",\"time\":\"2025-11-30\"},{\"action\":\"versioned\",\"by\":\"M-002\",\"time\":\"2025-12-02\"},{\"action\":\"approved\",\"by\":\"M-001\",\"time\":\"2025-12-05\"}]}'),
('D-002','{\"title\":\"Committee Report \\u2014 Public Works Q1\",\"category\":\"Committee Report\",\"type\":\"PDF\",\"size\":\"1.8 MB\",\"uploadedBy\":\"M-003\",\"dateUploaded\":\"2026-07-08\",\"version\":\"v1\",\"status\":\"Active\",\"tags\":[\"committee\",\"infrastructure\"],\"audit\":[{\"action\":\"uploaded\",\"by\":\"M-003\",\"time\":\"2026-07-08\"}]}'),
('D-003','{\"title\":\"Public Hearing Transcript \\u2014 Smoke-Free Zones\",\"category\":\"Transcript\",\"type\":\"DOCX\",\"size\":\"780 KB\",\"uploadedBy\":\"M-004\",\"dateUploaded\":\"2026-07-30\",\"version\":\"v2\",\"status\":\"Active\",\"tags\":[\"hearing\",\"health\"],\"audit\":[{\"action\":\"uploaded\",\"by\":\"M-004\",\"time\":\"2026-07-28\"},{\"action\":\"revised\",\"by\":\"M-004\",\"time\":\"2026-07-30\"}]}'),
('D-004','{\"title\":\"City Development Plan 2024\\u20132027\",\"category\":\"Plan\",\"type\":\"PDF\",\"size\":\"12.5 MB\",\"uploadedBy\":\"M-001\",\"dateUploaded\":\"2026-05-09\",\"version\":\"v1\",\"status\":\"Final\",\"tags\":[\"plan\",\"development\",\"strategy\"],\"audit\":[{\"action\":\"uploaded\",\"by\":\"M-001\",\"time\":\"2026-05-09\"}]}'),
('D-005','{\"title\":\"Ordinance Draft \\u2014 Traffic Management Code\",\"category\":\"Legislation Draft\",\"type\":\"DOCX\",\"size\":\"1.1 MB\",\"uploadedBy\":\"M-003\",\"dateUploaded\":\"2026-07-18\",\"version\":\"v1\",\"status\":\"Draft\",\"tags\":[\"draft\",\"transportation\"],\"audit\":[{\"action\":\"uploaded\",\"by\":\"M-003\",\"time\":\"2026-07-18\"}]}');
/*!40000 ALTER TABLE `app_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_research`
--

DROP TABLE IF EXISTS `app_research`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_research` (
  `id` varchar(64) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_research`
--

LOCK TABLES `app_research` WRITE;
/*!40000 ALTER TABLE `app_research` DISABLE KEYS */;
INSERT INTO `app_research` VALUES
('R-001','{\"title\":\"Impact Assessment: Single-Use Plastic Ban\",\"policy\":\"Plastic Regulation\",\"type\":\"Impact Assessment\",\"status\":\"Completed\",\"date\":\"2026-06-08\",\"impactScore\":8.6,\"scope\":\"Environment\",\"recommendation\":\"Adopt with phased enforcement and green-business incentives.\",\"benchmark\":\"Modeled on 3 peer cities; projected 40% plastic-waste reduction in 18 months.\",\"metrics\":{\"environmental\":90,\"economic\":65,\"social\":78,\"implementability\":72}}'),
('R-002','{\"title\":\"Comparative Analysis: Traffic Management Codes (5 Cities)\",\"policy\":\"Traffic Management\",\"type\":\"Comparative Analysis\",\"status\":\"Completed\",\"date\":\"2026-07-08\",\"impactScore\":7.4,\"scope\":\"Transportation\",\"recommendation\":\"Adopt demerit-point system; prioritize bike-lane rollout in business districts.\",\"benchmark\":\"5 peer cities benchmarked; best performer reduced congestion 22% in 2 years.\",\"metrics\":{\"environmental\":55,\"economic\":80,\"social\":85,\"implementability\":60}}'),
('R-003','{\"title\":\"Policy Research: Socialized Housing Endorsement\",\"policy\":\"Housing\",\"type\":\"Policy Research\",\"status\":\"In Progress\",\"date\":\"2026-07-28\",\"impactScore\":8.1,\"scope\":\"Housing\",\"recommendation\":\"Proceed with NHA endorsement; pre-identify 3 candidate sites.\",\"benchmark\":\"National housing data; 1,200-unit potential yield for the city.\",\"metrics\":{\"environmental\":40,\"economic\":70,\"social\":92,\"implementability\":68}}'),
('R-004','{\"title\":\"Benchmarking: City Scholarship Programs\",\"policy\":\"Education\",\"type\":\"Benchmarking\",\"status\":\"Completed\",\"date\":\"2026-08-02\",\"impactScore\":7.9,\"scope\":\"Education\",\"recommendation\":\"Cap scholarships at 200/year; tie retention to GPA 2.5 minimum.\",\"benchmark\":\"4 peer LGU scholarship models compared.\",\"metrics\":{\"environmental\":20,\"economic\":75,\"social\":95,\"implementability\":82}}');
/*!40000 ALTER TABLE `app_research` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_resolutions`
--

DROP TABLE IF EXISTS `app_resolutions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_resolutions` (
  `id` varchar(64) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_resolutions`
--

LOCK TABLES `app_resolutions` WRITE;
/*!40000 ALTER TABLE `app_resolutions` DISABLE KEYS */;
INSERT INTO `app_resolutions` VALUES
('RES-2024-001','{\"number\":\"Resolution No. 2024-001\",\"title\":\"A Resolution Expressing Sympathy and Condolences to the Family of the Late Hon. Eduardo Perez\",\"author\":\"M-002\",\"category\":\"Ceremonial\",\"status\":\"Adopted\",\"stage\":\"Adopted\",\"dateIntroduced\":\"2026-05-29\",\"summary\":\"Expresses condolences on behalf of the Sanggunian to the Perez family.\",\"aiSummary\":\"Ceremonial resolution of condolence. No fiscal or policy impact; procedural adoption.\"}'),
('RES-2024-002','{\"number\":\"Resolution No. 2024-002\",\"title\":\"A Resolution Endorsing the City to the National Housing Authority for a Socialized Housing Project\",\"author\":\"M-003\",\"category\":\"Housing\",\"committeeId\":\"C-003\",\"status\":\"Adopted\",\"stage\":\"Adopted\",\"dateIntroduced\":\"2026-06-08\",\"summary\":\"Endorses the city as a priority site for an NHA socialized housing development.\",\"aiSummary\":\"Endorsement resolution enabling a national housing project. Potential benefit: 1,200 housing units for informal-settler families.\"}'),
('RES-2024-003','{\"number\":\"Resolution No. 2024-003\",\"title\":\"A Resolution Authorizing the Mayor to Enter into a Memorandum of Agreement with the Department of Health\",\"author\":\"M-004\",\"category\":\"Health\",\"committeeId\":\"C-004\",\"status\":\"Pending Review\",\"stage\":\"Committee Review\",\"dateIntroduced\":\"2026-07-23\",\"summary\":\"Authorizes an MOA with DOH for the expanded immunization program.\",\"aiSummary\":\"MOA authorization with DOH for expanded immunization. Zero local cost; program funded nationally. Expected coverage: 95% of children under 5.\"}'),
('RES-2024-004','{\"number\":\"Resolution No. 2024-004\",\"title\":\"A Resolution Declaring the Last Friday of Every Month as Clean and Green Day\",\"author\":\"M-006\",\"category\":\"Environment\",\"status\":\"Pending Review\",\"stage\":\"Committee Review\",\"dateIntroduced\":\"2026-08-05\",\"summary\":\"Designates a monthly citywide clean-up and tree-planting day with barangay participation.\",\"aiSummary\":\"Declares a monthly clean-and-green day. Low-cost, high-participation initiative; supports waste-reduction targets.\"}'),
('RES-2024-005','{\"number\":\"Resolution No. 2024-005\",\"title\":\"A Resolution Urging the National Government to Establish a Satellite Office in the City\",\"author\":\"M-011\",\"category\":\"Governance\",\"status\":\"Drafting\",\"stage\":\"Drafting\",\"dateIntroduced\":\"2026-08-14\",\"summary\":\"Urges national agencies to establish a satellite office to improve citizen access to services.\",\"aiSummary\":\"Advocacy resolution requesting a national government satellite office. Improves citizen access to frontline services; no local fiscal impact.\"}');
/*!40000 ALTER TABLE `app_resolutions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_sessions`
--

DROP TABLE IF EXISTS `app_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_sessions` (
  `id` varchar(64) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_sessions`
--

LOCK TABLES `app_sessions` WRITE;
/*!40000 ALTER TABLE `app_sessions` DISABLE KEYS */;
INSERT INTO `app_sessions` VALUES
('S-001','{\"title\":\"Regular Session \\u2014 42nd Regular Session\",\"type\":\"Regular\",\"date\":\"2026-08-17\",\"time\":\"09:00\",\"venue\":\"Session Hall, 3rd Floor\",\"status\":\"In Progress\",\"agendaCount\":5,\"attendance\":[{\"memberId\":\"M-001\",\"status\":\"present\"},{\"memberId\":\"M-002\",\"status\":\"present\"},{\"memberId\":\"M-003\",\"status\":\"present\"},{\"memberId\":\"M-004\",\"status\":\"late\"},{\"memberId\":\"M-005\",\"status\":\"present\"},{\"memberId\":\"M-006\",\"status\":\"absent\"},{\"memberId\":\"M-007\",\"status\":\"present\"},{\"memberId\":\"M-008\",\"status\":\"present\"},{\"memberId\":\"M-011\",\"status\":\"present\"},{\"memberId\":\"M-012\",\"status\":\"present\"}],\"duration\":0}'),
('S-002','{\"title\":\"Special Session \\u2014 Budget Deliberations\",\"type\":\"Special\",\"date\":\"2026-08-20\",\"time\":\"14:00\",\"venue\":\"Session Hall, 3rd Floor\",\"status\":\"Scheduled\",\"agendaCount\":3,\"attendance\":[],\"duration\":0}'),
('S-003','{\"title\":\"Joint Session \\u2014 with Barangay Councils\",\"type\":\"Joint\",\"date\":\"2026-08-24\",\"time\":\"09:00\",\"venue\":\"City Gymnasium\",\"status\":\"Scheduled\",\"agendaCount\":4,\"attendance\":[],\"duration\":0}'),
('S-004','{\"title\":\"Regular Session \\u2014 41st Regular Session\",\"type\":\"Regular\",\"date\":\"2026-08-03\",\"time\":\"09:00\",\"venue\":\"Session Hall, 3rd Floor\",\"status\":\"Concluded\",\"agendaCount\":6,\"attendance\":[{\"memberId\":\"M-001\",\"status\":\"present\"},{\"memberId\":\"M-002\",\"status\":\"present\"},{\"memberId\":\"M-003\",\"status\":\"absent\"},{\"memberId\":\"M-004\",\"status\":\"present\"},{\"memberId\":\"M-005\",\"status\":\"present\"},{\"memberId\":\"M-006\",\"status\":\"present\"},{\"memberId\":\"M-007\",\"status\":\"present\"},{\"memberId\":\"M-008\",\"status\":\"late\"},{\"memberId\":\"M-011\",\"status\":\"present\"},{\"memberId\":\"M-012\",\"status\":\"present\"}],\"duration\":245}'),
('S-005','{\"title\":\"Regular Session \\u2014 40th Regular Session\",\"type\":\"Regular\",\"date\":\"2026-07-20\",\"time\":\"09:00\",\"venue\":\"Session Hall, 3rd Floor\",\"status\":\"Concluded\",\"agendaCount\":7,\"attendance\":[{\"memberId\":\"M-001\",\"status\":\"present\"},{\"memberId\":\"M-002\",\"status\":\"present\"},{\"memberId\":\"M-003\",\"status\":\"present\"},{\"memberId\":\"M-004\",\"status\":\"present\"},{\"memberId\":\"M-005\",\"status\":\"present\"},{\"memberId\":\"M-006\",\"status\":\"absent\"},{\"memberId\":\"M-007\",\"status\":\"present\"},{\"memberId\":\"M-008\",\"status\":\"present\"},{\"memberId\":\"M-011\",\"status\":\"present\"},{\"memberId\":\"M-012\",\"status\":\"present\"}],\"duration\":210}');
/*!40000 ALTER TABLE `app_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `app_votes`
--

DROP TABLE IF EXISTS `app_votes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_votes` (
  `id` varchar(64) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_votes`
--

LOCK TABLES `app_votes` WRITE;
/*!40000 ALTER TABLE `app_votes` DISABLE KEYS */;
INSERT INTO `app_votes` VALUES
('V-001','{\"subject\":\"Approval of Ordinance No. 2024-001 (Single-Use Plastics)\",\"sessionId\":\"S-004\",\"type\":\"Roll Call\",\"date\":\"2026-08-03\",\"total\":10,\"yes\":8,\"no\":1,\"abstain\":1,\"result\":\"Passed\",\"tallies\":[{\"memberId\":\"M-001\",\"vote\":\"yes\"},{\"memberId\":\"M-002\",\"vote\":\"yes\"},{\"memberId\":\"M-004\",\"vote\":\"yes\"},{\"memberId\":\"M-005\",\"vote\":\"no\"},{\"memberId\":\"M-006\",\"vote\":\"yes\"},{\"memberId\":\"M-007\",\"vote\":\"yes\"},{\"memberId\":\"M-008\",\"vote\":\"abstain\"},{\"memberId\":\"M-011\",\"vote\":\"yes\"},{\"memberId\":\"M-012\",\"vote\":\"yes\"},{\"memberId\":\"M-010\",\"vote\":\"yes\"}]}'),
('V-002','{\"subject\":\"Approval of Resolution No. 2024-001 (Condolences)\",\"sessionId\":\"S-005\",\"type\":\"Viva Voce\",\"date\":\"2026-07-20\",\"total\":9,\"yes\":9,\"no\":0,\"abstain\":0,\"result\":\"Unanimous\",\"tallies\":[]}'),
('V-003','{\"subject\":\"Approval of Annual Budget FY 2024 (Ord. 2023-018)\",\"sessionId\":\"S-005\",\"type\":\"Roll Call\",\"date\":\"2026-07-20\",\"total\":9,\"yes\":7,\"no\":2,\"abstain\":0,\"result\":\"Passed\",\"tallies\":[{\"memberId\":\"M-001\",\"vote\":\"yes\"},{\"memberId\":\"M-002\",\"vote\":\"yes\"},{\"memberId\":\"M-004\",\"vote\":\"yes\"},{\"memberId\":\"M-005\",\"vote\":\"no\"},{\"memberId\":\"M-007\",\"vote\":\"yes\"},{\"memberId\":\"M-008\",\"vote\":\"yes\"},{\"memberId\":\"M-011\",\"vote\":\"yes\"},{\"memberId\":\"M-012\",\"vote\":\"no\"},{\"memberId\":\"M-010\",\"vote\":\"yes\"}]}');
/*!40000 ALTER TABLE `app_votes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance`
--

LOCK TABLES `attendance` WRITE;
/*!40000 ALTER TABLE `attendance` DISABLE KEYS */;
INSERT INTO `attendance` VALUES
(1,'{\"session_id\":1,\"member_id\":1,\"status\":\"Present\",\"time_in\":\"13:02\"}'),
(2,'{\"session_id\":1,\"member_id\":2,\"status\":\"Present\",\"time_in\":\"13:01\"}'),
(3,'{\"session_id\":1,\"member_id\":3,\"status\":\"Present\",\"time_in\":\"13:03\"}'),
(4,'{\"session_id\":1,\"member_id\":4,\"status\":\"Present\",\"time_in\":\"13:00\"}'),
(5,'{\"session_id\":1,\"member_id\":5,\"status\":\"Excused\",\"time_in\":null}'),
(6,'{\"session_id\":1,\"member_id\":6,\"status\":\"Present\",\"time_in\":\"13:04\"}'),
(7,'{\"session_id\":1,\"member_id\":7,\"status\":\"Absent\",\"time_in\":null}'),
(8,'{\"session_id\":1,\"member_id\":8,\"status\":\"Present\",\"time_in\":\"13:02\"}'),
(9,'{\"session_id\":1,\"member_id\":9,\"status\":\"Absent\",\"time_in\":null}'),
(10,'{\"session_id\":3,\"member_id\":1,\"status\":\"Present\",\"time_in\":\"13:00\"}'),
(11,'{\"session_id\":3,\"member_id\":2,\"status\":\"Present\",\"time_in\":\"13:00\"}'),
(12,'{\"session_id\":3,\"member_id\":3,\"status\":\"Present\",\"time_in\":\"13:05\"}'),
(13,'{\"session_id\":3,\"member_id\":4,\"status\":\"Present\",\"time_in\":\"13:02\"}'),
(14,'{\"session_id\":3,\"member_id\":5,\"status\":\"Present\",\"time_in\":\"13:01\"}'),
(15,'{\"session_id\":3,\"member_id\":6,\"status\":\"Present\",\"time_in\":\"13:03\"}'),
(16,'{\"session_id\":3,\"member_id\":7,\"status\":\"Present\",\"time_in\":\"13:06\"}'),
(17,'{\"session_id\":3,\"member_id\":8,\"status\":\"Excused\",\"time_in\":null}'),
(18,'{\"session_id\":3,\"member_id\":9,\"status\":\"Present\",\"time_in\":\"13:02\"}');
/*!40000 ALTER TABLE `attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `members`
--

DROP TABLE IF EXISTS `members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `members`
--

LOCK TABLES `members` WRITE;
/*!40000 ALTER TABLE `members` DISABLE KEYS */;
INSERT INTO `members` VALUES
(1,'{\"name\":\"Hon. R. Almazan\",\"position\":\"City Vice Mayor / Presiding Officer\",\"committee\":\"Presiding\"}'),
(2,'{\"name\":\"Hon. D. Santos\",\"position\":\"City Councilor\",\"committee\":\"Committee on Finance\"}'),
(3,'{\"name\":\"Hon. M. Reyes\",\"position\":\"City Councilor\",\"committee\":\"Committee on Health\"}'),
(4,'{\"name\":\"Hon. J. Cruz\",\"position\":\"City Councilor\",\"committee\":\"Committee on Public Works\"}'),
(5,'{\"name\":\"Hon. A. Bautista\",\"position\":\"City Councilor\",\"committee\":\"Committee on Education\"}'),
(6,'{\"name\":\"Hon. P. Villanueva\",\"position\":\"City Councilor\",\"committee\":\"Committee on Environment\"}'),
(7,'{\"name\":\"Hon. L. Fernandez\",\"position\":\"City Councilor\",\"committee\":\"Committee on Peace and Order\"}'),
(8,'{\"name\":\"Hon. C. Domingo\",\"position\":\"ABC President (Ex-Officio)\",\"committee\":\"Liga ng mga Barangay\"}'),
(9,'{\"name\":\"Hon. E. Manalo\",\"position\":\"SK Federation President (Ex-Officio)\",\"committee\":\"SK Federation\"}');
/*!40000 ALTER TABLE `members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `minutes`
--

DROP TABLE IF EXISTS `minutes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `minutes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `minutes`
--

LOCK TABLES `minutes` WRITE;
/*!40000 ALTER TABLE `minutes` DISABLE KEYS */;
/*!40000 ALTER TABLE `minutes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `proceedings`
--

DROP TABLE IF EXISTS `proceedings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `proceedings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `proceedings`
--

LOCK TABLES `proceedings` WRITE;
/*!40000 ALTER TABLE `proceedings` DISABLE KEYS */;
INSERT INTO `proceedings` VALUES
(1,'{\"session_id\":1,\"timestamp\":\"2026-08-06 13:02\",\"author\":\"Secretary\'s Office\",\"note\":\"Session called to order by the Presiding Officer, Hon. R. Almazan. Roll call conducted; quorum declared present.\"}'),
(2,'{\"session_id\":1,\"timestamp\":\"2026-08-06 13:07\",\"author\":\"Secretary\'s Office\",\"note\":\"Minutes of the 57th Regular Session read into record. Motion to approve by Hon. J. Cruz, seconded by Hon. D. Santos.\"}'),
(3,'{\"session_id\":1,\"timestamp\":\"2026-08-06 13:15\",\"author\":\"Secretary\'s Office\",\"note\":\"Floor opened for discussion on Proposed Ordinance No. 2026-014 (Solid Waste Management Program), sponsored by Hon. P. Villanueva.\"}'),
(4,'{\"session_id\":3,\"timestamp\":\"2026-07-30 13:04\",\"author\":\"Secretary\'s Office\",\"note\":\"Session called to order. Roll call conducted; quorum declared present.\"}'),
(5,'{\"session_id\":3,\"timestamp\":\"2026-07-30 13:40\",\"author\":\"Secretary\'s Office\",\"note\":\"Resolution No. 2026-041 approved by unanimous vote.\"}'),
(6,'{\"session_id\":3,\"timestamp\":\"2026-07-30 14:20\",\"author\":\"Secretary\'s Office\",\"note\":\"Session adjourned by the Presiding Officer.\"}');
/*!40000 ALTER TABLE `proceedings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `qr_users`
--

DROP TABLE IF EXISTS `qr_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `qr_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `qr_users`
--

LOCK TABLES `qr_users` WRITE;
/*!40000 ALTER TABLE `qr_users` DISABLE KEYS */;
INSERT INTO `qr_users` VALUES
(1,'{\"memberId\":1,\"name\":\"Hon. R. Almazan\",\"position\":\"City Vice Mayor / Presiding Officer\",\"token\":\"7b8e4e6339260f3f0effd22e\",\"deviceId\":null,\"deviceLabel\":null,\"boundAt\":null,\"lastLoginAt\":null}'),
(2,'{\"memberId\":2,\"name\":\"Hon. D. Santos\",\"position\":\"City Councilor\",\"token\":\"7ded9c6ddac5f184445bc4a5\",\"deviceId\":null,\"deviceLabel\":null,\"boundAt\":null,\"lastLoginAt\":null}'),
(3,'{\"memberId\":3,\"name\":\"Hon. M. Reyes\",\"position\":\"City Councilor\",\"token\":\"1228de6f5b62c536e460fe7c\",\"deviceId\":null,\"deviceLabel\":null,\"boundAt\":null,\"lastLoginAt\":null}'),
(4,'{\"memberId\":4,\"name\":\"Hon. J. Cruz\",\"position\":\"City Councilor\",\"token\":\"730c799528ce301f7ccbd912\",\"deviceId\":null,\"deviceLabel\":null,\"boundAt\":null,\"lastLoginAt\":null}'),
(5,'{\"memberId\":5,\"name\":\"Hon. A. Bautista\",\"position\":\"City Councilor\",\"token\":\"99322b6c749d67685d08190b\",\"deviceId\":null,\"deviceLabel\":null,\"boundAt\":null,\"lastLoginAt\":null}'),
(6,'{\"memberId\":6,\"name\":\"Hon. P. Villanueva\",\"position\":\"City Councilor\",\"token\":\"9ab757ef6b7ba212843aee37\",\"deviceId\":null,\"deviceLabel\":null,\"boundAt\":null,\"lastLoginAt\":null}'),
(7,'{\"memberId\":7,\"name\":\"Hon. L. Fernandez\",\"position\":\"City Councilor\",\"token\":\"cfc8ddd28b9a7ef869e3c917\",\"deviceId\":null,\"deviceLabel\":null,\"boundAt\":null,\"lastLoginAt\":null}'),
(8,'{\"memberId\":8,\"name\":\"Hon. C. Domingo\",\"position\":\"ABC President (Ex-Officio)\",\"token\":\"ece62fb252e954ec3668bf0e\",\"deviceId\":null,\"deviceLabel\":null,\"boundAt\":null,\"lastLoginAt\":null}'),
(9,'{\"memberId\":9,\"name\":\"Hon. E. Manalo\",\"position\":\"SK Federation President (Ex-Officio)\",\"token\":\"2dfadffdc97a50a5da7fac5f\",\"deviceId\":null,\"deviceLabel\":null,\"boundAt\":null,\"lastLoginAt\":null}');
/*!40000 ALTER TABLE `qr_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES
(1,'{\"title\":\"58th Regular Session\",\"type\":\"Regular\",\"date\":\"2026-08-06\",\"time\":\"13:00\",\"venue\":\"Sangguniang Panlungsod Session Hall\",\"status\":\"Ongoing\",\"started_at\":1786026820,\"current_agenda_id\":2,\"created_at\":\"2026-07-30 09:12:00\"}'),
(2,'{\"title\":\"59th Regular Session\",\"type\":\"Regular\",\"date\":\"2026-08-13\",\"time\":\"13:00\",\"venue\":\"Sangguniang Panlungsod Session Hall\",\"status\":\"Scheduled\",\"started_at\":null,\"current_agenda_id\":null,\"created_at\":\"2026-08-01 10:05:00\"}'),
(3,'{\"title\":\"57th Regular Session\",\"type\":\"Regular\",\"date\":\"2026-07-30\",\"time\":\"13:00\",\"venue\":\"Sangguniang Panlungsod Session Hall\",\"status\":\"Completed\",\"started_at\":1785395400,\"current_agenda_id\":null,\"created_at\":\"2026-07-23 08:40:00\"}');
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-20  4:26:25
