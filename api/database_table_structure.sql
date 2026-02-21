-- LOMS SQL backup
-- Generated: 2026-02-20T00:47:38+01:00

SET FOREIGN_KEY_CHECKS=0;

-- ----------------------------
-- Table structure for `jo_composition_components`
-- ----------------------------

DROP TABLE IF EXISTS `jo_composition_components`;
CREATE TABLE `jo_composition_components` (
  `id` int NOT NULL AUTO_INCREMENT,
  `jo_record_id` int NOT NULL,
  `component` varchar(50) NOT NULL,
  `value` decimal(10,4) DEFAULT NULL,
  `unit` enum('mol%','wt%','at%') DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comp_component` (`component`),
  KEY `idx_comp_record` (`jo_record_id`),
  CONSTRAINT `jo_composition_components_ibfk_1` FOREIGN KEY (`jo_record_id`) REFERENCES `jo_records` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=113 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------
-- Table structure for `jo_contributors`
-- ----------------------------

DROP TABLE IF EXISTS `jo_contributors`;
CREATE TABLE `jo_contributors` (
  `uid` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `email` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  `name` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  `affiliation` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  `orcid` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  UNIQUE KEY `uid` (`uid`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ----------------------------
-- Table structure for `jo_records`
-- ----------------------------

DROP TABLE IF EXISTS `jo_records`;
CREATE TABLE `jo_records` (
  `id` int NOT NULL AUTO_INCREMENT,
  `publication_id` int NOT NULL,
  `contributor_info` varchar(255) DEFAULT NULL,
  `re_ion` varchar(10) NOT NULL,
  `re_conc_value` decimal(10,4) DEFAULT NULL,
  `re_conc_value_upper` decimal(10,4) DEFAULT NULL,
  `re_conc_value_note` text,
  `re_conc_unit` enum('mol%','wt%','at%','ions/cm3','unknown') DEFAULT 'unknown',
  `sample_label` varchar(100) DEFAULT NULL,
  `host_type` enum('glass','glass_ceramic','single_crystal','polycrystal','other','vapor','solution','melt','powder','aqua') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `host_family` enum('silicate','phosphate','borate','germanate','tellurite','fluoride','oxide_crystal','fluoride_crystal','other') DEFAULT 'other',
  `composition_text` text NOT NULL,
  `temperature_k` decimal(7,2) DEFAULT NULL,
  `lambda_min_nm` smallint DEFAULT NULL,
  `lambda_max_nm` smallint DEFAULT NULL,
  `method` enum('absorption','emission','combined','given_in_paper') DEFAULT 'given_in_paper',
  `omega2` decimal(12,6) DEFAULT NULL,
  `omega4` decimal(12,6) DEFAULT NULL,
  `omega6` decimal(12,6) DEFAULT NULL,
  `omega2_error` decimal(12,6) DEFAULT NULL,
  `omega4_error` decimal(12,6) DEFAULT NULL,
  `omega6_error` decimal(12,6) DEFAULT NULL,
  `omega_unit` varchar(32) DEFAULT '10^-20 cm^2',
  `jo_original_paper` tinyint(1) DEFAULT '1',
  `jo_recalc_by_loms` tinyint(1) DEFAULT '0',
  `has_transmission_spec` tinyint(1) DEFAULT '0',
  `has_absorption_spec` tinyint(1) DEFAULT '0',
  `has_emission_spec` tinyint(1) DEFAULT '0',
  `has_n_spectrum` tinyint(1) DEFAULT '0',
  `has_n_parameters` tinyint(1) DEFAULT '0',
  `has_density` tinyint(1) DEFAULT '0',
  `has_lifetime` tinyint(1) DEFAULT '0',
  `has_branching_ratios` tinyint(1) DEFAULT '0',
  `density_g_cm3` decimal(7,4) DEFAULT NULL,
  `n_546nm` decimal(6,4) DEFAULT NULL,
  `n_633nm` decimal(6,4) DEFAULT NULL,
  `dispersion_model` enum('none','cauchy1','cauchy2','cauchy3','sellmeier1','sellmeier2','sellmeier3','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'none',
  `dispersion_a` decimal(16,8) DEFAULT NULL,
  `dispersion_b` decimal(16,8) DEFAULT NULL,
  `dispersion_c` decimal(16,8) DEFAULT NULL,
  `refractive_index_option` tinyint(1) NOT NULL,
  `combinatorial_jo_option` tinyint(1) NOT NULL,
  `sigma_f_s_option` tinyint(1) NOT NULL,
  `mag_dipole_option` tinyint(1) NOT NULL,
  `reduced_element_option` tinyint(1) NOT NULL,
  `recalculated_loms_option` tinyint(1) NOT NULL,
  `refractive_index_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `combinatorial_jo_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `sigma_f_s_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `mag_dipole_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `reduced_element_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `recalculated_loms_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `extra_notes` text,
  `is_contributor_author` tinyint(1) DEFAULT '0',
  `review_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `date_submitted` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_approved` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_jo_re_ion` (`re_ion`),
  KEY `idx_jo_host_type` (`host_type`),
  KEY `idx_jo_host_family` (`host_family`),
  KEY `idx_jo_pub` (`publication_id`),
  FULLTEXT KEY `ft_jo_composition` (`composition_text`),
  CONSTRAINT `jo_records_ibfk_1` FOREIGN KEY (`publication_id`) REFERENCES `publications` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------
-- Table structure for `publications`
-- ----------------------------

DROP TABLE IF EXISTS `publications`;
CREATE TABLE `publications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `doi` varchar(255) DEFAULT NULL,
  `title` varchar(500) NOT NULL,
  `journal` varchar(255) DEFAULT NULL,
  `year` smallint DEFAULT NULL,
  `volume` varchar(50) DEFAULT NULL,
  `pages` varchar(50) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `authors` text NOT NULL,
  `metadata` json NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `doi` (`doi`)
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SET FOREIGN_KEY_CHECKS=1;
