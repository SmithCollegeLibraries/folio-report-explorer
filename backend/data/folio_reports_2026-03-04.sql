# ************************************************************
# Sequel Ace SQL dump
# Version 20095
#
# https://sequel-ace.com/
# https://github.com/Sequel-Ace/Sequel-Ace
#
# Host: localhost (MySQL 5.5.5-10.3.39-MariaDB-log)
# Database: folio_reports
# Generation Time: 2026-03-04 18:56:34 +0000
# ************************************************************


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
SET NAMES utf8mb4;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE='NO_AUTO_VALUE_ON_ZERO', SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


# Dump of table ACRL_Statistics
# ------------------------------------------------------------

DROP TABLE IF EXISTS `ACRL_Statistics`;

CREATE TABLE `ACRL_Statistics` (
  `Category` varchar(255) DEFAULT NULL,
  `Subcategory` varchar(255) DEFAULT NULL,
  `Year_2016` decimal(18,2) DEFAULT NULL,
  `Year_2017` decimal(18,2) DEFAULT NULL,
  `Year_2018` decimal(18,2) DEFAULT NULL,
  `Year_2019` decimal(18,2) DEFAULT NULL,
  `Year_2020` decimal(18,2) DEFAULT NULL,
  `Year_2021` decimal(18,2) DEFAULT NULL,
  `Year_2022` decimal(18,2) DEFAULT NULL,
  `Year_2023` decimal(18,0) DEFAULT NULL,
  `Notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

LOCK TABLES `ACRL_Statistics` WRITE;
/*!40000 ALTER TABLE `ACRL_Statistics` DISABLE KEYS */;

INSERT INTO `ACRL_Statistics` (`Category`, `Subcategory`, `Year_2016`, `Year_2017`, `Year_2018`, `Year_2019`, `Year_2020`, `Year_2021`, `Year_2022`, `Year_2023`, `Notes`)
VALUES
	('Materials/services expenses','ONE-TIME PURCHASE OF BOOKS, SERIAL BACKFILES, AND OTHER MATERIALS',1134645.00,1432380.00,1438125.00,1210076.36,1008440.67,929803.00,810037.00,642903,NULL),
	('Materials/services expenses','E-BOOKS',352760.00,320828.00,352050.00,430860.47,319809.07,325248.00,290229.00,417066,NULL),
	('Materials/services expenses','ONGOING COMMITMENTS TO SUBSCRIPTIONS',2778593.00,2789720.00,3113648.00,3207378.02,3360449.92,3496134.00,3439722.00,3571043,NULL),
	('Materials/services expenses','E-BOOKS',95309.00,80643.00,84389.00,80534.04,106026.73,95758.00,104512.00,214480,NULL),
	('Materials/services expenses','E-JOURNALS',493598.00,517620.00,560559.00,610486.62,598939.65,582460.00,647411.00,813496,NULL),
	('Materials/services expenses','ALL OTHER MATERIALS/SERVICE COST',35487.00,107477.00,123112.83,109867.00,112846.18,116446.00,120440.00,187837,NULL),
	('Materials/services expenses','TOTAL MATERIALS/SERVICES EXPENSES',3948725.00,4329577.00,4674885.00,4527321.38,4481736.77,4542383.00,4370199.00,4401783,NULL),
	('Operations and maintenance expenses','PRESERVATION SERVICES',35000.00,43667.21,33622.00,22423.00,11591.14,0.00,0.00,0,NULL),
	('Operations and maintenance expenses','ALL OTHER OPERATIONS AND MAINTENANCE EXPENSES',1039016.00,1213653.63,1208352.46,1179480.00,919866.00,723590.00,860801.33,961681,NULL),
	('Operations and maintenance expenses','TOTAL OPERATIONS AND MAINTENANCE EXPENSES',1074016.00,1213653.63,1241974.46,1201903.00,931457.14,723590.00,860801.33,961681,NULL),
	('Library Collections','BOOKS (TITLE COUNT) - Physical',1020665.00,1032611.00,1044965.00,1051634.00,1058538.00,1054103.00,1069686.00,NULL,NULL),
	('Library Collections','BOOKS (TITLE COUNT) - Digital',1281850.00,1526810.00,1822179.00,1916419.00,2133994.00,2197270.00,2056859.00,1653386,NULL),
	('Library Collections','BOOKS (VOLUME COUNT) - Physical',1843540.00,1734544.00,1214773.00,1228671.00,1237748.00,1246281.00,1239105.00,1273387,NULL),
	('Library Collections','BOOKS (VOLUME COUNT) - Digital',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
	('Library Collections','DATABASES - Physical',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
	('Library Collections','DATABASES - Digital',464.00,495.00,524.00,630.00,532.00,544.00,548.00,549,NULL),
	('Library Collections','MEDIA - Physical',59117.00,50783.00,43146.00,43305.00,43402.00,43507.00,43261.00,42713,NULL),
	('Library Collections','MEDIA - Digital',113181.00,119126.00,130208.00,145885.00,153399.00,140707.00,144475.00,119872,NULL),
	('Library Collections','SERIALS - Physical',36661.00,37451.00,51264.00,51564.00,51813.00,51942.00,51895.00,50052,NULL),
	('Library Collections','SERIALS - Digital',96868.00,104584.00,597273.00,120054.00,126411.00,197955.00,207194.00,220695,NULL),
	('Library Collections','TOTAL - Physical',95778.00,1822778.00,1127021.00,1139834.00,1146849.00,1153987.00,1153987.00,1162451,NULL),
	('Library Collections','TOTAL - Digital',1492363.00,1751015.00,2550184.00,2182988.00,2414336.00,2536476.00,2536476.00,1994502,NULL),
	('Institutional Repositories','ITEMS CONTRIBUTED TO THE INSTITUTIONAL REPOSITORY VIA UPLOADS',212.00,623.00,292.00,505.00,585.00,4419.00,5232.00,5676,NULL),
	('Institutional Repositories','ITEM USAGE FROM THE INSTITUTIONAL REPOSITORY',4751.00,77347.00,198192.00,162032.00,305749.00,1469498.00,2134086.00,664752,NULL),
	('Library Circulation Usage','INITIAL CIRCULATION - Physical',86253.00,86935.00,85323.00,79094.00,43013.00,11168.00,44178.00,15367,NULL),
	('Library Circulation Usage','INITIAL CIRCULATION - Digital/Electronic',453054.00,554392.00,465736.00,385890.00,305742.00,305405.00,305405.00,369701,NULL),
	('Library Circulation Usage','E-BOOK USAGE COUNTER - Physical',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
	('Library Circulation Usage','E-BOOK USAGE COUNTER - Digital/Electronic',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
	('Library Circulation Usage','E-BOOK USAGE - Physical',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
	('Library Circulation Usage','E-BOOK USAGE - Digital/Electronic',417698.00,503467.00,419863.00,463120.00,NULL,NULL,NULL,NULL,NULL),
	('Library Circulation Usage','E-SERIALS USAGE - Physical',NULL,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL),
	('Library Circulation Usage','E-SERIALS USAGE - Digital/Electronic',258650.00,287710.00,283383.00,0.00,277517.00,202457.00,202457.00,369701,NULL),
	('Hours','NUMBER OF HOURS OPEN DURING A TYPICAL WEEK IN AN ACADEMIC SESSION',109.50,109.50,109.50,111.50,111.50,NULL,109.50,100,NULL),
	('Hours','Number of weeks the main library was closed due to COVID-19',NULL,NULL,NULL,NULL,NULL,40.00,NULL,NULL,NULL),
	('Hours','Number of weeks the main library had limited occupancy due to COVID-19',NULL,NULL,NULL,NULL,NULL,0.00,NULL,NULL,NULL),
	('Library Circulation Usage','DOES YOUR INSTITUTION HAVE INTERLIBRARY LOAN SERVICES?',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
	('Library Circulation Usage','TOTAL INTERLIBRARY LOANS AND DOCUMENTS PROVIDED TO OTHER LIBRARIES',NULL,16001.00,21241.00,24602.00,3411.00,6611.00,6611.00,8415,NULL),
	('Library Circulation Usage','ILL-01 RETURNABLE',17137.00,NULL,NULL,NULL,NULL,NULL,NULL,610,NULL),
	('Library Circulation Usage','ILL-02 NON-RETURNABLE',2289.00,NULL,NULL,NULL,NULL,NULL,NULL,9025,NULL),
	('Library Circulation Usage','TOTAL IF ILL-01 AND ILL-02 ARE REPORTED SEPARATELY',19426.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
	('Library Circulation Usage','TOTAL INTERLIBRARY LOANS AND DOCUMENTS RECEIVED',NULL,20286.00,25771.00,28954.00,4635.00,7334.00,7334.00,NULL,NULL),
	('Library Circulation Usage','ILL-03 RETURNABLES',13899.00,NULL,NULL,NULL,NULL,NULL,NULL,8885,NULL),
	('Library Circulation Usage','ILL-04 NON-RETURNABLES',4331.00,NULL,NULL,NULL,NULL,NULL,NULL,1644,NULL),
	('Library Circulation Usage','ILL-05 DOCUMENTS RECEIVED FROM COMMERCIAL SERVICES',6.00,8.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
	('Library Circulation Usage','TOTAL IF ILL-03, ILL-04 AND ILL-05 ARE REPORTED SEPARATELY',18236.00,NULL,NULL,NULL,NULL,NULL,NULL,10529,NULL),
	('Student Enrollment','90FULL-TIME EQUIVALENTS (FTE)',3057.00,2891.00,2918.00,2880.00,2862.00,2799.00,2924.00,2827,NULL),
	('Student Enrollment','HEADCOUNTS',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
	('Student Enrollment','FULL TIME UNDERGRADUATE',NULL,2505.00,2521.00,2490.00,2518.00,2160.00,2566.00,2518,NULL),
	('Student Enrollment','PART TIME UNDERGRADUATE',NULL,16.00,16.00,12.00,12.00,22.00,12.00,5,NULL),
	('Student Enrollment','FULL TIME GRADUATE',NULL,373.00,397.00,378.00,344.00,253.00,341.00,317,NULL),
	('Student Enrollment','PART TIME GRADUATE',NULL,24.00,24.00,23.00,19.00,15.00,17.00,33,NULL),
	('Student Enrollment','TOTAL HEADCOUNT',NULL,2918.00,2918.00,2903.00,2893.00,2450.00,2936.00,2873,NULL),
	('Information Services to Individuals','Email reference',4410.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
	('Information Services to Individuals','Chat reference, commercial services',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
	('Information Services to Individuals','Chat reference, instant messaging applications',84.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
	('Information Services to Individuals','Short message service (SMS) or text messaging',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
	('Information Services to Individuals','Online conferencing',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
	('Information Services to Individuals','Transactions',8316.00,11738.00,NULL,227.00,2611.00,2807.00,1236.00,NULL,NULL),
	('Information Services to Individuals','Consultations',101.00,81.00,271.00,719.00,1150.00,268.00,625.00,NULL,NULL),
	('Information Services to Individuals','Transactions and Consultations if unable to report separately',NULL,NULL,NULL,NULL,NULL,NULL,1349.00,5097,NULL),
	('Information Services to Individuals','Virtual Reference Services',NULL,950.00,878.00,996.00,1134.00,NULL,819.00,2750,NULL),
	('Information Services to Individuals','Number of presentations - physical',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
	('Information Services to Individuals','Number of presentations - digital/electronic',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
	('Information Services to Individuals','Total attendance at all presentations - total (if breakdown not available)',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
	('Information Services to Individuals','Number of Synchronous Presentations - Physical',NULL,NULL,215.00,NULL,215.00,188.00,264.00,188,NULL),
	('Information Services to Individuals','Number of Synchronous Presentations - Digital/Electronic',NULL,NULL,21.00,NULL,21.00,0.00,24.00,0,NULL),
	('Information Services to Individuals','Number of Synchronous Presentations - Total (if unable to break apart)',304.00,417.00,224.00,230.00,236.00,196.00,288.00,NULL,NULL),
	('Information Services to Individuals','Total Attendance at All Synchronous Presentations - Physical',NULL,NULL,2880.00,NULL,2880.00,3240.00,4651.00,3240,NULL),
	('Information Services to Individuals','Total Attendance at All Synchronous Presentations - Digital/Electronic',NULL,NULL,310.00,NULL,310.00,0.00,261.00,0,NULL),
	('Information Services to Individuals','Total Attendance at All Synchronous Presentations - Total (if unable to break apart)',5341.00,7500.00,3147.00,4110.00,3190.00,3586.00,4912.00,NULL,NULL),
	('Information Services to Individuals','Number of Asynchronous Presentations',NULL,NULL,1.00,0.00,1.00,0.00,0.00,0,NULL),
	('Information Services to Individuals','Total Attendance at All Asynchronous Presentations - Digital/Electronic',NULL,NULL,650.00,0.00,650.00,0.00,0.00,0,NULL);

/*!40000 ALTER TABLE `ACRL_Statistics` ENABLE KEYS */;
UNLOCK TABLES;



/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
