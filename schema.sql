-- IEMA CRMOps database structure (STRUCTURE ONLY — no client or user data)
-- Source: phpMyAdmin export, MariaDB 11.4. Load into an empty database.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `ai_usage_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `feature` enum('followup','proposal_note','proposal_assist','interaction_summary','next_action','lead_intelligence') NOT NULL,
  `lead_id` int(10) UNSIGNED DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `audit_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `entity_type` varchar(60) NOT NULL,
  `entity_id` int(10) UNSIGNED DEFAULT NULL,
  `details` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `clients` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_name` varchar(180) NOT NULL,
  `rc_number` varchar(40) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `sector` varchar(120) DEFAULT NULL,
  `company_size` varchar(60) DEFAULT NULL,
  `website` varchar(190) DEFAULT NULL,
  `primary_contact_name` varchar(120) DEFAULT NULL,
  `primary_contact_email` varchar(190) DEFAULT NULL,
  `primary_contact_phone` varchar(40) DEFAULT NULL,
  `is_strategic` tinyint(1) NOT NULL DEFAULT 0,
  `auditops_sync_id` varchar(80) DEFAULT NULL,
  `status` enum('prospect','active','inactive') NOT NULL DEFAULT 'prospect',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `assigned_crm_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Client Relationship Manager (users.id) who owns this account post-sale',
  `health_status` enum('Good','At Risk','Critical') NOT NULL DEFAULT 'Good'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `client_escalations` (
  `id` int(10) UNSIGNED NOT NULL,
  `client_id` int(10) UNSIGNED NOT NULL,
  `issue` varchar(500) NOT NULL,
  `raised_by` int(10) UNSIGNED DEFAULT NULL,
  `raised_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('Open','Resolved') NOT NULL DEFAULT 'Open',
  `resolved_by` int(10) UNSIGNED DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolution_notes` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `client_schemes` (
  `client_id` int(10) UNSIGNED NOT NULL,
  `scheme_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `contacts` (
  `id` int(10) UNSIGNED NOT NULL,
  `client_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `role` enum('Decision Maker','Technical Contact','Finance Contact','Other') NOT NULL DEFAULT 'Other',
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `interactions` (
  `id` int(10) UNSIGNED NOT NULL,
  `client_id` int(10) UNSIGNED DEFAULT NULL,
  `lead_id` int(10) UNSIGNED DEFAULT NULL,
  `type` enum('Call','Email','Meeting','Site Visit') NOT NULL,
  `interaction_date` datetime NOT NULL,
  `notes` text DEFAULT NULL,
  `logged_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoices` (
  `id` int(10) UNSIGNED NOT NULL,
  `invoice_number` varchar(40) NOT NULL,
  `client_id` int(10) UNSIGNED DEFAULT NULL,
  `lead_id` int(10) UNSIGNED DEFAULT NULL,
  `proposal_id` int(10) UNSIGNED DEFAULT NULL,
  `bill_to_name` varchar(180) NOT NULL,
  `bill_to_contact` varchar(200) DEFAULT NULL,
  `bill_to_phone` varchar(50) DEFAULT NULL,
  `bill_to_address` varchar(255) DEFAULT NULL,
  `bill_to_email` varchar(190) DEFAULT NULL,
  `issue_date` date NOT NULL,
  `due_date` date NOT NULL,
  `subtotal_ngn` decimal(14,2) NOT NULL DEFAULT 0.00,
  `discount_ngn` decimal(14,2) NOT NULL DEFAULT 0.00,
  `vat_rate` decimal(5,2) NOT NULL DEFAULT 7.50,
  `vat_amount_ngn` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_ngn` decimal(14,2) NOT NULL DEFAULT 0.00,
  `payment_account_note` varchar(255) DEFAULT NULL,
  `notes` varchar(255) DEFAULT 'Payment is 100%',
  `status` enum('draft','sent','part-paid','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `service_code` varchar(60) DEFAULT NULL,
  `description` varchar(500) NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `unit_price_ngn` decimal(14,2) NOT NULL DEFAULT 0.00,
  `amount_ngn` decimal(14,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_payments` (
  `id` int(10) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `amount_ngn` decimal(14,2) NOT NULL,
  `payment_date` date NOT NULL,
  `reference` varchar(255) DEFAULT NULL COMMENT 'e.g. bank transfer ref, cheque number',
  `notes` varchar(255) DEFAULT NULL,
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `leads` (
  `id` int(10) UNSIGNED NOT NULL,
  `reference_number` varchar(40) NOT NULL,
  `company_name` varchar(180) NOT NULL,
  `contact_person` varchar(120) DEFAULT NULL,
  `contact_email` varchar(190) DEFAULT NULL,
  `contact_phone` varchar(40) DEFAULT NULL,
  `sector` varchar(120) DEFAULT NULL,
  `lead_source` varchar(100) DEFAULT NULL,
  `estimated_value_ngn` decimal(14,2) DEFAULT NULL,
  `country_region` varchar(100) DEFAULT NULL,
  `stage` enum('New','Contacted','Needs Assessment','Proposal Sent','Negotiation','Won','Lost') NOT NULL DEFAULT 'New',
  `lost_reason` enum('Price','Competitor','No Budget','Timing','Scope Mismatch','Other') DEFAULT NULL,
  `assigned_to` int(10) UNSIGNED DEFAULT NULL,
  `client_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `quote_details` text DEFAULT NULL COMMENT 'Raw submission from the public Get a Quote form — address, website, branches, timeframe, request type, certification stage, business description.'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lead_schemes` (
  `lead_id` int(10) UNSIGNED NOT NULL,
  `scheme_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `proposals` (
  `id` int(10) UNSIGNED NOT NULL,
  `reference_number` varchar(60) NOT NULL,
  `client_id` int(10) UNSIGNED DEFAULT NULL,
  `lead_id` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('Draft','Internal Review','Sent to Client','Under Client Review','Revised','Signed','Rejected') NOT NULL DEFAULT 'Draft',
  `value_ngn` decimal(14,2) NOT NULL DEFAULT 0.00,
  `document_link` varchar(255) DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `signed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `scope_of_work` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `proposal_revisions` (
  `id` int(10) UNSIGNED NOT NULL,
  `proposal_id` int(10) UNSIGNED NOT NULL,
  `previous_value_ngn` decimal(14,2) DEFAULT NULL,
  `previous_status` varchar(40) DEFAULT NULL,
  `revised_at` datetime NOT NULL DEFAULT current_timestamp(),
  `revised_by` int(10) UNSIGNED DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `renewal_opportunities` (
  `id` int(10) UNSIGNED NOT NULL,
  `client_id` int(10) UNSIGNED NOT NULL,
  `renewal_date` date NOT NULL,
  `follow_up_status` enum('Pending','Flagged','Contacted','Completed') NOT NULL DEFAULT 'Pending',
  `opportunity_type` enum('Renewal','Upsell','Cross-sell') NOT NULL DEFAULT 'Renewal',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `scheduled_email_drafts` (
  `id` int(10) UNSIGNED NOT NULL,
  `lead_id` int(10) UNSIGNED NOT NULL,
  `subject` varchar(200) NOT NULL,
  `body` text NOT NULL,
  `status` enum('pending_review','approved','sent','dismissed') NOT NULL DEFAULT 'pending_review',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schemes` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `surveillance_tasks` (
  `id` int(10) UNSIGNED NOT NULL,
  `client_id` int(10) UNSIGNED DEFAULT NULL,
  `company_name` varchar(180) NOT NULL,
  `cert_summary` text DEFAULT NULL,
  `assigned_to` int(10) UNSIGNED DEFAULT NULL,
  `assigned_by` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('Assigned','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Assigned',
  `instructions` text DEFAULT NULL,
  `outcome_notes` text DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `surveillance_task_notifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `task_id` int(10) UNSIGNED NOT NULL,
  `recipient_id` int(10) UNSIGNED NOT NULL,
  `message` varchar(255) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sync_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `sync_type` enum('client_push','renewal_pull') NOT NULL,
  `entity_type` varchar(60) NOT NULL,
  `entity_id` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('success','failed') NOT NULL,
  `details` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('bd_officer','bd_manager','management','it_admin','account_manager','auditor') NOT NULL DEFAULT 'bd_officer',
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `mail_email` varchar(190) DEFAULT NULL,
  `mail_password_enc` text DEFAULT NULL,
  `mail_imap_host` varchar(190) DEFAULT NULL,
  `mail_imap_port` int(11) DEFAULT 993,
  `mail_smtp_host` varchar(190) DEFAULT NULL,
  `mail_smtp_port` int(11) DEFAULT 465,
  `mail_skip_cert_check` tinyint(1) NOT NULL DEFAULT 0,
  `signature_title` varchar(150) DEFAULT NULL COMMENT 'Job title/position shown in email signature',
  `signature_phone` varchar(40) DEFAULT NULL COMMENT 'Phone number shown in email signature'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexes, auto-increment and foreign keys

ALTER TABLE `ai_usage_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `lead_id` (`lead_id`);

ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_clients_rc_number` (`rc_number`);

ALTER TABLE `client_escalations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `raised_by` (`raised_by`),
  ADD KEY `resolved_by` (`resolved_by`);

ALTER TABLE `client_schemes`
  ADD PRIMARY KEY (`client_id`,`scheme_id`),
  ADD KEY `scheme_id` (`scheme_id`);

ALTER TABLE `contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`);

ALTER TABLE `interactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `lead_id` (`lead_id`),
  ADD KEY `logged_by` (`logged_by`);

ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `lead_id` (`lead_id`),
  ADD KEY `proposal_id` (`proposal_id`),
  ADD KEY `created_by` (`created_by`);

ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `invoice_id` (`invoice_id`);

ALTER TABLE `invoice_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `recorded_by` (`recorded_by`);

ALTER TABLE `leads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_number` (`reference_number`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `client_id` (`client_id`);

ALTER TABLE `lead_schemes`
  ADD PRIMARY KEY (`lead_id`,`scheme_id`),
  ADD KEY `scheme_id` (`scheme_id`);

ALTER TABLE `proposals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_number` (`reference_number`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `lead_id` (`lead_id`);

ALTER TABLE `proposal_revisions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `proposal_id` (`proposal_id`),
  ADD KEY `revised_by` (`revised_by`);

ALTER TABLE `renewal_opportunities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`);

ALTER TABLE `scheduled_email_drafts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lead_id` (`lead_id`),
  ADD KEY `reviewed_by` (`reviewed_by`);

ALTER TABLE `schemes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

ALTER TABLE `surveillance_tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `assigned_by` (`assigned_by`);

ALTER TABLE `surveillance_task_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `task_id` (`task_id`),
  ADD KEY `recipient_id` (`recipient_id`);

ALTER TABLE `sync_log`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

ALTER TABLE `ai_usage_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `audit_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `clients`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `client_escalations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `contacts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `interactions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `invoices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `invoice_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `invoice_payments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `leads`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `proposals`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `proposal_revisions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `renewal_opportunities`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `scheduled_email_drafts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `schemes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `surveillance_tasks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `surveillance_task_notifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `sync_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `ai_usage_log`
  ADD CONSTRAINT `ai_usage_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `ai_usage_log_ibfk_2` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL;

ALTER TABLE `audit_log`
  ADD CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `client_escalations`
  ADD CONSTRAINT `client_escalations_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `client_escalations_ibfk_2` FOREIGN KEY (`raised_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `client_escalations_ibfk_3` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `client_schemes`
  ADD CONSTRAINT `client_schemes_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `client_schemes_ibfk_2` FOREIGN KEY (`scheme_id`) REFERENCES `schemes` (`id`) ON DELETE CASCADE;

ALTER TABLE `contacts`
  ADD CONSTRAINT `contacts_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

ALTER TABLE `interactions`
  ADD CONSTRAINT `interactions_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `interactions_ibfk_2` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `interactions_ibfk_3` FOREIGN KEY (`logged_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `invoices_ibfk_3` FOREIGN KEY (`proposal_id`) REFERENCES `proposals` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `invoices_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `invoice_items`
  ADD CONSTRAINT `invoice_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE;

ALTER TABLE `invoice_payments`
  ADD CONSTRAINT `invoice_payments_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoice_payments_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `leads`
  ADD CONSTRAINT `leads_ibfk_1` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leads_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL;

ALTER TABLE `lead_schemes`
  ADD CONSTRAINT `lead_schemes_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lead_schemes_ibfk_2` FOREIGN KEY (`scheme_id`) REFERENCES `schemes` (`id`) ON DELETE CASCADE;

ALTER TABLE `proposals`
  ADD CONSTRAINT `proposals_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `proposals_ibfk_2` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL;

ALTER TABLE `proposal_revisions`
  ADD CONSTRAINT `proposal_revisions_ibfk_1` FOREIGN KEY (`proposal_id`) REFERENCES `proposals` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `proposal_revisions_ibfk_2` FOREIGN KEY (`revised_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `renewal_opportunities`
  ADD CONSTRAINT `renewal_opportunities_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

ALTER TABLE `scheduled_email_drafts`
  ADD CONSTRAINT `scheduled_email_drafts_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `scheduled_email_drafts_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `surveillance_tasks`
  ADD CONSTRAINT `surveillance_tasks_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `surveillance_tasks_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `surveillance_tasks_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `surveillance_task_notifications`
  ADD CONSTRAINT `surveillance_task_notifications_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `surveillance_tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `surveillance_task_notifications_ibfk_2` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- Reference list of certification schemes (not client data)
INSERT INTO `schemes` (`id`, `name`) VALUES
(4, 'EcoMark'),
(23, 'ISO 13485'),
(2, 'ISO 14001'),
(179, 'ISO 14001 Environmental Management'),
(24, 'ISO 20121'),
(22, 'ISO 22001'),
(27, 'ISO 26000'),
(31, 'ISO 27301'),
(62, 'ISO 27301 Business Continuity Management System'),
(28, 'ISO 31000'),
(25, 'ISO 37001'),
(3, 'ISO 45001'),
(29, 'ISO 50001'),
(1, 'ISO 9001'),
(42, 'ISO 9001 Quality Management'),
(26, 'ISO/IEC 17025'),
(32, 'ISO/IEC 17065'),
(82, 'ISO/IEC 17065 Product Certification'),
(30, 'ISO/IEC 27001'),
(41, 'ISO/IEC 27001 Information Security Management'),
(6, 'NDPA Compliance'),
(33, 'NDPC Data Protection Compliance'),
(7, 'Other'),
(5, 'Personnel Certification');

SET FOREIGN_KEY_CHECKS = 1;
