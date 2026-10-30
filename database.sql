-- EarNova fresh-install schema for import through phpMyAdmin.
-- Existing databases and data are not reset or migrated by this file.

CREATE DATABASE IF NOT EXISTS user_database
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS gig_database
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS shift_database
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS earning_database
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS rating_database
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- User identities and role-specific student/provider details.
USE user_database;

CREATE TABLE IF NOT EXISTS users (
  user_id INT NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('Student', 'Provider', 'Admin') NOT NULL DEFAULT 'Student',
  status ENUM('Active', 'Blocked', 'Pending') NOT NULL DEFAULT 'Active',
  joining_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY unique_users_email (email)
) ENGINE=InnoDB AUTO_INCREMENT=1;

INSERT IGNORE INTO users (full_name, email, phone, password, role, status)
VALUES (
  'Alfiya Admin',
  'alfiyaadmin@gmail.com',
  '',
  '$2y$10$K7UX4TcA8euC3Q1L6FCnQexAKpZ3pivsxNoLsYxVWkxXQofgxWFLm',
  'Admin',
  'Active'
);

CREATE TABLE IF NOT EXISTS students (
  student_id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  address VARCHAR(255) DEFAULT NULL,
  education VARCHAR(150) DEFAULT NULL,
  college VARCHAR(150) DEFAULT NULL,
  city VARCHAR(100) DEFAULT NULL,
  skills VARCHAR(255) DEFAULT NULL,
  bio TEXT DEFAULT NULL,
  profile_image VARCHAR(255) DEFAULT NULL,
  id_card_path VARCHAR(255) DEFAULT NULL,
  verification_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  rejection_reason TEXT DEFAULT NULL,
  approved_by INT DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  id_uploaded_at DATETIME DEFAULT NULL,
  PRIMARY KEY (student_id),
  UNIQUE KEY unique_students_user (user_id),
  KEY idx_students_verification_status (verification_status),
  CONSTRAINT fk_student_approved_by
    FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_student_user
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1;

CREATE TABLE IF NOT EXISTS providers (
  provider_id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  business_name VARCHAR(150) DEFAULT NULL,
  business_address VARCHAR(255) DEFAULT NULL,
  business_category VARCHAR(150) DEFAULT NULL,
  business_image VARCHAR(255) DEFAULT NULL,
  verification_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  rejection_reason TEXT DEFAULT NULL,
  approved_by INT DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  PRIMARY KEY (provider_id),
  UNIQUE KEY unique_providers_user (user_id),
  KEY idx_providers_verification_status (verification_status),
  CONSTRAINT fk_provider_approved_by
    FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_provider_user
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1;

CREATE TABLE IF NOT EXISTS disputes (
  dispute_id INT NOT NULL AUTO_INCREMENT,
  issue VARCHAR(255) NOT NULL DEFAULT '',
  student_name VARCHAR(150) NOT NULL DEFAULT '',
  provider_name VARCHAR(150) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'Open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  application_id INT DEFAULT NULL,
  submitter_id INT NOT NULL DEFAULT 0,
  submitter_role VARCHAR(20) NOT NULL DEFAULT '',
  student_id INT NOT NULL DEFAULT 0,
  provider_id INT NOT NULL DEFAULT 0,
  subject VARCHAR(255) NOT NULL DEFAULT '',
  description TEXT DEFAULT NULL,
  gig_title VARCHAR(150) NOT NULL DEFAULT '',
  PRIMARY KEY (dispute_id),
  KEY idx_disputes_submitter (submitter_role, submitter_id),
  KEY idx_disputes_application (application_id)
) ENGINE=InnoDB AUTO_INCREMENT=1;

-- Categories, gigs, and student applications belong to the gig database.
USE gig_database;

CREATE TABLE IF NOT EXISTS categories (
  category_id INT NOT NULL AUTO_INCREMENT,
  category_name VARCHAR(100) NOT NULL,
  category_image VARCHAR(255) DEFAULT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (category_id),
  UNIQUE KEY unique_categories_name (category_name)
) ENGINE=InnoDB AUTO_INCREMENT=1;

CREATE TABLE IF NOT EXISTS gigs (
  gig_id INT NOT NULL AUTO_INCREMENT,
  provider_id INT NOT NULL,
  category_id INT DEFAULT NULL,
  task_title VARCHAR(150) NOT NULL,
  task_description TEXT DEFAULT NULL,
  gig_image VARCHAR(255) DEFAULT NULL,
  category VARCHAR(100) NOT NULL,
  location VARCHAR(150) NOT NULL,
  shift_date DATE DEFAULT NULL,
  start_time TIME DEFAULT NULL,
  end_time TIME DEFAULT NULL,
  payment_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'Pending',
  rejection_reason TEXT DEFAULT NULL,
  posted_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (gig_id),
  KEY idx_gigs_provider (provider_id),
  KEY idx_gigs_status (status)
) ENGINE=InnoDB AUTO_INCREMENT=1;

-- Existing installations: run once if the gigs table was created before gig_image was added:
-- ALTER TABLE gig_database.gigs ADD COLUMN gig_image VARCHAR(255) DEFAULT NULL AFTER task_description;

-- Each student can apply only once to a given gig.
CREATE TABLE IF NOT EXISTS applications (
  application_id INT NOT NULL AUTO_INCREMENT,
  gig_id INT NOT NULL,
  student_id INT NOT NULL,
  provider_id INT NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  email VARCHAR(150) NOT NULL,
  skills TEXT DEFAULT NULL,
  experience TEXT DEFAULT NULL,
  availability VARCHAR(50) DEFAULT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Pending',
  shift_code VARCHAR(10) DEFAULT NULL,
  applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  approved_at DATETIME DEFAULT NULL,
  PRIMARY KEY (application_id),
  UNIQUE KEY unique_student_gig (gig_id, student_id),
  KEY idx_applications_provider (provider_id),
  KEY idx_applications_status (status)
) ENGINE=InnoDB AUTO_INCREMENT=1;

-- Scheduled shifts keep the confirmed time and the code used to verify completion.
USE shift_database;

CREATE TABLE IF NOT EXISTS shifts (
  shift_id INT NOT NULL AUTO_INCREMENT,
  gig_id INT NOT NULL,
  application_id INT NOT NULL,
  student_id INT NOT NULL,
  provider_id INT NOT NULL,
  shift_code VARCHAR(10) NOT NULL,
  scheduled_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  status ENUM('Scheduled', 'Verified', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Scheduled',
  verified_at DATETIME DEFAULT NULL,
  completed_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (shift_id),
  UNIQUE KEY unique_shift_application (application_id),
  UNIQUE KEY unique_shift_code (shift_code),
  KEY idx_shifts_student (student_id),
  KEY idx_shifts_provider (provider_id),
  KEY idx_shifts_status (status)
) ENGINE=InnoDB AUTO_INCREMENT=1;

USE earning_database;

-- One payout record is stored for each completed shift.
CREATE TABLE IF NOT EXISTS earnings (
  earning_id INT NOT NULL AUTO_INCREMENT,
  shift_id INT NOT NULL,
  gig_id INT NOT NULL,
  student_id INT NOT NULL,
  provider_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  payment_status ENUM('Pending', 'Paid', 'Failed') NOT NULL DEFAULT 'Pending',
  earned_at DATETIME DEFAULT NULL,
  paid_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (earning_id),
  UNIQUE KEY unique_shift_earning (shift_id),
  KEY idx_earnings_student (student_id),
  KEY idx_earnings_provider (provider_id)
) ENGINE=InnoDB AUTO_INCREMENT=1;

USE rating_database;

-- A reviewer can submit one rating per shift; values are restricted to one through five.
CREATE TABLE IF NOT EXISTS ratings (
  rating_id INT NOT NULL AUTO_INCREMENT,
  shift_id INT NOT NULL,
  gig_id INT NOT NULL,
  reviewer_id INT NOT NULL,
  reviewed_user_id INT NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  review_text TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (rating_id),
  UNIQUE KEY unique_shift_reviewer (shift_id, reviewer_id),
  KEY idx_ratings_reviewed_user (reviewed_user_id),
  CONSTRAINT chk_rating_value CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB AUTO_INCREMENT=1;
