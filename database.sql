-- Schema for the plagiarism detection system.
-- Import via phpMyAdmin, or just run install.php once (it creates these tables).

CREATE TABLE IF NOT EXISTS activities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS submissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  activity_id INT UNSIGNED NOT NULL,
  student_name VARCHAR(255) NOT NULL,
  image_path VARCHAR(500) NOT NULL,
  raw_text MEDIUMTEXT NULL,
  confident_text MEDIUMTEXT NULL,
  word_count INT UNSIGNED NOT NULL DEFAULT 0,
  low_conf_words INT UNSIGNED NOT NULL DEFAULT 0,
  avg_confidence DECIMAL(5,4) NOT NULL DEFAULT 0,
  status ENUM('pending','flagged','reviewed') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sub_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS comparisons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  activity_id INT UNSIGNED NOT NULL,
  submission_a INT UNSIGNED NOT NULL,
  submission_b INT UNSIGNED NOT NULL,
  similarity DECIMAL(6,4) NOT NULL,
  review_status ENUM('pending','plagiarized','not_plagiarism') NOT NULL DEFAULT 'pending',
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pair (submission_a, submission_b),
  CONSTRAINT fk_comp_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
  CONSTRAINT fk_comp_a FOREIGN KEY (submission_a) REFERENCES submissions(id) ON DELETE CASCADE,
  CONSTRAINT fk_comp_b FOREIGN KEY (submission_b) REFERENCES submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;