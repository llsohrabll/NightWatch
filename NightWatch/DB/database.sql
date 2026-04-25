CREATE DATABASE Registration;

CREATE USER 'nightwatch_app'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON Registration.* TO 'nightwatch_app'@'localhost';
FLUSH PRIVILEGES;

USE Registration;

CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    token VARCHAR(255) DEFAULT NULL,
    token_expires_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;