-- demo_seed.sql — sanitized baseline dataset for PeterPangFit demo sandbox
-- Generated for Demo Mode resets. Contains schema definitions and starter rows
-- safe for public walkthroughs.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Drop in dependency order so we can recreate fresh tables each reset
DROP TABLE IF EXISTS plan_exercises;
DROP TABLE IF EXISTS user_plan_exercises;
DROP TABLE IF EXISTS user_plans;
DROP TABLE IF EXISTS workout_plans;
DROP TABLE IF EXISTS exercise_categories;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS exercises;
DROP TABLE IF EXISTS trainer_session_transactions;
DROP TABLE IF EXISTS trainer_sessions;
DROP TABLE IF EXISTS trainer_session_packages;
DROP TABLE IF EXISTS invites;
DROP TABLE IF EXISTS user_sessions;
DROP TABLE IF EXISTS trusted_devices;
DROP TABLE IF EXISTS user_recognized_ips;
DROP TABLE IF EXISTS ip_cache;
DROP TABLE IF EXISTS system_logs;
DROP TABLE IF EXISTS user_notifications;
DROP TABLE IF EXISTS passkeys;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS users;

-- ---------------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role ENUM('super_admin','admin','trainer','client') NOT NULL DEFAULT 'client',
  is_client TINYINT(1) NOT NULL DEFAULT 0,
  is_trainer TINYINT(1) NOT NULL DEFAULT 0,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  phone VARCHAR(32) NULL,
  birthdate DATE NULL,
  gender VARCHAR(20) NULL,
  first_name VARCHAR(100) NULL,
  middle_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NULL,
  photo_url VARCHAR(255) NULL,
  theme VARCHAR(64) NULL,
  inactivity_timeout_seconds INT NULL,
  failed_login_count INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login DATETIME NULL,
  ip_address VARCHAR(45) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  last_password_change DATETIME NULL,
  password_reset_token VARCHAR(255) NULL,
  password_reset_expires DATETIME NULL,
  twofa_email_enabled TINYINT(1) NOT NULL DEFAULT 0,
  twofa_email_code VARCHAR(16) NULL,
  twofa_email_expires DATETIME NULL,
  twofa_app_enabled TINYINT(1) NOT NULL DEFAULT 0,
  twofa_app_token VARCHAR(128) NULL,
  twofa_app_expires DATETIME NULL,
  twofa_secret VARCHAR(64) NULL,
  twofa_recovery_codes TEXT NULL,
  height_ft TINYINT NULL,
  height_in TINYINT NULL,
  weight_lbs DECIMAL(6,2) NULL,
  bio TEXT NULL,
  notes TEXT NULL,
  timezone VARCHAR(64) NULL,
  time_format_24h TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users
  (id, role, is_client, is_trainer, email, password_hash, phone, birthdate, gender,
   first_name, last_name, inactivity_timeout_seconds, is_active, created_at, theme)
VALUES
  -- Passwords: DemoAdmin!2024 / DemoTrainer!2024 / DemoClient!2024
  (1, 'admin', 0, 0, 'demo.admin@example.com', '$2y$12$/funyv8yayxn3I6LITVSXObWBpMP3fRYvfN4FxFiiXD7m4HQqqrq2',
   '+1-555-0100', '1988-05-06', 'female', 'Avery', 'Stone', 10800, 1, '2023-01-01 09:00:00', 'aurora'),
  (2, 'trainer', 0, 1, 'demo.trainer@example.com', '$2y$12$nLkM5nMYlD4kDsAt36gSmOUWmLnXJA39PwudFT.QGia4MaoA4XT4m',
   '+1-555-0101', '1990-07-14', 'male', 'Kai', 'Rivera', 7200, 1, '2023-01-03 10:30:00', 'summit'),
  (3, 'client', 1, 0, 'demo.client@example.com', '$2y$12$UmKZWNPNwIH/zKmI1xRTFOgk/V2ohv9MfL/gdnkrh8UB0Ra5Opjlq',
   '+1-555-0102', '1995-02-22', 'female', 'Jordan', 'Parker', 7200, 1, '2023-02-10 15:45:00', 'default'),
  (4, 'trainer', 0, 1, 'demo.mindbody.trainer@example.com', '$2y$12$nLkM5nMYlD4kDsAt36gSmOUWmLnXJA39PwudFT.QGia4MaoA4XT4m',
   '+1-555-0103', '1985-11-18', 'female', 'Mira', 'Chen', 7200, 1, '2023-01-05 08:15:00', 'zenith'),
  (5, 'client', 1, 0, 'demo.client.alex@example.com', '$2y$12$UmKZWNPNwIH/zKmI1xRTFOgk/V2ohv9MfL/gdnkrh8UB0Ra5Opjlq',
   '+1-555-0104', '1992-03-11', 'male', 'Alex', 'Morgan', 7200, 1, '2023-02-18 13:20:00', 'default'),
  (6, 'client', 1, 0, 'demo.client.skylar@example.com', '$2y$12$UmKZWNPNwIH/zKmI1xRTFOgk/V2ohv9MfL/gdnkrh8UB0Ra5Opjlq',
   '+1-555-0105', '1989-09-07', 'female', 'Skylar', 'Reed', 7200, 1, '2023-02-22 07:40:00', 'aurora'),
  (7, 'client', 1, 0, 'demo.client.emery@example.com', '$2y$12$UmKZWNPNwIH/zKmI1xRTFOgk/V2ohv9MfL/gdnkrh8UB0Ra5Opjlq',
   '+1-555-0106', '1984-12-02', 'non-binary', 'Emery', 'Blake', 5400, 1, '2023-03-02 11:55:00', 'summit'),
  (8, 'client', 1, 0, 'demo.client.aria@example.com', '$2y$12$UmKZWNPNwIH/zKmI1xRTFOgk/V2ohv9MfL/gdnkrh8UB0Ra5Opjlq',
   '+1-555-0107', '1997-04-19', 'female', 'Aria', 'Lopez', 7200, 1, '2023-03-09 10:05:00', 'aurora'),
  (9, 'client', 1, 0, 'demo.client.darius@example.com', '$2y$12$UmKZWNPNwIH/zKmI1xRTFOgk/V2ohv9MfL/gdnkrh8UB0Ra5Opjlq',
   '+1-555-0108', '1981-06-23', 'male', 'Darius', 'Cole', 5400, 1, '2023-03-15 17:45:00', 'summit'),
  (10, 'client', 1, 0, 'demo.client.nova@example.com', '$2y$12$UmKZWNPNwIH/zKmI1xRTFOgk/V2ohv9MfL/gdnkrh8UB0Ra5Opjlq',
   '+1-555-0109', '1994-10-30', 'female', 'Nova', 'Singh', 7200, 1, '2023-03-21 08:10:00', 'default'),
  (11, 'client', 1, 0, 'demo.client.eli@example.com', '$2y$12$UmKZWNPNwIH/zKmI1xRTFOgk/V2ohv9MfL/gdnkrh8UB0Ra5Opjlq',
   '+1-555-0110', '1987-01-12', 'male', 'Eli', 'Patel', 7200, 1, '2023-03-27 06:30:00', 'summit'),
  (12, 'client', 1, 0, 'demo.client.zara@example.com', '$2y$12$UmKZWNPNwIH/zKmI1xRTFOgk/V2ohv9MfL/gdnkrh8UB0Ra5Opjlq',
   '+1-555-0111', '1999-08-05', 'female', 'Zara', 'Khan', 7200, 1, '2023-04-02 14:25:00', 'aurora');

-- Promote the developer account to Super Admin if it exists as an admin.
UPDATE users SET role='super_admin' WHERE email='abdickens@me.com' AND role='admin';

-- ---------------------------------------------------------------------------
-- system_settings
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_settings (
  `key` VARCHAR(100) NOT NULL,
  `value` TEXT NULL,
  updated_at DATETIME NULL,
  updated_by INT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_settings (`key`, `value`, updated_at, updated_by) VALUES
  ('demo_mode_enabled', '1', NOW(), 1),
  ('lockout_default_minutes', '30', NOW(), 1),
  ('lockout_minutes_admin', '30', NOW(), 1),
  ('lockout_minutes_trainer', '30', NOW(), 1),
  ('lockout_minutes_client', '45', NOW(), 1),
  ('test_register_token_enabled', '0', NOW(), 1),
  ('test_register_token_value', '', NOW(), 1);

-- ---------------------------------------------------------------------------
-- passkeys
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS passkeys (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  cred_id VARBINARY(255) NOT NULL,
  public_key VARBINARY(255) NOT NULL,
  counter INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_passkeys_user_cred (user_id, cred_id),
  CONSTRAINT fk_passkeys_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- user_notifications
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  type_key VARCHAR(100) NOT NULL DEFAULT '',
  category VARCHAR(40) NOT NULL DEFAULT 'system',
  title VARCHAR(255) NOT NULL,
  message TEXT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  is_mutable TINYINT(1) NOT NULL DEFAULT 1,
  allow_email TINYINT(1) NOT NULL DEFAULT 1,
  send_email TINYINT(1) NOT NULL DEFAULT 0,
  email_sent_at DATETIME NULL DEFAULT NULL,
  context TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user (user_id),
  KEY idx_notifications_read (user_id, is_read),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO passkeys (user_id, name, cred_id, public_key, counter, created_at, last_used_at)
VALUES
  (1, 'Admin Laptop', UNHEX('aabbccddeeff001122334455'), UNHEX('0102030405060708090a0b0c0d0e0f10'), 5, '2023-06-01 12:00:00', '2023-09-15 08:30:00');

-- ---------------------------------------------------------------------------
-- invites
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invites (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NULL,
  email VARCHAR(255) NOT NULL,
  token VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  accepted_at DATETIME NULL,
  completed_at DATETIME NULL,
  used TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invites_token (token),
  KEY idx_invites_email (email(120))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO invites (user_id, email, token, created_at, expires_at, accepted_at, completed_at, used, created_by)
VALUES
  (NULL, 'new.client@example.com', 'INVITE-ALPHA-2023', '2023-08-01 10:00:00', '2023-09-01 10:00:00', NULL, NULL, 0, 2),
  (NULL, 'vip.client@example.com', 'INVITE-BRAVO-2023', '2023-08-15 11:00:00', '2023-09-15 11:00:00', '2023-08-20 14:00:00', '2023-08-22 09:30:00', 1, 2);

-- ---------------------------------------------------------------------------
-- password reset tokens
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_resets_token (token_hash),
  KEY idx_password_resets_user (user_id),
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO password_resets (user_id, token_hash, expires_at, used_at, created_at)
VALUES
  (3, '54d9f6b8c7cbb5d2d5d4a32d7f5cdd8aab4ec2f76bd2f43f7090d6ef0a4c9b11', '2023-09-12 12:00:00', NULL, '2023-09-12 11:00:00'),
  (1, 'd1e8c4a0b5c9f32e4a7d88c5f0b6d3a1902f54e776ca2d71a8e34bb7129dff45', '2023-08-01 18:30:00', '2023-08-01 18:45:00', '2023-08-01 17:40:00');

-- ---------------------------------------------------------------------------
-- trainer session tables
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trainer_session_packages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT UNSIGNED NOT NULL,
  trainer_id INT UNSIGNED NOT NULL,
  package_name VARCHAR(191) NOT NULL,
  purchased_sessions INT NOT NULL DEFAULT 0,
  price_per_session DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_package_trainer (trainer_id),
  KEY idx_package_client (client_id),
  CONSTRAINT fk_package_client FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_package_trainer FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trainer_sessions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id INT UNSIGNED NOT NULL,
  scheduled_start DATETIME NOT NULL,
  scheduled_end DATETIME NULL,
  actual_start_at DATETIME NULL,
  actual_end_at DATETIME NULL,
  status ENUM('scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  completed_at DATETIME NULL,
  completion_marked_by INT NULL,
  timer_started_by INT NULL,
  timer_ended_by INT NULL,
  duration_seconds INT NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_session_package (package_id),
  KEY idx_session_status (status),
  CONSTRAINT fk_session_package FOREIGN KEY (package_id) REFERENCES trainer_session_packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trainer_session_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id INT UNSIGNED NOT NULL,
  txn_type ENUM('payment','refund') NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  description VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NULL,
  PRIMARY KEY (id),
  KEY idx_txn_package (package_id),
  KEY idx_txn_type (txn_type),
  CONSTRAINT fk_txn_package FOREIGN KEY (package_id) REFERENCES trainer_session_packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO trainer_session_packages (id, client_id, trainer_id, package_name, purchased_sessions, price_per_session, notes, created_at)
VALUES
  (1, 3, 2, 'Starter Pack', 4, 110.00, 'Initial transformation focus block.', '2023-07-01 09:00:00');

INSERT INTO trainer_sessions (package_id, scheduled_start, scheduled_end, actual_start_at, actual_end_at, status, completed_at, completion_marked_by, duration_seconds, notes)
VALUES
  (1, '2023-07-05 09:00:00', '2023-07-05 10:00:00', '2023-07-05 09:02:00', '2023-07-05 09:58:00', 'completed', '2023-07-05 09:58:00', 2, 3360, 'Kickoff strength assessment.'),
  (1, '2023-07-12 09:00:00', '2023-07-12 10:00:00', NULL, NULL, 'scheduled', NULL, NULL, NULL, 'Focus on core stability.');

INSERT INTO trainer_session_transactions (package_id, txn_type, amount, description, created_at, created_by)
VALUES
  (1, 'payment', 440.00, 'Upfront purchase of Starter Pack (4 sessions).', '2023-07-01 09:15:00', 2);

-- ---------------------------------------------------------------------------
-- exercises & categories
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NULL,
  updated_at DATETIME NULL,
  updated_by INT NULL,
  PRIMARY KEY (id),
  KEY idx_category_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exercises (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(191) NOT NULL,
  notes TEXT NULL,
  video_url VARCHAR(512) NULL,
  video_poster_url VARCHAR(512) NULL,
  video_duration_sec INT NULL,
  video_autoplay TINYINT(1) NOT NULL DEFAULT 0,
  video_loop TINYINT(1) NOT NULL DEFAULT 0,
  video_muted TINYINT(1) NOT NULL DEFAULT 1,
  captions_vtt_url VARCHAR(512) NULL,
  category_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NULL,
  updated_at DATETIME NULL,
  updated_by INT NULL,
  PRIMARY KEY (id),
  KEY idx_exercise_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exercise_categories (
  exercise_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (exercise_id, category_id),
  KEY idx_ec_category (category_id),
  CONSTRAINT fk_ec_exercise FOREIGN KEY (exercise_id) REFERENCES exercises(id) ON DELETE CASCADE,
  CONSTRAINT fk_ec_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO categories (id, name, description, created_at, created_by)
VALUES
  (1, 'Strength', 'Foundational compound lifts.', '2023-06-01 08:00:00', 2),
  (2, 'Conditioning', 'Intervals and energy systems work.', '2023-06-01 08:05:00', 2),
  (3, 'Mobility', 'Dynamic warmups and mobility flows.', '2023-06-01 08:10:00', 2),
  (4, 'Core & Stability', 'Anti-rotation, balance, and trunk stability drills.', '2023-06-01 08:15:00', 2),
  (5, 'Mind-Body', 'Breath work and restorative flow sessions.', '2023-06-01 08:20:00', 4);

INSERT INTO exercises (id, name, notes, video_url, video_poster_url, video_duration_sec, video_autoplay, video_loop, video_muted, captions_vtt_url, category_id, created_at, created_by)
VALUES
  (1, 'Barbell Back Squat', 'Focus on depth and bracing. 3x8 at moderate load.', '/media/demo/barbell-squat.mp4', '/media/demo/barbell-squat.jpg', 52, 0, 0, 1, NULL, 1, '2023-06-02 09:00:00', 2),
  (2, 'Assault Bike Intervals', '30s hard effort, 60s easy pace. Repeat for 10 rounds.', '/media/demo/assault-bike.mp4', '/media/demo/assault-bike.jpg', 75, 0, 0, 1, NULL, 2, '2023-06-02 09:30:00', 2),
  (3, 'Worlds Greatest Stretch', 'Dynamic mobility sequence to open hips and thoracic spine.', NULL, NULL, NULL, 0, 0, 1, NULL, 3, '2023-06-02 09:45:00', 2),
  (4, 'Kettlebell Swings', 'Power-focused hinge pattern. 4 sets of 15 reps.', '/media/demo/kb-swing.mp4', '/media/demo/kb-swing.jpg', 48, 0, 0, 1, NULL, 2, '2023-06-03 07:55:00', 2),
  (5, 'Single-Leg Romanian Deadlift', 'Balance challenge with glute emphasis. Use moderate kettlebell.', '/media/demo/sl-rdl.mp4', '/media/demo/sl-rdl.jpg', 60, 0, 0, 1, NULL, 4, '2023-06-03 08:10:00', 2),
  (6, 'Box Jump Series', 'Explosive jumps with controlled landings. 3x8.', '/media/demo/box-jump.mp4', '/media/demo/box-jump.jpg', 33, 0, 0, 1, NULL, 2, '2023-06-03 08:20:00', 2),
  (7, 'Half-Kneeling Thoracic Rotation', 'Breath-driven mobility. 2x10 per side.', NULL, NULL, NULL, 0, 0, 1, NULL, 3, '2023-06-03 08:30:00', 4),
  (8, 'Dumbbell Bench Press', 'Tempo-controlled pressing. 4x10 with 3011 tempo.', '/media/demo/db-bench.mp4', '/media/demo/db-bench.jpg', 57, 0, 0, 1, NULL, 1, '2023-06-04 09:05:00', 2),
  (9, 'Row Erg Pyramids', 'Increasing/decreasing pace sets. Maintain strong stroke rate.', '/media/demo/row-erg.mp4', '/media/demo/row-erg.jpg', 68, 0, 0, 1, NULL, 2, '2023-06-04 09:20:00', 2),
  (10, 'Primal Flow Reset', 'Mind-body flow for downregulation and recovery.', '/media/demo/primal-flow.mp4', '/media/demo/primal-flow.jpg', 180, 0, 0, 1, NULL, 5, '2023-06-04 09:35:00', 4),
  (11, 'Pallof Press Hold', 'Anti-rotation core stability. 3x30s per side.', NULL, NULL, NULL, 0, 0, 1, NULL, 4, '2023-06-04 09:45:00', 4),
  (12, 'Farmer Carry March', 'Grip and core builder. 5 rounds of 40 yards.', '/media/demo/farmer-carry.mp4', '/media/demo/farmer-carry.jpg', 42, 0, 0, 1, NULL, 1, '2023-06-04 09:55:00', 2);

INSERT INTO exercise_categories (exercise_id, category_id) VALUES
  (1, 1),
  (2, 2),
  (3, 3),
  (1, 3),
  (4, 2),
  (5, 1),
  (5, 4),
  (6, 2),
  (7, 3),
  (8, 1),
  (9, 2),
  (10, 5),
  (11, 4),
  (12, 1),
  (12, 2);

-- ---------------------------------------------------------------------------
-- workout plans and assignments
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS workout_plans (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(191) NOT NULL,
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NULL,
  updated_at DATETIME NULL,
  updated_by INT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plan_exercises (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  plan_id INT UNSIGNED NOT NULL,
  exercise_id INT UNSIGNED NOT NULL,
  position INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_plan_exercise (plan_id, exercise_id),
  KEY idx_plan_pos (plan_id, position),
  KEY idx_plan_exercise (exercise_id),
  CONSTRAINT fk_plan_exercises_plan FOREIGN KEY (plan_id) REFERENCES workout_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_plan_exercises_ex FOREIGN KEY (exercise_id) REFERENCES exercises(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_plans (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  assigned_by INT NULL,
  PRIMARY KEY (id),
  KEY idx_user_plans_user (user_id),
  KEY idx_user_plans_plan (plan_id),
  CONSTRAINT fk_user_plans_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_plans_plan FOREIGN KEY (plan_id) REFERENCES workout_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_plan_exercises (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_plan_id INT UNSIGNED NOT NULL,
  exercise_id INT UNSIGNED NOT NULL,
  sets INT NULL,
  reps INT NULL,
  duration_seconds INT NULL,
  weight_lbs DECIMAL(6,2) NULL,
  user_notes TEXT NULL,
  set_details_json LONGTEXT NULL,
  position INT NULL,
  updated_at DATETIME NULL,
  updated_by INT NULL,
  PRIMARY KEY (id),
  KEY idx_upe_plan (user_plan_id),
  KEY idx_upe_exercise (exercise_id),
  CONSTRAINT fk_upe_plan FOREIGN KEY (user_plan_id) REFERENCES user_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_upe_ex FOREIGN KEY (exercise_id) REFERENCES exercises(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO workout_plans (id, name, description, created_at, created_by)
VALUES
  (1, 'Total Strength Builder', 'Three-day strength emphasis with conditioning support.', '2023-06-10 08:00:00', 2),
  (2, 'Metcon Express', 'Quick interval sessions for busy professionals.', '2023-06-15 07:30:00', 2),
  (3, 'Mobility Recharge', 'Joint-by-joint mobility reset with mindful breathing.', '2023-06-18 06:45:00', 4),
  (4, 'Hypertrophy Push/Pull', 'Upper/lower split with progressive overload focus.', '2023-06-20 07:15:00', 2),
  (5, 'Endurance Engine', 'Aerobic base building with tempo progressions.', '2023-06-22 06:30:00', 2);

INSERT INTO plan_exercises (plan_id, exercise_id, position, created_at)
VALUES
  (1, 1, 1, '2023-06-10 08:05:00'),
  (1, 3, 2, '2023-06-10 08:06:00'),
  (1, 11, 3, '2023-06-10 08:07:00'),
  (2, 2, 1, '2023-06-15 07:35:00'),
  (2, 6, 2, '2023-06-15 07:36:00'),
  (2, 9, 3, '2023-06-15 07:37:00'),
  (3, 7, 1, '2023-06-18 06:50:00'),
  (3, 10, 2, '2023-06-18 06:51:00'),
  (3, 5, 3, '2023-06-18 06:52:00'),
  (4, 8, 1, '2023-06-20 07:20:00'),
  (4, 1, 2, '2023-06-20 07:21:00'),
  (4, 12, 3, '2023-06-20 07:22:00'),
  (5, 4, 1, '2023-06-22 06:35:00'),
  (5, 2, 2, '2023-06-22 06:36:00'),
  (5, 9, 3, '2023-06-22 06:37:00');

INSERT INTO plan_exercises (plan_id, exercise_id, position, created_at)
VALUES
  (1, 1, 1, '2023-06-10 08:05:00'),
  (1, 3, 2, '2023-06-10 08:06:00'),
  (2, 2, 1, '2023-06-15 07:35:00');

INSERT INTO user_plans (id, user_id, plan_id, assigned_at, assigned_by)
VALUES
  (1, 3, 1, '2023-06-20 09:00:00', 2),
  (2, 3, 2, '2023-07-10 08:30:00', 2),
  (3, 5, 1, '2023-07-02 07:30:00', 2),
  (4, 5, 3, '2023-07-02 07:35:00', 4),
  (5, 6, 2, '2023-07-05 06:45:00', 2),
  (6, 7, 4, '2023-07-08 08:15:00', 2),
  (7, 8, 3, '2023-07-11 12:05:00', 4),
  (8, 9, 5, '2023-07-15 09:25:00', 2),
  (9, 10, 4, '2023-07-18 05:55:00', 2),
  (10, 11, 5, '2023-07-20 06:10:00', 2),
  (11, 12, 2, '2023-07-24 07:50:00', 4);

INSERT INTO user_plan_exercises (user_plan_id, exercise_id, sets, reps, duration_seconds, weight_lbs, user_notes, set_details_json, position, updated_at, updated_by)
VALUES
  (1, 1, 3, 8, NULL, 135.0, 'Add 5lbs if all sets move smoothly.', NULL, 1, '2023-06-20 09:05:00', 2),
  (1, 3, 2, 10, NULL, NULL, 'Use as active recovery between squat sets.', NULL, 2, '2023-06-20 09:06:00', 2),
  (1, 11, 3, 12, NULL, 20.0, 'Pause for anti-rotation focus.', NULL, 3, '2023-06-20 09:07:00', 2),
  (2, 2, NULL, NULL, 1800, NULL, 'Aim for consistent wattage above 65.', NULL, 1, '2023-07-10 08:35:00', 2),
  (2, 6, 4, 12, NULL, NULL, 'Explosive but soft landings.', NULL, 2, '2023-07-10 08:36:00', 2),
  (2, 9, NULL, NULL, 1200, NULL, 'Negative split on descending ladder.', NULL, 3, '2023-07-10 08:37:00', 2),
  (3, 1, 4, 6, NULL, 155.0, 'Tempo 31X1 across sets.', NULL, 1, '2023-07-02 07:45:00', 2),
  (3, 12, 5, 40, NULL, 65.0, 'Carry with tall posture.', NULL, 2, '2023-07-02 07:46:00', 2),
  (4, 7, 2, 10, NULL, NULL, 'Slow breath through rib cage.', NULL, 1, '2023-07-02 07:50:00', 4),
  (4, 10, NULL, NULL, 1500, NULL, 'Finish with guided breath work.', NULL, 2, '2023-07-02 07:51:00', 4),
  (5, 2, NULL, NULL, 1500, NULL, 'Keep cadence between 70-75 RPM.', NULL, 1, '2023-07-05 06:55:00', 2),
  (5, 6, 4, 10, NULL, NULL, 'Step down quietly to absorb landing.', NULL, 2, '2023-07-05 06:56:00', 2),
  (5, 9, NULL, NULL, 900, NULL, 'Cool down with low damper easy row.', NULL, 3, '2023-07-05 06:57:00', 2),
  (6, 8, 4, 12, NULL, 55.0, 'Add final drop set with lighter weight.', NULL, 1, '2023-07-08 08:25:00', 2),
  (6, 1, 4, 8, NULL, 185.0, 'Pause at bottom for 1 second.', NULL, 2, '2023-07-08 08:26:00', 2),
  (6, 12, 5, 50, NULL, 75.0, 'Slow march pace while keeping rib cage stacked.', NULL, 3, '2023-07-08 08:27:00', 2),
  (7, 7, 2, 12, NULL, NULL, 'Focus on gentle thoracic opening.', NULL, 1, '2023-07-11 12:10:00', 4),
  (7, 5, 3, 10, NULL, 25.0, 'Light load for balance practice.', NULL, 2, '2023-07-11 12:11:00', 4),
  (7, 10, NULL, NULL, 1320, NULL, 'Guided flow soundtrack.', NULL, 3, '2023-07-11 12:12:00', 4),
  (8, 4, 5, 15, NULL, 35.0, 'Strong hip snap each rep.', NULL, 1, '2023-07-15 09:35:00', 2),
  (8, 2, NULL, NULL, 1800, NULL, 'Alternate minutes 80/60 RPM.', NULL, 2, '2023-07-15 09:36:00', 2),
  (8, 9, NULL, NULL, 1200, NULL, 'Final tempo row with nasal breathing.', NULL, 3, '2023-07-15 09:37:00', 2),
  (9, 8, 4, 10, NULL, 60.0, 'Last set drop weight by 15%.', NULL, 1, '2023-07-18 06:05:00', 2),
  (9, 11, 4, 12, NULL, 30.0, 'Resist rotation on each press.', NULL, 2, '2023-07-18 06:06:00', 2),
  (9, 12, 4, 40, NULL, 80.0, 'Farmer carry finisher with sled handles.', NULL, 3, '2023-07-18 06:07:00', 2),
  (10, 4, 4, 12, NULL, 40.0, 'Breath reset between sets.', NULL, 1, '2023-07-20 06:20:00', 2),
  (10, 2, NULL, NULL, 2100, NULL, 'Long slow distance ride.', NULL, 2, '2023-07-20 06:21:00', 2),
  (10, 10, NULL, NULL, 1500, NULL, 'Extended cooldown stretch.', NULL, 3, '2023-07-20 06:22:00', 4),
  (11, 2, NULL, NULL, 1650, NULL, 'Progressive interval build.', NULL, 1, '2023-07-24 08:00:00', 4),
  (11, 6, 5, 8, NULL, NULL, 'Add contrast jump step downs.', NULL, 2, '2023-07-24 08:01:00', 4),
  (11, 9, NULL, NULL, 900, NULL, 'Finish with easy paddle strokes.', NULL, 3, '2023-07-24 08:02:00', 4);

-- ---------------------------------------------------------------------------
-- session + trusted device tables
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  session_id VARCHAR(128) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  ip VARCHAR(45) NULL,
  city VARCHAR(80) NULL,
  region VARCHAR(80) NULL,
  user_agent TEXT NULL,
  platform VARCHAR(40) NULL,
  browser VARCHAR(40) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_session (user_id, session_id),
  KEY idx_sessions_user (user_id),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trusted_devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  selector VARBINARY(24) NOT NULL,
  validator_hash VARBINARY(64) NOT NULL,
  device_name VARCHAR(100) NOT NULL,
  user_agent VARCHAR(255) NULL,
  ip_address VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trusted_selector (selector),
  KEY idx_trusted_user (user_id),
  KEY idx_trusted_expires (expires_at),
  CONSTRAINT fk_trusted_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_recognized_ips (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  ip_bin VARBINARY(16) NOT NULL,
  ip_address VARCHAR(45) NULL,
  label VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recognized_user_ip (user_id, ip_bin),
  KEY idx_recognized_user (user_id),
  CONSTRAINT fk_recognized_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ip_cache (
  ip_bin VARBINARY(16) NOT NULL,
  city VARCHAR(80) NOT NULL DEFAULT '',
  region VARCHAR(80) NOT NULL DEFAULT '',
  country VARCHAR(80) NULL,
  latitude DECIMAL(9,6) NULL,
  longitude DECIMAL(9,6) NULL,
  looked_up_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_vpn TINYINT(1) NOT NULL DEFAULT 0,
  vpn_checked_at DATETIME NULL,
  is_icloud TINYINT(1) NOT NULL DEFAULT 0,
  icloud_checked_at DATETIME NULL,
  PRIMARY KEY (ip_bin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO user_sessions (user_id, session_id, created_at, last_seen_at, revoked, ip, city, region, platform, browser)
VALUES
  (1, 'demo-admin-session', '2023-09-10 08:00:00', '2023-09-10 09:15:00', 0, '203.0.113.10', 'Seattle', 'WA', 'macOS', 'Safari');

INSERT INTO trusted_devices (user_id, selector, validator_hash, device_name, user_agent, ip_address, created_at, last_used_at, expires_at)
VALUES
  (1, UNHEX('11223344556677889900aabb'), UNHEX('8f2f06b032a4c6f9d34f83b4d8f1dfc1d6452e91b6d0a7b23d7c6f9080a1b2c3'), 'Admin MacBook',
   'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.5 Safari/605.1.15', '203.0.113.10',
   '2023-09-01 07:30:00', '2023-09-10 09:15:00', '2023-10-01 07:30:00');

INSERT INTO user_recognized_ips (user_id, ip_bin, ip_address, label, created_at, last_seen_at)
VALUES
  (1, INET6_ATON('203.0.113.10'), '203.0.113.10', 'Admin HQ Office', '2023-08-01 09:00:00', '2023-09-10 09:15:00'),
  (3, INET6_ATON('198.51.100.25'), '198.51.100.25', 'Client Home', '2023-08-12 07:45:00', '2023-09-03 18:20:00');

INSERT INTO ip_cache (ip_bin, city, region, country, latitude, longitude, looked_up_at, is_vpn, vpn_checked_at, is_icloud, icloud_checked_at)
VALUES
  (INET6_ATON('203.0.113.10'), 'Seattle', 'Washington', 'United States', 47.6062, -122.3321, '2023-09-01 07:31:00', 0, '2023-09-01 07:31:00', 0, NULL),
  (INET6_ATON('198.51.100.25'), 'Denver', 'Colorado', 'United States', 39.7392, -104.9903, '2023-09-02 12:05:00', 1, '2023-09-02 12:05:00', 0, NULL);

-- ---------------------------------------------------------------------------
-- system logs (auditing)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id INT NULL,
  actor_email VARCHAR(255) NULL,
  actor_role VARCHAR(32) NULL,
  ip_address VARCHAR(64) NULL,
  action VARCHAR(100) NOT NULL,
  target_type VARCHAR(64) NULL,
  target_id VARCHAR(64) NULL,
  details TEXT NULL,
  session_id VARCHAR(128) NULL,
  context_page VARCHAR(128) NULL,
  PRIMARY KEY (id),
  KEY idx_logs_created (created_at),
  KEY idx_logs_action (action),
  KEY idx_logs_user (user_id),
  KEY idx_logs_session (session_id(64)),
  KEY idx_logs_page (context_page(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_logs (user_id, actor_email, actor_role, ip_address, action, target_type, target_id, details, session_id, context_page, created_at)
VALUES
  (1, 'demo.admin@example.com', 'admin', '203.0.113.10', 'demo_mode_reset', 'system', 'demo', 'Demo sandbox reset to baseline dataset.', 'demo-admin-session', 'dashboard.php', '2023-09-10 09:16:00'),
  (2, 'demo.trainer@example.com', 'trainer', '203.0.113.20', 'workout_plan_created', 'workout_plan', '1', 'Initial strength plan authored for Jordan Parker.', 'trainer-session-demo', 'trainer_sessions.php', '2023-06-10 08:00:00');

SET FOREIGN_KEY_CHECKS = 1;
