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
-- Table structure for table `aplicacion_vacuna`
--

DROP TABLE IF EXISTS `aplicacion_vacuna`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `aplicacion_vacuna` (
  `id_aplicacion_vacuna` int NOT NULL AUTO_INCREMENT,
  `id_paciente` char(36) NOT NULL,
  `id_esquema_biologico_dosis` int NOT NULL,
  `id_lote` int NOT NULL,
  `id_unidad` int NOT NULL,
  `id_biologico_indicado` int DEFAULT NULL,
  `fecha_aplicacion` date NOT NULL,
  `clasificacion_vph` varchar(40) DEFAULT NULL,
  `clasificacion_hepatitis_b` varchar(30) DEFAULT NULL,
  `clasificacion_sr` varchar(24) DEFAULT NULL,
  `clasificacion_varicela_hepatitis_a` varchar(16) DEFAULT NULL,
  `dosis_cero` tinyint(1) NOT NULL,
  `observaciones` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id_aplicacion_vacuna`),
  KEY `FK_AV_PACIENTE` (`id_paciente`),
  KEY `FK_AV_EBD` (`id_esquema_biologico_dosis`),
  KEY `FK_AV_LOTE` (`id_lote`),
  KEY `FK_AV_UNIDAD` (`id_unidad`),
  KEY `FK_AV_BIOLOGICO_INDICADO` (`id_biologico_indicado`),
  CONSTRAINT `FK_AV_BIOLOGICO_INDICADO` FOREIGN KEY (`id_biologico_indicado`) REFERENCES `biologico` (`id_biologico`),
  CONSTRAINT `FK_AV_EBD` FOREIGN KEY (`id_esquema_biologico_dosis`) REFERENCES `esquema_biologico_dosis` (`id_esquema_biologico_dosis`),
  CONSTRAINT `FK_AV_LOTE` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`),
  CONSTRAINT `FK_AV_PACIENTE` FOREIGN KEY (`id_paciente`) REFERENCES `paciente` (`id_paciente`),
  CONSTRAINT `FK_AV_UNIDAD` FOREIGN KEY (`id_unidad`) REFERENCES `unidad` (`id_unidad`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-07 19:38:39
