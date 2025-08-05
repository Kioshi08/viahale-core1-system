
-- Create the database
CREATE DATABASE IF NOT EXISTS otp_login;

-- Use the database
USE otp_login;

-- Create the users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    otp VARCHAR(6),
    otp_expiry DATETIME
);

-- Insert a sample user (email: pyketyson42@gmail.com, password: password123)
-- Password is hashed with SHA-256
INSERT INTO users (email, password) VALUES 
('pyketyson42@gmail.com', SHA2('password123', 256));
