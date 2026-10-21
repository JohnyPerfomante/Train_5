SET NAMES utf8mb4;
CREATE TABLE clients (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 full_name VARCHAR(120) NOT NULL,
 email VARCHAR(254) NOT NULL UNIQUE,
 phone VARCHAR(20) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 email_verified_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE services (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(80) NOT NULL,
 description VARCHAR(200) NOT NULL,
 max_passengers SMALLINT UNSIGNED NOT NULL,
 base_price DECIMAL(10,2) NOT NULL,
 CHECK (max_passengers BETWEEN 1 AND 50),
 CHECK (base_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE orders (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 client_id INT UNSIGNED NOT NULL,
 service_id INT UNSIGNED NOT NULL,
 origin VARCHAR(150) NOT NULL,
 destination VARCHAR(150) NOT NULL,
 departure_at DATETIME NOT NULL,
 passengers SMALLINT UNSIGNED NOT NULL,
 notes VARCHAR(500) NOT NULL DEFAULT '',
 status ENUM('awaiting_email','confirmed','cancelled') NOT NULL DEFAULT 'awaiting_email',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
 FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT,
 INDEX ix_orders_client_date (client_id, created_at),
 CHECK (passengers BETWEEN 1 AND 50),
 CHECK (origin <> destination)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE email_tokens (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 client_id INT UNSIGNED NOT NULL,
 order_id INT UNSIGNED NULL,
 kind ENUM('verify','order') NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
 FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
 INDEX ix_tokens_client (client_id, kind, created_at),
 CHECK ((kind='verify' AND order_id IS NULL) OR (kind='order' AND order_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE mail_jobs (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 client_id INT UNSIGNED NOT NULL,
 token_id INT UNSIGNED NOT NULL UNIQUE,
 recipient VARCHAR(254) NOT NULL,
 subject VARCHAR(200) NOT NULL,
 body TEXT NOT NULL,
 status ENUM('pending','sent','discarded') NOT NULL DEFAULT 'pending',
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 last_error VARCHAR(200) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 sent_at DATETIME NULL,
 FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
 FOREIGN KEY (token_id) REFERENCES email_tokens(id) ON DELETE CASCADE,
 INDEX ix_mail_pending (status, attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE login_attempts (
 bucket CHAR(64) CHARACTER SET ascii PRIMARY KEY,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 window_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO services (name,description,max_passengers,base_price) VALUES
 ('Міський трансфер','Поїздки містом, вокзал та аеропорт',4,450),
 ('Міжміська поїздка','Індивідуальні маршрути між містами',8,1800),
 ('Групове перевезення','Мікроавтобус для команди або події',20,3200);
