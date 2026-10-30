SET NAMES utf8mb4;

USE `garage_system`;

UPDATE `services` SET `image` = 'engine-bay-open.jpg'      WHERE `name` = 'General Service' AND `image` = 'repair4.jpg';
UPDATE `services` SET `image` = 'oil-change-underbody.jpg' WHERE `name` = 'Oil Change'      AND `image` = 'engine1.jpg';
UPDATE `services` SET `image` = 'engine-strip.jpg'         WHERE `name` = 'Engine Repair'   AND `image` = 'engine3.jpg';
UPDATE `services` SET `image` = 'wheel-bodywork.jpg'       WHERE `name` = 'Wheel Alignment' AND `image` = 'garage1.jpg';
