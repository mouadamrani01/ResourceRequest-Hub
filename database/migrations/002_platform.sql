-- Run ONCE when upgrading a v1 database. Back up the database first.
CREATE TABLE IF NOT EXISTS resource_pools (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  resource_type ENUM('CPU','GPU','Storage') NOT NULL,
  capacity INT UNSIGNED NOT NULL,
  unit VARCHAR(30) NOT NULL DEFAULT 'units',
  location VARCHAR(100) NOT NULL DEFAULT '',
  description VARCHAR(500) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_pool_capacity CHECK (capacity BETWEEN 1 AND 100000),
  INDEX idx_pool_type_active (resource_type, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS project_spaces (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_project_owner FOREIGN KEY (owner_id) REFERENCES users(id),
  INDEX idx_project_owner (owner_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE resource_requests
  ADD COLUMN project_id INT UNSIGNED DEFAULT NULL AFTER purpose,
  ADD COLUMN resource_pool_id INT UNSIGNED DEFAULT NULL AFTER project_id,
  ADD COLUMN priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal' AFTER resource_pool_id,
  ADD COLUMN needed_from DATE DEFAULT NULL AFTER priority,
  ADD COLUMN needed_until DATE DEFAULT NULL AFTER needed_from,
  MODIFY COLUMN status ENUM('pending','approved','rejected','cancelled','released') NOT NULL DEFAULT 'pending',
  ADD CONSTRAINT fk_request_project FOREIGN KEY (project_id) REFERENCES project_spaces(id),
  ADD CONSTRAINT fk_request_pool FOREIGN KEY (resource_pool_id) REFERENCES resource_pools(id),
  ADD INDEX idx_project (project_id),
  ADD INDEX idx_pool_status (resource_pool_id, status);
ALTER TABLE request_audit MODIFY COLUMN action ENUM('created','approved','rejected','cancelled','released') NOT NULL;
CREATE TABLE IF NOT EXISTS request_comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  author_id INT UNSIGNED NOT NULL,
  body VARCHAR(1500) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_comment_request FOREIGN KEY (request_id) REFERENCES resource_requests(id),
  CONSTRAINT fk_comment_author FOREIGN KEY (author_id) REFERENCES users(id),
  INDEX idx_comment_request (request_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO resource_pools (code,name,resource_type,capacity,unit,location,description) VALUES
('GPU-LAB','GPU Research Cluster','GPU',16,'GPUs','Compute Lab A','Accelerated training and model evaluation workloads.'),
('CPU-HPC','High Performance Compute','CPU',256,'vCPUs','Data Center 1','Batch processing, experiments and numerical simulations.'),
('STORAGE','Research Data Storage','Storage',500,'TB','Data Center 1','Managed project datasets, checkpoints and research output.'),
('GPU-VIS','Visualization Workstations','GPU',8,'GPUs','Visualization Studio','Interactive research and visualization environments.');
