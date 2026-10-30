SET NAMES utf8mb4;

USE `garage_system`;

ALTER TABLE `admin`
  ADD COLUMN IF NOT EXISTS `full_name` VARCHAR(100) DEFAULT NULL AFTER `username`,
  ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `password_hash`;

ALTER TABLE `services`
  ADD COLUMN IF NOT EXISTS `image` VARCHAR(100) DEFAULT NULL AFTER `description`;

UPDATE `services` SET `image` = CASE `name`
    WHEN 'General Service' THEN 'repair4.jpg'
    WHEN 'Oil Change' THEN 'engine1.jpg'
    WHEN 'Diagnostics' THEN 'diagnosis5.jpg'
    WHEN 'Brake Repair' THEN 'wheel2.jpg'
    WHEN 'Engine Repair' THEN 'engine3.jpg'
    WHEN 'Battery Service' THEN 'diagnosis6.jpg'
    WHEN 'Wheel Alignment' THEN 'garage1.jpg'
    WHEN 'Tyre Rotation & Replacement' THEN 'wheel3.jpg'
    WHEN 'Suspension & Exhaust' THEN 'wheel1.jpg'
    WHEN 'Car Electrical Repair' THEN 'diagnosis3.jpg'
    WHEN 'Car Painting' THEN 'painting2.jpg'
    WHEN 'Pre-Purchase Inspection' THEN 'repair3.jpg'
    ELSE `image`
  END
WHERE `image` IS NULL;

CREATE TABLE IF NOT EXISTS `mechanics` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`  VARCHAR(100) NOT NULL,
  `phone`      VARCHAR(20)  DEFAULT NULL,
  `specialty`  VARCHAR(100) DEFAULT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `parts` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `part_number`    VARCHAR(40)   DEFAULT NULL,
  `name`           VARCHAR(120)  NOT NULL,
  `unit`           VARCHAR(20)   NOT NULL DEFAULT 'piece',
  `cost_price`     INT UNSIGNED  NOT NULL DEFAULT 0,
  `sell_price`     INT UNSIGNED  NOT NULL DEFAULT 0,
  `stock_quantity` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `reorder_level`  DECIMAL(10,2) NOT NULL DEFAULT 0,
  `supplier`       VARCHAR(100)  DEFAULT NULL,
  `location`       VARCHAR(50)   DEFAULT NULL,
  `is_active`      TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_parts_number` (`part_number`),
  KEY `idx_parts_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `breakdown_requests`
  ADD COLUMN IF NOT EXISTS `mechanic_id` INT UNSIGNED DEFAULT NULL AFTER `status`,
  ADD KEY IF NOT EXISTS `idx_breakdowns_mechanic` (`mechanic_id`),
  ADD CONSTRAINT `fk_breakdowns_mechanic` FOREIGN KEY IF NOT EXISTS (`mechanic_id`) REFERENCES `mechanics` (`id`) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS `jobs` (
  `id`              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `customer_id`     INT UNSIGNED      DEFAULT NULL,
  `vehicle_id`      INT UNSIGNED      DEFAULT NULL,
  `booking_id`      INT UNSIGNED      DEFAULT NULL,
  `breakdown_id`    INT UNSIGNED      DEFAULT NULL,
  `mechanic_id`     INT UNSIGNED      DEFAULT NULL,
  `contact_name`    VARCHAR(100)      NOT NULL,
  `contact_phone`   VARCHAR(20)       NOT NULL,
  `contact_email`   VARCHAR(150)      DEFAULT NULL,
  `vehicle_plate`   VARCHAR(15)       NOT NULL,
  `vehicle_make`    VARCHAR(50)       NOT NULL,
  `vehicle_model`   VARCHAR(50)       NOT NULL,
  `vehicle_year`    SMALLINT UNSIGNED DEFAULT NULL,
  `vehicle_colour`  VARCHAR(30)       DEFAULT NULL,
  `odometer_km`     INT UNSIGNED      DEFAULT NULL,
  `fuel_level`      ENUM('empty','quarter','half','three_quarters','full') DEFAULT NULL,
  `condition_notes` TEXT              DEFAULT NULL,
  `complaint`       TEXT              NOT NULL,
  `diagnosis`       TEXT              DEFAULT NULL,
  `recommendations` TEXT              DEFAULT NULL,
  `customer_note`   TEXT              DEFAULT NULL,
  `status`          ENUM('checked_in','in_progress','awaiting_parts','awaiting_approval','ready','collected','cancelled') NOT NULL DEFAULT 'checked_in',
  `promised_at`     DATETIME          DEFAULT NULL,
  `ready_at`        DATETIME          DEFAULT NULL,
  `collected_at`    DATETIME          DEFAULT NULL,
  `created_by`      INT UNSIGNED      DEFAULT NULL,
  `created_at`      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jobs_booking` (`booking_id`),
  UNIQUE KEY `uq_jobs_breakdown` (`breakdown_id`),
  KEY `idx_jobs_status` (`status`, `created_at`),
  KEY `idx_jobs_customer` (`customer_id`),
  KEY `idx_jobs_vehicle` (`vehicle_id`),
  KEY `idx_jobs_mechanic` (`mechanic_id`),
  KEY `idx_jobs_plate` (`vehicle_plate`),
  KEY `idx_jobs_phone` (`contact_phone`),
  KEY `idx_jobs_created_by` (`created_by`),
  CONSTRAINT `fk_jobs_customer`   FOREIGN KEY (`customer_id`)  REFERENCES `customers` (`id`)          ON DELETE SET NULL,
  CONSTRAINT `fk_jobs_vehicle`    FOREIGN KEY (`vehicle_id`)   REFERENCES `vehicles` (`id`)           ON DELETE SET NULL,
  CONSTRAINT `fk_jobs_booking`    FOREIGN KEY (`booking_id`)   REFERENCES `bookings` (`id`)           ON DELETE SET NULL,
  CONSTRAINT `fk_jobs_breakdown`  FOREIGN KEY (`breakdown_id`) REFERENCES `breakdown_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jobs_mechanic`   FOREIGN KEY (`mechanic_id`)  REFERENCES `mechanics` (`id`)          ON DELETE SET NULL,
  CONSTRAINT `fk_jobs_created_by` FOREIGN KEY (`created_by`)   REFERENCES `admin` (`id`)              ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `job_items` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `job_id`      INT UNSIGNED  NOT NULL,
  `item_type`   ENUM('labour','part','other') NOT NULL,
  `description` VARCHAR(200)  NOT NULL,
  `part_id`     INT UNSIGNED  DEFAULT NULL,
  `mechanic_id` INT UNSIGNED  DEFAULT NULL,
  `quantity`    DECIMAL(10,2) NOT NULL DEFAULT 1,
  `unit_price`  INT UNSIGNED  NOT NULL DEFAULT 0,
  `created_by`  INT UNSIGNED  DEFAULT NULL,
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_job_items_job` (`job_id`),
  KEY `idx_job_items_part` (`part_id`),
  KEY `idx_job_items_mechanic` (`mechanic_id`, `created_at`),
  KEY `idx_job_items_created_by` (`created_by`),
  CONSTRAINT `fk_job_items_job`        FOREIGN KEY (`job_id`)      REFERENCES `jobs` (`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_job_items_part`       FOREIGN KEY (`part_id`)     REFERENCES `parts` (`id`),
  CONSTRAINT `fk_job_items_mechanic`   FOREIGN KEY (`mechanic_id`) REFERENCES `mechanics` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_job_items_created_by` FOREIGN KEY (`created_by`)  REFERENCES `admin` (`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `part_id`         INT UNSIGNED  NOT NULL,
  `quantity_change` DECIMAL(10,2) NOT NULL,
  `reason`          ENUM('opening','purchase','job_use','job_return','adjustment') NOT NULL,
  `job_id`          INT UNSIGNED  DEFAULT NULL,
  `unit_cost`       INT UNSIGNED  DEFAULT NULL,
  `note`            VARCHAR(255)  DEFAULT NULL,
  `admin_id`        INT UNSIGNED  DEFAULT NULL,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_movements_part` (`part_id`, `created_at`),
  KEY `idx_movements_reason` (`reason`, `created_at`),
  KEY `idx_movements_job` (`job_id`),
  KEY `idx_movements_admin` (`admin_id`),
  CONSTRAINT `fk_movements_part`  FOREIGN KEY (`part_id`)  REFERENCES `parts` (`id`),
  CONSTRAINT `fk_movements_job`   FOREIGN KEY (`job_id`)   REFERENCES `jobs` (`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_movements_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `invoices` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`        INT UNSIGNED NOT NULL,
  `status`        ENUM('issued','void') NOT NULL DEFAULT 'issued',
  `bill_name`     VARCHAR(100) NOT NULL,
  `bill_phone`    VARCHAR(20)  NOT NULL,
  `bill_email`    VARCHAR(150) DEFAULT NULL,
  `vehicle_plate` VARCHAR(15)  NOT NULL,
  `vehicle_label` VARCHAR(120) NOT NULL,
  `odometer_km`   INT UNSIGNED DEFAULT NULL,
  `subtotal`      INT UNSIGNED NOT NULL,
  `discount`      INT UNSIGNED NOT NULL DEFAULT 0,
  `tax_rate`      DECIMAL(5,2) NOT NULL DEFAULT 0,
  `tax_amount`    INT UNSIGNED NOT NULL DEFAULT 0,
  `total`         INT UNSIGNED NOT NULL,
  `notes`         TEXT         DEFAULT NULL,
  `void_reason`   VARCHAR(255) DEFAULT NULL,
  `voided_at`     DATETIME     DEFAULT NULL,
  `issued_by`     INT UNSIGNED DEFAULT NULL,
  `issued_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_invoices_job` (`job_id`),
  KEY `idx_invoices_status` (`status`, `issued_at`),
  KEY `idx_invoices_issued_by` (`issued_by`),
  CONSTRAINT `fk_invoices_job`       FOREIGN KEY (`job_id`)    REFERENCES `jobs` (`id`),
  CONSTRAINT `fk_invoices_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `admin` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `invoice_id`  INT UNSIGNED  NOT NULL,
  `item_type`   ENUM('labour','part','other') NOT NULL,
  `description` VARCHAR(200)  NOT NULL,
  `quantity`    DECIMAL(10,2) NOT NULL,
  `unit_price`  INT UNSIGNED  NOT NULL,
  `line_total`  INT UNSIGNED  NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_invoice_items_invoice` (`invoice_id`),
  CONSTRAINT `fk_invoice_items_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payments` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id`  INT UNSIGNED NOT NULL,
  `amount`      INT UNSIGNED NOT NULL,
  `method`      ENUM('cash','mobile_money','bank_transfer','card') NOT NULL,
  `reference`   VARCHAR(100) DEFAULT NULL,
  `note`        VARCHAR(255) DEFAULT NULL,
  `paid_on`     DATE         NOT NULL,
  `received_by` INT UNSIGNED DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payments_invoice` (`invoice_id`),
  KEY `idx_payments_paid_on` (`paid_on`),
  KEY `idx_payments_received_by` (`received_by`),
  CONSTRAINT `fk_payments_invoice`     FOREIGN KEY (`invoice_id`)  REFERENCES `invoices` (`id`),
  CONSTRAINT `fk_payments_received_by` FOREIGN KEY (`received_by`) REFERENCES `admin` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
