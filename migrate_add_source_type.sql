ALTER TABLE submissions
  ADD COLUMN source_type ENUM('image','text') NOT NULL DEFAULT 'image' AFTER image_path;
