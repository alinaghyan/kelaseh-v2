-- تبدیل کد شهر به ۴ رقم (رشته)

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `isfahan_cities`
  MODIFY COLUMN `code` VARCHAR(10) NOT NULL;

ALTER TABLE `users`
  MODIFY COLUMN `city_code` VARCHAR(10) NULL;

UPDATE `isfahan_cities`
SET `code` = LPAD(`code`, 4, '0')
WHERE `code` REGEXP '^[0-9]+$' AND CHAR_LENGTH(`code`) < 4;

UPDATE `users`
SET `city_code` = LPAD(`city_code`, 4, '0')
WHERE `city_code` IS NOT NULL AND `city_code` REGEXP '^[0-9]+$' AND CHAR_LENGTH(`city_code`) < 4;

SET FOREIGN_KEY_CHECKS = 1;

