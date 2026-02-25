-- MySQL dump 10.13  Distrib 8.0.45, for Linux (aarch64)
--
-- Host: localhost    Database: folio_reports
-- ------------------------------------------------------
-- Server version	8.0.45

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
-- Dumping data for table `saved_queries`
--

LOCK TABLES `saved_queries` WRITE;
/*!40000 ALTER TABLE `saved_queries` DISABLE KEYS */;
REPLACE INTO `saved_queries` (`id`, `name`, `description`, `query_definition`, `generated_sql`, `source`, `nl_prompt`, `is_pinned`, `created_at`, `updated_at`) VALUES (1,'Josten top 100 circulating books','','\"[]\"','SELECT\n  inst.title,\n  ii.effective_call_number_components__call_number AS call_number,\n  ilb.name AS library_name,\n  MAX(cl.loan_date) AS last_circulation_date,\n  COUNT(cl.id) AS circulation_count\nFROM\n  circulation.loan__t AS cl\nJOIN\n  inventory.item__t AS ii ON cl.item_id = ii.id\nJOIN\n  inventory.holdings_record__t AS ihr ON ii.holdings_record_id = ihr.id\nJOIN\n  inventory.instance__t AS inst ON ihr.instance_id = inst.id\nJOIN\n  inventory.location__t AS iloc ON ii.effective_location_id = iloc.id\nJOIN\n  inventory.loclibrary__t AS ilb ON iloc.library_id = ilb.id\nWHERE\n  LOWER(ilb.name) ILIKE \'%josten library%\'\nGROUP BY\n  inst.title,\n  ii.effective_call_number_components__call_number,\n  ilb.name\nORDER BY\n  circulation_count DESC\nLIMIT 100;','nl','Show me the top 100 circulating items in Josten Library of all time. I want to know the last time they circulated and I want to see the title, call number, library, and last circulation date.',0,'2026-02-20 17:08:43','2026-02-20 17:08:43');
/*!40000 ALTER TABLE `saved_queries` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-02-25 19:02:58
