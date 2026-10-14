-- MySQL dump 10.13  Distrib 8.0.46, for Linux (x86_64)
--
-- Host: localhost    Database: chitty
-- ------------------------------------------------------
-- Server version	8.0.46-0ubuntu0.22.04.4

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `auctions`
--

DROP TABLE IF EXISTS `auctions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auctions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `scheme_id` int NOT NULL,
  `month_number` int NOT NULL,
  `auction_date` date NOT NULL,
  `winning_subscription_id` int DEFAULT NULL,
  `bid_amount` decimal(12,2) DEFAULT NULL,
  `prize_amount` decimal(12,2) DEFAULT NULL,
  `commission_amount` decimal(12,2) DEFAULT NULL,
  `dividend_pool` decimal(12,2) DEFAULT NULL,
  `dividend_per_ticket` decimal(12,2) DEFAULT NULL,
  `net_installment` decimal(12,2) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_scheme_month` (`scheme_id`,`month_number`),
  KEY `fk_auction_winner` (`winning_subscription_id`),
  CONSTRAINT `fk_auction_scheme` FOREIGN KEY (`scheme_id`) REFERENCES `chit_schemes` (`id`),
  CONSTRAINT `fk_auction_winner` FOREIGN KEY (`winning_subscription_id`) REFERENCES `subscriptions` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auctions`
--

LOCK TABLES `auctions` WRITE;
/*!40000 ALTER TABLE `auctions` DISABLE KEYS */;
INSERT INTO `auctions` VALUES (7,6,1,'2026-11-11',27,16000.00,34000.00,2500.00,13500.00,1350.00,3650.00,NULL),(8,6,2,'2026-12-12',30,12000.00,38000.00,2500.00,9500.00,950.00,4050.00,NULL),(9,6,3,'2027-01-01',29,10000.00,40000.00,2500.00,7500.00,750.00,4250.00,NULL),(10,6,4,'2027-02-02',31,10000.00,40000.00,2500.00,7500.00,750.00,4250.00,NULL),(11,6,5,'2027-03-31',32,12000.00,38000.00,2500.00,9500.00,950.00,4050.00,NULL),(12,6,6,'2026-02-12',33,12000.00,38000.00,2500.00,9500.00,950.00,4050.00,NULL),(13,6,7,'2026-12-12',34,15000.00,35000.00,2500.00,12500.00,1250.00,3750.00,NULL);
/*!40000 ALTER TABLE `auctions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chit_schemes`
--

DROP TABLE IF EXISTS `chit_schemes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chit_schemes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `chit_value` decimal(12,2) NOT NULL,
  `duration_months` int NOT NULL,
  `monthly_subscription` decimal(12,2) NOT NULL,
  `commission_percent` decimal(5,2) NOT NULL DEFAULT '5.00',
  `start_date` date NOT NULL,
  `status` enum('active','closed') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chit_schemes`
--

LOCK TABLES `chit_schemes` WRITE;
/*!40000 ALTER TABLE `chit_schemes` DISABLE KEYS */;
INSERT INTO `chit_schemes` VALUES (6,'Chitty No.46',50000.00,10,5000.00,5.00,'2026-11-11','active','2026-09-21 06:15:22'),(7,'chitty 47',100000.00,12,8333.33,5.00,'2026-11-11','active','2026-09-21 06:22:55'),(8,'chitty 6',100000.00,10,10000.00,5.00,'0226-11-11','active','2026-09-30 07:01:52'),(9,'mss',50000.00,20,2500.00,5.00,'2026-10-15','active','2026-10-06 06:51:26');
/*!40000 ALTER TABLE `chit_schemes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `installments`
--

DROP TABLE IF EXISTS `installments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `installments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `scheme_id` int NOT NULL,
  `subscription_id` int NOT NULL,
  `month_number` int NOT NULL,
  `amount_due` decimal(12,2) NOT NULL,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT '0.00',
  `paid_date` date DEFAULT NULL,
  `status` enum('pending','partial','paid') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sub_month` (`subscription_id`,`month_number`),
  KEY `idx_inst_scheme_month` (`scheme_id`,`month_number`),
  CONSTRAINT `fk_inst_scheme` FOREIGN KEY (`scheme_id`) REFERENCES `chit_schemes` (`id`),
  CONSTRAINT `fk_inst_sub` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=187 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `installments`
--

LOCK TABLES `installments` WRITE;
/*!40000 ALTER TABLE `installments` DISABLE KEYS */;
INSERT INTO `installments` VALUES (47,6,27,1,3650.00,3650.00,'2026-09-21','paid'),(48,6,28,1,3650.00,3650.00,'2026-09-21','paid'),(49,6,29,1,3650.00,3650.00,'2026-09-21','paid'),(50,6,30,1,3650.00,3650.00,'2026-09-21','paid'),(51,6,31,1,3650.00,3650.00,'2026-09-21','paid'),(52,6,32,1,3650.00,3650.00,'2026-09-21','paid'),(53,6,33,1,3650.00,3650.00,'2026-09-21','paid'),(54,6,34,1,3650.00,3650.00,'2026-09-21','paid'),(55,6,35,1,3650.00,3650.00,'2026-09-21','paid'),(56,6,36,1,3650.00,3650.00,'2026-09-21','paid'),(57,6,27,2,4050.00,4050.00,'2026-09-21','paid'),(58,6,28,2,4050.00,4050.00,'2026-09-21','paid'),(59,6,29,2,4050.00,4050.00,'2026-09-21','paid'),(60,6,30,2,4050.00,4050.00,'2026-09-21','paid'),(61,6,31,2,4050.00,4050.00,'2026-09-21','paid'),(62,6,32,2,4050.00,4050.00,'2026-09-21','paid'),(63,6,33,2,4050.00,4050.00,'2026-09-21','paid'),(64,6,34,2,4050.00,4050.00,'2026-09-21','paid'),(65,6,35,2,4050.00,4050.00,'2026-09-21','paid'),(66,6,36,2,4050.00,4050.00,'2026-09-21','paid'),(77,6,27,3,4250.00,4000.00,'2026-09-30','partial'),(78,6,28,3,4250.00,4250.00,'2026-09-30','paid'),(79,6,29,3,4250.00,4250.00,'2026-09-21','paid'),(80,6,30,3,4250.00,3000.00,'2026-09-21','partial'),(81,6,31,3,4250.00,0.00,NULL,'pending'),(82,6,32,3,4250.00,0.00,NULL,'pending'),(83,6,33,3,4250.00,0.00,NULL,'pending'),(84,6,34,3,4250.00,0.00,NULL,'pending'),(85,6,35,3,4250.00,0.00,NULL,'pending'),(86,6,36,3,4250.00,4250.00,'2026-10-01','paid'),(97,6,27,4,4250.00,0.00,NULL,'pending'),(98,6,28,4,4250.00,4250.00,'2026-09-30','paid'),(99,6,29,4,4250.00,0.00,NULL,'pending'),(100,6,30,4,4250.00,0.00,NULL,'pending'),(101,6,31,4,4250.00,0.00,NULL,'pending'),(102,6,32,4,4250.00,0.00,NULL,'pending'),(103,6,33,4,4250.00,0.00,NULL,'pending'),(104,6,34,4,4250.00,0.00,NULL,'pending'),(105,6,35,4,4250.00,0.00,NULL,'pending'),(106,6,36,4,4250.00,4250.00,'2026-09-30','paid'),(147,6,27,5,4050.00,4050.00,'2026-09-21','paid'),(148,6,28,5,4050.00,4050.00,'2026-09-21','paid'),(149,6,29,5,4050.00,4050.00,'2026-09-21','paid'),(150,6,30,5,4050.00,4050.00,'2026-09-21','paid'),(151,6,31,5,4050.00,0.00,NULL,'pending'),(152,6,32,5,4050.00,0.00,NULL,'pending'),(153,6,33,5,4050.00,0.00,NULL,'pending'),(154,6,34,5,4050.00,0.00,NULL,'pending'),(155,6,35,5,4050.00,0.00,NULL,'pending'),(156,6,36,5,4050.00,4050.00,'2026-10-07','paid'),(157,6,27,6,4050.00,0.00,NULL,'pending'),(158,6,28,6,4050.00,4050.00,'2026-10-07','paid'),(159,6,29,6,4050.00,0.00,NULL,'pending'),(160,6,30,6,4050.00,0.00,NULL,'pending'),(161,6,31,6,4050.00,0.00,NULL,'pending'),(162,6,32,6,4050.00,0.00,NULL,'pending'),(163,6,33,6,4050.00,0.00,NULL,'pending'),(164,6,34,6,4050.00,0.00,NULL,'pending'),(165,6,35,6,4050.00,0.00,NULL,'pending'),(166,6,36,6,4050.00,0.00,NULL,'pending'),(177,6,27,7,3750.00,0.00,NULL,'pending'),(178,6,28,7,3750.00,0.00,NULL,'pending'),(179,6,29,7,3750.00,0.00,NULL,'pending'),(180,6,30,7,3750.00,0.00,NULL,'pending'),(181,6,31,7,3750.00,0.00,NULL,'pending'),(182,6,32,7,3750.00,0.00,NULL,'pending'),(183,6,33,7,3750.00,0.00,NULL,'pending'),(184,6,34,7,3750.00,0.00,NULL,'pending'),(185,6,35,7,3750.00,0.00,NULL,'pending'),(186,6,36,7,3750.00,0.00,NULL,'pending');
/*!40000 ALTER TABLE `installments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `payment_id` int NOT NULL,
  `user_id` int NOT NULL,
  `scheme_id` int NOT NULL,
  `installment_id` int NOT NULL,
  `transaction_id` varchar(100) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `verified_at` timestamp NULL DEFAULT NULL,
  `verified_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_notif_payment` (`payment_id`),
  KEY `fk_notif_user` (`user_id`),
  KEY `fk_notif_scheme` (`scheme_id`),
  KEY `fk_notif_inst` (`installment_id`),
  CONSTRAINT `fk_notif_inst` FOREIGN KEY (`installment_id`) REFERENCES `installments` (`id`),
  CONSTRAINT `fk_notif_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_scheme` FOREIGN KEY (`scheme_id`) REFERENCES `chit_schemes` (`id`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (3,17,7,6,158,'1234567890',4050.00,'verified',1,'2026-10-07 05:20:17','2026-10-07 05:21:42',1),(4,18,7,6,178,'0898675645654',3750.00,'pending',0,'2026-10-07 05:21:26',NULL,NULL);
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `scheme_id` int NOT NULL,
  `installment_id` int NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'INR',
  `payment_method` varchar(20) NOT NULL DEFAULT 'upi',
  `razorpay_order_id` varchar(100) DEFAULT NULL,
  `razorpay_payment_id` varchar(100) DEFAULT NULL,
  `razorpay_signature` varchar(255) DEFAULT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `status` enum('created','authorized','captured','failed','under_processing') NOT NULL DEFAULT 'created',
  `error_code` varchar(100) DEFAULT NULL,
  `error_description` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pay_installment` (`installment_id`),
  KEY `idx_pay_user` (`user_id`),
  KEY `idx_pay_order` (`razorpay_order_id`),
  CONSTRAINT `fk_pay_installment` FOREIGN KEY (`installment_id`) REFERENCES `installments` (`id`),
  CONSTRAINT `fk_pay_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
INSERT INTO `payments` VALUES (4,7,6,78,2250.00,'INR','upi','order_Ti7n3Oqdxi5ZbI','pay_Ti7nVWn1l0Qfwn','6731d7bdabb73efee3ac29ee6514a418d86e61900819023937f8daf3f432af73',NULL,'captured',NULL,NULL,'2026-09-30 04:58:32','2026-09-30 04:59:17'),(5,7,6,98,4250.00,'INR','upi','order_Ti7wQ9QEu12pGq','pay_Ti7wyuhgZGKffm','adeacefd500fa1194ffc7be0535811923c6375e2eddf002bfd6a4a88f444e857',NULL,'captured',NULL,NULL,'2026-09-30 05:07:24','2026-09-30 05:08:15'),(6,7,6,106,4250.00,'INR','upi','order_Ti8MfT2fYpI3hJ',NULL,NULL,NULL,'created',NULL,NULL,'2026-09-30 05:32:15','2026-09-30 05:32:15'),(7,7,6,158,4050.00,'INR','upi','order_Ti9qhp4dK2xfsP',NULL,NULL,NULL,'created',NULL,NULL,'2026-09-30 06:59:22','2026-09-30 06:59:22'),(8,7,6,106,4250.00,'INR','upi','order_Ti9rWRnwuIHPxq','pay_Ti9rgfF8MFIlzb','796ff18306c7dccb3e157a049e3386f6f5de222c8ae274749da4374e25ac20f8',NULL,'captured',NULL,NULL,'2026-09-30 07:00:09','2026-09-30 07:00:37'),(9,7,6,86,4250.00,'INR','upi','order_TiZ8KoOv69S2gI','pay_TiZ8aBHC7sJVBA','0e2071c9de236a0bdb8ffec5343224fe7236b4fae6c7fdadc5317be7baf7d7ba',NULL,'captured',NULL,NULL,'2026-10-01 07:43:24','2026-10-01 07:43:59'),(10,7,6,156,4050.00,'INR','upi','order_TkDOWeTWZedXPA',NULL,NULL,NULL,'created',NULL,NULL,'2026-10-05 11:45:28','2026-10-05 11:45:28'),(11,7,6,156,4050.00,'INR','upi','order_TkDOoFTwyXkzFO',NULL,NULL,NULL,'created',NULL,NULL,'2026-10-05 11:45:44','2026-10-05 11:45:44'),(12,7,6,156,4050.00,'INR','upi','order_TkDTb9MVuBa9Ai',NULL,NULL,NULL,'created',NULL,NULL,'2026-10-05 11:50:16','2026-10-05 11:50:16'),(13,7,6,156,4050.00,'INR','upi','order_TkXViamc8lG2tT',NULL,NULL,NULL,'created',NULL,NULL,'2026-10-06 07:26:10','2026-10-06 07:26:10'),(14,7,6,156,4050.00,'INR','upi','order_TktXR5B3YDWerD','pay_TktXmhigqZb1OH','22358915a5be80651dfae7db53b6661f6044c1b4bda5f191982e0f1d594cd879',NULL,'captured',NULL,NULL,'2026-10-07 04:59:02','2026-10-07 04:59:39'),(17,7,6,158,4050.00,'INR','upi',NULL,NULL,NULL,'1234567890','captured',NULL,NULL,'2026-10-07 05:20:17','2026-10-07 05:21:42'),(18,7,6,178,3750.00,'INR','upi',NULL,NULL,NULL,'0898675645654','under_processing',NULL,NULL,'2026-10-07 05:21:26','2026-10-07 05:21:26');
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscriptions`
--

DROP TABLE IF EXISTS `subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscriptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `scheme_id` int NOT NULL,
  `user_id` int NOT NULL,
  `ticket_number` int NOT NULL,
  `has_won` tinyint(1) NOT NULL DEFAULT '0',
  `won_month` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_scheme_ticket` (`scheme_id`,`ticket_number`),
  KEY `fk_sub_user` (`user_id`),
  KEY `idx_sub_scheme` (`scheme_id`),
  CONSTRAINT `fk_sub_scheme` FOREIGN KEY (`scheme_id`) REFERENCES `chit_schemes` (`id`),
  CONSTRAINT `fk_sub_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscriptions`
--

LOCK TABLES `subscriptions` WRITE;
/*!40000 ALTER TABLE `subscriptions` DISABLE KEYS */;
INSERT INTO `subscriptions` VALUES (27,6,6,1,1,1),(28,6,7,2,1,2),(29,6,8,3,1,3),(30,6,9,4,1,2),(31,6,10,5,1,4),(32,6,11,6,1,5),(33,6,12,7,1,6),(34,6,13,8,1,7),(35,6,6,9,0,NULL),(36,6,7,10,0,NULL),(37,8,7,2,0,NULL),(38,8,7,3,0,NULL),(43,9,20,2,0,NULL),(44,9,21,3,0,NULL),(45,9,22,4,0,NULL),(46,9,23,5,0,NULL),(47,9,24,6,0,NULL),(48,9,25,7,0,NULL),(49,9,26,8,0,NULL),(50,9,27,9,0,NULL),(51,9,28,10,0,NULL),(52,9,29,11,0,NULL),(53,9,30,12,0,NULL),(54,9,31,13,0,NULL),(55,9,32,14,0,NULL),(56,9,33,15,0,NULL),(57,9,33,16,0,NULL),(58,9,34,17,0,NULL),(59,9,35,18,0,NULL),(60,9,36,19,0,NULL),(61,9,37,20,0,NULL),(62,9,38,1,0,NULL);
/*!40000 ALTER TABLE `subscriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','member') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone` (`phone`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Chitty Administrator','9000000001','admin@chitty.local',NULL,'$2y$10$JuOwNc.Bm5RXGhoOVqGl2.ngSN8dvzMvtZrEYCm/hMHzogulK25A.','admin','2026-09-07 06:18:08'),(6,'Member 1','9000000002','member1@gmail.com',NULL,'$2y$10$lTnWn9KYopjYANVguT8BvObMq4GUkmLwUPVSKjM8VVOSvnOaBk/F.','member','2026-09-09 06:52:31'),(7,'Member 2','9000000003','member2@gmail.com',NULL,'$2y$10$zbxBmCHAJ8NRT74pc42em.nIbh0Bo4OByaWXVFGu/wImY2644mHni','member','2026-09-09 06:52:48'),(8,'Member 3','9000000004','member3@gmail.com',NULL,'$2y$10$Dk0NEUvGWUhEjYgz4RRfmulsOKfff7WAaXb.LhVrDX6Zt9a/D0wWC','member','2026-09-09 06:53:05'),(9,'Member 4','9000000005','member4@gmail.com',NULL,'$2y$10$orq1ZeSfLrwNwUeGjk/1c.TPFEjhoHvZJuFryNJrmvwJ/rGnzSJdy','member','2026-09-09 06:53:24'),(10,'Member 5','9000000006','member5@gmail.com',NULL,'$2y$10$CPSMIeDNdxBcGYkk9UDlIu30wS9.mU185foE5P5vE7bSMOY9hGONy','member','2026-09-09 06:53:53'),(11,'Member 6','9000000007','member6@gmail.com',NULL,'$2y$10$551tr5EXtPxNWFNaLjx0yeLa6NlxTZzBjyXMRt8VHYYT80f5ZJnnC','member','2026-09-21 06:12:05'),(12,'Member 7','9000000008','member7@gmail.com',NULL,'$2y$10$dvAVgV3nYXpCj9QRNSXSo.spTcOKQ/3Jz3/7r8U/WVHiMB.GxHhGm','member','2026-09-21 06:12:36'),(13,'Member 8','9000000009','member8@gmail.com',NULL,'$2y$10$QAK4sj.dtGRXVRr7BP7fKedb.YQgbc1oo2C5vdYzBChWR347muea2','member','2026-09-21 06:13:01'),(14,'Society Admin','9999999999',NULL,NULL,'$2y$10$DP6vXjefyF9YZ5NqCyKZhuoVm2Ui5zvnMpnJbzq1Ohbe.cnm.IoB6','admin','2026-09-29 09:01:29'),(15,'Test Member','9999999998','member@test.com','Test Address','$2y$10$AG.brarP.QXY56F99.7wfu9/OY2vrH9Y5FqhNNbXog56szcqjZ84O','member','2026-09-29 09:01:38'),(20,'Srutheesh','8043321819',NULL,NULL,'$2y$10$YEbORoSgxCl.AKICb7vMMuiXELnDDjNDbDc42n7IU8sOgj0/xfVlK','member','2026-10-06 09:07:20'),(21,'Drishya Prasad','9001338908',NULL,NULL,'$2y$10$7N98Uv9HRQYEqyMjZxlbv.4XB9E.0eyL8rhHTUs1XN8jEve2nlSky','member','2026-10-06 09:07:20'),(22,'Renu Mary','8863794026',NULL,NULL,'$2y$10$2NowrMXRaPfHZl0asJCzIOwXIhAMISjmGcxQ9A/QFmIyRLDdm/Quq','member','2026-10-06 09:07:20'),(23,'Dhanyamol','9423511615',NULL,NULL,'$2y$10$X.B2M/AcenerM3Oc0j7dkOHtQYoCG8gyEktkBBOxafGU7kUMmiDqG','member','2026-10-06 09:07:20'),(24,'Asha Sebastian','9940781618',NULL,NULL,'$2y$10$uFC0xqhwRFQ5OCnaPrTcbuq3Q.vBa8HTTF.0s64U/5MuEr/mw6WC2','member','2026-10-06 09:07:20'),(25,'John Paul M','9959310341',NULL,NULL,'$2y$10$90flddi0RYRTXyaxXhSEWOTAfutsLcG6PEX3JY33xTmg0TkPl/hNG','member','2026-10-06 09:07:20'),(26,'Manisha','8164752553',NULL,NULL,'$2y$10$Of9dc3DvhTYeiF9mt6BNV.vb.D7TOuJpIkizCFfg6I/2BiVcRdNAK','member','2026-10-06 09:07:21'),(27,'Rageshwarier','9192832764',NULL,NULL,'$2y$10$Kzqjo6FPNLgfl1Ukd1Ug4u7k.sqogH2Om5T2e0j7sVZaF26rjiIY.','member','2026-10-06 09:07:21'),(28,'Vineeth P','8503056413',NULL,NULL,'$2y$10$pjy2jStBqhhnUiuVUbXOtOcg5DENLI990ekNK4P0AFecag2Vm4PwO','member','2026-10-06 09:07:21'),(29,'Manjith B C','9376724238',NULL,NULL,'$2y$10$F8uxN6zBPJcYoBqW834mluUoUBZsHqMWRQCvewtXdEoNJ2oxoGrFS','member','2026-10-06 09:07:21'),(30,'Manu Madhavan','9969653287',NULL,NULL,'$2y$10$G8PXpzxJpXLBEHJ6kZ7eNeVQ1j8EeltwGCTw8HC62pIo8DCJXF4xe','member','2026-10-06 09:07:21'),(31,'Christina','8012269166',NULL,NULL,'$2y$10$fUxI7taLpru.9.bj5QgKrOvgQ9L4qfZWHpQ27PuOHiA0xFiGgHMZi','member','2026-10-06 09:07:21'),(32,'Bijo Baby','9848018451',NULL,NULL,'$2y$10$U2wOJHEP99vCJZsNq0U3mOgacP/0q8qjUHOTewXf0x8KC/WeYgUtm','member','2026-10-06 09:07:21'),(33,'Binu M','9627048281',NULL,NULL,'$2y$10$SOi5GJAZMKAs8X4/Ie8.z.CUhUxMpHq6hK6R7.zLc.rLUOx3psd/a','member','2026-10-06 09:07:21'),(34,'Sumesh C','9893252880',NULL,NULL,'$2y$10$K4.CTe92yGab92Pfr2XuruAf0vnD67TUWbbZ4iu4/SAkrMZcfnrsC','member','2026-10-06 09:07:21'),(35,'Sara Renjit','9701543039',NULL,NULL,'$2y$10$bKsunVOkJ2O2hxV8CmIEu.RWS0poW.5FqAaGeZt0viuCI6wpbfx1W','member','2026-10-06 09:07:21'),(36,'Ebin Deni R','8171822782',NULL,NULL,'$2y$10$duB9OJVaoeciz7t6jz2hQOSt2den1YUyfouZyZjqZnIU363Ydnc9.','member','2026-10-06 09:07:21'),(37,'Panchami','9896383465',NULL,NULL,'$2y$10$IPrZu/ab7JHstSpIFcsJk.x9n9oeMmV.X2AQ2kLwxKOHv6tidBD4m','member','2026-10-06 09:07:21'),(38,'foreman','0987654321',NULL,NULL,'$2y$10$1cFw6i/S7uLbla7NWXlo/u1kvIiDCG0ey1OdWoLsr4mpMWBhChWF6','member','2026-10-06 11:49:01');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-07 11:34:16
