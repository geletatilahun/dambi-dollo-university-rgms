-- ============================================================
-- DDU Research Grant Management System (DDU-RGMS)
-- Database Schema v1.1  (MariaDB-compatible)
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+03:00";

CREATE DATABASE IF NOT EXISTS `ddu_rgms`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `ddu_rgms`;

-- 1. ROLES
CREATE TABLE `roles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL UNIQUE,
  `description` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'researcher',  'Submits proposals and progress reports'),
(2, 'coordinator', 'First-level review and reviewer assignment'),
(3, 'college',     'College-level endorsement'),
(4, 'director',    'Strategic review, forwards to VP'),
(5, 'vp',          'Final approval or rejection'),
(6, 'reviewer',    'Scores and comments on proposals'),
(7, 'admin',       'System administrator');

-- 2. DEPARTMENTS
CREATE TABLE `departments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `code` VARCHAR(20) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. USERS
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role_id` INT UNSIGNED NOT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `failed_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` DATETIME DEFAULT NULL,
  `last_login` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_users_role` (`role_id`),
  KEY `fk_users_dept` (`department_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`),
  CONSTRAINT `fk_users_dept` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. RESEARCHER PROFILES
CREATE TABLE `researcher_profiles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL UNIQUE,
  `academic_rank` VARCHAR(80) DEFAULT NULL,
  `specialization` VARCHAR(150) DEFAULT NULL,
  `orcid` VARCHAR(30) DEFAULT NULL,
  `bio` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_profile_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. PROPOSALS
CREATE TABLE `proposals` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proposal_code` VARCHAR(30) NOT NULL UNIQUE,
  `researcher_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `abstract` TEXT NOT NULL,
  `objectives` TEXT DEFAULT NULL,
  `methodology` TEXT DEFAULT NULL,
  `timeline_months` INT UNSIGNED DEFAULT NULL,
  `total_budget` DECIMAL(14,2) DEFAULT 0.00,
  `status` ENUM('draft','submitted','under_review','college_review',
                'director_review','vp_review','approved','rejected',
                'returned','project_created') NOT NULL DEFAULT 'draft',
  `current_stage` VARCHAR(50) DEFAULT NULL,
  `submitted_at` DATETIME DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `parent_proposal_id` INT UNSIGNED DEFAULT NULL,
  `merge_note` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_prop_researcher` (`researcher_id`),
  KEY `fk_prop_parent` (`parent_proposal_id`),
  KEY `idx_prop_status` (`status`),
  CONSTRAINT `fk_prop_researcher` FOREIGN KEY (`researcher_id`) REFERENCES `users`(`id`),
  CONSTRAINT `fk_prop_parent` FOREIGN KEY (`parent_proposal_id`) REFERENCES `proposals`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. PROPOSAL DOCUMENTS
CREATE TABLE `proposal_documents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proposal_id` INT UNSIGNED NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_type` VARCHAR(50) DEFAULT NULL,
  `file_size` INT UNSIGNED DEFAULT NULL,
  `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_doc_proposal` (`proposal_id`),
  CONSTRAINT `fk_doc_proposal` FOREIGN KEY (`proposal_id`) REFERENCES `proposals`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 7. BUDGET ITEMS  (FIXED for MariaDB — no generated column)
-- ============================================================
CREATE TABLE `budget_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proposal_id` INT UNSIGNED NOT NULL,
  `category` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `unit_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_budget_proposal` (`proposal_id`),
  CONSTRAINT `fk_budget_proposal` FOREIGN KEY (`proposal_id`) REFERENCES `proposals`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Trigger to auto-calculate total_cost on insert
DELIMITER $$
CREATE TRIGGER `trg_budget_insert`
BEFORE INSERT ON `budget_items`
FOR EACH ROW
BEGIN
  SET NEW.total_cost = NEW.quantity * NEW.unit_cost;
END$$
DELIMITER ;

-- Trigger to auto-recalculate on update
DELIMITER $$
CREATE TRIGGER `trg_budget_update`
BEFORE UPDATE ON `budget_items`
FOR EACH ROW
BEGIN
  SET NEW.total_cost = NEW.quantity * NEW.unit_cost;
END$$
DELIMITER ;

-- ============================================================
-- 8. REVIEW ASSIGNMENTS
-- ============================================================
CREATE TABLE `review_assignments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proposal_id` INT UNSIGNED NOT NULL,
  `reviewer_id` INT UNSIGNED NOT NULL,
  `assigned_by` INT UNSIGNED NOT NULL,
  `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `due_date` DATE DEFAULT NULL,
  `status` ENUM('pending','completed','declined') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`),
  KEY `fk_ra_proposal` (`proposal_id`),
  KEY `fk_ra_reviewer` (`reviewer_id`),
  KEY `fk_ra_assigned_by` (`assigned_by`),
  CONSTRAINT `fk_ra_proposal` FOREIGN KEY (`proposal_id`) REFERENCES `proposals`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ra_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users`(`id`),
  CONSTRAINT `fk_ra_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. REVIEWS
CREATE TABLE `reviews` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `assignment_id` INT UNSIGNED NOT NULL UNIQUE,
  `score` DECIMAL(4,2) NOT NULL DEFAULT 0.00,
  `comments` TEXT DEFAULT NULL,
  `merge_recommended` TINYINT(1) NOT NULL DEFAULT 0,
  `merge_target_id` INT UNSIGNED DEFAULT NULL,
  `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_rev_assignment` (`assignment_id`),
  KEY `fk_rev_merge_target` (`merge_target_id`),
  CONSTRAINT `fk_rev_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `review_assignments`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rev_merge_target` FOREIGN KEY (`merge_target_id`) REFERENCES `proposals`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. APPROVALS
CREATE TABLE `approvals` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proposal_id` INT UNSIGNED NOT NULL,
  `stage` ENUM('coordinator','college','director','vp') NOT NULL,
  `approver_id` INT UNSIGNED NOT NULL,
  `decision` ENUM('approved','rejected','returned','forwarded') NOT NULL,
  `comment` TEXT DEFAULT NULL,
  `decided_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_app_proposal` (`proposal_id`),
  KEY `fk_app_approver` (`approver_id`),
  CONSTRAINT `fk_app_proposal` FOREIGN KEY (`proposal_id`) REFERENCES `proposals`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_app_approver` FOREIGN KEY (`approver_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. PROJECTS
CREATE TABLE `projects` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_code` VARCHAR(30) NOT NULL UNIQUE,
  `proposal_id` INT UNSIGNED NOT NULL UNIQUE,
  `leader_id` INT UNSIGNED NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `funds_awarded` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active','extended','completed','terminated') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_proj_proposal` (`proposal_id`),
  KEY `fk_proj_leader` (`leader_id`),
  CONSTRAINT `fk_proj_proposal` FOREIGN KEY (`proposal_id`) REFERENCES `proposals`(`id`),
  CONSTRAINT `fk_proj_leader` FOREIGN KEY (`leader_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. PROGRESS REPORTS
CREATE TABLE `progress_reports` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `summary` TEXT NOT NULL,
  `file_path` VARCHAR(500) DEFAULT NULL,
  `report_date` DATE NOT NULL,
  `review_status` ENUM('pending','reviewed','accepted','returned') NOT NULL DEFAULT 'pending',
  `reviewed_by` INT UNSIGNED DEFAULT NULL,
  `review_comment` TEXT DEFAULT NULL,
  `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_pr_project` (`project_id`),
  KEY `fk_pr_reviewer` (`reviewed_by`),
  CONSTRAINT `fk_pr_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pr_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. NOTIFICATIONS
CREATE TABLE `notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `link` VARCHAR(500) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_notif_user` (`user_id`),
  KEY `idx_notif_unread` (`user_id`,`is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. AUDIT LOG
CREATE TABLE `audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `table_name` VARCHAR(80) DEFAULT NULL,
  `record_id` INT UNSIGNED DEFAULT NULL,
  `details` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_audit_user` (`user_id`),
  KEY `idx_audit_action` (`action`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15. SYSTEM SETTINGS
CREATE TABLE `settings` (
  `key_name` VARCHAR(80) NOT NULL,
  `value` TEXT DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `settings` (`key_name`,`value`) VALUES
('site_name','DDU Research Grant Management System'),
('max_budget_per_proposal','500000.00'),
('max_upload_size_mb','10'),
('allow_registration','1'),
('session_timeout_minutes','30'),
('smtp_host',''),
('smtp_port','587'),
('smtp_user',''),
('smtp_pass',''),
('smtp_from','noreply@ddu.edu.et');

-- 16. DEFAULT DATA
INSERT INTO `departments` (`id`,`name`,`code`) VALUES
(1,'Research and Community Engagement Office','RCEO');

INSERT INTO `users` (`full_name`,`email`,`password`,`role_id`,`department_id`)
VALUES (
  'System Administrator',
  'admin@ddu.edu.et',
  '$2y$10$e0MYzXyjpJS7Pd0RVvHwHe1HlCS4bZJ18JuywdB3q5wLz4Hf0eO2q',
  7, 1
);