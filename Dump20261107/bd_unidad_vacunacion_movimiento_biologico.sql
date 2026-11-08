-- MySQL dump 10.13  Distrib 8.0.46, for Win64 (x86_64)
--
-- Host: localhost    Database: bd_unidad_vacunacion
-- ------------------------------------------------------
-- Server version	8.0.46

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `movimiento_biologico`
--

DROP TABLE IF EXISTS `movimiento_biologico`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `movimiento_biologico` (
  `id_movimiento` int NOT NULL AUTO_INCREMENT,
  `id_unidad` int NOT NULL,
  `id_lote` int NOT NULL,
  `tipo_movimiento` varchar(15) NOT NULL,
  `cantidad_frascos` int NOT NULL,
  `cantidad_dosis` int NOT NULL,
  `fecha_movimiento` date NOT NULL,
  `necesidad` int NOT NULL,
  `observaciones` text,
  `id_unidad_receptora` int DEFAULT NULL,
  PRIMARY KEY (`id_movimiento`),
  KEY `FK_MB_UNIDAD` (`id_unidad`),
  KEY `FK_MB_LOTE` (`id_lote`),
  KEY `FK_MB_UNIDAD_RECEPTORA` (`id_unidad_receptora`),
  CONSTRAINT `FK_MB_LOTE` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`),
  CONSTRAINT `FK_MB_UNIDAD` FOREIGN KEY (`id_unidad`) REFERENCES `unidad` (`id_unidad`),
  CONSTRAINT `FK_MB_UNIDAD_RECEPTORA` FOREIGN KEY (`id_unidad_receptora`) REFERENCES `unidad` (`id_unidad`),
  CONSTRAINT `CK_MB_TIPO` CHECK ((`tipo_movimiento` in (_utf8mb4'Entrada',_utf8mb4'Desecho',_utf8mb4'Donación')))
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-07 19:38:41
