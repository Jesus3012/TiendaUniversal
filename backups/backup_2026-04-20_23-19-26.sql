-- Backup generado el 2026-04-20 23:19:26
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
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `auditoria_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=735 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Datos de tabla `codigos_barras`
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('579', '45', 'P00000045', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('580', '44', 'P00000044', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('582', '42', 'P00000042', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('583', '41', 'P00000041', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('584', '40', 'P00000040', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('585', '39', 'P00000039', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('586', '38', 'P00000038', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('587', '37', 'P00000037', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('588', '36', 'P00000036', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('589', '35', 'P00000035', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('590', '34', 'P00000034', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('591', '33', 'P00000033', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('592', '32', 'P00000032', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('593', '31', 'P00000031', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('594', '30', 'P00000030', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('595', '29', 'P00000029', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('596', '28', 'P00000028', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('597', '27', 'P00000027', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('598', '26', 'P00000026', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('599', '25', 'P00000025', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('600', '24', 'P00000024', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('601', '23', 'P00000023', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('602', '22', 'P00000022', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('603', '21', 'P00000021', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('604', '20', 'P00000020', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('605', '19', 'P00000019', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('607', '17', 'P00000017', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('609', '15', 'P00000015', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('614', '10', 'P00000010', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('615', '9', 'P00000009', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('617', '7', 'P00000007', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('618', '6', 'P00000006', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('619', '5', 'P00000005', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('620', '4', 'P00000004', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('621', '3', 'P00000003', '1');
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
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('661', '47', '4700001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('662', '48', '4800001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('663', '49', '4900001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('664', '50', '5000001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('665', '50', '5000002', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('666', '50', '5000003', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('667', '50', '5000004', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('668', '50', '5000005', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('669', '50', '5000006', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('670', '51', '5100001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('671', '51', '5100002', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('672', '51', '5100003', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('673', '51', '5100004', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('674', '51', '5100005', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('675', '51', '5100006', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('676', '52', '5200001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('677', '52', '5200002', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('678', '52', '5200003', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('679', '52', '5200004', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('680', '52', '5200005', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('681', '52', '5200006', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('682', '53', '5300001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('683', '54', '5400001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('684', '54', '5400002', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('685', '54', '5400003', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('686', '54', '5400004', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('687', '55', '5500001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('688', '16', 'P00000016', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('689', '18', 'P00000018', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('690', '8', 'P00000008', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('691', '14', 'P00000014', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('692', '12', 'P00000012', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('693', '11', 'P00000011', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('694', '13', 'P00000013', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('695', '1', 'P00000001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('696', '2', 'P00000002', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('697', '56', '5600001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('698', '57', '5700001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('699', '57', '5700002', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('700', '57', '5700003', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('701', '57', '5700004', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('702', '57', '5700005', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('703', '57', '5700006', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('704', '57', '5700007', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('705', '57', '5700008', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('706', '58', '5800001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('707', '43', 'P00000043', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('708', '46', '4600037', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('709', '46', '4600038', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('710', '46', '4600039', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('711', '46', '4600040', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('712', '59', '5900001', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('713', '59', '5900002', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('714', '59', '5900003', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('716', '60', 'P00000060', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('717', '61', 'P00000061', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('718', '62', 'P00000062', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('719', '63', 'P00000063', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('720', '64', 'P00000064', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('721', '65', 'P00000065', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('722', '66', 'P00000066', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('723', '67', 'P00000067', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('724', '68', 'P00000068', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('725', '69', 'P00000069', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('726', '70', 'P00000070', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('727', '71', 'P00000071', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('728', '72', 'P00000072', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('730', '73', 'P00000073', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('731', '46', '4600041', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('732', '46', '4600042', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('733', '46', '4600043', '1');
INSERT INTO `codigos_barras` (`id`, `producto_id`, `codigo`, `disponible`) VALUES ('734', '46', '4600044', '1');

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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos de tabla `configuracion_correo`
INSERT INTO `configuracion_correo` (`id`, `smtp_host`, `smtp_port`, `smtp_usuario`, `smtp_password`, `smtp_secure`, `correo_origen`, `nombre_origen`, `activo`, `created_at`, `updated_at`) VALUES ('1', 'smtp.gmail.com', '587', 'jesusgabrielmtz78@gmail.com', 'iwdf uyqu erzq wvbm', 'tls', 'jesusgabrielmtz78@gmail.com', 'Tienda Pescadores', '1', '2026-04-17 04:30:53', '2026-04-20 22:14:52');

-- Estructura de tabla `configuracion_galeria`
DROP TABLE IF EXISTS `configuracion_galeria`;
CREATE TABLE `configuracion_galeria` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(200) NOT NULL DEFAULT 'Gimnasio',
  `telefono` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `direccion` text DEFAULT NULL,
  `horario` varchar(255) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos de tabla `configuracion_galeria`
INSERT INTO `configuracion_galeria` (`id`, `nombre`, `telefono`, `email`, `direccion`, `horario`, `logo`, `created_at`, `updated_at`) VALUES ('1', 'Pescadores de la Prehistoria', '2223211', 'karmina.aranguthy@hotmail.com', 'Tepexi de Rodriguez, Pue', 'Lunes a Domingo 10:00 - 20:00.', 'img/panel_principal.jpg', '2026-04-20 12:58:45', '2026-04-20 22:14:32');

-- Estructura de tabla `devoluciones_parciales`
DROP TABLE IF EXISTS `devoluciones_parciales`;
CREATE TABLE `devoluciones_parciales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_venta` int(11) NOT NULL,
  `cantidad_devuelta` int(11) NOT NULL,
  `motivo` varchar(255) DEFAULT NULL,
  `fecha` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Datos de tabla `devoluciones_parciales`
INSERT INTO `devoluciones_parciales` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha`) VALUES ('1', '15', '7', 'me equivoque', '2026-02-19 15:07:05');
INSERT INTO `devoluciones_parciales` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha`) VALUES ('2', '21', '2', '', '2026-03-07 00:12:04');
INSERT INTO `devoluciones_parciales` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha`) VALUES ('3', '26', '24', '', '2026-03-07 20:44:15');

-- Estructura de tabla `historial_reportes`
DROP TABLE IF EXISTS `historial_reportes`;
CREATE TABLE `historial_reportes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_archivo` varchar(255) NOT NULL,
  `proveedor` varchar(150) DEFAULT NULL,
  `fecha_generacion` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Datos de tabla `historial_reportes`
INSERT INTO `historial_reportes` (`id`, `nombre_archivo`, `proveedor`, `fecha_generacion`) VALUES ('1', 'ticket_13.pdf', 'nevaris', '2026-04-18 19:58:30');

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
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Datos de tabla `historial_stock`
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('1', '46', '22.00', '26.00', '4.00', 'ajuste', 'AJUSTE: estaban separados (diferencia: +4)', '1', '2026-03-21 19:38:49');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('2', '21', '0.00', '1.00', '1.00', 'ajuste', 'AJUSTE: no lo vi (diferencia: +1)', '1', '2026-03-21 19:39:29');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('3', '59', '0.00', '3.00', '3.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 18:27:15');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('4', '60', '0.00', '2.00', '2.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 18:28:10');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('5', '61', '0.00', '2.00', '2.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 18:30:42');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('6', '62', '0.00', '3.00', '3.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 18:32:13');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('7', '63', '0.00', '5.00', '5.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 18:33:34');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('8', '64', '0.00', '2.00', '2.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 18:34:45');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('9', '65', '0.00', '30.00', '30.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 18:35:29');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('10', '66', '0.00', '2.00', '2.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 18:38:27');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('11', '67', '0.00', '4.00', '4.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 18:39:45');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('12', '68', '0.00', '1.00', '1.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 18:41:23');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('13', '69', '0.00', '1.00', '1.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 18:42:21');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('14', '70', '0.00', '3.00', '3.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 18:43:31');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('15', '71', '0.00', '53.00', '53.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 18:49:33');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('16', '72', '0.00', '2.00', '2.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 18:51:56');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('17', '73', '0.00', '52.00', '52.00', 'entrada', 'Registro inicial de producto', '1', '2026-03-29 18:58:51');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('18', '45', '5.00', '2.00', '-3.00', 'ajuste', 'AJUSTE: Desaparecieron (diferencia: -3)', '1', '2026-03-29 19:01:10');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('19', '46', '26.00', '30.00', '4.00', 'ajuste', 'AJUSTE: Conte mal (diferencia: +4)', '1', '2026-04-18 20:39:44');
INSERT INTO `historial_stock` (`id`, `producto_id`, `cantidad_anterior`, `cantidad_nueva`, `cantidad_agregada`, `tipo_movimiento`, `nota`, `usuario_id`, `fecha_movimiento`) VALUES ('20', '46', '30.00', '26.00', '-4.00', 'ajuste', 'AJUSTE: conte mal (diferencia: -4)', '1', '2026-04-18 21:12:31');

-- Estructura de tabla `ordenes_pedido`
DROP TABLE IF EXISTS `ordenes_pedido`;
CREATE TABLE `ordenes_pedido` (
  `id_orden` int(11) NOT NULL,
  `solicitado_por` varchar(100) DEFAULT NULL,
  `fecha` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

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
  `id` int(11) NOT NULL,
  `id_producto` int(11) DEFAULT NULL,
  `nombre_producto` varchar(255) DEFAULT NULL,
  `stock_actual` int(11) DEFAULT NULL,
  `cantidad_pedida` int(11) DEFAULT NULL,
  `faltante` int(11) DEFAULT NULL,
  `solicitado_por` varchar(100) DEFAULT NULL,
  `fecha` timestamp NOT NULL DEFAULT current_timestamp(),
  `id_orden` int(11) DEFAULT NULL,
  `estado` enum('pendiente','completado') DEFAULT 'pendiente'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Estructura de tabla `pedidos_log`
DROP TABLE IF EXISTS `pedidos_log`;
CREATE TABLE `pedidos_log` (
  `id` int(11) NOT NULL,
  `id_pedido` int(11) DEFAULT NULL,
  `accion` varchar(100) DEFAULT NULL,
  `detalle` text DEFAULT NULL,
  `usuario` varchar(100) DEFAULT NULL,
  `fecha` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Estructura de tabla `pedidos_solventados`
DROP TABLE IF EXISTS `pedidos_solventados`;
CREATE TABLE `pedidos_solventados` (
  `id` int(11) NOT NULL,
  `id_pedido` int(11) NOT NULL,
  `id_producto` int(11) DEFAULT NULL,
  `cantidad_original` int(11) NOT NULL,
  `cantidad_solventada` int(11) NOT NULL,
  `cantidad_faltante` int(11) NOT NULL,
  `usuario` varchar(100) DEFAULT NULL,
  `fecha` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=74 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Datos de tabla `productos`
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('1', 'Llaveros grandes', 'Llavero', NULL, 'Nevaris 3D', '1', 'uploads/productos/1772432060_69a52abc15070.jpg', '136', '20.00', '45.00', 'unico', '2026-02-16 00:31:18', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('2', 'Llaveros Ch', 'Llavero', NULL, 'Nevaris 3D', '1', 'uploads/productos/1772432033_69a52aa1cd102.jpg', '78', '15.00', '35.00', 'unico', '2026-02-16 00:33:46', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('3', 'Diente megalodon', 'Figuras', NULL, 'Nevaris 3D', '1', 'uploads/productos/1772432093_69a52add8be5b.jpeg', '3', '60.00', '120.00', 'unico', '2026-02-16 00:37:16', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('4', 'Perro globo', 'Figuras', NULL, 'Nevaris 3D', '1', '', '3', '50.00', '110.00', 'unico', '2026-02-16 00:40:05', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('5', 'Cabezones', 'Juguetes', NULL, 'centro', '2', '', '11', '25.00', '55.00', 'unico', '2026-02-16 00:42:02', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('6', 'Dino bebé', 'Figuras', NULL, 'Nevaris 3D', '1', '', '5', '35.00', '70.00', 'unico', '2026-02-16 00:43:14', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('7', 'Langosta', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '50.00', '110.00', 'unico', '2026-02-16 00:44:51', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('8', 'Megalodon G', 'Figuras', NULL, 'Nevaris 3D', '1', '', '5', '60.00', '120.00', 'unico', '2026-02-16 00:46:11', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('9', 'Spinosaurio', 'Figuras', NULL, 'Nevaris 3D', '1', '', '5', '50.00', '110.00', 'unico', '2026-02-16 00:47:37', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('10', 'Stegosaurio', 'Figuras', NULL, 'Nevaris 3D', '1', '', '4', '50.00', '110.00', 'unico', '2026-02-16 00:51:25', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('11', 'Ankilosaurio', 'Figuras', NULL, 'Nevaris 3D', '1', '', '3', '50.00', '110.00', 'unico', '2026-02-16 00:53:40', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('12', 'velocirraptor', 'Figuras', NULL, 'Nevaris 3D', '1', '', '5', '50.00', '120.00', 'unico', '2026-02-16 00:55:08', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('13', 'T rex', 'Figuras', NULL, 'Nevaris 3D', '1', '', '10', '50.00', '110.00', 'unico', '2026-02-16 00:55:41', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('14', 'Mamut', 'Figuras', NULL, 'Nevaris 3D', '1', '', '3', '50.00', '120.00', 'unico', '2026-02-16 00:56:51', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('15', 'pez espinoso', 'Figuras', NULL, 'Nevaris 3D', '1', '', '4', '40.00', '90.00', 'unico', '2026-02-16 00:57:54', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('16', 'Pangolin Ch', 'Figuras', NULL, 'Nevaris 3D', '1', '', '4', '50.00', '110.00', 'unico', '2026-02-16 00:58:45', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('17', 'Tulipanes', 'Figuras', NULL, 'Nevaris 3D', '1', '', '5', '35.00', '70.00', 'unico', '2026-02-16 01:01:20', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('18', 'Craneo ch', 'Figuras', NULL, 'Nevaris 3D', '1', '', '6', '50.00', '110.00', 'unico', '2026-02-16 01:02:33', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('19', 'Craneo G', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '240.00', '360.00', 'unico', '2026-02-16 01:03:55', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('20', 'Pico de pato', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '40.00', '100.00', 'unico', '2026-02-16 01:05:08', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('21', 'Ditto', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '15.00', '35.00', 'unico', '2026-02-16 01:06:36', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('22', 'Dragón', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '90.00', '150.00', 'unico', '2026-02-16 01:07:05', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('23', 'Floreros', 'Figuras', NULL, 'Nevaris 3D', '1', '', '2', '80.00', '140.00', 'unico', '2026-02-16 01:08:19', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('24', 'Libretas', 'Libretas', NULL, 'Nevaris 3D', '1', '', '16', '110.00', '170.00', 'unico', '2026-02-16 01:08:59', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('25', 'Porta tubos de ensayo', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '50.00', '110.00', 'unico', '2026-02-16 01:10:58', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('26', 'Saltamontes peluche', 'Peluche', NULL, 'Aurora peluches', '3', '', '3', '130.00', '185.00', 'unico', '2026-02-16 01:13:17', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('27', 'Armadillo peluche', 'Peluche', NULL, 'Aurora peluches', '3', '', '1', '130.00', '185.00', 'unico', '2026-02-16 01:14:02', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('28', 'Nutria peluche', 'Peluche', NULL, 'Aurora peluches', '3', '', '0', '130.00', '185.00', 'unico', '2026-02-16 01:14:51', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('29', 'Robin peluche', 'Peluche', NULL, 'Aurora peluches', '3', 'uploads/productos/1772431977_69a52a699c886.jpg', '0', '185.00', '240.00', 'unico', '2026-02-16 01:17:16', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('30', 'Colibri peluche', 'Peluche', NULL, 'Aurora peluches', '3', '', '1', '185.00', '240.00', 'unico', '2026-02-16 01:18:12', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('31', 'Ave azul', 'Peluche', NULL, 'Aurora peluches', '3', '', '1', '185.00', '240.00', 'unico', '2026-02-16 01:18:46', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('32', 'Oso peresozo', 'Peluche', NULL, 'Aurora peluches', '3', '', '1', '130.00', '185.00', 'unico', '2026-02-16 01:19:29', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('33', 'Chinchilla', 'Peluche', NULL, 'Aurora peluches', '3', '', '1', '130.00', '185.00', 'unico', '2026-02-16 01:19:58', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('34', 'Stegosaurus peluche', 'Peluche', NULL, 'Aurora peluches', '3', '', '1', '260.00', '320.00', 'unico', '2026-02-16 01:20:56', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('35', 'T rex peluche', 'Peluche', NULL, 'Aurora peluches', '3', '', '1', '260.00', '320.00', 'unico', '2026-02-16 01:21:33', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('36', 'Pteronodon peluche', 'Peluche', NULL, 'Aurora peluches', '3', '', '1', '260.00', '320.00', 'unico', '2026-02-16 01:22:12', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('37', 'Termo taza', 'Utensilios', NULL, 'Smart print', '4', '', '2', '252.00', '360.00', 'unico', '2026-02-16 01:22:55', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('38', 'Tortuga llavero', 'Llavero', NULL, 'centro cdmx', '5', '', '3', '35.00', '85.00', 'unico', '2026-02-16 01:25:31', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('39', 'Llavero caracol', 'Llavero', NULL, 'centro cdmx', '5', '', '4', '20.00', '60.00', 'unico', '2026-02-16 01:26:28', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('40', 'Llavero ambistoma', 'Llavero', NULL, 'centro cdmx', '5', '', '0', '20.00', '60.00', 'unico', '2026-02-16 01:27:02', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('41', 'Llaveros dinosaurios hule', 'Llavero', NULL, 'centro cdmx', '5', '', '18', '20.00', '50.00', 'unico', '2026-02-16 01:29:36', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('42', 'Gorras', 'Ropa', NULL, 'centro carmen', '6', '', '4', '75.00', '185.00', 'unico', '2026-02-16 01:31:54', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('43', 'Playera estampada', 'Ropa', NULL, 'centro carmen', '6', '', '17', '65.00', '200.00', 'unico', '2026-02-16 01:35:03', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('44', 'sudadera estampada', 'Ropa', NULL, 'centro carmen', '6', '', '7', '220.00', '360.00', 'unico', '2026-02-16 01:37:36', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('45', 'Tote bag', 'Bolsas', NULL, 'centro carmen', '6', '', '2', '60.00', '120.00', 'unico', '2026-02-16 01:38:39', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('46', 'Llaveros letras', 'Llavero', NULL, 'Nevaris 3D', '1', '', '26', '21.00', '45.00', 'multiple', '2026-03-06 23:35:18', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('47', 'T Rex Grande', 'Figuras', NULL, 'Nevaris 3D', '1', '', '0', '250.00', '365.00', 'multiple', '2026-03-06 23:36:54', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('48', 'Veloci G', 'Figuras', NULL, 'Nevaris 3D', '1', '', '0', '250.00', '365.00', 'multiple', '2026-03-06 23:37:41', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('49', 'Protoceratops', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '50.00', '120.00', 'multiple', '2026-03-06 23:38:44', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('50', 'Mosasaurio', 'Figuras', NULL, 'Nevaris 3D', '1', '', '4', '60.00', '130.00', 'multiple', '2026-03-06 23:39:53', '0', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('51', 'Mosasaurio', 'Figuras', NULL, 'Nevaris 3D', '1', '', '4', '60.00', '130.00', 'multiple', '2026-03-06 23:39:54', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('52', 'Pterodactilo', 'Figuras', NULL, 'Nevaris 3D', '1', '', '3', '50.00', '120.00', 'multiple', '2026-03-06 23:40:52', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('53', 'Craneo Gigante Terap', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '320.00', '430.00', 'multiple', '2026-03-06 23:41:46', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('54', 'Aretes', 'Bisuteria', NULL, 'Nevaris 3D', '1', '', '0', '22.00', '55.00', 'multiple', '2026-03-06 23:42:45', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('55', 'Letras Tlayúa', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '170.00', '250.00', 'multiple', '2026-03-06 23:44:55', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('56', 'Amonita G', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '50.00', '120.00', 'multiple', '2026-03-13 17:48:01', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('57', 'Patitos Ch', 'Figuras', NULL, 'Nevaris 3D', '1', '', '8', '5.00', '10.00', 'multiple', '2026-03-13 17:48:53', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('58', 'Cuadro ch cerebro', 'Figuras', NULL, 'Nevaris 3D', '1', '', '1', '100.00', '170.00', 'multiple', '2026-03-13 17:50:06', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('59', 'Bolsa cuadrada', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', '7', '', '3', '140.00', '200.00', 'multiple', '2026-03-29 18:27:15', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('60', 'Carpeta oficio', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', '7', '', '2', '150.00', '265.00', '', '2026-03-29 18:28:10', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('61', 'Cartera plana', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', '7', '', '2', '100.00', '160.00', 'unico', '2026-03-29 18:30:42', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('62', 'Monedero mediano', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', '7', '', '3', '40.00', '75.00', 'unico', '2026-03-29 18:32:13', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('63', 'Monedero grande', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', '7', '', '5', '50.00', '85.00', 'unico', '2026-03-29 18:33:34', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('64', 'Cartera doble cierre', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', '7', '', '2', '140.00', '200.00', 'unico', '2026-03-29 18:34:45', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('65', 'Mantelitos', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', '7', '', '30', '15.00', '35.00', 'unico', '2026-03-29 18:35:29', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('66', 'Bolsa cartera', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', '7', '', '2', '170.00', '235.00', 'unico', '2026-03-29 18:38:27', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('67', 'Lapiceras', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', '7', '', '4', '60.00', '95.00', 'unico', '2026-03-29 18:39:45', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('68', 'Bolsa de colgar chica', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', '7', '', '1', '60.00', '115.00', 'unico', '2026-03-29 18:41:23', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('69', 'Bolsa de colgar mediana', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', '7', '', '1', '80.00', '135.00', 'unico', '2026-03-29 18:42:21', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('70', 'Monedero chico', 'Palma', NULL, 'Artesanías Gloria (Huatlatlauca)', '7', '', '3', '30.00', '45.00', 'unico', '2026-03-29 18:43:31', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('71', 'Taza de cerámica', 'Sublimado', NULL, 'Publicidad Impresa', '8', '', '53', '33.60', '100.00', 'unico', '2026-03-29 18:49:33', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('72', 'Libreta de tela', 'Papeleria', NULL, 'Smart print', '4', '', '2', '80.00', '135.00', 'unico', '2026-03-29 18:51:56', '1', 'producto');
INSERT INTO `productos` (`id`, `nombre`, `categoria`, `atributos`, `proveedor`, `proveedor_id`, `imagen`, `cantidad`, `precio_compra`, `precio_venta`, `tipo_codigo`, `fecha_registro`, `activo`, `tipo_inventario`) VALUES ('73', 'Posters', 'Papeleria', NULL, 'Qubits', '9', '', '52', '11.00', '35.00', '', '2026-03-29 18:58:51', '1', 'producto');

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
  `pais` varchar(50) DEFAULT 'México',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Datos de tabla `proveedores`
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('1', 'Nevaris 3D', NULL, NULL, NULL, '1', '2026-04-18 16:46:27', NULL, NULL, NULL, NULL, NULL, NULL, 'México');
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('2', 'centro', NULL, NULL, NULL, '1', '2026-04-18 16:46:27', NULL, NULL, NULL, NULL, NULL, NULL, 'México');
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('3', 'Aurora peluches', NULL, NULL, NULL, '1', '2026-04-18 16:46:27', NULL, NULL, NULL, NULL, NULL, NULL, 'México');
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('4', 'Smart print', NULL, NULL, NULL, '1', '2026-04-18 16:46:27', NULL, NULL, NULL, NULL, NULL, NULL, 'México');
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('5', 'centro cdmx', NULL, NULL, NULL, '1', '2026-04-18 16:46:27', NULL, NULL, NULL, NULL, NULL, NULL, 'México');
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('6', 'centro carmen', NULL, NULL, NULL, '1', '2026-04-18 16:46:27', NULL, NULL, NULL, NULL, NULL, NULL, 'México');
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('7', 'Artesanías Gloria (Huatlatlauca)', '', '2241009956', NULL, '1', '2026-04-18 16:46:27', '', '', '', '', '', '', 'México');
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('8', 'Publicidad Impresa', NULL, NULL, NULL, '1', '2026-04-18 16:46:27', NULL, NULL, NULL, NULL, NULL, NULL, 'México');
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('9', 'Qubits', NULL, NULL, NULL, '1', '2026-04-18 16:46:27', NULL, NULL, NULL, NULL, NULL, NULL, 'México');
INSERT INTO `proveedores` (`id`, `nombre`, `correo`, `telefono`, `logo`, `activo`, `created_at`, `calle`, `numero`, `colonia`, `ciudad`, `estado`, `codigo_postal`, `pais`) VALUES ('11', 'Jesus VIdal Prueba', 'jesusgabrielmtz78@gmail.com', '2229804687', 'uploads/proveedores/Jesus_VIdal_Prueba.jpg', '1', '2026-04-18 16:56:30', '117 oriente', '1401', 'Los Héroes de Puebla', 'Puebla', 'Puebla', '72590', 'México');

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
) ENGINE=InnoDB AUTO_INCREMENT=110 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Datos de tabla `usuarios`
INSERT INTO `usuarios` (`id`, `nombre`, `email`, `password`, `rol`, `fecha_registro`, `activo`, `created_by`, `debe_cambiar_password`, `foto_perfil`) VALUES ('1', 'Karmina Aranguthy Garcia', 'karmina.aranguthy@hotmail.com', '$2y$10$qTWBeNv3xYa2k6nKGiJpe.E9NJ1iiQLLeRoM8l2YFxOxPHUU8GHTG', 'administrador', '2025-10-26 20:47:46', '1', NULL, '0', 'uploads/perfiles/perfil_1.jpeg');
INSERT INTO `usuarios` (`id`, `nombre`, `email`, `password`, `rol`, `fecha_registro`, `activo`, `created_by`, `debe_cambiar_password`, `foto_perfil`) VALUES ('2', 'Jesús Gabriel Martinez Vidal', 'jesus@gmail.com', '$2y$10$9pUh0Ja2iOiSD.CuPAWTi.HLY0C5fc9a0BYpjWA2cReM3KU6ZIMbq', 'vendedor', '2025-10-26 21:03:04', '1', NULL, '1', NULL);

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
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Datos de tabla `ventas`
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('1', 'VENTA_2026-02-19_21:51:40-1428', NULL, '11', NULL, '1', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('2', 'VENTA_2026-02-19_21:51:40-1428', NULL, '27', NULL, '2', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('3', 'VENTA_2026-02-19_21:51:40-1428', NULL, '42', NULL, '4', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('4', 'VENTA_2026-02-19_21:51:40-1428', NULL, '24', NULL, '2', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('5', 'VENTA_2026-02-19_21:51:40-1428', NULL, '40', NULL, '1', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('6', 'VENTA_2026-02-19_21:51:40-1428', NULL, '39', NULL, '1', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('7', 'VENTA_2026-02-19_21:51:40-1428', NULL, '2', NULL, '20', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('8', 'VENTA_2026-02-19_21:51:40-1428', NULL, '41', NULL, '4', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('9', 'VENTA_2026-02-19_21:51:40-1428', NULL, '1', NULL, '17', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('10', 'VENTA_2026-02-19_21:51:40-1428', NULL, '14', NULL, '1', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('11', 'VENTA_2026-02-19_21:51:40-1428', NULL, '28', NULL, '1', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('12', 'VENTA_2026-02-19_21:51:40-1428', NULL, '16', NULL, '4', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('13', 'VENTA_2026-02-19_21:51:40-1428', NULL, '43', NULL, '9', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('14', 'VENTA_2026-02-19_21:51:40-1428', NULL, '29', NULL, '1', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('15', 'VENTA_2026-02-19_21:51:40-1428', NULL, '9', NULL, '4', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('16', 'VENTA_2026-02-19_21:51:40-1428', NULL, '10', NULL, '2', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('17', 'VENTA_2026-02-19_21:51:40-1428', NULL, '44', NULL, '4', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('18', 'VENTA_2026-02-19_21:51:40-1428', NULL, '13', NULL, '1', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('19', 'VENTA_2026-02-19_21:51:40-1428', NULL, '37', NULL, '6', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('20', 'VENTA_2026-02-19_21:51:40-1428', NULL, '45', NULL, '2', NULL, NULL, NULL, '2026-02-19 14:51:40', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('21', 'VENTA_2026-03-07_07:11:02-8350', NULL, '43', NULL, '3', NULL, NULL, NULL, '2026-03-07 00:11:02', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('22', 'VENTA_69ace1cbc1278', NULL, '1', NULL, '3', NULL, NULL, NULL, '2026-03-07 20:41:15', 'ticket_VENTA_69ace1cbc1278.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('23', 'VENTA_69ace1cbc1278', NULL, '2', NULL, '11', NULL, NULL, NULL, '2026-03-07 20:41:15', 'ticket_VENTA_69ace1cbc1278.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('24', 'VENTA_69ace1cbc1278', NULL, '10', NULL, '1', NULL, NULL, NULL, '2026-03-07 20:41:15', 'ticket_VENTA_69ace1cbc1278.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('25', 'VENTA_69ace1cbc1278', NULL, '14', NULL, '1', NULL, NULL, NULL, '2026-03-07 20:41:15', 'ticket_VENTA_69ace1cbc1278.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('26', 'VENTA_69ace1cbc1278', NULL, '24', NULL, '4', NULL, NULL, NULL, '2026-03-07 20:41:15', 'ticket_VENTA_69ace1cbc1278.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('27', 'VENTA_69ace1cbc1278', NULL, '46', NULL, '8', NULL, NULL, NULL, '2026-03-07 20:41:15', 'ticket_VENTA_69ace1cbc1278.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('28', 'VENTA_69ace1cbc1278', NULL, '54', NULL, '4', NULL, NULL, NULL, '2026-03-07 20:41:15', 'ticket_VENTA_69ace1cbc1278.pdf');
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('29', 'VENTA_69ace8efcd6a0', NULL, '42', NULL, '8', NULL, NULL, NULL, '2026-03-07 21:11:43', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('30', 'VENTA_69ace8efcd6a0', NULL, '43', NULL, '5', NULL, NULL, NULL, '2026-03-07 21:11:43', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('31', 'VENTA_69ace8efcd6a0', NULL, '44', NULL, '2', NULL, NULL, NULL, '2026-03-07 21:11:43', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('32', 'VENTA_69acec24bd000', NULL, '37', NULL, '2', NULL, NULL, NULL, '2026-03-07 21:25:24', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('33', 'VENTA_69b4a1d555716', NULL, '1', NULL, '11', NULL, NULL, NULL, '2026-03-13 17:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('34', 'VENTA_69b4a1d555716', NULL, '2', NULL, '31', NULL, NULL, NULL, '2026-03-13 17:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('35', 'VENTA_69b4a1d555716', NULL, '10', NULL, '1', NULL, NULL, NULL, '2026-03-13 17:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('36', 'VENTA_69b4a1d555716', NULL, '11', NULL, '1', NULL, NULL, NULL, '2026-03-13 17:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('37', 'VENTA_69b4a1d555716', NULL, '12', NULL, '3', NULL, NULL, NULL, '2026-03-13 17:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('38', 'VENTA_69b4a1d555716', NULL, '16', NULL, '5', NULL, NULL, NULL, '2026-03-13 17:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('39', 'VENTA_69b4a1d555716', NULL, '21', NULL, '1', NULL, NULL, NULL, '2026-03-13 17:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('40', 'VENTA_69b4a1d555716', NULL, '24', NULL, '8', NULL, NULL, NULL, '2026-03-13 17:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('41', 'VENTA_69b4a1d555716', NULL, '46', NULL, '6', NULL, NULL, NULL, '2026-03-13 17:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('42', 'VENTA_69b4a1d555716', NULL, '48', NULL, '1', NULL, NULL, NULL, '2026-03-13 17:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('43', 'VENTA_69b4a1d555716', NULL, '50', NULL, '2', NULL, NULL, NULL, '2026-03-13 17:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('44', 'VENTA_69b4a1d555716', NULL, '51', NULL, '2', NULL, NULL, NULL, '2026-03-13 17:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('45', 'VENTA_69b4a1d555716', NULL, '52', NULL, '2', NULL, NULL, NULL, '2026-03-13 17:46:29', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('46', 'VENTA_2026-03-14_00:54:05-3448', NULL, '42', NULL, '13', NULL, NULL, NULL, '2026-03-13 17:54:05', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('47', 'VENTA_69bf46627d105', NULL, '11', NULL, '3', NULL, NULL, NULL, '2026-03-21 19:31:14', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('48', 'VENTA_69bf46627d105', NULL, '18', NULL, '1', NULL, NULL, NULL, '2026-03-21 19:31:14', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('49', 'VENTA_69bf46627d105', NULL, '2', NULL, '6', NULL, NULL, NULL, '2026-03-21 19:31:14', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('50', 'VENTA_69bf46627d105', NULL, '1', NULL, '15', NULL, NULL, NULL, '2026-03-21 19:31:14', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('51', 'VENTA_69bf46627d105', NULL, '16', NULL, '1', NULL, NULL, NULL, '2026-03-21 19:31:14', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('52', 'VENTA_69bf46627d105', NULL, '52', NULL, '1', NULL, NULL, NULL, '2026-03-21 19:31:14', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('53', 'VENTA_69bf46627d105', NULL, '9', NULL, '2', NULL, NULL, NULL, '2026-03-21 19:31:14', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('54', 'VENTA_69bf46627d105', NULL, '47', NULL, '1', NULL, NULL, NULL, '2026-03-21 19:31:14', NULL);
INSERT INTO `ventas` (`id`, `folio_ticket`, `id_orden`, `id_producto`, `id_vendedor`, `cantidad_vendida`, `correo_cliente`, `metodo_pago`, `referencia_pago`, `fecha_venta`, `ticket_pdf`) VALUES ('55', 'VENTA_69bf46627d105', NULL, '12', NULL, '1', NULL, NULL, NULL, '2026-03-21 19:31:14', NULL);

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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Datos de tabla `ventas_canceladas`
INSERT INTO `ventas_canceladas` (`id`, `id_venta`, `cantidad_devuelta`, `motivo`, `fecha_cancelacion`, `folio_ticket`) VALUES ('1', '21', '4', 'CancelaciÃ³n total del ticket', '2026-02-19 15:05:37', 'VENTA_2026-02-19_22:05:10-1353');

