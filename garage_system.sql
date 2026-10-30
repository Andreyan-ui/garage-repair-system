SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `garage_system`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE `garage_system`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `invoice_items`;
DROP TABLE IF EXISTS `invoices`;
DROP TABLE IF EXISTS `stock_movements`;
DROP TABLE IF EXISTS `job_items`;
DROP TABLE IF EXISTS `jobs`;
DROP TABLE IF EXISTS `parts`;
DROP TABLE IF EXISTS `mechanics`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `breakdown_requests`;
DROP TABLE IF EXISTS `bookings`;
DROP TABLE IF EXISTS `vehicles`;
DROP TABLE IF EXISTS `customers`;
DROP TABLE IF EXISTS `services`;
DROP TABLE IF EXISTS `faqs`;
DROP TABLE IF EXISTS `admin`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `admin` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)  NOT NULL,
  `full_name`     VARCHAR(100) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `customers` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`     VARCHAR(100) NOT NULL,
  `phone`         VARCHAR(20)  NOT NULL,
  `email`         VARCHAR(150) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customers_phone` (`phone`),
  UNIQUE KEY `uq_customers_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `vehicles` (
  `id`           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `customer_id`  INT UNSIGNED      NOT NULL,
  `plate_number` VARCHAR(15)       NOT NULL,
  `make`         VARCHAR(50)       NOT NULL,
  `model`        VARCHAR(50)       NOT NULL,
  `model_year`   SMALLINT UNSIGNED DEFAULT NULL,
  `colour`       VARCHAR(30)       DEFAULT NULL,
  `created_at`   TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicles_customer_plate` (`customer_id`, `plate_number`),
  KEY `idx_vehicles_plate` (`plate_number`),
  CONSTRAINT `fk_vehicles_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `services` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(80)  NOT NULL,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `image`       VARCHAR(100) DEFAULT NULL,
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_services_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `mechanics` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`  VARCHAR(100) NOT NULL,
  `phone`      VARCHAR(20)  DEFAULT NULL,
  `specialty`  VARCHAR(100) DEFAULT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `parts` (
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

CREATE TABLE `bookings` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference`      VARCHAR(12)  NOT NULL,
  `customer_id`    INT UNSIGNED DEFAULT NULL,
  `vehicle_id`     INT UNSIGNED DEFAULT NULL,
  `service_id`     INT UNSIGNED NOT NULL,
  `contact_name`   VARCHAR(100) NOT NULL,
  `contact_phone`  VARCHAR(20)  NOT NULL,
  `contact_email`  VARCHAR(150) DEFAULT NULL,
  `vehicle_plate`  VARCHAR(15)  NOT NULL,
  `vehicle_make`   VARCHAR(50)  NOT NULL,
  `vehicle_model`  VARCHAR(50)  NOT NULL,
  `preferred_date` DATE         NOT NULL,
  `preferred_slot` ENUM('morning','afternoon') NOT NULL,
  `message`        TEXT         DEFAULT NULL,
  `status`         ENUM('pending','confirmed','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  `garage_note`    TEXT         DEFAULT NULL,
  `cancelled_by`   ENUM('customer','garage') DEFAULT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bookings_reference` (`reference`),
  KEY `idx_bookings_customer` (`customer_id`),
  KEY `idx_bookings_status_date` (`status`, `preferred_date`),
  KEY `idx_bookings_phone` (`contact_phone`),
  KEY `idx_bookings_plate` (`vehicle_plate`),
  CONSTRAINT `fk_bookings_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bookings_vehicle`  FOREIGN KEY (`vehicle_id`)  REFERENCES `vehicles` (`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_bookings_service`  FOREIGN KEY (`service_id`)  REFERENCES `services` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `breakdown_requests` (
  `id`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `reference`           VARCHAR(12)   NOT NULL,
  `customer_id`         INT UNSIGNED  DEFAULT NULL,
  `contact_name`        VARCHAR(100)  NOT NULL,
  `contact_phone`       VARCHAR(20)   NOT NULL,
  `problem_type`        ENUM('wont_start','battery','flat_tyre','overheating','out_of_fuel','locked_out','accident','other') NOT NULL,
  `location_text`       VARCHAR(255)  NOT NULL,
  `latitude`            DECIMAL(10,7) DEFAULT NULL,
  `longitude`           DECIMAL(10,7) DEFAULT NULL,
  `location_accuracy`   INT UNSIGNED  DEFAULT NULL,
  `vehicle_plate`       VARCHAR(15)   DEFAULT NULL,
  `vehicle_description` VARCHAR(100)  DEFAULT NULL,
  `towing`              ENUM('unsure','yes','no') NOT NULL DEFAULT 'unsure',
  `details`             TEXT          DEFAULT NULL,
  `status`              ENUM('new','dispatched','on_site','completed','cancelled') NOT NULL DEFAULT 'new',
  `mechanic_id`         INT UNSIGNED  DEFAULT NULL,
  `responder_name`      VARCHAR(100)  DEFAULT NULL,
  `responder_phone`     VARCHAR(20)   DEFAULT NULL,
  `eta_at`              DATETIME      DEFAULT NULL,
  `garage_note`         TEXT          DEFAULT NULL,
  `cancelled_by`        ENUM('customer','garage') DEFAULT NULL,
  `created_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_breakdowns_reference` (`reference`),
  KEY `idx_breakdowns_customer` (`customer_id`),
  KEY `idx_breakdowns_status_created` (`status`, `created_at`),
  KEY `idx_breakdowns_mechanic` (`mechanic_id`),
  CONSTRAINT `fk_breakdowns_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_breakdowns_mechanic` FOREIGN KEY (`mechanic_id`) REFERENCES `mechanics` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `jobs` (
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

CREATE TABLE `job_items` (
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

CREATE TABLE `stock_movements` (
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

CREATE TABLE `invoices` (
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

CREATE TABLE `invoice_items` (
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

CREATE TABLE `payments` (
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

CREATE TABLE `faqs` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category`     VARCHAR(30)  NOT NULL,
  `question`     VARCHAR(200) NOT NULL,
  `answer`       TEXT         NOT NULL,
  `call_us_when` VARCHAR(500) DEFAULT NULL,
  `sort_order`   INT          NOT NULL DEFAULT 0,
  `is_published` TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_faqs_listing` (`is_published`, `category`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `login_attempts` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope`        VARCHAR(20)  NOT NULL,
  `identifier`   VARCHAR(190) NOT NULL,
  `ip_address`   VARCHAR(45)  NOT NULL,
  `attempted_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_identifier` (`scope`, `identifier`, `ip_address`, `attempted_at`),
  KEY `idx_attempts_ip` (`scope`, `ip_address`, `attempted_at`),
  KEY `idx_attempts_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `admin` (`id`, `username`, `full_name`, `password_hash`) VALUES
(1, 'admin', 'Garage Manager', '$2y$10$Pxl5.JUsjmz39sStSkbz7.jzc/hRRljpWgwXWRLDRabYZemlia0hS');

INSERT INTO `services` (`id`, `name`, `description`, `image`, `sort_order`) VALUES
(1,  'General Service',             'Full scheduled service: oil, filters, plugs, fluid top-ups and a safety check.', 'engine-bay-open.jpg', 10),
(2,  'Oil Change',                  'Engine oil and filter replacement with a quick fluid and tyre check.', 'oil-change-underbody.jpg', 20),
(3,  'Diagnostics',                 'Computer scan to read fault codes and find the cause of warning lights.', 'diagnosis5.jpg', 30),
(4,  'Brake Repair',                'Pads, discs, shoes and brake fluid inspected and replaced.', 'wheel2.jpg', 40),
(5,  'Engine Repair',               'Misfires, leaks, overheating, timing and full engine overhauls.', 'engine-strip.jpg', 50),
(6,  'Battery Service',             'Battery testing, terminal cleaning, charging and replacement.', 'diagnosis6.jpg', 60),
(7,  'Wheel Alignment',             'Alignment and balancing to stop pulling and uneven tyre wear.', 'wheel-bodywork.jpg', 70),
(8,  'Tyre Rotation & Replacement', 'Tyre rotation, puncture repair, new tyres and pressure checks.', 'wheel3.jpg', 80),
(9,  'Suspension & Exhaust',        'Shock absorbers, bushes, ball joints and exhaust leaks.', 'wheel1.jpg', 90),
(10, 'Car Electrical Repair',       'Lights, wiring, alternators, starters and power windows.', 'diagnosis3.jpg', 100),
(11, 'Car Painting',                'Scratch and dent repair, panel painting and minor bodywork.', 'painting2.jpg', 110),
(12, 'Pre-Purchase Inspection',     'A thorough independent check before you buy a used car.', 'repair3.jpg', 120);

INSERT INTO `mechanics` (`id`, `full_name`, `phone`, `specialty`) VALUES
(1, 'Moses Kato',    '+256700000201', 'Breakdowns, tyres and batteries'),
(2, 'Paul Otim',     '+256700000301', 'Engines and diagnostics'),
(3, 'Ruth Namubiru', '+256700000401', 'Auto electrical');

INSERT INTO `parts` (`id`, `part_number`, `name`, `unit`, `cost_price`, `sell_price`, `stock_quantity`, `reorder_level`, `supplier`, `location`) VALUES
(1,  'OIL-5W30',    'Engine oil 5W-30',                 'litre',  22000,  30000,  40, 12, 'Local parts dealer', 'Shelf A1'),
(2,  'FLT-OIL-TY',  'Oil filter (Toyota)',              'piece',  15000,  25000,  14,  5, 'Local parts dealer', 'Shelf A2'),
(3,  'BRK-PAD-PRM', 'Front brake pads (Toyota Premio)', 'set',    60000,  95000,   3,  4, 'Local parts dealer', 'Shelf B1'),
(4,  'FLT-AIR-GEN', 'Air filter (general)',             'piece',  20000,  35000,   8,  4, 'Local parts dealer', 'Shelf A3'),
(5,  'SPK-NGK',     'Spark plug (NGK)',                 'piece',  12000,  20000,  24,  8, 'Local parts dealer', 'Drawer C1'),
(6,  'BAT-N50',     'Car battery N50',                  'piece', 280000, 350000,   2,  2, 'Battery dealer',     'Floor D1'),
(7,  'CLT-GRN',     'Coolant (green)',                  'litre',  12000,  18000,  20,  6, 'Local parts dealer', 'Shelf A4'),
(8,  'BRK-FLD-D4',  'Brake fluid DOT4 (500 ml)',        'bottle', 15000,  25000,   6,  3, 'Local parts dealer', 'Shelf B2'),
(9,  'WPR-22',      'Wiper blade 22 inch',              'piece',  10000,  18000,   0,  4, 'Local parts dealer', 'Shelf B3'),
(10, 'RLY-FAN',     'Radiator fan relay',               'piece',  25000,  40000,   5,  2, 'Local parts dealer', 'Drawer C2');

INSERT INTO `customers` (`id`, `full_name`, `phone`, `email`, `password_hash`) VALUES
(1, 'Sarah Nakato', '+256772451890', 'sarah.nakato@example.com', '$2y$10$MUosuo7M2xnpX/0xvk0RG.TKqBljaWG4WE49W7oR3AOBMA3ykMw3S');

INSERT INTO `vehicles` (`id`, `customer_id`, `plate_number`, `make`, `model`, `model_year`, `colour`) VALUES
(1, 1, 'UBA 482K', 'Toyota', 'Premio', 2008, 'Silver'),
(2, 1, 'UBF 071R', 'Subaru', 'Forester', 2010, 'Blue');

INSERT INTO `bookings`
  (`id`, `reference`, `customer_id`, `vehicle_id`, `service_id`, `contact_name`, `contact_phone`, `contact_email`,
   `vehicle_plate`, `vehicle_make`, `vehicle_model`, `preferred_date`, `preferred_slot`, `message`, `status`, `garage_note`)
VALUES
(1, 'BK-A7K3M9QD', NULL, NULL, 2, 'Isaac Arambe', '+256782006103', 'isaac.arambe@example.com',
 'UAM 204B', 'Toyota', 'Harrier', CURDATE() + INTERVAL IF(DAYOFWEEK(CURDATE() + INTERVAL 2 DAY) = 1, 3, 2) DAY, 'morning',
 'Car is due for a service, prefer a morning slot.', 'pending', NULL),
(2, 'BK-R4T8W2NP', 1, 1, 4, 'Sarah Nakato', '+256772451890', 'sarah.nakato@example.com',
 'UBA 482K', 'Toyota', 'Premio', CURDATE() + INTERVAL IF(DAYOFWEEK(CURDATE() + INTERVAL 1 DAY) = 1, 2, 1) DAY, 'afternoon',
 'Brakes squeak when stopping.', 'confirmed', 'Please bring the car in by 2pm so we can finish the same day.'),
(3, 'BK-H6Z3C8VJ', NULL, NULL, 5, 'David Okello', '+256701334527', 'd.okello@example.com',
 'UBD 330M', 'Nissan', 'X-Trail', CURDATE(), 'morning',
 'Engine overheats in traffic.', 'in_progress', 'Radiator fan relay replaced, now testing the cooling system.'),
(4, 'BK-M2X7F5KT', NULL, NULL, 7, 'Grace Achieng', '+256758920145', 'grace.achieng@example.com',
 'UAR 562F', 'Toyota', 'Wish', CURDATE() + INTERVAL IF(DAYOFWEEK(CURDATE() + INTERVAL 4 DAY) = 1, 5, 4) DAY, 'morning',
 'Steering pulls to the left.', 'pending', NULL),
(5, 'BK-P9D4B6WR', NULL, NULL, 3, 'Peter Mugisha', '+256788112233', 'p.mugisha@example.com',
 'UBB 118T', 'Honda', 'Fit', CURDATE() - INTERVAL 3 DAY, 'afternoon',
 'Check engine light is on.', 'completed', 'Faulty oxygen sensor replaced and fault codes cleared.'),
(6, 'BK-Y3N8G2QH', 1, 2, 1, 'Sarah Nakato', '+256772451890', 'sarah.nakato@example.com',
 'UBF 071R', 'Subaru', 'Forester', CURDATE() - INTERVAL 20 DAY, 'morning',
 NULL, 'completed', 'Full service done. Front brake pads at 40 percent, check again in 3 months.');

INSERT INTO `breakdown_requests`
  (`id`, `reference`, `customer_id`, `contact_name`, `contact_phone`, `problem_type`, `location_text`,
   `latitude`, `longitude`, `location_accuracy`, `vehicle_plate`, `vehicle_description`, `towing`, `details`,
   `status`, `mechanic_id`, `responder_name`, `responder_phone`, `eta_at`, `garage_note`, `created_at`)
VALUES
(1, 'BD-K7W3P9XM', NULL, 'Brian Ssekandi', '+256700000101', 'wont_start',
 'Kampala Road, outside Centenary Bank, facing Nakasero',
 0.3136100, 32.5811100, 18, 'UAW 774P', 'White Toyota Corolla', 'unsure',
 'Clicking sound when I turn the key.', 'new', NULL, NULL, NULL, NULL, NULL, NOW() - INTERVAL 6 MINUTE),
(2, 'BD-T4R8N2HC', 1, 'Sarah Nakato', '+256772451890', 'flat_tyre',
 'Entebbe Road near Kajjansi, opposite the Shell station',
 0.2180000, 32.5530000, 25, 'UBA 482K', 'Silver Toyota Premio', 'no',
 'Rear left tyre is completely flat and the spare is also low.', 'dispatched',
 1, 'Moses Kato', '+256700000201', NOW() + INTERVAL 15 MINUTE,
 'Moses is bringing a spare tyre and a compressor.', NOW() - INTERVAL 25 MINUTE),
(3, 'BD-V6Q2J8ZD', NULL, 'Joan Nambi', '+256700000102', 'battery',
 'Ntinda, Capital Shoppers car park, near the main entrance',
 NULL, NULL, NULL, 'UBE 903L', 'Red Suzuki Swift', 'no',
 NULL, 'completed', 1, 'Moses Kato', '+256700000201', NULL,
 'Battery jump-started. The battery is weak, we recommend replacing it soon.', NOW() - INTERVAL 2 DAY);

INSERT INTO `jobs`
  (`id`, `customer_id`, `vehicle_id`, `booking_id`, `breakdown_id`, `mechanic_id`,
   `contact_name`, `contact_phone`, `contact_email`,
   `vehicle_plate`, `vehicle_make`, `vehicle_model`, `vehicle_year`, `vehicle_colour`, `odometer_km`, `fuel_level`, `condition_notes`,
   `complaint`, `diagnosis`, `recommendations`, `customer_note`, `status`,
   `promised_at`, `ready_at`, `collected_at`, `created_by`, `created_at`)
VALUES
(1, NULL, NULL, 3, NULL, 2,
 'David Okello', '+256701334527', 'd.okello@example.com',
 'UBD 330M', 'Nissan', 'X-Trail', 2009, 'Black', 184250, 'half', 'Small dent on the rear bumper. Spare wheel and jack in the boot.',
 'Engine Repair: Engine overheats in traffic.',
 'Radiator fan does not switch on. The fan relay has burnt out.', NULL,
 'Radiator fan relay replaced, now testing the cooling system.', 'in_progress',
 TIMESTAMP(CURDATE(), '17:00:00'), NULL, NULL, 1, NOW() - INTERVAL 3 HOUR),
(2, NULL, NULL, 5, NULL, 2,
 'Peter Mugisha', '+256788112233', 'p.mugisha@example.com',
 'UBB 118T', 'Honda', 'Fit', 2010, 'White', 96400, 'quarter', NULL,
 'Diagnostics: Check engine light is on.',
 'Fault code P0135, oxygen sensor heater circuit.', 'Check the engine light again after 500 km.',
 'Faulty oxygen sensor replaced and fault codes cleared.', 'collected',
 NULL, TIMESTAMP(CURDATE() - INTERVAL 3 DAY, '16:30:00'), TIMESTAMP(CURDATE() - INTERVAL 3 DAY, '17:10:00'),
 1, TIMESTAMP(CURDATE() - INTERVAL 3 DAY, '14:05:00')),
(3, 1, 2, 6, NULL, 1,
 'Sarah Nakato', '+256772451890', 'sarah.nakato@example.com',
 'UBF 071R', 'Subaru', 'Forester', 2010, 'Blue', 132780, 'half', 'Light scratch on the driver door.',
 'General Service',
 'Routine service. Front brake pads at 40 percent.', 'Replace the front brake pads within 3 months.',
 'Full service done. Front brake pads at 40 percent, check again in 3 months.', 'collected',
 NULL, TIMESTAMP(CURDATE() - INTERVAL 20 DAY, '15:00:00'), TIMESTAMP(CURDATE() - INTERVAL 20 DAY, '17:30:00'),
 1, TIMESTAMP(CURDATE() - INTERVAL 20 DAY, '08:30:00')),
(4, NULL, NULL, NULL, NULL, 2,
 'Kenneth Mukasa', '+256700000501', NULL,
 'UAT 880Q', 'Toyota', 'Premio', 2007, 'Grey', 241900, 'three_quarters', 'Cracked windscreen on the passenger side.',
 'Grinding noise from the front brakes.',
 'Front pads worn down to the metal and the discs are scored.', NULL,
 'We have sent you a quote. Please call us to approve the work.', 'awaiting_approval',
 NULL, NULL, NULL, 1, NOW() - INTERVAL 1 DAY),
(5, NULL, NULL, NULL, NULL, 3,
 'Aisha Nansubuga', '+256700000601', 'aisha.n@example.com',
 'UBH 245W', 'Mazda', 'Demio', 2011, 'Red', 118300, 'quarter', NULL,
 'Car struggles to start in the morning.',
 'Battery failed the load test. Charging system is fine.', NULL,
 'Your car is ready. A new battery has been fitted and the charging system checked.', 'ready',
 NULL, NOW() - INTERVAL 1 HOUR, NULL, 1, NOW() - INTERVAL 5 HOUR);

INSERT INTO `job_items` (`job_id`, `item_type`, `description`, `part_id`, `mechanic_id`, `quantity`, `unit_price`, `created_by`, `created_at`) VALUES
(1, 'labour', 'Diagnose overheating and replace radiator fan relay', NULL, 2, 1, 80000, 1, NOW() - INTERVAL 2 HOUR),
(1, 'part',   'Radiator fan relay',                                 10, NULL, 1, 40000, 1, NOW() - INTERVAL 2 HOUR),
(1, 'part',   'Coolant (green)',                                     7, NULL, 4, 18000, 1, NOW() - INTERVAL 2 HOUR),
(2, 'labour', 'Diagnostic scan',                                  NULL, 2, 1, 50000, 1, TIMESTAMP(CURDATE() - INTERVAL 3 DAY, '14:30:00')),
(2, 'labour', 'Replace oxygen sensor',                            NULL, 2, 1, 40000, 1, TIMESTAMP(CURDATE() - INTERVAL 3 DAY, '15:30:00')),
(2, 'other',  'Oxygen sensor (bought in)',                        NULL, NULL, 1, 180000, 1, TIMESTAMP(CURDATE() - INTERVAL 3 DAY, '15:30:00')),
(3, 'labour', 'Full service',                                     NULL, 1, 1, 150000, 1, TIMESTAMP(CURDATE() - INTERVAL 20 DAY, '10:00:00')),
(3, 'part',   'Engine oil 5W-30',                                    1, NULL, 5, 30000, 1, TIMESTAMP(CURDATE() - INTERVAL 20 DAY, '10:00:00')),
(3, 'part',   'Oil filter (Toyota)',                                 2, NULL, 1, 25000, 1, TIMESTAMP(CURDATE() - INTERVAL 20 DAY, '10:00:00')),
(3, 'part',   'Air filter (general)',                                4, NULL, 1, 35000, 1, TIMESTAMP(CURDATE() - INTERVAL 20 DAY, '10:00:00')),
(3, 'part',   'Spark plug (NGK)',                                    5, NULL, 4, 20000, 1, TIMESTAMP(CURDATE() - INTERVAL 20 DAY, '10:00:00')),
(4, 'labour', 'Replace front brake pads and skim discs',          NULL, 2, 1, 120000, 1, NOW() - INTERVAL 1 DAY),
(4, 'part',   'Front brake pads (Toyota Premio)',                    3, NULL, 1, 95000, 1, NOW() - INTERVAL 1 DAY),
(5, 'part',   'Car battery N50',                                     6, NULL, 1, 350000, 1, NOW() - INTERVAL 4 HOUR),
(5, 'labour', 'Battery load test and charging system check',      NULL, 3, 1, 20000, 1, NOW() - INTERVAL 4 HOUR);

INSERT INTO `stock_movements` (`part_id`, `quantity_change`, `reason`, `job_id`, `unit_cost`, `note`, `admin_id`, `created_at`) VALUES
(1,  45, 'opening', NULL,  22000, 'Opening stock', 1, NOW() - INTERVAL 30 DAY),
(2,  15, 'opening', NULL,  15000, 'Opening stock', 1, NOW() - INTERVAL 30 DAY),
(3,   4, 'opening', NULL,  60000, 'Opening stock', 1, NOW() - INTERVAL 30 DAY),
(4,   9, 'opening', NULL,  20000, 'Opening stock', 1, NOW() - INTERVAL 30 DAY),
(5,  28, 'opening', NULL,  12000, 'Opening stock', 1, NOW() - INTERVAL 30 DAY),
(6,   3, 'opening', NULL, 280000, 'Opening stock', 1, NOW() - INTERVAL 30 DAY),
(7,  24, 'opening', NULL,  12000, 'Opening stock', 1, NOW() - INTERVAL 30 DAY),
(8,   6, 'opening', NULL,  15000, 'Opening stock', 1, NOW() - INTERVAL 30 DAY),
(10,  6, 'opening', NULL,  25000, 'Opening stock', 1, NOW() - INTERVAL 30 DAY),
(1,  -5, 'job_use', 3, NULL, NULL, 1, TIMESTAMP(CURDATE() - INTERVAL 20 DAY, '10:00:00')),
(2,  -1, 'job_use', 3, NULL, NULL, 1, TIMESTAMP(CURDATE() - INTERVAL 20 DAY, '10:00:00')),
(4,  -1, 'job_use', 3, NULL, NULL, 1, TIMESTAMP(CURDATE() - INTERVAL 20 DAY, '10:00:00')),
(5,  -4, 'job_use', 3, NULL, NULL, 1, TIMESTAMP(CURDATE() - INTERVAL 20 DAY, '10:00:00')),
(3,  -1, 'job_use', 4, NULL, NULL, 1, NOW() - INTERVAL 1 DAY),
(6,  -1, 'job_use', 5, NULL, NULL, 1, NOW() - INTERVAL 4 HOUR),
(10, -1, 'job_use', 1, NULL, NULL, 1, NOW() - INTERVAL 2 HOUR),
(7,  -4, 'job_use', 1, NULL, NULL, 1, NOW() - INTERVAL 2 HOUR);

INSERT INTO `invoices`
  (`id`, `job_id`, `status`, `bill_name`, `bill_phone`, `bill_email`, `vehicle_plate`, `vehicle_label`, `odometer_km`,
   `subtotal`, `discount`, `tax_rate`, `tax_amount`, `total`, `notes`, `issued_by`, `issued_at`)
VALUES
(1, 2, 'issued', 'Peter Mugisha', '+256788112233', 'p.mugisha@example.com', 'UBB 118T', 'White Honda Fit (2010)', 96400,
 270000, 0, 0, 0, 270000, NULL, 1, TIMESTAMP(CURDATE() - INTERVAL 3 DAY, '16:35:00')),
(2, 3, 'issued', 'Sarah Nakato', '+256772451890', 'sarah.nakato@example.com', 'UBF 071R', 'Blue Subaru Forester (2010)', 132780,
 440000, 0, 0, 0, 440000, 'Balance of UGX 140,000 to be paid at the next visit.', 1, TIMESTAMP(CURDATE() - INTERVAL 20 DAY, '15:05:00')),
(3, 5, 'issued', 'Aisha Nansubuga', '+256700000601', 'aisha.n@example.com', 'UBH 245W', 'Red Mazda Demio (2011)', 118300,
 370000, 0, 0, 0, 370000, NULL, 1, NOW() - INTERVAL 1 HOUR);

INSERT INTO `invoice_items` (`invoice_id`, `item_type`, `description`, `quantity`, `unit_price`, `line_total`) VALUES
(1, 'labour', 'Diagnostic scan', 1, 50000, 50000),
(1, 'labour', 'Replace oxygen sensor', 1, 40000, 40000),
(1, 'other',  'Oxygen sensor (bought in)', 1, 180000, 180000),
(2, 'labour', 'Full service', 1, 150000, 150000),
(2, 'part',   'Engine oil 5W-30', 5, 30000, 150000),
(2, 'part',   'Oil filter (Toyota)', 1, 25000, 25000),
(2, 'part',   'Air filter (general)', 1, 35000, 35000),
(2, 'part',   'Spark plug (NGK)', 4, 20000, 80000),
(3, 'part',   'Car battery N50', 1, 350000, 350000),
(3, 'labour', 'Battery load test and charging system check', 1, 20000, 20000);

INSERT INTO `payments` (`invoice_id`, `amount`, `method`, `reference`, `note`, `paid_on`, `received_by`, `created_at`) VALUES
(1, 270000, 'mobile_money', '10458723311', NULL, CURDATE() - INTERVAL 3 DAY, 1, TIMESTAMP(CURDATE() - INTERVAL 3 DAY, '17:05:00')),
(2, 300000, 'cash', NULL, 'Part payment on collection', CURDATE() - INTERVAL 20 DAY, 1, TIMESTAMP(CURDATE() - INTERVAL 20 DAY, '17:25:00'));

INSERT INTO `faqs` (`category`, `question`, `answer`, `call_us_when`, `sort_order`) VALUES
('starting', 'My car won''t start and I hear a clicking sound',
 'A fast clicking sound usually means the battery is too weak to turn the engine.\n1. Switch off the lights, radio and air conditioning, then try again.\n2. Open the bonnet and look at the battery terminals. If they are loose or covered in white or green crust, tighten them with the engine off and wipe them with a dry cloth.\n3. Try a jump-start from another car (see the jump-start guide).\n4. If the car starts, drive for at least 30 minutes without switching off so the battery can recharge.',
 'It still only clicks after a jump-start, you smell burning, or the battery case is swollen or leaking.', 10),
('starting', 'How do I jump-start my car safely?',
 'You need jumper cables and a second car with a good battery.\n1. Park the cars close together without touching. Switch both engines off and apply the handbrakes.\n2. Clip one red clamp to the + terminal of the flat battery, then the other red clamp to the + terminal of the good battery.\n3. Clip one black clamp to the - terminal of the good battery, then clip the last black clamp to bare, unpainted metal on your engine, away from the battery.\n4. Start the working car and let it run for 3 to 5 minutes, then try to start yours.\n5. Once your car is running, remove the clamps in reverse order: black from your car, black from the good car, red from the good car, red from your car.',
 'The cables spark a lot or get hot, or your car still will not start after two or three tries.', 20),
('starting', 'The engine turns over but will not start',
 'If the engine cranks normally but never fires, the cause is usually fuel or the key.\n1. Check the fuel gauge. Gauges can be wrong when the tank is nearly empty, so add a few litres if you are unsure.\n2. Make sure the car is in Park (automatic) or that the clutch is pressed all the way down (manual).\n3. Try your spare key. A flat key battery can stop the immobiliser from recognising the key.\n4. Do not crank for more than 10 seconds at a time. Wait a minute between tries so you do not flatten the battery.',
 'You smell fuel, the car has been cranking for a long time, or it still will not start after these checks.', 30),
('tyres', 'I have a flat tyre. How do I change it?',
 '1. Pull over on flat, firm ground well away from traffic. Switch on your hazard lights and apply the handbrake.\n2. Place your warning triangle (life saver) about 30 metres behind the car.\n3. Loosen each wheel nut half a turn while the wheel is still on the ground.\n4. Put the jack under the jacking point shown in your owner''s manual and raise the car until the tyre is just off the ground.\n5. Remove the nuts and the wheel, fit the spare, and tighten the nuts by hand.\n6. Lower the car, then tighten the nuts fully in a star pattern.\n7. Drive gently to a garage. Many spare wheels are not made for long distances or high speed.',
 'You are on a busy highway or a bend, the ground is soft or sloping, the nuts will not loosen, or your spare is also flat.', 10),
('tyres', 'How often should I check my tyre pressure?',
 'Check your tyres once a month and before long journeys, when the tyres are cold.\n1. Find the correct pressure on the sticker inside the driver''s door or in your owner''s manual.\n2. Use the gauge at a fuel station to check each tyre, including the spare.\n3. Add air until each tyre matches the recommended pressure.\nUnder-inflated tyres wear out faster, use more fuel and make the car harder to control.',
 'A tyre keeps losing air, has a bulge or cut on the side wall, or the tread is worn smooth.', 20),
('warning_lights', 'What do the dashboard warning lights mean?',
 'Red usually means stop soon. Amber or yellow means get it checked.\n- Red oil can: low oil pressure. Stop the engine as soon as it is safe.\n- Red thermometer: the engine is overheating. Stop and let it cool.\n- Red battery: the charging system has failed. The car will only run until the battery is flat.\n- Red brake symbol: make sure the handbrake is fully released. If the light stays on, the brake fluid may be low.\n- Amber engine outline: an engine fault has been recorded. You can usually drive gently to a garage.\n- Amber tyre with an exclamation mark: one of your tyres is low on air.',
 'Any red light stays on, or the engine light is flashing. A flashing engine light means a misfire that can damage the car.', 10),
('warning_lights', 'The check-engine light came on. Can I keep driving?',
 'If the light is steady and the car drives normally:\n1. Make sure the fuel cap is tightened. A loose cap can turn the light on.\n2. Drive gently and avoid carrying heavy loads.\n3. Book a diagnostics scan so we can read the fault code.\nIf the light is flashing, or the car is shaking or losing power, stop driving.',
 'The light is flashing, the car is shaking or losing power, or you can smell burning.', 20),
('engine', 'My engine is overheating. What should I do?',
 '1. Switch off the air conditioning and turn the heater to full hot. This pulls heat away from the engine.\n2. Pull over when it is safe and switch off the engine.\n3. Do not open the radiator cap while the engine is hot. The coolant is under pressure and can cause serious burns.\n4. Wait at least 30 minutes, then check the coolant level in the plastic overflow bottle.\n5. If the level is low, top it up with coolant, or clean water if that is all you have, to get you to a garage.',
 'Steam keeps coming from under the bonnet, coolant is leaking under the car, or the temperature rises again soon after topping up.', 10),
('engine', 'How do I check my engine oil?',
 '1. Park on level ground and wait 5 minutes after switching the engine off.\n2. Pull out the dipstick (usually a yellow or orange handle), wipe it clean, push it all the way back in, then pull it out again.\n3. The oil should sit between the two marks. If it is near or below the lower mark, top up a little at a time with the oil grade listed in your manual.\n4. Oil that looks milky or smells burnt is a sign of a problem.',
 'The oil looks milky, the level drops quickly between checks, or the oil warning light comes on.', 20),
('engine', 'How do I top up windscreen washer fluid?',
 '1. Open the bonnet and find the washer reservoir. Its cap usually shows a windscreen symbol.\n2. Fill it with clean water mixed with a little screen wash. Avoid washing-up liquid, which can damage paint and rubber seals.\n3. Close the cap firmly.',
 'The pump makes a noise but no water sprays, or the reservoir empties without being used. It may be cracked.', 30),
('electrical', 'My lights, radio or power windows suddenly stopped working',
 'This is often caused by a blown fuse.\n1. Find the fuse box. It is usually under the dashboard or under the bonnet, and the lid shows which fuse does what.\n2. With the engine and lights off, pull out the fuse for the faulty item using the small plastic puller.\n3. A blown fuse has a broken wire inside. Replace it with a fuse of exactly the same amp rating (the number printed on top).\n4. Never fit a higher-rated fuse or wrap a fuse in foil. This can start a fire.',
 'The new fuse blows again straight away. That means there is a short circuit that needs to be repaired.', 10),
('electrical', 'My remote key has stopped working',
 'A flat battery in the key is the most common cause.\n1. Use the metal key hidden inside the remote to open the door.\n2. Gently open the remote with a small screwdriver or coin and note the battery type printed on it.\n3. Fit a new battery with the + side facing the same way as the old one.\n4. If the car still will not start, hold the remote against the start button or ignition. Many cars can read the key even when its battery is flat.',
 'A new battery does not help, or the car shows an immobiliser or key warning.', 20),
('safety', 'What should I do if my car breaks down on the road?',
 '1. Switch on your hazard lights and move as far off the road as you safely can.\n2. On a busy or fast road, get everyone out on the side away from traffic and stand well back from the car.\n3. Place your warning triangle (life saver) about 30 metres behind the car. Never stand in the road to do it.\n4. Note a landmark near you, such as a fuel station, shop or school, so we can find you quickly.\n5. Call us or send a breakdown request with your location.',
 'Always. This is exactly what our breakdown service is for. If anyone is hurt, call 999 or 112 first.', 10);
