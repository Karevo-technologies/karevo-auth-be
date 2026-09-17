-- K-ID Backend - Patient + Organisation + Provider sharing same DB
-- Beta v1.0 LAUTECH 2026 - XAMPP + Docker + Render compatible
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- 1. patients (Patient Guide 2.1 - authoritative)
CREATE TABLE IF NOT EXISTS patients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kid_number VARCHAR(20) UNIQUE NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  phone VARCHAR(20) UNIQUE NOT NULL,
  email VARCHAR(150) UNIQUE,
  date_of_birth DATE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_kid (kid_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. providers (needed for health_records FK and record_requests)
CREATE TABLE IF NOT EXISTS providers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  provider_name VARCHAR(200) NOT NULL,
  facility_type ENUM('lab','hospital','clinic','other') DEFAULT 'hospital',
  license_number VARCHAR(100) UNIQUE NOT NULL,
  cac_number VARCHAR(50) NULL,
  email VARCHAR(150) UNIQUE NOT NULL,
  phone VARCHAR(20) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  verification_status ENUM('pending','verified','rejected') DEFAULT 'pending',
  verified_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. organisations (Org Guide 2.1)
CREATE TABLE IF NOT EXISTS organisations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  org_name VARCHAR(200) NOT NULL,
  org_type ENUM('school','employer','insurer','other') NOT NULL,
  cac_number VARCHAR(50) UNIQUE NOT NULL,
  authorised_person VARCHAR(150) NOT NULL,
  email VARCHAR(150) UNIQUE NOT NULL,
  phone VARCHAR(20) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  verification_status ENUM('pending','verified','rejected') DEFAULT 'pending',
  verified_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cac (cac_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. health_records (Patient Guide 2.2 + Org reuse)
CREATE TABLE IF NOT EXISTS health_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  provider_id INT NULL,
  record_type VARCHAR(100) NOT NULL,
  issued_by VARCHAR(200) NOT NULL,
  issued_date DATE NOT NULL,
  summary TEXT NOT NULL,
  details TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
  INDEX idx_patient_type (patient_id, record_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. record_requests (Patient 2.3 / Org reused)
CREATE TABLE IF NOT EXISTS record_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  requester_id INT NOT NULL,
  requester_type ENUM('provider','organisation') NOT NULL,
  record_type VARCHAR(100) NOT NULL,
  reason TEXT NOT NULL,
  status ENUM('pending','approved','declined') DEFAULT 'pending',
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  responded_at TIMESTAMP NULL,
  FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  INDEX idx_patient (patient_id),
  INDEX idx_requester (requester_type, requester_id),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. qr_codes (Patient 2.4 - 10 min expiry, single use)
CREATE TABLE IF NOT EXISTS qr_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  record_id INT NOT NULL,
  request_id INT NOT NULL,
  token VARCHAR(255) UNIQUE NOT NULL,
  is_used BOOLEAN DEFAULT FALSE,
  expires_at TIMESTAMP NOT NULL,
  scanned_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  FOREIGN KEY (record_id) REFERENCES health_records(id) ON DELETE CASCADE,
  FOREIGN KEY (request_id) REFERENCES record_requests(id) ON DELETE CASCADE,
  INDEX idx_token (token),
  INDEX idx_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. activity_log (Patient 2.5 + Org reuse - append only)
CREATE TABLE IF NOT EXISTS activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  action VARCHAR(100) NOT NULL,
  description TEXT NOT NULL,
  actor_id INT NULL,
  actor_type ENUM('patient','provider','organisation','system') NULL,
  record_id INT NULL,
  request_id INT NULL,
  status VARCHAR(50) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  INDEX idx_patient_created (patient_id, created_at DESC),
  INDEX idx_actor (actor_type, actor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. notifications (Patient 2.6 + Org reuse)
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  type ENUM('new_record','new_request','request_approved','request_declined','qr_scanned','access_revoked') NOT NULL,
  title VARCHAR(200) NOT NULL,
  message TEXT NOT NULL,
  is_read BOOLEAN DEFAULT FALSE,
  reference_id INT NULL,
  reference_type VARCHAR(50) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  INDEX idx_patient_read (patient_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. organisation_access (Org Guide 2.3 - revocation core)
CREATE TABLE IF NOT EXISTS organisation_access (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  org_id INT NOT NULL,
  request_id INT NOT NULL,
  status ENUM('active','revoked') DEFAULT 'active',
  granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  revoked_at TIMESTAMP NULL,
  FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  FOREIGN KEY (org_id) REFERENCES organisations(id) ON DELETE CASCADE,
  FOREIGN KEY (request_id) REFERENCES record_requests(id) ON DELETE CASCADE,
  UNIQUE KEY uq_patient_org_request (patient_id, org_id, request_id),
  INDEX idx_org_status (org_id, status),
  INDEX idx_patient_org (patient_id, org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS=1;

-- Seed data for testing flow (password: password123 => $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi)
INSERT IGNORE INTO patients (id, kid_number, first_name, last_name, phone, email, date_of_birth, password_hash) VALUES
(1, 'KID-20260001', 'Amara', 'Okafor', '08012345678', 'amara@email.com', '2001-03-15', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
(2, 'KID-20260002', 'Chidi', 'Okoro', '08012345679', 'chidi@email.com', '1999-07-22', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

INSERT IGNORE INTO providers (id, provider_name, facility_type, license_number, email, phone, password_hash, verification_status, verified_at) VALUES
(1, 'LAUTECH Health Centre', 'hospital', 'LIC-001-HC', 'healthcentre@lautech.edu.ng', '08000000010', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'verified', NOW()),
(2, 'Helix Biogen Institute', 'lab', 'LIC-002-HBI', 'lab@helixbiogen.ng', '08000000011', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'verified', NOW());

INSERT IGNORE INTO organisations (id, org_name, org_type, cac_number, authorised_person, email, phone, password_hash, verification_status, verified_at) VALUES
(1, 'LAUTECH Student Affairs', 'school', 'CAC/IT/100234', 'Dr. Aminu Bello', 'studentaffairs@lautech.edu.ng', '08012345678', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'verified', NOW()),
(2, 'Karevo Insurance Ltd', 'insurer', 'CAC/IT/100235', 'Mrs. Bola', 'insurance@karevo.ng', '08012345680', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'pending', NULL);

INSERT IGNORE INTO health_records (id, patient_id, provider_id, record_type, issued_by, issued_date, summary, details) VALUES
(1, 1, 1, 'Vaccination History', 'LAUTECH Health Centre', '2026-03-01', 'Hepatitis B and Yellow Fever vaccinations confirmed.', 'Hepatitis B: 3 doses complete. Yellow Fever: valid until 2036.'),
(2, 1, 2, 'Blood Test Result', 'Helix Biogen Institute', '2026-05-15', 'All values within normal range.', 'Haemoglobin: 14.2 g/dL, WBC: 6.1, Platelets: 250');
