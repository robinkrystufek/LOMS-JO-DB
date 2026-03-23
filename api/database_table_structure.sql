-- LOMS SQL structure

-- -----------------------------------------------
-- Table structure for `jo_components`
-- -----------------------------------------------
CREATE TABLE `jo_components` (
  `id` int NOT NULL,
  `ui_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `cid` int DEFAULT NULL,
  `pubchem_name` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `pubchem_details` json DEFAULT NULL,
  `mw` decimal(65,30) DEFAULT NULL,
  `atom_number` float DEFAULT NULL,
  `composition` json DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
ALTER TABLE `jo_components`
  ADD PRIMARY KEY (`id`),
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

-- -----------------------------------------------
-- Table structure for `jo_composition_components`
-- -----------------------------------------------

CREATE TABLE `jo_composition_components` (
  `id` int NOT NULL,
  `jo_record_id` int NOT NULL,
  `component` varchar(50) NOT NULL,
  `value` decimal(18,10) DEFAULT NULL,
  `unit` enum('mol%','wt%','at%') DEFAULT NULL,
  `calc_mol` decimal(18,10) DEFAULT NULL,
  `calc_wt` decimal(18,10) DEFAULT NULL,
  `calc_at` decimal(18,10) DEFAULT NULL,
  `component_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
ALTER TABLE `jo_composition_components`
  ADD PRIMARY KEY (`id`),
  MODIFY `id` int NOT NULL AUTO_INCREMENT,
  ADD KEY `idx_comp_component` (`component`),
  ADD KEY `idx_comp_record` (`jo_record_id`),
  ADD CONSTRAINT `jo_composition_components_ibfk_1` FOREIGN KEY (`jo_record_id`) REFERENCES `jo_records` (`id`) ON DELETE CASCADE;

-- -----------------------------------------------
-- Table structure for `jo_composition_elements`
-- -----------------------------------------------

CREATE TABLE `jo_composition_elements` (
  `id` int NOT NULL,
  `element` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `c_mol` decimal(18,10) DEFAULT NULL,
  `c_wt` decimal(18,10) DEFAULT NULL,
  `re_c` decimal(18,10) DEFAULT NULL,
  `re_c_unit` enum('mol%','wt%','at%') COLLATE utf8mb4_unicode_520_ci DEFAULT NULL,
  `record_id` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
ALTER TABLE `jo_composition_elements`
  ADD PRIMARY KEY (`id`),
  MODIFY `id` int NOT NULL AUTO_INCREMENT,
  ADD KEY `idx_record_id` (`record_id`);

--
-- Triggers `jo_composition_elements`
--

DELIMITER $$
CREATE TRIGGER `jo_comp_el_ad_data_quality` AFTER DELETE ON `jo_composition_elements` FOR EACH ROW BEGIN
  UPDATE jo_records
  SET data_quality = calc_jo_record_data_quality(OLD.record_id)
  WHERE id = OLD.record_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `jo_comp_el_ai_data_quality` AFTER INSERT ON `jo_composition_elements` FOR EACH ROW BEGIN
  UPDATE jo_records
  SET data_quality = calc_jo_record_data_quality(NEW.record_id)
  WHERE id = NEW.record_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `jo_comp_el_au_data_quality` AFTER UPDATE ON `jo_composition_elements` FOR EACH ROW BEGIN
  IF OLD.record_id <> NEW.record_id THEN
    UPDATE jo_records
    SET data_quality = calc_jo_record_data_quality(OLD.record_id)
    WHERE id = OLD.record_id;
  END IF;

  UPDATE jo_records
  SET data_quality = calc_jo_record_data_quality(NEW.record_id)
  WHERE id = NEW.record_id;
END
$$
DELIMITER ;

-- -----------------------------------------------
-- Table structure for `jo_contributors`
-- -----------------------------------------------

CREATE TABLE `jo_contributors` (
  `uid` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
  `email` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  `name` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  `affiliation` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  `orcid` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  `role` enum('admin','reviewer','depositor','user') COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT 'user'
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
ALTER TABLE `jo_contributors`
  ADD UNIQUE KEY `uid` (`uid`);

-- -----------------------------------------------
-- Table structure for `jo_records`
-- -----------------------------------------------

CREATE TABLE `jo_records` (
  `id` int NOT NULL,
  `publication_id` int NOT NULL,
  `contributor_info` varchar(255) DEFAULT NULL,
  `re_ion` varchar(10) NOT NULL,
  `re_conc_value` decimal(65,10) DEFAULT NULL,
  `re_conc_value_upper` decimal(65,10) DEFAULT NULL,
  `re_conc_value_note` text,
  `re_conc_unit` enum('mol%','wt%','at%','ions/cm3','unknown') DEFAULT 'unknown',
  `sample_label` varchar(100) DEFAULT NULL,
  `host_type` enum('glass','glass_ceramic','single_crystal','polycrystal','other','vapor','solution','melt','powder','aqua') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `composition_text` text NOT NULL,
  `omega2` decimal(18,10) DEFAULT NULL,
  `omega4` decimal(18,10) DEFAULT NULL,
  `omega6` decimal(18,10) DEFAULT NULL,
  `omega2_error` decimal(18,10) DEFAULT NULL,
  `omega4_error` decimal(18,10) DEFAULT NULL,
  `omega6_error` decimal(18,10) DEFAULT NULL,
  `jo_recalc_by_loms` tinyint(1) DEFAULT '0',
  `has_density` tinyint(1) DEFAULT '0',
  `density_g_cm3` decimal(18,10) DEFAULT NULL,
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
  `data_quality` int DEFAULT NULL,
  `extra_notes` text,
  `is_contributor_author` tinyint(1) DEFAULT '0',
  `is_revision_of_id` int DEFAULT NULL,
  `review_status` enum('pending','approved','rejected','revised','pending_revision') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'pending',
  `approved_by` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `date_submitted` datetime DEFAULT CURRENT_TIMESTAMP,
  `date_approved` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
ALTER TABLE `jo_records`
  ADD PRIMARY KEY (`id`),
  MODIFY `id` int NOT NULL AUTO_INCREMENT,
  ADD KEY `idx_jo_re_ion` (`re_ion`),
  ADD KEY `idx_jo_host_type` (`host_type`),
  ADD KEY `idx_jo_pub` (`publication_id`),
  ADD KEY `idx_revision_of` (`is_revision_of_id`),
  ADD FULLTEXT KEY `ft_jo_composition` (`composition_text`),
  ADD CONSTRAINT `fk_revision_of` FOREIGN KEY (`is_revision_of_id`) REFERENCES `jo_records` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `jo_records_ibfk_1` FOREIGN KEY (`publication_id`) REFERENCES `jo_publications` (`id`);

--
-- Triggers `jo_records`
--

DELIMITER $$
CREATE TRIGGER `jo_records_bi_data_quality` BEFORE INSERT ON `jo_records` FOR EACH ROW BEGIN
  SET NEW.data_quality =
      0
      + CASE WHEN NEW.density_g_cm3 IS NOT NULL THEN 10 ELSE 0 END
      + CASE WHEN NEW.re_conc_value IS NOT NULL THEN 10 ELSE 0 END
      - CASE WHEN NEW.re_conc_value_upper IS NOT NULL THEN 5 ELSE 0 END
      + CASE WHEN NEW.omega2 IS NOT NULL THEN 5 ELSE 0 END
      + CASE WHEN NEW.omega4 IS NOT NULL THEN 5 ELSE 0 END
      + CASE WHEN NEW.omega6 IS NOT NULL THEN 5 ELSE 0 END
      + CASE WHEN NEW.refractive_index_option = 3 THEN 10 ELSE 0 END
      + CASE
          WHEN NEW.refractive_index_option = 2
           AND NEW.refractive_index_note IS NOT NULL
           AND TRIM(NEW.refractive_index_note) <> ''
          THEN 10 ELSE 0
        END
      + CASE WHEN NEW.sigma_f_s_option = 2 THEN 10 ELSE 0 END
      + CASE WHEN NEW.mag_dipole_option IN (0, 2) THEN 10 ELSE 0 END
      + CASE WHEN NEW.combinatorial_jo_option = 2 THEN 5 ELSE 0 END
      + CASE WHEN NEW.reduced_element_option = 2 THEN 5 ELSE 0 END
      + CASE WHEN NEW.jo_recalc_by_loms = 2 THEN 5 ELSE 0 END;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `jo_records_bu_data_quality` BEFORE UPDATE ON `jo_records` FOR EACH ROW BEGIN
  SET NEW.data_quality =
      0
      + CASE WHEN NEW.density_g_cm3 IS NOT NULL THEN 10 ELSE 0 END
      + CASE WHEN NEW.re_conc_value IS NOT NULL THEN 10 ELSE 0 END
      - CASE WHEN NEW.re_conc_value_upper IS NOT NULL THEN 5 ELSE 0 END
      + CASE WHEN NEW.omega2 IS NOT NULL THEN 5 ELSE 0 END
      + CASE WHEN NEW.omega4 IS NOT NULL THEN 5 ELSE 0 END
      + CASE WHEN NEW.omega6 IS NOT NULL THEN 5 ELSE 0 END
      + CASE WHEN NEW.refractive_index_option = 3 THEN 10 ELSE 0 END
      + CASE
          WHEN NEW.refractive_index_option = 2
           AND NEW.refractive_index_note IS NOT NULL
           AND TRIM(NEW.refractive_index_note) <> ''
          THEN 10 ELSE 0
        END
      + CASE WHEN NEW.sigma_f_s_option = 2 THEN 10 ELSE 0 END
      + CASE WHEN NEW.mag_dipole_option IN (0, 2) THEN 10 ELSE 0 END
      + CASE WHEN NEW.combinatorial_jo_option = 2 THEN 5 ELSE 0 END
      + CASE WHEN NEW.reduced_element_option = 2 THEN 5 ELSE 0 END
      + CASE WHEN NEW.jo_recalc_by_loms = 2 THEN 5 ELSE 0 END;
END
$$
DELIMITER ;

-- -----------------------------------------------
-- Table structure for `jo_publications`
-- -----------------------------------------------

CREATE TABLE `jo_publications` (
  `id` int NOT NULL,
  `doi` varchar(255) DEFAULT NULL,
  `alex_id` varchar(50) DEFAULT NULL,
  `title` varchar(500) NOT NULL,
  `journal` varchar(255) DEFAULT NULL,
  `year` smallint DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `authors` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `metadata` json DEFAULT NULL,
  `alex_refs` json DEFAULT NULL,
  `alex_citations` json DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
ALTER TABLE `jo_publications`
  ADD PRIMARY KEY (`id`),
  MODIFY `id` int NOT NULL AUTO_INCREMENT,
  ADD UNIQUE KEY `doi` (`doi`),
  ADD UNIQUE KEY `alex_id` (`alex_id`);

SET FOREIGN_KEY_CHECKS=1;

-- -----------------------------------------------
-- Functions
-- -----------------------------------------------

DELIMITER $$
CREATE FUNCTION calc_jo_record_data_quality(p_record_id INT)
  RETURNS INT
  DETERMINISTIC
  READS SQL DATA
BEGIN
  DECLARE v_score INT DEFAULT 0;

  SELECT
      0
      + CASE WHEN jr.density_g_cm3 IS NOT NULL THEN 10 ELSE 0 END
      + CASE WHEN jr.re_conc_value IS NOT NULL THEN 10 ELSE 0 END
      - CASE WHEN jr.re_conc_value_upper IS NOT NULL THEN 5 ELSE 0 END
      + CASE WHEN jr.omega2 IS NOT NULL THEN 5 ELSE 0 END
      + CASE WHEN jr.omega4 IS NOT NULL THEN 5 ELSE 0 END
      + CASE WHEN jr.omega6 IS NOT NULL THEN 5 ELSE 0 END
      + CASE WHEN jr.refractive_index_option = 3 THEN 10 ELSE 0 END
      + CASE
          WHEN jr.refractive_index_option = 2
           AND jr.refractive_index_note IS NOT NULL
           AND TRIM(jr.refractive_index_note) <> ''
          THEN 10 ELSE 0
        END
      + CASE WHEN jr.sigma_f_s_option = 2 THEN 10 ELSE 0 END
      + CASE WHEN jr.mag_dipole_option IN (0, 2) THEN 10 ELSE 0 END
      + CASE WHEN jr.combinatorial_jo_option = 2 THEN 5 ELSE 0 END
      + CASE WHEN jr.reduced_element_option = 2 THEN 5 ELSE 0 END
      + CASE WHEN jr.jo_recalc_by_loms = 2 THEN 5 ELSE 0 END
      + CASE
          WHEN EXISTS (
            SELECT 1
            FROM jo_composition_elements jce
            WHERE jce.record_id = jr.id
              AND jce.element = jr.re_ion COLLATE utf8mb4_unicode_520_ci
              AND jce.c_mol IS NOT NULL
              AND jce.c_wt IS NOT NULL
          )
          THEN 20 ELSE 0
        END
  INTO v_score
  FROM jo_records jr
  WHERE jr.id = p_record_id;
  RETURN COALESCE(v_score, 0);
END$$
DELIMITER ;