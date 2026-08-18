-- HamaraVideo schema v1
-- Run once in phpMyAdmin on the new database.

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mobile VARCHAR(15) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  pin_hash VARCHAR(255) NOT NULL,
  credits INT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  prompt TEXT NOT NULL,
  used_prompt TEXT NULL,
  aspect VARCHAR(10) NOT NULL DEFAULT '9:16',
  fal_request_id VARCHAR(80) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'SUBMITTED', -- SUBMITTED / COMPLETED / FAILED
  video_url TEXT NULL,
  credit_charged TINYINT NOT NULL DEFAULT 1,
  credit_refunded TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id),
  INDEX idx_fal (fal_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  pack VARCHAR(20) NOT NULL,
  amount INT NOT NULL,          -- in paise
  credits INT NOT NULL,
  razorpay_order_id VARCHAR(64) NULL,
  razorpay_payment_id VARCHAR(64) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'CREATED', -- CREATED / PAID / FAILED
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id),
  INDEX idx_order (razorpay_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
