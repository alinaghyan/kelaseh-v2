USE `kelaseh_v2`;

INSERT INTO `users` (`email`, `username`, `password_hash`, `role`, `display_name`, `first_name`, `last_name`, `mobile`, `national_code`, `city_code`, `branch_count`, `branch_start_no`, `branch_capacity`, `is_active`, `created_at`, `last_login_at`)
VALUES (NULL, 'alinaghyan', '$2y$10$oZmFDnIhMkPDR8gycMIVyOvDtL5efAaVSxj4d7mF5WwLLr6/W.JPu', 'admin', NULL, NULL, NULL, NULL, NULL, NULL, 1, 1, 10, 1, NOW(), NULL)
ON DUPLICATE KEY UPDATE
  `password_hash` = VALUES(`password_hash`),
  `role` = 'admin',
  `is_active` = 1;

