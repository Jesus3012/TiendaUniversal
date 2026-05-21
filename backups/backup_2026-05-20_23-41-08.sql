-- Backup generado el 2026-05-20 23:41:08
-- Base de datos: tienda_pescadores

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Estructura de tabla `auditoria`
DROP TABLE IF EXISTS `auditoria`;
CREATE TABLE `auditoria` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) DEFAULT NULL,
  `accion` varchar(100) NOT NULL,
  `detalle` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`)
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos de tabla `auditoria`
INSERT INTO `auditoria` (`id`, `usuario_id`, `accion`, `detalle`, `ip`, `fecha`) VALUES ('76', '1', 'Actualizar Configuración General', 'Actualizó la configuración general de la tienda', '::1', '2026-05-03 14:58:54');
INSERT INTO `auditoria` (`id`, `usuario_id`, `accion`, `detalle`, `ip`, `fecha`) VALUES ('77', '1', 'Actualizar Configuración General', 'Actualizó la configuración general de la tienda', '::1', '2026-05-05 15:55:28');
INSERT INTO `auditoria` (`id`, `usuario_id`, `accion`, `detalle`, `ip`, `fecha`) VALUES ('78', '1', 'Actualizar Configuración General', 'Actualizó la configuración general de la tienda', '::1', '2026-05-05 15:56:05');
INSERT INTO `auditoria` (`id`, `usuario_id`, `accion`, `detalle`, `ip`, `fecha`) VALUES ('79', '1', 'Resetear Contraseña', 'Restableció la contraseña del usuario ID: 2', '::1', '2026-05-11 11:07:44');
INSERT INTO `auditoria` (`id`, `usuario_id`, `accion`, `detalle`, `ip`, `fecha`) VALUES ('80', '1', 'Actualizar Configuración General', 'Actualizó la configuración general de la tienda', '::1', '2026-05-13 09:52:21');
INSERT INTO `auditoria` (`id`, `usuario_id`, `accion`, `detalle`, `ip`, `fecha`) VALUES ('81', '1', 'Resetear Contraseña', 'Restableció la contraseña del usuario ID: 1', '::1', '2026-05-13 09:53:08');
INSERT INTO `auditoria` (`id`, `usuario_id`, `accion`, `detalle`, `ip`, `fecha`) VALUES ('82', '1', 'Actualizar Configuración General', 'Actualizó la configuración general de la tienda', '::1', '2026-05-13 13:09:02');
INSERT INTO `auditoria` (`id`, `usuario_id`, `accion`, `detalle`, `ip`, `fecha`) VALUES ('83', '1', 'Actualizar Configuración General', 'Actualizó la configuración general de la tienda', '::1', '2026-05-20 22:33:23');
INSERT INTO `auditoria` (`id`, `usuario_id`, `accion`, `detalle`, `ip`, `fecha`) VALUES ('84', '1', 'Limpiar Auditoría', 'Limpió registros de auditoría antiguos. Eliminados: 0', '::1', '2026-05-20 22:40:53');

-- Estructura de tabla `codigos_barras`
DROP TABLE IF EXISTS `codigos_barras`;
CREATE TABLE `codigos_barras` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `producto_id` int(11) NOT NULL,
  `codigo` varchar(50) NOT NULL,
  `disponible` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `codigos_barras_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1763 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Datos de tabla `codigos_barras`
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('588', '36', 'P00000036', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('625', '46', '4600001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('626', '46', '4600002', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('627', '46', '4600003', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('628', '46', '4600004', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('629', '46', '4600005', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('630', '46', '4600006', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('631', '46', '4600007', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('632', '46', '4600008', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('633', '46', '4600009', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('634', '46', '4600010', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('635', '46', '4600011', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('636', '46', '4600012', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('637', '46', '4600013', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('638', '46', '4600014', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('639', '46', '4600015', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('640', '46', '4600016', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('641', '46', '4600017', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('642', '46', '4600018', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('643', '46', '4600019', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('644', '46', '4600020', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('645', '46', '4600021', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('646', '46', '4600022', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('647', '46', '4600023', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('648', '46', '4600024', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('649', '46', '4600025', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('650', '46', '4600026', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('651', '46', '4600027', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('652', '46', '4600028', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('653', '46', '4600029', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('654', '46', '4600030', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('655', '46', '4600031', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('656', '46', '4600032', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('657', '46', '4600033', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('658', '46', '4600034', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('659', '46', '4600035', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('660', '46', '4600036', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('662', '48', '4800001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('664', '50', '5000001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('665', '50', '5000002', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('666', '50', '5000003', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('667', '50', '5000004', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('668', '50', '5000005', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('669', '50', '5000006', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('708', '46', '4600037', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('709', '46', '4600038', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('710', '46', '4600039', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('711', '46', '4600040', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('745', '46', '4600041', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('746', '46', '4600042', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('747', '46', '4600043', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('748', '46', '4600044', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('749', '46', '4600045', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('750', '46', '4600046', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('751', '46', '4600047', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('752', '46', '4600048', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('753', '46', '4600049', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('754', '46', '4600050', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('755', '46', '4600051', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('756', '46', '4600052', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('757', '46', '4600053', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('758', '46', '4600054', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('848', '85', 'P00000085', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('849', '84', 'P00000084', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('850', '83', 'P00000083', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('851', '82', 'P00000082', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('852', '81', 'P00000081', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('853', '80', 'P00000080', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('854', '79', 'P00000079', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('855', '78', 'P00000078', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('856', '77', 'P00000077', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('857', '76', 'P00000076', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('858', '75', 'P00000075', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('859', '74', 'P00000074', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('860', '73', 'P00000073', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('862', '71', 'P00000071', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('863', '70', 'P00000070', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('864', '65', 'P00000065', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('865', '69', 'P00000069', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('866', '68', 'P00000068', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('867', '67', 'P00000067', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('868', '66', 'P00000066', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('869', '64', 'P00000064', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('870', '63', 'P00000063', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('871', '62', 'P00000062', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('872', '57', '5700001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('873', '57', '5700002', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('874', '57', '5700003', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('875', '57', '5700004', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('876', '57', '5700005', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('877', '57', '5700006', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('878', '57', '5700007', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('879', '57', '5700008', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('880', '44', 'P00000044', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('881', '43', 'P00000043', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('882', '42', 'P00000042', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('883', '41', 'P00000041', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('884', '37', 'P00000037', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('885', '2', 'P00000002', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('886', '1', 'P00000001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('888', '61', 'P00000061', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('889', '3', 'P00000003', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('890', '4', 'P00000004', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('891', '5', 'P00000005', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('892', '6', 'P00000006', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('893', '7', 'P00000007', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('894', '8', 'P00000008', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('895', '9', 'P00000009', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('896', '10', 'P00000010', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('897', '11', 'P00000011', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('898', '12', 'P00000012', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('899', '13', 'P00000013', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('900', '14', 'P00000014', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('901', '60', 'P00000060', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('902', '59', 'P00000059', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('903', '58', 'P00000058', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('904', '56', 'P00000056', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('905', '55', 'P00000055', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('906', '18', 'P00000018', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('907', '54', 'P00000054', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('908', '53', 'P00000053', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('909', '52', 'P00000052', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('910', '51', 'P00000051', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('911', '35', 'P00000035', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('912', '49', 'P00000049', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('913', '16', 'P00000016', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('914', '47', 'P00000047', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('915', '45', 'P00000045', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('916', '40', 'P00000040', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('917', '39', 'P00000039', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('918', '38', 'P00000038', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('919', '34', 'P00000034', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('920', '23', 'P00000023', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('922', '17', 'P00000017', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('923', '33', 'P00000033', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('924', '32', 'P00000032', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('925', '31', 'P00000031', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('926', '30', 'P00000030', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('927', '29', 'P00000029', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('928', '28', 'P00000028', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('929', '27', 'P00000027', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('930', '26', 'P00000026', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('931', '25', 'P00000025', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('932', '22', 'P00000022', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('933', '21', 'P00000021', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('934', '20', 'P00000020', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('935', '19', 'P00000019', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('936', '15', 'P00000015', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1520', '24', 'P00000024', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1521', '72', 'P00000072', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1602', '90', 'P00000090', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1653', '89', 'P00000089', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1732', '88', 'P00000088', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1733', '87', 'P00000087', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1736', '86', 'P00000086', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1737', '91', '9100001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1738', '92', '9200001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1739', '92', '9200002', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1740', '92', '9200003', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1741', '92', '9200004', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1742', '92', '9200005', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1743', '92', '9200006', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1744', '92', '9200007', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1745', '92', '9200008', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1746', '92', '9200009', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1747', '92', '9200010', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1748', '92', '9200011', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1749', '92', '9200012', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1750', '92', '9200013', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1751', '92', '9200014', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1752', '92', '9200015', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1753', '92', '9200016', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1754', '92', '9200017', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1755', '92', '9200018', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1756', '92', '9200019', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1757', '92', '9200020', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1758', '92', '9200021', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1759', '92', '9200022', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1760', '92', '9200023', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1761', '92', '9200024', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('1762', '92', '9200025', '1');

-- Estructura de tabla `configuracion_correo`
DROP TABLE IF EXISTS `configuracion_correo`;
CREATE TABLE `configuracion_correo` (
  `id` int(11) NOT NULL,
  `smtp_host` varchar(100) NOT NULL DEFAULT 'smtp.gmail.com',
  `smtp_port` int(11) NOT NULL DEFAULT 587,
  `smtp_usuario` varchar(100) NOT NULL,
  `smtp_password` varchar(255) NOT NULL,
  `smtp_secure` enum('tls','ssl') NOT NULL DEFAULT 'tls',
  `correo_origen` varchar(100) NOT NULL,
  `nombre_origen` varchar(100) NOT NULL DEFAULT 'Tienda Pescadores',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos de tabla `configuracion_correo`
INSERT INTO `configuracion_correo` (`id`, `smtp_host`, `smtp_port`, `smtp_usuario`, `smtp_password`, `smtp_secure`, `correo_origen`, `nombre_origen`, `activo`, `created_at`, `updated_at`) VALUES ('1', 'smtp.gmail.com', '587', 'jesusgabrielmtz78@gmail.com', 'iwdf uyqu erzq wvbm', 'tls', 'jesusgabrielmtz78@gmail.com', 'Tienda Pescadores', '1', '2026-04-17 04:30:53', '2026-04-20 22:14:52');

-- Estructura de tabla `configuracion_galeria`
DROP TABLE IF EXISTS `configuracion_galeria`;
CREATE TABLE `configuracion_galeria` (
  `id` int(11) NOT NULL,
  `nombre` varchar(200) NOT NULL DEFAULT 'Gimnasio',
  `telefono` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `direccion` text DEFAULT NULL,
  `horario` varchar(255) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `imagen_dashboard` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos de tabla `configuracion_galeria`
INSERT INTO `configuracion_galeria` (`id`, `nombre`, `telefono`, `email`, `direccion`, `horario`, `logo`, `imagen_dashboard`, `created_at`, `updated_at`) VALUES ('1', 'Pescadores de la Prehistoria1', '22221', 'karmina.aranguthy@hotmail.com', 'Tepexi de Rodriguez, Pue', 'Lunes a Domingo 10:00 - 20:00.', 'img/panel_principal.jpg', 'img/dashboard_principal.jpeg', '2026-04-20 12:58:45', '2026-05-20 22:33:23');

-- Estructura de tabla `devoluciones_parciales`
DROP TABLE IF EXISTS `devoluciones_parciales`;
CREATE TABLE `devoluciones_parciales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_venta` int(11) NOT NULL,
  `cantidad_devuelta` int(11) NOT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `fecha` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Datos de tabla `devoluciones_parciales`
INSERT INTO `devoluciones_parciales` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha`) VALUES ('1', '15', '7', 'me equivoque', '2026-02-19 09:07:05');
INSERT INTO `devoluciones_parciales` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha`) VALUES ('2', '21', '2', '', '2026-03-06 18:12:04');
INSERT INTO `devoluciones_parciales` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha`) VALUES ('3', '26', '24', '', '2026-03-07 14:44:15');
INSERT INTO `devoluciones_parciales` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha`) VALUES ('4', '146', '1', 'me equivoque', '2026-05-03 23:41:29');

-- Estructura de tabla `historial_reportes`
DROP TABLE IF EXISTS `historial_reportes`;
CREATE TABLE `historial_reportes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) DEFAULT NULL,
  `usuario_nombre` varchar(100) DEFAULT NULL,
  `tipo_reporte` varchar(50) DEFAULT 'pdf',
  `modulo` varchar(100) DEFAULT NULL,
  `proveedor` varchar(150) DEFAULT NULL,
  `fecha_generacion` datetime NOT NULL DEFAULT current_timestamp(),
  `total_registros` int(11) DEFAULT 0,
  `nombre_archivo` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_fecha` (`fecha_generacion`),
  KEY `idx_modulo` (`modulo`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Datos de tabla `historial_reportes`
INSERT INTO `historial_reportes` (`id`, `usuario_id`, `usuario_nombre`, `tipo_reporte`, `modulo`, `proveedor`, `fecha_generacion`, `total_registros`, `nombre_archivo`, `created_at`) VALUES ('1', '1', 'karmina Aranguthy Garcia', 'pdf', 'reporte de ventas - proveedor', 'Nevaris 3D', '2026-05-13 14:20:29', '29', 'uploads/Ventas_Proveedor/reporte_proveedor_Nevaris_3D_2026-05-13_1.pdf', '2026-05-13 14:20:29');
INSERT INTO `historial_reportes` (`id`, `usuario_id`, `usuario_nombre`, `tipo_reporte`, `modulo`, `proveedor`, `fecha_generacion`, `total_registros`, `nombre_archivo`, `created_at`) VALUES ('2', '1', 'karmina Aranguthy Garcia', 'pdf', 'reporte inventario - proveedor', 'Nevaris 3D', '2026-05-14 12:33:26', '53', 'uploads/Stock_Proveedor/reporte_inventario_Nevaris_3D_2026-05-14.pdf', '2026-05-14 12:33:26');
INSERT INTO `historial_reportes` (`id`, `usuario_id`, `usuario_nombre`, `tipo_reporte`, `modulo`, `proveedor`, `fecha_generacion`, `total_registros`, `nombre_archivo`, `created_at`) VALUES ('3', '1', 'karmina Aranguthy Garcia', 'pdf', 'reporte inventario - general', 'todos', '2026-05-14 12:33:44', '89', 'uploads/Stock_General/reporte_inventario_2026-05-14.pdf', '2026-05-14 12:33:44');

-- Estructura de tabla `historial_stock`
DROP TABLE IF EXISTS `historial_stock`;
CREATE TABLE `historial_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `producto_id` int(11) NOT NULL,
  `cantidad_anterior` decimal(10,2) NOT NULL,
  `cantidad_nueva` decimal(10,2) NOT NULL,
  `cantidad_agregada` decimal(10,2) NOT NULL,
  `tipo_movimiento` enum('entrada','salida','ajuste') NOT NULL,
  `nota` text DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `fecha_movimiento` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=60 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Datos de tabla `historial_stock`
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('1', '46', '22.00', '26.00', '4.00', 'ajuste', 'AJUSTE: estaban separados (diferencia: +4)', '1', '2026-03-21 13:38:49');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('2', '21', '0.00', '1.00', '1.00', 'ajuste', 'AJUSTE: no lo vi (diferencia: +1)', '1', '2026-03-21 13:39:29');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('3', '59', '0.00', '3.00', '3.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 12:27:15');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('4', '60', '0.00', '2.00', '2.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 12:28:10');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('5', '61', '0.00', '2.00', '2.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 12:30:42');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('6', '62', '0.00', '3.00', '3.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 12:32:13');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('7', '63', '0.00', '5.00', '5.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 12:33:34');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('8', '64', '0.00', '2.00', '2.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 12:34:45');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('9', '65', '0.00', '30.00', '30.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 12:35:29');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('10', '66', '0.00', '2.00', '2.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 12:38:27');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('11', '67', '0.00', '4.00', '4.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 12:39:45');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('12', '68', '0.00', '1.00', '1.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 12:41:23');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('13', '69', '0.00', '1.00', '1.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 12:42:21');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('14', '70', '0.00', '3.00', '3.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 12:43:31');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('15', '71', '0.00', '53.00', '53.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 12:49:33');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('16', '72', '0.00', '2.00', '2.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 12:51:56');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('17', '73', '0.00', '52.00', '52.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 12:58:51');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('18', '45', '5.00', '2.00', '3.00', 'ajuste', 'AJUSTE: Desaparecieron (diferencia: -3)', '1', '2026-03-29 13:01:10');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('19', '46', '26.00', '22.00', '4.00', 'ajuste', 'AJUSTE: puse de más (diferencia: -4)', '1', '2026-04-01 11:20:21');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('20', '74', '0.00', '12.00', '12.00', 'entrada', 'Registro inicial de producto', '1', '2026-04-03 12:04:09');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('21', '75', '0.00', '10.00', '10.00', 'entrada', 'Registro inicial de producto', '1', '2026-04-03 12:05:53');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('22', '76', '0.00', '5.00', '5.00', 'entrada', 'Registro inicial de producto', '1', '2026-04-03 12:08:00');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('23', '77', '0.00', '2.00', '2.00', 'entrada', 'Registro inicial de producto', '1', '2026-04-03 12:09:46');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('24', '78', '0.00', '4.00', '4.00', 'entrada', 'Registro inicial de producto', '1', '2026-04-03 12:10:42');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('25', '79', '0.00', '9.00', '9.00', 'entrada', 'Registro inicial de producto', '1', '2026-04-03 12:14:01');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('26', '80', '0.00', '4.00', '4.00', 'entrada', 'Registro inicial de producto', '1', '2026-04-03 12:15:23');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('27', '11', '3.00', '6.00', '3.00', 'entrada', 'Nueva compra', '1', '2026-04-03 12:22:00');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('28', '75', '10.00', '20.00', '10.00', 'entrada', 'Nuevo producto', '1', '2026-04-03 12:22:34');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('29', '54', '0.00', '8.00', '8.00', 'entrada', 'Nuevo producto', '1', '2026-04-03 12:22:55');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('30', '76', '5.00', '7.00', '2.00', 'entrada', 'Nuevo producto', '1', '2026-04-03 12:23:47');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('31', '1', '136.00', '237.00', '101.00', 'entrada', 'Nuevo producto', '1', '2026-04-03 12:26:22');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('32', '46', '22.00', '36.00', '14.00', 'entrada', 'Nuevo producto', '1', '2026-04-03 12:26:46');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('33', '51', '4.00', '7.00', '3.00', 'entrada', 'Nuevo producto', '1', '2026-04-03 12:27:45');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('34', '52', '3.00', '6.00', '3.00', 'entrada', 'Nuevo producto', '1', '2026-04-03 12:28:12');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('35', '13', '10.00', '13.00', '3.00', 'entrada', 'Nuevo producto', '1', '2026-04-03 12:30:10');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('36', '12', '5.00', '11.00', '6.00', 'entrada', 'Nuevo producto', '1', '2026-04-03 12:30:36');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('37', '16', '4.00', '12.00', '8.00', 'entrada', 'Nuevo producto', '1', '2026-04-03 12:31:55');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('38', '14', '3.00', '6.00', '3.00', 'entrada', 'Nuevo producto', '1', '2026-04-03 12:41:26');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('39', '19', '1.00', '2.00', '1.00', 'entrada', 'Nuevo producto', '1', '2026-04-03 12:43:53');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('40', '10', '4.00', '10.00', '6.00', 'entrada', 'Nuevo producto', '1', '2026-04-03 12:46:04');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('41', '75', '20.00', '10.00', '10.00', 'ajuste', 'AJUSTE: Error de conteo (diferencia: -10)', '1', '2026-04-03 12:48:18');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('42', '76', '7.00', '5.00', '2.00', 'ajuste', 'AJUSTE: Error de conteo (diferencia: -2)', '1', '2026-04-03 12:50:08');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('43', '81', '0.00', '1.00', '1.00', 'entrada', 'Registro inicial de producto', '1', '2026-05-01 07:53:31');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('44', '82', '0.00', '1.00', '1.00', 'entrada', 'Registro inicial de producto', '1', '2026-05-01 07:54:31');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('45', '83', '0.00', '1.00', '1.00', 'entrada', 'Registro inicial de producto', '1', '2026-05-01 07:55:26');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('46', '84', '0.00', '17.00', '17.00', 'entrada', 'Registro inicial de producto', '1', '2026-05-01 07:56:36');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('47', '85', '0.00', '1.00', '1.00', 'entrada', 'Registro inicial de producto', '1', '2026-05-01 07:57:33');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('48', '86', '0.00', '1.00', '1.00', 'entrada', 'Registro inicial de producto', '1', '2026-05-01 07:58:28');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('49', '87', '0.00', '1.00', '1.00', 'entrada', 'Registro inicial de producto', '1', '2026-05-01 07:58:29');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('50', '88', '0.00', '1.00', '1.00', 'entrada', 'Registro inicial de producto', '1', '2026-05-01 07:59:34');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('51', '89', '0.00', '5.00', '5.00', 'entrada', 'Registro inicial de producto', '1', '2026-05-01 08:00:40');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('52', '90', '0.00', '2.00', '2.00', 'entrada', 'Registro inicial de producto', '1', '2026-05-01 08:02:02');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('53', '91', '0.00', '1.00', '1.00', 'entrada', 'Registro inicial de producto', '1', '2026-05-01 08:03:19');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('54', '52', '3.00', '8.00', '5.00', 'entrada', 'agregue 5', '1', '2026-05-01 08:04:12');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('55', '91', '0.00', '10.00', '10.00', 'entrada', '', '1', '2026-05-03 20:03:28');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('56', '91', '10.00', '15.00', '5.00', 'entrada', '', '1', '2026-05-03 20:10:43');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('57', '91', '15.00', '20.00', '5.00', 'entrada', '', '1', '2026-05-03 20:14:25');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('58', '91', '20.00', '1.00', '-19.00', 'ajuste', 'AJUSTE: conte mal (diferencia: -19)', '1', '2026-05-03 20:14:59');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('59', '92', '0.00', '25.00', '25.00', 'entrada', 'Registro inicial de producto', '1', '2026-05-18 13:02:33');

-- Estructura de tabla `ordenes_pedido`
DROP TABLE IF EXISTS `ordenes_pedido`;
CREATE TABLE `ordenes_pedido` (
  `id_orden` int(11) NOT NULL AUTO_INCREMENT,
  `solicitado_por` varchar(100) DEFAULT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  `fecha_cancelacion` datetime DEFAULT NULL,
  PRIMARY KEY (`id_orden`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Datos de tabla `ordenes_pedido`
INSERT INTO `ordenes_pedido` (`id_orden`, `solicitado_por`, `fecha`, `fecha_cancelacion`) VALUES ('24', 'Karmina', '2026-05-06 14:15:19', '2026-05-06 16:21:15');
INSERT INTO `ordenes_pedido` (`id_orden`, `solicitado_por`, `fecha`, `fecha_cancelacion`) VALUES ('25', 'Juan', '2026-05-06 16:20:20', '2026-05-06 18:04:14');
INSERT INTO `ordenes_pedido` (`id_orden`, `solicitado_por`, `fecha`, `fecha_cancelacion`) VALUES ('26', 'richi', '2026-05-06 16:34:16', '2026-05-06 18:04:09');

-- Estructura de tabla `password_resets`
DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Estructura de tabla `pedidos`
DROP TABLE IF EXISTS `pedidos`;
CREATE TABLE `pedidos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_producto` int(11) DEFAULT NULL,
  `nombre_producto` varchar(255) DEFAULT NULL,
  `stock_actual` int(11) DEFAULT NULL,
  `cantidad_pedida` int(11) DEFAULT NULL,
  `faltante` int(11) DEFAULT NULL,
  `solicitado_por` varchar(100) DEFAULT NULL,
  `fecha` timestamp NOT NULL DEFAULT current_timestamp(),
  `id_orden` int(11) DEFAULT NULL,
  `estado` enum('pendiente','completado','cancelado') NOT NULL DEFAULT 'pendiente',
  `fecha_completado` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Datos de tabla `pedidos`
INSERT INTO `pedidos` (`id`, `id_producto`, `nombre_producto`, `stock_actual`, `cantidad_pedida`, `faltante`, `solicitado_por`, `fecha`, `id_orden`, `estado`, `fecha_completado`) VALUES ('29', '56', 'Amonita G', '1', '1', '0', 'Karmina', '2026-05-06 14:15:19', '24', 'cancelado', '2026-05-06 14:34:47');
INSERT INTO `pedidos` (`id`, `id_producto`, `nombre_producto`, `stock_actual`, `cantidad_pedida`, `faltante`, `solicitado_por`, `fecha`, `id_orden`, `estado`, `fecha_completado`) VALUES ('30', '91', 'amonita viva', '1', '1', '0', 'Karmina', '2026-05-06 14:15:19', '24', 'cancelado', '2026-05-06 14:34:47');
INSERT INTO `pedidos` (`id`, `id_producto`, `nombre_producto`, `stock_actual`, `cantidad_pedida`, `faltante`, `solicitado_por`, `fecha`, `id_orden`, `estado`, `fecha_completado`) VALUES ('31', '56', 'Amonita G', '0', '2', '0', 'Juan', '2026-05-06 16:20:20', '25', 'cancelado', '2026-05-06 16:33:27');
INSERT INTO `pedidos` (`id`, `id_producto`, `nombre_producto`, `stock_actual`, `cantidad_pedida`, `faltante`, `solicitado_por`, `fecha`, `id_orden`, `estado`, `fecha_completado`) VALUES ('32', '91', 'amonita viva', '0', '2', '0', 'Juan', '2026-05-06 16:20:20', '25', 'cancelado', '2026-05-06 16:33:27');
INSERT INTO `pedidos` (`id`, `id_producto`, `nombre_producto`, `stock_actual`, `cantidad_pedida`, `faltante`, `solicitado_por`, `fecha`, `id_orden`, `estado`, `fecha_completado`) VALUES ('33', '11', 'Ankilosaurio', '0', '3', '0', 'Juan', '2026-05-06 16:20:20', '25', 'cancelado', '2026-05-06 16:33:27');
INSERT INTO `pedidos` (`id`, `id_producto`, `nombre_producto`, `stock_actual`, `cantidad_pedida`, `faltante`, `solicitado_por`, `fecha`, `id_orden`, `estado`, `fecha_completado`) VALUES ('34', '56', 'Amonita G', '-1', '1', '2', 'richi', '2026-05-06 16:34:16', '26', 'cancelado', NULL);
INSERT INTO `pedidos` (`id`, `id_producto`, `nombre_producto`, `stock_actual`, `cantidad_pedida`, `faltante`, `solicitado_por`, `fecha`, `id_orden`, `estado`, `fecha_completado`) VALUES ('35', '91', 'amonita viva', '-1', '1', '2', 'richi', '2026-05-06 16:34:16', '26', 'cancelado', NULL);

-- Estructura de tabla `pedidos_log`
DROP TABLE IF EXISTS `pedidos_log`;
CREATE TABLE `pedidos_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_pedido` int(11) DEFAULT NULL,
  `accion` varchar(100) DEFAULT NULL,
  `detalle` text DEFAULT NULL,
  `usuario` varchar(100) DEFAULT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=193 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Datos de tabla `pedidos_log`
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('166', '24', 'PEDIDO CREADO', 'El usuario creó el pedido para: Karmina', 'karmina Aranguthy Garcia', '2026-05-06 14:15:19');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('167', '24', 'PRODUCTO AGREGADO', 'Producto: Amonita G | Stock actual: 1 | Pedido: 1 | Faltante: 0', 'karmina Aranguthy Garcia', '2026-05-06 14:15:19');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('168', '24', 'STOCK DESCONTADO', 'Se descontaron 1 unidades de Amonita G. Stock anterior: 1 | Stock actual: 0', 'karmina Aranguthy Garcia', '2026-05-06 14:15:19');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('169', '24', 'PRODUCTO AGREGADO', 'Producto: amonita viva | Stock actual: 1 | Pedido: 1 | Faltante: 0', 'karmina Aranguthy Garcia', '2026-05-06 14:15:19');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('170', '24', 'STOCK DESCONTADO', 'Se descontaron 1 unidades de amonita viva. Stock anterior: 1 | Stock actual: 0', 'karmina Aranguthy Garcia', '2026-05-06 14:15:19');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('171', '24', 'PRODUCTO COMPLETADO', 'Producto: Amonita G | Original: 1 | Completado: 1', 'karmina Aranguthy Garcia', '2026-05-06 14:34:25');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('172', '24', 'PRODUCTO COMPLETADO', 'Producto: amonita viva | Original: 1 | Completado: 1', 'karmina Aranguthy Garcia', '2026-05-06 14:34:47');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('173', '24', 'PEDIDO COMPLETADO', 'El pedido ha sido completado en su totalidad', 'karmina Aranguthy Garcia', '2026-05-06 14:34:47');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('174', '25', 'PEDIDO CREADO', 'El usuario creó el pedido para: Juan', 'karmina Aranguthy Garcia', '2026-05-06 16:20:20');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('175', '25', 'PRODUCTO AGREGADO', 'Producto: Amonita G | Stock actual: 0 | Pedido: 2 | Faltante: 2', 'karmina Aranguthy Garcia', '2026-05-06 16:20:20');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('176', '25', 'STOCK NEGATIVO', 'Stock INSUFICIENTE para Amonita G. Se descontaron 2 unidades. Stock anterior: 0 | Stock actual: -2 (NEGATIVO) | Faltante: 2', 'karmina Aranguthy Garcia', '2026-05-06 16:20:20');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('177', '25', 'PRODUCTO AGREGADO', 'Producto: amonita viva | Stock actual: 0 | Pedido: 2 | Faltante: 2', 'karmina Aranguthy Garcia', '2026-05-06 16:20:20');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('178', '25', 'STOCK NEGATIVO', 'Stock INSUFICIENTE para amonita viva. Se descontaron 2 unidades. Stock anterior: 0 | Stock actual: -2 (NEGATIVO) | Faltante: 2', 'karmina Aranguthy Garcia', '2026-05-06 16:20:20');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('179', '25', 'PRODUCTO AGREGADO', 'Producto: Ankilosaurio | Stock actual: 0 | Pedido: 3 | Faltante: 3', 'karmina Aranguthy Garcia', '2026-05-06 16:20:20');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('180', '25', 'STOCK NEGATIVO', 'Stock INSUFICIENTE para Ankilosaurio. Se descontaron 3 unidades. Stock anterior: 0 | Stock actual: -3 (NEGATIVO) | Faltante: 3', 'karmina Aranguthy Garcia', '2026-05-06 16:20:20');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('181', '24', 'Pedido completado cancelado', 'Se canceló un pedido que ya estaba completado (ticket PEDIDO-24). Stock restaurado. Estado actual: cancelado', 'karmina Aranguthy Garcia', '2026-05-06 16:21:15');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('182', '25', 'PRODUCTO COMPLETADO', 'Producto: Amonita G | Original: 2 | Completado: 0', 'karmina Aranguthy Garcia', '2026-05-06 16:33:27');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('183', '25', 'PRODUCTO COMPLETADO', 'Producto: amonita viva | Original: 2 | Completado: 0', 'karmina Aranguthy Garcia', '2026-05-06 16:33:27');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('184', '25', 'PRODUCTO COMPLETADO', 'Producto: Ankilosaurio | Original: 3 | Completado: 0', 'karmina Aranguthy Garcia', '2026-05-06 16:33:27');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('185', '25', 'PEDIDO COMPLETADO', 'El pedido ha sido completado en su totalidad', 'karmina Aranguthy Garcia', '2026-05-06 16:33:27');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('186', '26', 'PEDIDO CREADO', 'El usuario creó el pedido para: richi', 'karmina Aranguthy Garcia', '2026-05-06 16:34:16');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('187', '26', 'PRODUCTO AGREGADO', 'Producto: Amonita G | Stock actual: -1 | Pedido: 1 | Faltante: 2', 'karmina Aranguthy Garcia', '2026-05-06 16:34:16');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('188', '26', 'STOCK NEGATIVO', 'Stock INSUFICIENTE para Amonita G. Se descontaron 1 unidades. Stock anterior: -1 | Stock actual: -2 (NEGATIVO) | Faltante: 2', 'karmina Aranguthy Garcia', '2026-05-06 16:34:16');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('189', '26', 'PRODUCTO AGREGADO', 'Producto: amonita viva | Stock actual: -1 | Pedido: 1 | Faltante: 2', 'karmina Aranguthy Garcia', '2026-05-06 16:34:16');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('190', '26', 'STOCK NEGATIVO', 'Stock INSUFICIENTE para amonita viva. Se descontaron 1 unidades. Stock anterior: -1 | Stock actual: -2 (NEGATIVO) | Faltante: 2', 'karmina Aranguthy Garcia', '2026-05-06 16:34:16');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('191', '26', 'Pedido cancelado', 'Cancelación realizada desde ventas (ticket PEDIDO-26). Estado actual: cancelado', 'karmina Aranguthy Garcia', '2026-05-06 18:04:09');
INSERT INTO `pedidos_log` (`id`, `id_pedido`, `accion`, `detalle`, `usuario`, `fecha`) VALUES ('192', '25', 'Pedido completado cancelado', 'Se canceló un pedido que ya estaba completado (ticket PEDIDO-25). Stock restaurado. Estado actual: cancelado', 'karmina Aranguthy Garcia', '2026-05-06 18:04:14');

-- Estructura de tabla `pedidos_solventados`
DROP TABLE IF EXISTS `pedidos_solventados`;
CREATE TABLE `pedidos_solventados` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_pedido` int(11) NOT NULL,
  `id_producto` int(11) DEFAULT NULL,
  `cantidad_original` int(11) NOT NULL,
  `cantidad_solventada` int(11) NOT NULL,
  `cantidad_faltante` int(11) NOT NULL,
  `usuario` varchar(100) DEFAULT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=44 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Datos de tabla `pedidos_solventados`
INSERT INTO `pedidos_solventados` (`id`, `id_pedido`, `id_producto`, `cantidad_original`, `cantidad_solventada`, `cantidad_faltante`, `usuario`, `fecha`) VALUES ('39', '29', '56', '1', '1', '0', 'karmina Aranguthy Garcia', '2026-05-06 14:34:25');
INSERT INTO `pedidos_solventados` (`id`, `id_pedido`, `id_producto`, `cantidad_original`, `cantidad_solventada`, `cantidad_faltante`, `usuario`, `fecha`) VALUES ('40', '30', '91', '1', '1', '0', 'karmina Aranguthy Garcia', '2026-05-06 14:34:47');
INSERT INTO `pedidos_solventados` (`id`, `id_pedido`, `id_producto`, `cantidad_original`, `cantidad_solventada`, `cantidad_faltante`, `usuario`, `fecha`) VALUES ('41', '31', '56', '2', '0', '2', 'karmina Aranguthy Garcia', '2026-05-06 16:33:27');
INSERT INTO `pedidos_solventados` (`id`, `id_pedido`, `id_producto`, `cantidad_original`, `cantidad_solventada`, `cantidad_faltante`, `usuario`, `fecha`) VALUES ('42', '32', '91', '2', '0', '2', 'karmina Aranguthy Garcia', '2026-05-06 16:33:27');
INSERT INTO `pedidos_solventados` (`id`, `id_pedido`, `id_producto`, `cantidad_original`, `cantidad_solventada`, `cantidad_faltante`, `usuario`, `fecha`) VALUES ('43', '33', '11', '3', '0', '3', 'karmina Aranguthy Garcia', '2026-05-06 16:33:27');

-- Estructura de tabla `productos`
DROP TABLE IF EXISTS `productos`;
CREATE TABLE `productos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `categoria` varchar(50) NOT NULL,
  `atributos` text DEFAULT NULL,
  `proveedor` varchar(100) DEFAULT NULL,
  `proveedor_id` int(11) DEFAULT NULL,
  `imagen` varchar(255) DEFAULT NULL,
  `cantidad` int(11) NOT NULL,
  `precio_compra` decimal(10,2) NOT NULL,
  `precio_venta` decimal(10,2) NOT NULL,
  `tipo_codigo` enum('unico','multiple') NOT NULL DEFAULT 'multiple',
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  `activo` tinyint(1) DEFAULT 1,
  `tipo_inventario` enum('producto','insumo') DEFAULT 'producto',
  `tipo_adquisicion` enum('pagado','concesion') NOT NULL DEFAULT 'concesion',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=93 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Datos de tabla `productos`
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('1', 'Llaveros grandes', 'Llavero', NULL, 'Nevaris 3D', '1', 'uploads/productos/1772432060_69a52abc15070.jpg', '157', '20.00', '45.00', 'multiple', '2026-02-16 00:31:18', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('2', 'Llaveros Ch', 'Llavero', NULL, 'Nevaris 3D', '1', 'uploads/productos/1772432033_69a52aa1cd102.jpg', '102', '15.00', '35.00', 'multiple', '2026-02-16 00:33:46', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('3', 'Diente megalodon', 'Figuras', NULL, 'Nevaris 3D', '1', 'uploads/productos/1772432093_69a52add8be5b.jpeg', '1', '60.00', '120.00', 'multiple', '2026-02-16 00:37:16', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('4', 'Perro globo', 'Figuras', NULL, 'Nevaris 3D', '1', '', '3', '50.00', '110.00', 'multiple', '2026-02-16 00:40:05', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('5', 'Cabezones', 'Juguetes', NULL, 'centro', '2', '', '11', '25.00', '55.00', 'multiple', '2026-02-16 00:42:02', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('6', 'Dino bebé', 'Figuras', NULL, 'Nevaris 3D', '1', '', '0', '35.00', '70.00', 'multiple', '2026-02-16 00:43:14', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('7', 'Langosta', 'Figuras', NULL, 'Nevaris 3D', '1', '', '0', '50.00', '110.00', 'multiple', '2026-02-16 00:44:51', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('8', 'Megalodon G', 'Figuras', NULL, 'Nevaris 3D', '1', '', '5', '60.00', '120.00', 'multiple', '2026-02-16 00:46:11', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('9', 'Spinosaurio', 'Figuras', NULL, 'Nevaris 3D', '1', '', '8', '50.00', '110.00', 'multiple', '2026-02-16 00:47:37', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('10', 'Stegosaurio', 'Figuras', NULL, 'Nevaris 3D', '1', '', '10', '50.00', '110.00', 'multiple', '2026-02-16 00:51:25', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('11', 'Ankilosaurio', 'Figuras', NULL, 'Nevaris 3D', '1', '', '0', '50.00', '110.00', 'multiple', '2026-02-16 00:53:40', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('12', 'velocirraptor', 'Figuras', NULL, 'Nevaris 3D', '1', '', '5', '50.00', '120.00', 'multiple', '2026-02-16 00:55:08', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('13', 'T rex', 'Figuras', NULL, 'Nevaris 3D', '1', '', '12', '50.00', '110.00', 'multiple', '2026-02-16 00:55:41', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('14', 'Mamut', 'Figuras', NULL, 'Nevaris 3D', '1', '', '4', '50.00', '120.00', 'multiple', '2026-02-16 00:56:51', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('15', 'pez espinoso', 'Figuras', NULL, 'Nevaris 3D', '1', '', '4', '40.00', '90.00', 'multiple', '2026-02-16 00:57:54', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('16', 'Pangolin Ch', 'Figuras', NULL, 'Nevaris 3D', '1', '', '12', '50.00', '110.00', 'multiple', '2026-02-16 00:58:45', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('17', 'Tulipanes', 'Figuras', NULL, 'Nevaris 3D', '1', '', '5', '35.00', '70.00', 'multiple', '2026-02-16 01:01:20', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('18', 'Craneo ch', 'Figuras', NULL, 'Nevaris 3D', '1', '', '7', '50.00', '110.00', 'multiple', '2026-02-16 01:02:33', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('19', 'Craneo G', 'Figuras', NULL, 'Nevaris 3D', '1', '', '2', '240.00', '360.00', 'multiple', '2026-02-16 01:03:55', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('20', 'Pico de pato', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '40.00', '100.00', 'multiple', '2026-02-16 01:05:08', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('21', 'Ditto', 'Figuras', NULL, 'Nevaris 3D', '1', '', '0', '15.00', '35.00', 'multiple', '2026-02-16 01:06:36', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('22', 'Dragón', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '90.00', '150.00', 'multiple', '2026-02-16 01:07:05', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('23', 'Floreros', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '80.00', '140.00', 'multiple', '2026-02-16 01:08:19', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('24', 'Libretas', 'Libretas', NULL, 'Nevaris 3D', '1', '', '18', '110.00', '170.00', 'multiple', '2026-02-16 01:08:59', '1', 'producto', 'pagado');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('25', 'Porta tubos de ensayo', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '50.00', '110.00', 'multiple', '2026-02-16 01:10:58', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('26', 'Saltamontes peluche', 'Peluche', NULL, 'Aurora peluches', '3', '', '3', '130.00', '185.00', 'multiple', '2026-02-16 01:13:17', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('27', 'Armadillo peluche', 'Peluche', NULL, 'Aurora peluches', '3', '', '3', '130.00', '185.00', 'multiple', '2026-02-16 01:14:02', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('28', 'Nutria peluche', 'Peluche', NULL, 'Aurora peluches', '3', '', '1', '130.00', '185.00', 'multiple', '2026-02-16 01:14:51', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('29', 'Robin peluche', 'Peluche', NULL, 'Aurora peluches', '3', 'uploads/productos/1772431977_69a52a699c886.jpg', '1', '185.00', '240.00', 'multiple', '2026-02-16 01:17:16', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('30', 'Colibri peluche', 'Peluche', NULL, 'Aurora peluches', '3', '', '1', '185.00', '240.00', 'multiple', '2026-02-16 01:18:12', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('31', 'Ave azul', 'Peluche', NULL, 'Aurora peluches', '3', '', '1', '185.00', '240.00', 'multiple', '2026-02-16 01:18:46', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('32', 'Oso peresozo', 'Peluche', NULL, 'Aurora peluches', '3', '', '1', '130.00', '185.00', 'multiple', '2026-02-16 01:19:29', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('33', 'Chinchilla', 'Peluche', NULL, 'Aurora peluches', '3', '', '1', '130.00', '185.00', 'multiple', '2026-02-16 01:19:58', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('34', 'Stegosaurus peluche', 'Peluche', NULL, 'Aurora peluches', '3', '', '1', '260.00', '320.00', 'multiple', '2026-02-16 01:20:56', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('35', 'T rex peluche', 'Peluche', NULL, 'Aurora peluches', '3', '', '1', '260.00', '320.00', 'multiple', '2026-02-16 01:21:33', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('36', 'Pteronodon peluche', 'Peluche', NULL, 'Aurora peluches', '3', '', '1', '260.00', '320.00', 'unico', '2026-02-16 01:22:12', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('37', 'Termo taza', 'Utensilios', NULL, 'Smart print', '4', '', '8', '252.00', '360.00', 'multiple', '2026-02-16 01:22:55', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('38', 'Tortuga llavero', 'Llavero', NULL, 'centro cdmx', '5', '', '3', '35.00', '85.00', 'multiple', '2026-02-16 01:25:31', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('39', 'Llavero caracol', 'Llavero', NULL, 'centro cdmx', '5', '', '5', '20.00', '60.00', 'multiple', '2026-02-16 01:26:28', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('40', 'Llavero ambistoma', 'Llavero', NULL, 'centro cdmx', '5', '', '1', '20.00', '60.00', 'multiple', '2026-02-16 01:27:02', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('41', 'Llaveros dinosaurios hule', 'Llavero', NULL, 'centro cdmx', '5', '', '22', '20.00', '50.00', 'multiple', '2026-02-16 01:29:36', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('42', 'Gorras', 'Ropa', NULL, 'centro carmen', '6', '', '21', '75.00', '185.00', 'multiple', '2026-02-16 01:31:54', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('43', 'Playera estampada', 'Ropa', NULL, 'centro carmen', '6', '', '26', '65.00', '200.00', 'multiple', '2026-02-16 01:35:03', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('44', 'sudadera estampada', 'Ropa', NULL, 'centro carmen', '6', '', '11', '220.00', '360.00', 'multiple', '2026-02-16 01:37:36', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('45', 'Tote bag', 'Bolsas', NULL, 'centro carmen', '6', '', '4', '60.00', '120.00', 'multiple', '2026-02-16 01:38:39', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('46', 'Llaveros letras', 'Llavero', NULL, 'Nevaris 3D', '1', '', '14', '21.00', '45.00', 'multiple', '2026-03-06 23:35:18', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('47', 'T Rex Grande', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '250.00', '365.00', 'multiple', '2026-03-06 23:36:54', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('48', 'Veloci G', 'Figuras', NULL, 'Nevaris 3D', '1', '', '0', '250.00', '365.00', 'multiple', '2026-03-06 23:37:41', '0', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('49', 'Protoceratops', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '50.00', '120.00', 'multiple', '2026-03-06 23:38:44', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('50', 'Mosasaurio', 'Figuras', NULL, 'Nevaris 3D', '1', '', '4', '60.00', '130.00', 'multiple', '2026-03-06 23:39:53', '0', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('51', 'Mosasaurio', 'Figuras', NULL, 'Nevaris 3D', '1', '', '2', '60.00', '130.00', 'multiple', '2026-03-06 23:39:54', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('52', 'Pterodactilo', 'Figuras', NULL, 'Nevaris 3D', '1', '', '8', '50.00', '120.00', 'multiple', '2026-03-06 23:40:52', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('53', 'Craneo Gigante Terap', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '320.00', '430.00', 'multiple', '2026-03-06 23:41:46', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('54', 'Aretes', 'Bisuteria', NULL, 'Nevaris 3D', '1', '', '0', '22.00', '55.00', 'multiple', '2026-03-06 23:42:45', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('55', 'Letras Tlayúa', 'Figuras', NULL, 'Nevaris 3D', '1', '', '0', '170.00', '250.00', 'multiple', '2026-03-06 23:44:55', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('56', 'Amonita G', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '50.00', '120.00', 'multiple', '2026-03-13 17:48:01', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('57', 'Patitos Ch', 'Figuras', NULL, 'Nevaris 3D', '1', '', '8', '5.00', '10.00', 'multiple', '2026-03-13 17:48:53', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('58', 'Cuadro ch cerebro', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '100.00', '170.00', 'multiple', '2026-03-13 17:50:06', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('59', 'Bolsa cuadrada', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', NULL, '', '3', '140.00', '200.00', 'multiple', '2026-03-29 18:27:15', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('60', 'Carpeta oficio', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', NULL, '', '2', '150.00', '265.00', 'multiple', '2026-03-29 18:28:10', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('61', 'Cartera plana', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', NULL, '', '2', '100.00', '160.00', 'multiple', '2026-03-29 18:30:42', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('62', 'Monedero mediano', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', NULL, '', '3', '40.00', '75.00', 'multiple', '2026-03-29 18:32:13', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('63', 'Monedero grande', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', NULL, '', '5', '50.00', '85.00', 'multiple', '2026-03-29 18:33:34', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('64', 'Cartera doble cierre', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', NULL, '', '2', '140.00', '200.00', 'multiple', '2026-03-29 18:34:45', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('65', 'Mantelitos', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', NULL, '', '30', '15.00', '35.00', 'multiple', '2026-03-29 18:35:29', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('66', 'Bolsa cartera', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', NULL, '', '2', '170.00', '235.00', 'multiple', '2026-03-29 18:38:27', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('67', 'Lapiceras', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', NULL, '', '4', '60.00', '95.00', 'multiple', '2026-03-29 18:39:45', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('68', 'Bolsa de colgar chica', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', NULL, '', '1', '60.00', '115.00', 'multiple', '2026-03-29 18:41:23', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('69', 'Bolsa de colgar mediana', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', NULL, '', '1', '80.00', '135.00', 'multiple', '2026-03-29 18:42:21', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('70', 'Monedero chico', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', NULL, '', '3', '30.00', '45.00', 'multiple', '2026-03-29 18:43:31', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('71', 'Taza de cerámica', 'Sublimado', NULL, 'Publicidad Impresa', '8', '', '53', '33.00', '100.00', 'multiple', '2026-03-29 18:49:33', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('72', 'Libreta de tela', 'Papeleria', NULL, 'Smart print', '4', '', '2', '80.00', '135.00', 'multiple', '2026-03-29 18:51:56', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('73', 'Posters', 'Papeleria', NULL, 'Qubits', '9', '', '50', '11.00', '35.00', 'multiple', '2026-03-29 18:58:51', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('74', 'Dinos G', 'Impresión 3D', NULL, 'Nevaris 3D', '1', '', '9', '250.00', '350.00', 'multiple', '2026-04-03 18:04:09', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('75', 'Anomalocaris', 'Impresión 3D', NULL, 'Nevaris 3D', '1', '', '2', '60.00', '130.00', 'multiple', '2026-04-03 18:05:53', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('76', 'Cactus porta lapicero', 'Impresión 3D', NULL, 'Nevaris 3D', '1', '', '4', '75.00', '125.00', 'multiple', '2026-04-03 18:08:00', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('77', 'Lampara luna', 'Impresión 3D', NULL, 'Nevaris 3D', '1', '', '2', '220.00', '290.00', 'multiple', '2026-04-03 18:09:46', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('78', 'Cuadro cráneo', 'Impresión 3D', NULL, 'Nevaris 3D', '1', '', '1', '180.00', '250.00', 'multiple', '2026-04-03 18:10:42', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('79', 'Octopus', 'Impresión 3D', NULL, 'Nevaris 3D', '1', '', '5', '60.00', '130.00', 'multiple', '2026-04-03 18:14:01', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('80', 'Mosasaurio ch', 'Impresión 3D', NULL, 'Nevaris 3D', '1', '', '4', '45.00', '70.00', 'multiple', '2026-04-03 18:15:23', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('81', 'cangrejo portalapicero', 'Impresión 3D', NULL, 'Nevaris 3D', NULL, '', '0', '50.00', '100.00', 'multiple', '2026-05-01 13:53:31', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('82', 'iguana', 'Impresión 3D', NULL, 'Nevaris 3D', NULL, '', '1', '60.00', '120.00', 'multiple', '2026-05-01 13:54:31', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('83', 'pingüino alajero', 'Impresión 3D', NULL, 'Nevaris 3D', NULL, '', '1', '60.00', '119.98', 'multiple', '2026-05-01 13:55:26', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('84', 'Pasadores dinos', 'Impresión 3D', NULL, 'Nevaris 3D', NULL, '', '17', '25.00', '45.00', 'multiple', '2026-05-01 13:56:36', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('85', 'T Rex figura', 'Impresión 3D', NULL, 'Nevaris 3D', NULL, '', '1', '50.00', '120.00', 'multiple', '2026-05-01 13:57:33', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('86', 'caracola', 'Impresión 3D', NULL, 'Nevaris 3D', '1', '', '1', '45.00', '90.00', '', '2026-05-01 13:58:28', '1', 'producto', 'pagado');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('87', 'caracola', 'Impresión 3D', NULL, 'Nevaris 3D', '1', '', '1', '45.00', '90.00', '', '2026-05-01 13:58:29', '1', 'producto', 'concesion');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('88', 'tetrix', 'Impresión 3D', NULL, 'Nevaris 3D', '1', '', '1', '80.00', '145.00', '', '2026-05-01 13:59:34', '1', 'producto', 'pagado');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('89', 'Imanes 3D', 'Impresión 3D', NULL, 'Nevaris 3D', '1', '', '5', '20.00', '34.99', 'multiple', '2026-05-01 14:00:40', '1', 'producto', 'pagado');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('90', 'Huevo dragón', 'Impresión 3D', NULL, 'Nevaris 3D', '1', '', '2', '90.00', '145.00', 'multiple', '2026-05-01 14:02:02', '1', 'producto', 'pagado');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('91', 'amonita viva', 'Impresión 3D', NULL, 'Nevaris 3D', '1', 'uploads/productos/amonita_viva.jpeg', '1', '65.00', '135.00', '', '2026-05-01 14:03:19', '1', 'producto', 'pagado');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`, `tipo_adquisicion`) VALUES ('92', 'Prueba', 'CategoriaPrueba', NULL, 'ProveedorPrueba', '14', 'uploads/productos/Prueba.jpg', '25', '10.00', '50.00', '', '2026-05-18 13:02:33', '1', 'producto', 'pagado');

-- Estructura de tabla `proveedores`
DROP TABLE IF EXISTS `proveedores`;
CREATE TABLE `proveedores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `correo` varchar(100) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `calle` varchar(200) DEFAULT NULL,
  `numero` varchar(20) DEFAULT NULL,
  `colonia` varchar(100) DEFAULT NULL,
  `ciudad` varchar(100) DEFAULT NULL,
  `estado` varchar(100) DEFAULT NULL,
  `codigo_postal` varchar(10) DEFAULT NULL,
  `pais` varchar(50) DEFAULT 'M�xico',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Datos de tabla `proveedores`
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('1', 'Nevaris 3D', '', '', 'uploads/proveedores/Nevaris_3D.jpeg', '1', '2026-04-18 16:46:27', '', '', '', '', '', '', 'México');
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('2', 'centro', NULL, NULL, NULL, '1', '2026-04-18 16:46:27', NULL, NULL, NULL, NULL, NULL, NULL, 'M�xico');
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('3', 'Aurora peluches', NULL, NULL, NULL, '1', '2026-04-18 16:46:27', NULL, NULL, NULL, NULL, NULL, NULL, 'M�xico');
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('4', 'Smart print', NULL, NULL, NULL, '1', '2026-04-18 16:46:27', NULL, NULL, NULL, NULL, NULL, NULL, 'M�xico');
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('5', 'centro cdmx', NULL, NULL, NULL, '1', '2026-04-18 16:46:27', NULL, NULL, NULL, NULL, NULL, NULL, 'M�xico');
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('6', 'centro carmen', '', '', 'uploads/proveedores/centro_carmen.jpg', '1', '2026-04-18 16:46:27', '', '', '', '', '', '', 'México');
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('7', 'Artesan�as Gloria (Huatlatlauca)', '', '2241009956', NULL, '1', '2026-04-18 16:46:27', '', '', '', '', '', '', 'M�xico');
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('8', 'Publicidad Impresa', NULL, NULL, NULL, '1', '2026-04-18 16:46:27', NULL, NULL, NULL, NULL, NULL, NULL, 'M�xico');
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('9', 'Qubits', NULL, NULL, NULL, '1', '2026-04-18 16:46:27', NULL, NULL, NULL, NULL, NULL, NULL, 'M�xico');
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('14', 'ProveedorPrueba', NULL, NULL, NULL, '1', '2026-05-19 14:34:08', NULL, NULL, NULL, NULL, NULL, NULL, 'M�xico');

-- Estructura de tabla `reporte_proveedor`
DROP TABLE IF EXISTS `reporte_proveedor`;
CREATE TABLE `reporte_proveedor` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `proveedor` varchar(100) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `stock_inicial` int(11) NOT NULL,
  `stock_contado` int(11) NOT NULL,
  `ventas` int(11) NOT NULL,
  `fecha_conteo` date NOT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_reporte` (`producto_id`,`fecha_conteo`),
  UNIQUE KEY `uk_reporte` (`proveedor`,`producto_id`,`fecha_conteo`),
  UNIQUE KEY `uk_producto_fecha` (`producto_id`,`fecha_conteo`),
  KEY `idx_proveedor_fecha` (`proveedor`,`fecha_conteo`),
  KEY `idx_producto` (`producto_id`)
) ENGINE=InnoDB AUTO_INCREMENT=250 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Datos de tabla `reporte_proveedor`
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('1', 'Nevaris 3D', '1', '165', '162', '3', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('2', 'Nevaris 3D', '2', '126', '115', '11', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('3', 'Nevaris 3D', '3', '3', '3', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('4', 'Nevaris 3D', '4', '3', '3', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('5', 'Nevaris 3D', '6', '5', '5', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('6', 'Nevaris 3D', '7', '1', '1', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('7', 'Nevaris 3D', '8', '5', '5', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('8', 'Nevaris 3D', '9', '7', '7', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('9', 'Nevaris 3D', '10', '6', '5', '1', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('10', 'Nevaris 3D', '11', '7', '7', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('11', 'Nevaris 3D', '12', '9', '9', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('12', 'Nevaris 3D', '13', '10', '10', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('13', 'Nevaris 3D', '14', '4', '3', '1', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('14', 'Nevaris 3D', '15', '4', '4', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('15', 'Nevaris 3D', '16', '10', '10', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('16', 'Nevaris 3D', '17', '5', '5', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('17', 'Nevaris 3D', '18', '7', '7', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('18', 'Nevaris 3D', '19', '1', '1', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('19', 'Nevaris 3D', '20', '1', '1', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('20', 'Nevaris 3D', '21', '1', '1', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('21', 'Nevaris 3D', '22', '1', '1', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('22', 'Nevaris 3D', '23', '2', '2', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('23', 'Nevaris 3D', '24', '28', '0', '28', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('24', 'Nevaris 3D', '25', '1', '1', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('25', 'Nevaris 3D', '46', '36', '28', '8', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('26', 'Nevaris 3D', '47', '1', '1', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('27', 'Nevaris 3D', '48', '1', '1', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('28', 'Nevaris 3D', '49', '1', '1', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('29', 'Nevaris 3D', '50', '6', '6', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('30', 'Nevaris 3D', '51', '6', '6', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('31', 'Nevaris 3D', '52', '6', '6', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('32', 'Nevaris 3D', '53', '1', '1', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('33', 'Nevaris 3D', '54', '4', '0', '4', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('34', 'Nevaris 3D', '55', '1', '1', '0', '2026-03-07', '2026-03-07 20:41:15', '2026-03-07 20:41:15');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('35', 'centro carmen', '42', '25', '17', '8', '2026-03-07', '2026-03-07 21:11:43', '2026-03-07 21:11:43');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('36', 'centro carmen', '43', '16', '11', '5', '2026-03-07', '2026-03-07 21:11:43', '2026-03-07 21:11:43');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('37', 'centro carmen', '44', '9', '7', '2', '2026-03-07', '2026-03-07 21:11:43', '2026-03-07 21:11:43');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('38', 'centro carmen', '45', '5', '5', '0', '2026-03-07', '2026-03-07 21:11:43', '2026-03-07 21:11:43');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('39', 'Smart print', '37', '4', '2', '2', '2026-03-07', '2026-03-07 21:25:24', '2026-03-07 21:25:24');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('40', 'Nevaris 3D', '1', '162', '151', '11', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('41', 'Nevaris 3D', '2', '115', '84', '31', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('42', 'Nevaris 3D', '3', '3', '3', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('43', 'Nevaris 3D', '4', '3', '3', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('44', 'Nevaris 3D', '6', '5', '5', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('45', 'Nevaris 3D', '7', '1', '1', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('46', 'Nevaris 3D', '8', '5', '5', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('47', 'Nevaris 3D', '9', '7', '7', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('48', 'Nevaris 3D', '10', '5', '4', '1', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('49', 'Nevaris 3D', '11', '7', '6', '1', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('50', 'Nevaris 3D', '12', '9', '6', '3', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('51', 'Nevaris 3D', '13', '10', '10', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('52', 'Nevaris 3D', '14', '3', '3', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('53', 'Nevaris 3D', '15', '4', '4', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('54', 'Nevaris 3D', '16', '10', '5', '5', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('55', 'Nevaris 3D', '17', '5', '5', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('56', 'Nevaris 3D', '18', '7', '7', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('57', 'Nevaris 3D', '19', '1', '1', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('58', 'Nevaris 3D', '20', '1', '1', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('59', 'Nevaris 3D', '21', '1', '0', '1', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('60', 'Nevaris 3D', '22', '1', '1', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('61', 'Nevaris 3D', '23', '2', '2', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('62', 'Nevaris 3D', '24', '24', '16', '8', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('63', 'Nevaris 3D', '25', '1', '1', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('64', 'Nevaris 3D', '46', '28', '22', '6', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('65', 'Nevaris 3D', '47', '1', '1', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('66', 'Nevaris 3D', '48', '1', '0', '1', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('67', 'Nevaris 3D', '49', '1', '1', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('68', 'Nevaris 3D', '50', '6', '4', '2', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('69', 'Nevaris 3D', '51', '6', '4', '2', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('70', 'Nevaris 3D', '52', '6', '4', '2', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('71', 'Nevaris 3D', '53', '1', '1', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('72', 'Nevaris 3D', '54', '0', '0', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('73', 'Nevaris 3D', '55', '1', '1', '0', '2026-03-13', '2026-03-13 17:46:29', '2026-03-13 17:46:29');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('74', 'Nevaris 3D', '56', '1', '1', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('75', 'Nevaris 3D', '11', '6', '3', '3', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('76', 'Nevaris 3D', '54', '0', '0', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('77', 'Nevaris 3D', '18', '7', '6', '1', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('78', 'Nevaris 3D', '19', '1', '1', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('79', 'Nevaris 3D', '53', '1', '1', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('80', 'Nevaris 3D', '58', '1', '1', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('81', 'Nevaris 3D', '3', '3', '3', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('82', 'Nevaris 3D', '6', '5', '5', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('83', 'Nevaris 3D', '21', '0', '0', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('84', 'Nevaris 3D', '22', '1', '1', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('85', 'Nevaris 3D', '23', '2', '2', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('86', 'Nevaris 3D', '7', '1', '1', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('87', 'Nevaris 3D', '55', '1', '1', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('88', 'Nevaris 3D', '24', '16', '16', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('89', 'Nevaris 3D', '2', '84', '78', '6', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('90', 'Nevaris 3D', '1', '151', '136', '15', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('91', 'Nevaris 3D', '46', '22', '22', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('92', 'Nevaris 3D', '14', '3', '3', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('93', 'Nevaris 3D', '8', '5', '5', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('94', 'Nevaris 3D', '51', '4', '4', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('95', 'Nevaris 3D', '16', '5', '4', '1', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('96', 'Nevaris 3D', '57', '8', '8', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('97', 'Nevaris 3D', '4', '3', '3', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('98', 'Nevaris 3D', '15', '4', '4', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('99', 'Nevaris 3D', '20', '1', '1', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('100', 'Nevaris 3D', '25', '1', '1', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('101', 'Nevaris 3D', '49', '1', '1', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('102', 'Nevaris 3D', '52', '4', '3', '1', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('103', 'Nevaris 3D', '9', '7', '5', '2', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('104', 'Nevaris 3D', '10', '4', '4', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('105', 'Nevaris 3D', '13', '10', '10', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('106', 'Nevaris 3D', '47', '1', '0', '1', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('107', 'Nevaris 3D', '17', '5', '5', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('108', 'Nevaris 3D', '48', '0', '0', '0', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('109', 'Nevaris 3D', '12', '6', '5', '1', '2026-03-21', '2026-03-21 19:31:14', '2026-03-21 19:31:14');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('110', 'Nevaris 3D', '56', '1', '1', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('111', 'Nevaris 3D', '11', '6', '4', '2', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('112', 'Nevaris 3D', '75', '10', '9', '1', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('113', 'Nevaris 3D', '54', '8', '7', '1', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('114', 'Nevaris 3D', '76', '5', '4', '1', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('115', 'Nevaris 3D', '18', '6', '6', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('116', 'Nevaris 3D', '19', '2', '2', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('117', 'Nevaris 3D', '53', '1', '1', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('118', 'Nevaris 3D', '58', '1', '1', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('119', 'Nevaris 3D', '78', '4', '2', '2', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('120', 'Nevaris 3D', '3', '3', '3', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('121', 'Nevaris 3D', '6', '5', '0', '5', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('122', 'Nevaris 3D', '74', '12', '9', '3', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('123', 'Nevaris 3D', '21', '1', '1', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('124', 'Nevaris 3D', '22', '1', '1', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('125', 'Nevaris 3D', '23', '2', '2', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('126', 'Nevaris 3D', '77', '2', '2', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('127', 'Nevaris 3D', '7', '1', '0', '1', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('128', 'Nevaris 3D', '55', '1', '0', '1', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('129', 'Nevaris 3D', '24', '16', '16', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('130', 'Nevaris 3D', '2', '78', '76', '2', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('131', 'Nevaris 3D', '1', '237', '199', '38', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('132', 'Nevaris 3D', '46', '36', '35', '1', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('133', 'Nevaris 3D', '14', '6', '6', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('134', 'Nevaris 3D', '8', '5', '5', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('135', 'Nevaris 3D', '51', '7', '6', '1', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('136', 'Nevaris 3D', '80', '4', '4', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('137', 'Nevaris 3D', '79', '9', '9', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('138', 'Nevaris 3D', '16', '12', '9', '3', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('139', 'Nevaris 3D', '57', '8', '8', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('140', 'Nevaris 3D', '4', '3', '3', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('141', 'Nevaris 3D', '15', '4', '4', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('142', 'Nevaris 3D', '20', '1', '1', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('143', 'Nevaris 3D', '25', '1', '1', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('144', 'Nevaris 3D', '49', '1', '1', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('145', 'Nevaris 3D', '52', '6', '5', '1', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('146', 'Nevaris 3D', '9', '5', '5', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('147', 'Nevaris 3D', '10', '10', '10', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('148', 'Nevaris 3D', '13', '13', '12', '1', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('149', 'Nevaris 3D', '47', '0', '0', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('150', 'Nevaris 3D', '17', '5', '5', '0', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('151', 'Nevaris 3D', '12', '11', '10', '1', '2026-04-03', '2026-04-03 18:59:01', '2026-04-03 18:59:01');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('152', 'Nevaris 3D', '56', '1', '1', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('153', 'Nevaris 3D', '11', '4', '3', '1', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('154', 'Nevaris 3D', '75', '9', '9', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('155', 'Nevaris 3D', '54', '7', '5', '2', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('156', 'Nevaris 3D', '76', '4', '4', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('157', 'Nevaris 3D', '18', '6', '6', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('158', 'Nevaris 3D', '19', '2', '2', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('159', 'Nevaris 3D', '53', '1', '1', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('160', 'Nevaris 3D', '58', '1', '1', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('161', 'Nevaris 3D', '78', '2', '1', '1', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('162', 'Nevaris 3D', '3', '3', '3', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('163', 'Nevaris 3D', '6', '0', '0', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('164', 'Nevaris 3D', '74', '9', '9', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('165', 'Nevaris 3D', '21', '1', '1', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('166', 'Nevaris 3D', '22', '1', '1', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('167', 'Nevaris 3D', '23', '2', '1', '1', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('168', 'Nevaris 3D', '77', '2', '2', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('169', 'Nevaris 3D', '7', '0', '0', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('170', 'Nevaris 3D', '55', '0', '0', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('171', 'Nevaris 3D', '24', '16', '16', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('172', 'Nevaris 3D', '2', '76', '76', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('173', 'Nevaris 3D', '1', '199', '176', '23', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('174', 'Nevaris 3D', '46', '35', '27', '8', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('175', 'Nevaris 3D', '14', '6', '5', '1', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('176', 'Nevaris 3D', '8', '5', '5', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('177', 'Nevaris 3D', '51', '6', '4', '2', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('178', 'Nevaris 3D', '80', '4', '4', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('179', 'Nevaris 3D', '79', '9', '5', '4', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('180', 'Nevaris 3D', '16', '9', '7', '2', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('181', 'Nevaris 3D', '57', '8', '8', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('182', 'Nevaris 3D', '4', '3', '3', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('183', 'Nevaris 3D', '15', '4', '4', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('184', 'Nevaris 3D', '20', '1', '1', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('185', 'Nevaris 3D', '25', '1', '1', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('186', 'Nevaris 3D', '49', '1', '1', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('187', 'Nevaris 3D', '52', '5', '3', '2', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('188', 'Nevaris 3D', '9', '5', '4', '1', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('189', 'Nevaris 3D', '10', '10', '8', '2', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('190', 'Nevaris 3D', '13', '12', '11', '1', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('191', 'Nevaris 3D', '47', '0', '0', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('192', 'Nevaris 3D', '17', '5', '5', '0', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('193', 'Nevaris 3D', '12', '10', '7', '3', '2026-04-10', '2026-04-10 18:49:36', '2026-04-10 18:49:36');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('194', 'Nevaris 3D', '56', '1', '1', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('195', 'Nevaris 3D', '91', '1', '0', '1', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('196', 'Nevaris 3D', '11', '3', '1', '2', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('197', 'Nevaris 3D', '75', '9', '7', '2', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('198', 'Nevaris 3D', '54', '5', '0', '5', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('199', 'Nevaris 3D', '76', '4', '4', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('200', 'Nevaris 3D', '81', '1', '0', '1', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('201', 'Nevaris 3D', '87', '1', '1', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('202', 'Nevaris 3D', '86', '1', '1', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('203', 'Nevaris 3D', '18', '6', '6', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('204', 'Nevaris 3D', '19', '2', '2', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('205', 'Nevaris 3D', '53', '1', '1', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('206', 'Nevaris 3D', '58', '1', '1', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('207', 'Nevaris 3D', '78', '1', '1', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('208', 'Nevaris 3D', '3', '3', '3', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('209', 'Nevaris 3D', '6', '0', '0', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('210', 'Nevaris 3D', '74', '9', '9', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('211', 'Nevaris 3D', '21', '1', '0', '1', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('212', 'Nevaris 3D', '22', '1', '1', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('213', 'Nevaris 3D', '23', '1', '1', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('214', 'Nevaris 3D', '90', '2', '2', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('215', 'Nevaris 3D', '82', '1', '1', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('216', 'Nevaris 3D', '89', '5', '5', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('217', 'Nevaris 3D', '77', '2', '2', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('218', 'Nevaris 3D', '7', '0', '0', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('219', 'Nevaris 3D', '55', '0', '0', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('220', 'Nevaris 3D', '24', '16', '16', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('221', 'Nevaris 3D', '2', '76', '76', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('222', 'Nevaris 3D', '1', '176', '132', '44', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('223', 'Nevaris 3D', '46', '27', '14', '13', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('224', 'Nevaris 3D', '14', '5', '3', '2', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('225', 'Nevaris 3D', '8', '5', '5', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('226', 'Nevaris 3D', '51', '4', '2', '2', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('227', 'Nevaris 3D', '80', '4', '4', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('228', 'Nevaris 3D', '79', '5', '5', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('229', 'Nevaris 3D', '16', '7', '7', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('230', 'Nevaris 3D', '84', '17', '17', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('231', 'Nevaris 3D', '57', '8', '8', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('232', 'Nevaris 3D', '4', '3', '3', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('233', 'Nevaris 3D', '15', '4', '4', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('234', 'Nevaris 3D', '20', '1', '1', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('235', 'Nevaris 3D', '83', '1', '1', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('236', 'Nevaris 3D', '25', '1', '1', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('237', 'Nevaris 3D', '49', '1', '1', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('238', 'Nevaris 3D', '52', '8', '7', '1', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('239', 'Nevaris 3D', '9', '4', '2', '2', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('240', 'Nevaris 3D', '10', '8', '8', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('241', 'Nevaris 3D', '13', '11', '11', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('242', 'Nevaris 3D', '85', '1', '1', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('243', 'Nevaris 3D', '47', '0', '0', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('244', 'Nevaris 3D', '88', '1', '1', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('245', 'Nevaris 3D', '17', '5', '5', '0', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('246', 'Nevaris 3D', '12', '7', '4', '3', '2026-05-01', '2026-05-01 14:37:27', '2026-05-01 14:37:27');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('247', 'Nevaris 3D', '56', '1', '0', '1', '2026-05-03', '2026-05-03 15:50:12', '2026-05-03 15:50:12');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('248', 'Nevaris 3D', '11', '5', '0', '5', '2026-05-03', '2026-05-03 15:50:12', '2026-05-03 15:50:12');
INSERT INTO `reporte_proveedor` (`id`, `proveedor`, `producto_id`, `stock_inicial`, `stock_contado`, `ventas`, `fecha_conteo`, `fecha_registro`, `fecha_actualizacion`) VALUES ('249', 'Nevaris 3D', '75', '7', '2', '5', '2026-05-03', '2026-05-03 15:50:12', '2026-05-03 15:50:12');

-- Estructura de tabla `usuarios`
DROP TABLE IF EXISTS `usuarios`;
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `rol` enum('administrador','vendedor') NOT NULL DEFAULT 'vendedor',
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  `activo` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `debe_cambiar_password` tinyint(1) NOT NULL DEFAULT 0,
  `foto_perfil` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Datos de tabla `usuarios`
INSERT INTO `usuarios` (`id`, `nombre`, `email`, `password`, `rol`, `fecha_registro`, `activo`, `created_by`, `debe_cambiar_password`, `foto_perfil`) VALUES ('1', 'karmina Aranguthy Garcia', 'karmina.aranguthy@hotmail.com', '$2y$10$lp7mbpd/j38Tz86BoGLJF.VvtX34qY1MUy6T6Xw2aytvvD8HXg42q', 'administrador', '2025-10-26 14:47:46', '1', NULL, '0', 'uploads/perfiles/karmina_aranguthy_garcia_1.jpeg');
INSERT INTO `usuarios` (`id`, `nombre`, `email`, `password`, `rol`, `fecha_registro`, `activo`, `created_by`, `debe_cambiar_password`, `foto_perfil`) VALUES ('2', 'Jesús Gabriel Martínez Vidal', 'jesus@gmail.com', '$2y$10$eR1O5YA9uR6dEY0gLwP2PefNoCBnWuBrt2coFf61dEun671yW.YCe', 'vendedor', '2025-10-26 15:03:04', '1', NULL, '0', 'uploads/perfiles/jes__s_gabriel_mart__nez_vidal_2.jpg');

-- Estructura de tabla `ventas`
DROP TABLE IF EXISTS `ventas`;
CREATE TABLE `ventas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `folio_ticket` varchar(50) DEFAULT NULL,
  `id_orden` int(11) DEFAULT NULL,
  `id_producto` int(11) NOT NULL,
  `id_vendedor` int(11) DEFAULT NULL,
  `cantidad_vendida` int(11) NOT NULL,
  `correo_cliente` varchar(100) DEFAULT NULL,
  `metodo_pago` varchar(20) DEFAULT NULL,
  `referencia_pago` varchar(50) DEFAULT NULL,
  `fecha_venta` timestamp NOT NULL DEFAULT current_timestamp(),
  `ticket_pdf` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_producto` (`id_producto`),
  CONSTRAINT `ventas_ibfk_1` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=159 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Datos de tabla `ventas`
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('21', 'VENTA_2026-03-07_07:11:02-8350', NULL, '43', NULL, '3', NULL, NULL, NULL, '2026-03-06 18:11:02', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('22', 'VENTA_69ace1cbc1278', NULL, '1', NULL, '3', NULL, NULL, NULL, '2026-03-07 14:41:15', 'ticket_VENTA_69ace1cbc1278.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('23', 'VENTA_69ace1cbc1278', NULL, '2', NULL, '11', NULL, NULL, NULL, '2026-03-07 14:41:15', 'ticket_VENTA_69ace1cbc1278.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('24', 'VENTA_69ace1cbc1278', NULL, '10', NULL, '1', NULL, NULL, NULL, '2026-03-07 14:41:15', 'ticket_VENTA_69ace1cbc1278.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('25', 'VENTA_69ace1cbc1278', NULL, '14', NULL, '1', NULL, NULL, NULL, '2026-03-07 14:41:15', 'ticket_VENTA_69ace1cbc1278.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('26', 'VENTA_69ace1cbc1278', NULL, '24', NULL, '4', NULL, NULL, NULL, '2026-03-07 14:41:15', 'ticket_VENTA_69ace1cbc1278.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('27', 'VENTA_69ace1cbc1278', NULL, '46', NULL, '8', NULL, NULL, NULL, '2026-03-07 14:41:15', 'ticket_VENTA_69ace1cbc1278.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('28', 'VENTA_69ace1cbc1278', NULL, '54', NULL, '4', NULL, NULL, NULL, '2026-03-07 14:41:15', 'ticket_VENTA_69ace1cbc1278.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('29', 'VENTA_69ace8efcd6a0', NULL, '42', NULL, '8', NULL, NULL, NULL, '2026-03-07 15:11:43', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('30', 'VENTA_69ace8efcd6a0', NULL, '43', NULL, '5', NULL, NULL, NULL, '2026-03-07 15:11:43', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('31', 'VENTA_69ace8efcd6a0', NULL, '44', NULL, '2', NULL, NULL, NULL, '2026-03-07 15:11:43', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('32', 'VENTA_69acec24bd000', NULL, '37', NULL, '2', NULL, NULL, NULL, '2026-03-07 15:25:24', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('33', 'VENTA_69b4a1d555716', NULL, '1', NULL, '11', NULL, NULL, NULL, '2026-03-13 11:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('34', 'VENTA_69b4a1d555716', NULL, '2', NULL, '31', NULL, NULL, NULL, '2026-03-13 11:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('35', 'VENTA_69b4a1d555716', NULL, '10', NULL, '1', NULL, NULL, NULL, '2026-03-13 11:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('36', 'VENTA_69b4a1d555716', NULL, '11', NULL, '1', NULL, NULL, NULL, '2026-03-13 11:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('37', 'VENTA_69b4a1d555716', NULL, '12', NULL, '3', NULL, NULL, NULL, '2026-03-13 11:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('38', 'VENTA_69b4a1d555716', NULL, '16', NULL, '5', NULL, NULL, NULL, '2026-03-13 11:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('39', 'VENTA_69b4a1d555716', NULL, '21', NULL, '1', NULL, NULL, NULL, '2026-03-13 11:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('40', 'VENTA_69b4a1d555716', NULL, '24', NULL, '8', NULL, NULL, NULL, '2026-03-13 11:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('41', 'VENTA_69b4a1d555716', NULL, '46', NULL, '6', NULL, NULL, NULL, '2026-03-13 11:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('42', 'VENTA_69b4a1d555716', NULL, '48', NULL, '1', NULL, NULL, NULL, '2026-03-13 11:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('43', 'VENTA_69b4a1d555716', NULL, '50', NULL, '2', NULL, NULL, NULL, '2026-03-13 11:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('44', 'VENTA_69b4a1d555716', NULL, '51', NULL, '2', NULL, NULL, NULL, '2026-03-13 11:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('45', 'VENTA_69b4a1d555716', NULL, '52', NULL, '2', NULL, NULL, NULL, '2026-03-13 11:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('56', 'VENTA_69d0625568a3e', NULL, '11', NULL, '2', NULL, NULL, NULL, '2026-04-03 12:59:01', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('57', 'VENTA_69d0625568a3e', NULL, '75', NULL, '1', NULL, NULL, NULL, '2026-04-03 12:59:01', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('58', 'VENTA_69d0625568a3e', NULL, '54', NULL, '1', NULL, NULL, NULL, '2026-04-03 12:59:01', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('59', 'VENTA_69d0625568a3e', NULL, '76', NULL, '1', NULL, NULL, NULL, '2026-04-03 12:59:01', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('60', 'VENTA_69d0625568a3e', NULL, '78', NULL, '2', NULL, NULL, NULL, '2026-04-03 12:59:01', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('61', 'VENTA_69d0625568a3e', NULL, '6', NULL, '5', NULL, NULL, NULL, '2026-04-03 12:59:01', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('62', 'VENTA_69d0625568a3e', NULL, '74', NULL, '3', NULL, NULL, NULL, '2026-04-03 12:59:01', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('63', 'VENTA_69d0625568a3e', NULL, '7', NULL, '1', NULL, NULL, NULL, '2026-04-03 12:59:01', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('64', 'VENTA_69d0625568a3e', NULL, '55', NULL, '1', NULL, NULL, NULL, '2026-04-03 12:59:01', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('65', 'VENTA_69d0625568a3e', NULL, '2', NULL, '2', NULL, NULL, NULL, '2026-04-03 12:59:01', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('66', 'VENTA_69d0625568a3e', NULL, '1', NULL, '38', NULL, NULL, NULL, '2026-04-03 12:59:01', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('67', 'VENTA_69d0625568a3e', NULL, '46', NULL, '1', NULL, NULL, NULL, '2026-04-03 12:59:01', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('68', 'VENTA_69d0625568a3e', NULL, '51', NULL, '1', NULL, NULL, NULL, '2026-04-03 12:59:01', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('69', 'VENTA_69d0625568a3e', NULL, '16', NULL, '3', NULL, NULL, NULL, '2026-04-03 12:59:01', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('70', 'VENTA_69d0625568a3e', NULL, '52', NULL, '1', NULL, NULL, NULL, '2026-04-03 12:59:01', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('71', 'VENTA_69d0625568a3e', NULL, '13', NULL, '1', NULL, NULL, NULL, '2026-04-03 12:59:01', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('72', 'VENTA_69d0625568a3e', NULL, '12', NULL, '1', NULL, NULL, NULL, '2026-04-03 12:59:01', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('73', 'VENTA_69d99aa02652e', NULL, '11', NULL, '1', NULL, NULL, NULL, '2026-04-10 12:49:36', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('74', 'VENTA_69d99aa02652e', NULL, '54', NULL, '2', NULL, NULL, NULL, '2026-04-10 12:49:36', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('75', 'VENTA_69d99aa02652e', NULL, '78', NULL, '1', NULL, NULL, NULL, '2026-04-10 12:49:36', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('76', 'VENTA_69d99aa02652e', NULL, '23', NULL, '1', NULL, NULL, NULL, '2026-04-10 12:49:36', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('77', 'VENTA_69d99aa02652e', NULL, '1', NULL, '23', NULL, NULL, NULL, '2026-04-10 12:49:36', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('78', 'VENTA_69d99aa02652e', NULL, '46', NULL, '8', NULL, NULL, NULL, '2026-04-10 12:49:36', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('79', 'VENTA_69d99aa02652e', NULL, '14', NULL, '1', NULL, NULL, NULL, '2026-04-10 12:49:36', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('80', 'VENTA_69d99aa02652e', NULL, '51', NULL, '2', NULL, NULL, NULL, '2026-04-10 12:49:36', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('81', 'VENTA_69d99aa02652e', NULL, '79', NULL, '4', NULL, NULL, NULL, '2026-04-10 12:49:36', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('82', 'VENTA_69d99aa02652e', NULL, '16', NULL, '2', NULL, NULL, NULL, '2026-04-10 12:49:36', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('83', 'VENTA_69d99aa02652e', NULL, '52', NULL, '2', NULL, NULL, NULL, '2026-04-10 12:49:36', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('84', 'VENTA_69d99aa02652e', NULL, '9', NULL, '1', NULL, NULL, NULL, '2026-04-10 12:49:36', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('85', 'VENTA_69d99aa02652e', NULL, '10', NULL, '2', NULL, NULL, NULL, '2026-04-10 12:49:36', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('86', 'VENTA_69d99aa02652e', NULL, '13', NULL, '1', NULL, NULL, NULL, '2026-04-10 12:49:36', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('87', 'VENTA_69d99aa02652e', NULL, '12', NULL, '3', NULL, NULL, NULL, '2026-04-10 12:49:36', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('88', 'VENTA_69f50f0714172', NULL, '91', NULL, '1', NULL, NULL, NULL, '2026-05-01 08:37:27', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('89', 'VENTA_69f50f0714172', NULL, '11', NULL, '2', NULL, NULL, NULL, '2026-05-01 08:37:27', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('90', 'VENTA_69f50f0714172', NULL, '75', NULL, '2', NULL, NULL, NULL, '2026-05-01 08:37:27', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('91', 'VENTA_69f50f0714172', NULL, '54', NULL, '5', NULL, NULL, NULL, '2026-05-01 08:37:27', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('92', 'VENTA_69f50f0714172', NULL, '81', NULL, '1', NULL, NULL, NULL, '2026-05-01 08:37:27', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('93', 'VENTA_69f50f0714172', NULL, '21', NULL, '1', NULL, NULL, NULL, '2026-05-01 08:37:27', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('94', 'VENTA_69f50f0714172', NULL, '1', NULL, '44', NULL, NULL, NULL, '2026-05-01 08:37:27', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('95', 'VENTA_69f50f0714172', NULL, '46', NULL, '13', NULL, NULL, NULL, '2026-05-01 08:37:27', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('96', 'VENTA_69f50f0714172', NULL, '14', NULL, '2', NULL, NULL, NULL, '2026-05-01 08:37:27', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('97', 'VENTA_69f50f0714172', NULL, '51', NULL, '2', NULL, NULL, NULL, '2026-05-01 08:37:27', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('98', 'VENTA_69f50f0714172', NULL, '52', NULL, '1', NULL, NULL, NULL, '2026-05-01 08:37:27', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('99', 'VENTA_69f50f0714172', NULL, '9', NULL, '2', NULL, NULL, NULL, '2026-05-01 08:37:27', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('100', 'VENTA_69f50f0714172', NULL, '12', NULL, '3', NULL, NULL, NULL, '2026-05-01 08:37:27', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('144', 'Venta_conteo_1', NULL, '11', '1', '5', NULL, NULL, NULL, '2026-05-03 15:50:12', 'ticket_Venta_conteo_1.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('145', 'Venta_conteo_1', NULL, '75', '1', '5', NULL, NULL, NULL, '2026-05-03 15:50:12', 'ticket_Venta_conteo_1.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('146', 'Venta_codigo_1', NULL, '73', '1', '2', 'jesusgabrielmtz78@gmail.com', 'efectivo', '', '2026-05-03 15:55:07', 'ticket_Venta_codigo_1.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('154', 'Venta_codigo_2', NULL, '91', '2', '1', '', 'efectivo', '', '2026-05-11 12:00:27', 'ticket_Venta_codigo_2.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('155', 'Venta_codigo_3', NULL, '3', '2', '1', '', 'efectivo', '', '2026-05-11 17:30:05', 'ticket_Venta_codigo_3.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('156', 'Venta_codigo_4', NULL, '3', '2', '1', '', 'transferencia', '123123A', '2026-05-11 17:30:43', 'ticket_Venta_codigo_4.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('157', 'Venta_codigo_5', NULL, '1', '2', '4', 'jesusgabrielmtz78@gmail.com', 'efectivo', '', '2026-05-14 14:31:07', 'ticket_Venta_codigo_5.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('158', 'Venta_codigo_6', NULL, '1', '2', '3', 'jesusgabrielmtz78@gmail.com', 'transferencia', '123123A', '2026-05-14 17:14:22', 'ticket_Venta_codigo_6.pdf');

-- Estructura de tabla `ventas_canceladas`
DROP TABLE IF EXISTS `ventas_canceladas`;
CREATE TABLE `ventas_canceladas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_venta` int(11) NOT NULL,
  `cantidad_devuelta` int(11) DEFAULT 0,
  `motivo` varchar(255) DEFAULT NULL,
  `fecha_cancelacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `folio_ticket` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=95 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Datos de tabla `ventas_canceladas`
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('1', '21', '4', 'Cancelación total del ticket', '2026-02-19 09:05:37', 'VENTA_2026-02-19_22:05:10-1353');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('2', '115', '2', 'Cancelación total del ticket', '2026-05-02 22:49:50', 'PEDIDO-5');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('3', '116', '2', 'Cancelación total del ticket', '2026-05-02 23:02:09', 'PEDIDO-6');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('4', '117', '2', 'Cancelación total del ticket', '2026-05-02 23:05:54', 'PEDIDO-7');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('5', '120', '2', 'Cancelación total del ticket', '2026-05-02 23:25:05', 'PEDIDO-10');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('6', '119', '2', 'Cancelación total del ticket', '2026-05-02 23:25:09', 'PEDIDO-9');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('7', '118', '2', 'Cancelación total del ticket', '2026-05-02 23:25:15', 'PEDIDO-8');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('8', '123', '3', 'Cancelación total del ticket', '2026-05-02 23:34:47', 'PEDIDO-13');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('9', '122', '2', 'Cancelación total del ticket', '2026-05-02 23:34:52', 'PEDIDO-12');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('10', '124', '2', 'Cancelación total del ticket', '2026-05-02 23:41:46', 'PEDIDO-14');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('11', '126', '2', 'Cancelación total del ticket', '2026-05-03 00:16:42', 'PEDIDO-16');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('12', '125', '2', 'Cancelación total del ticket', '2026-05-03 00:31:15', 'PEDIDO-15');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('13', '127', '3', 'Cancelación total del ticket', '2026-05-03 00:38:53', 'PEDIDO-17');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('14', '47', '3', 'Cancelación total del ticket', '2026-05-03 00:42:52', 'VENTA_69bf46627d105');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('15', '48', '1', 'Cancelación total del ticket', '2026-05-03 00:42:52', 'VENTA_69bf46627d105');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('16', '49', '6', 'Cancelación total del ticket', '2026-05-03 00:42:52', 'VENTA_69bf46627d105');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('17', '50', '15', 'Cancelación total del ticket', '2026-05-03 00:42:52', 'VENTA_69bf46627d105');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('18', '51', '1', 'Cancelación total del ticket', '2026-05-03 00:42:52', 'VENTA_69bf46627d105');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('19', '52', '1', 'Cancelación total del ticket', '2026-05-03 00:42:52', 'VENTA_69bf46627d105');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('20', '53', '2', 'Cancelación total del ticket', '2026-05-03 00:42:52', 'VENTA_69bf46627d105');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('21', '54', '1', 'Cancelación total del ticket', '2026-05-03 00:42:52', 'VENTA_69bf46627d105');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('22', '55', '1', 'Cancelación total del ticket', '2026-05-03 00:42:52', 'VENTA_69bf46627d105');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('23', '128', '2', 'Cancelación total del ticket', '2026-05-03 00:43:30', 'PEDIDO-18');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('24', '129', '2', 'Cancelación total del ticket', '2026-05-03 00:43:30', 'PEDIDO-18');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('29', '130', '3', 'Cancelación total del ticket', '2026-05-03 01:43:31', 'PEDIDO-19');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('30', '131', '3', 'Cancelación total del ticket', '2026-05-03 01:43:31', 'PEDIDO-19');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('52', '1', '1', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('53', '2', '2', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('54', '3', '4', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('55', '4', '2', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('56', '5', '1', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('57', '6', '1', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('58', '7', '20', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('59', '8', '4', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('60', '9', '17', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('61', '10', '1', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('62', '11', '1', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('63', '12', '4', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('64', '13', '9', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('65', '14', '1', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('66', '15', '4', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('67', '16', '2', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('68', '17', '4', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('69', '18', '1', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('70', '19', '6', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('71', '20', '2', 'Cancelación total del ticket', '2026-05-03 13:12:24', 'VENTA_2026-02-19_21:51:40-1428');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('75', '132', '2', 'Cancelación total del ticket', '2026-05-03 13:14:59', 'PEDIDO-20');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('76', '133', '2', 'Cancelación total del ticket', '2026-05-03 13:14:59', 'PEDIDO-20');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('77', '134', '2', 'Cancelación total del ticket', '2026-05-03 13:14:59', 'PEDIDO-20');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('78', '135', '2', 'Cancelación total del ticket', '2026-05-03 13:52:08', 'PEDIDO-21');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('79', '136', '2', 'Cancelación total del ticket', '2026-05-03 13:52:08', 'PEDIDO-21');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('80', '137', '2', 'Cancelación total del ticket', '2026-05-03 13:52:08', 'PEDIDO-21');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('81', '138', '2', 'Cancelación total del ticket', '2026-05-03 13:54:43', 'PEDIDO-22');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('82', '139', '2', 'Cancelación total del ticket', '2026-05-03 13:54:43', 'PEDIDO-22');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('83', '140', '2', 'Cancelación total del ticket', '2026-05-03 13:54:43', 'PEDIDO-22');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('84', '46', '13', 'Cancelación total del ticket', '2026-05-03 14:16:18', 'VENTA_2026-03-14_00:54:05-3448');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('85', '141', '5', 'Cancelación total del ticket', '2026-05-03 14:25:25', 'PEDIDO-23');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('86', '142', '5', 'Cancelación total del ticket', '2026-05-03 14:25:25', 'PEDIDO-23');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('87', '143', '0', 'me equivoque', '2026-05-03 23:55:08', NULL);
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('88', '147', '1', 'Cancelación total del ticket', '2026-05-06 16:21:15', 'PEDIDO-24');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('89', '148', '1', 'Cancelación total del ticket', '2026-05-06 16:21:15', 'PEDIDO-24');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('90', '152', '1', 'Cancelación total del ticket', '2026-05-06 18:04:09', 'PEDIDO-26');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('91', '153', '1', 'Cancelación total del ticket', '2026-05-06 18:04:09', 'PEDIDO-26');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('92', '149', '2', 'Cancelación total del ticket', '2026-05-06 18:04:14', 'PEDIDO-25');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('93', '150', '2', 'Cancelación total del ticket', '2026-05-06 18:04:14', 'PEDIDO-25');
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('94', '151', '3', 'Cancelación total del ticket', '2026-05-06 18:04:14', 'PEDIDO-25');

