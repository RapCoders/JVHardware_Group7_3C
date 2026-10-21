-- JV Hardware Inventory and Sales Management System
-- NOTE: You do NOT need to import this file. The system creates the database
-- automatically the first time you open it. This file is kept for reference
-- and for anyone who prefers importing through phpMyAdmin.

CREATE DATABASE IF NOT EXISTS jv_hardware_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE jv_hardware_db;

CREATE TABLE IF NOT EXISTS settings (
 k VARCHAR(60) NOT NULL PRIMARY KEY,
 v TEXT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(50) NOT NULL UNIQUE,
 full_name VARCHAR(100) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('owner','staff') NOT NULL DEFAULT 'staff',
 can_edit_prices TINYINT(1) NOT NULL DEFAULT 0,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sku VARCHAR(50) NOT NULL UNIQUE,
 name VARCHAR(150) NOT NULL,
 category VARCHAR(100) NOT NULL DEFAULT 'General',
 unit VARCHAR(30) NOT NULL DEFAULT 'piece',
 quantity INT NOT NULL DEFAULT 0,
 price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
 reorder_level INT NOT NULL DEFAULT 5,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL,
 CHECK(quantity >= 0), CHECK(price >= 0), CHECK(reorder_level >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS price_history (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 product_id INT UNSIGNED NOT NULL,
 old_price DECIMAL(10,2) NOT NULL,
 new_price DECIMAL(10,2) NOT NULL,
 user_id INT UNSIGNED NULL,
 changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_price_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_movements (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 product_id INT UNSIGNED NOT NULL,
 movement_type ENUM('IN','OUT','SALE','VOID') NOT NULL,
 quantity INT UNSIGNED NOT NULL,
 note VARCHAR(255) NULL,
 user_id INT UNSIGNED NULL,
 balance_after INT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_movement_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 receipt_no VARCHAR(40) NOT NULL UNIQUE,
 customer_name VARCHAR(150) NULL,
 payment_method ENUM('Cash','GCash','Bank Transfer','Other') NOT NULL DEFAULT 'Cash',
 subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 change_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 status VARCHAR(10) NOT NULL DEFAULT 'completed',
 void_reason VARCHAR(255) NULL,
 voided_at DATETIME NULL,
 user_id INT UNSIGNED NULL,
 sold_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_sold_at (sold_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sale_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sale_id BIGINT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 product_name VARCHAR(150) NOT NULL,
 sku VARCHAR(50) NOT NULL,
 quantity INT UNSIGNED NOT NULL,
 unit_price DECIMAL(10,2) NOT NULL,
 line_total DECIMAL(12,2) NOT NULL,
 CONSTRAINT fk_sale_item_sale FOREIGN KEY(sale_id) REFERENCES sales(id) ON DELETE CASCADE,
 CONSTRAINT fk_sale_item_product FOREIGN KEY(product_id) REFERENCES products(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO settings (k,v) VALUES
('store_name','JV Hardware'),
('store_address','Brgy. Lomboy, Inabanga, Bohol'),
('store_hours','Open daily 8:00 AM - 5:00 PM'),
('receipt_footer','Thank you for shopping at JV Hardware!');

INSERT IGNORE INTO products (sku,name,category,unit,quantity,price,reorder_level) VALUES
('JV-HW-001','PVC Pipe 1/2 inch','Plumbing','piece',50,85.00,10),
('JV-HW-002','Portland Cement','Construction Materials','bag',35,265.00,10),
('JV-HW-003','Electrical Tape','Electrical Supplies','roll',8,25.00,10),
('JV-HW-004','Common Nail 2 inch','Hardware','kilo',22,95.00,5),
('JV-HW-005','LED Bulb 9W','Electrical Supplies','piece',18,75.00,5),
('JV-HW-006','Steel Bar 10mm x 6m','Construction Materials','piece',40,320.00,10),
('JV-HW-007','Hollow Block 4 inch','Construction Materials','piece',300,14.00,50),
('JV-HW-008','Paint Brush 2 inch','Paint and Finishing','piece',25,45.00,6),
('JV-HW-009','Latex Paint White 1 gal','Paint and Finishing','gallon',12,640.00,4),
('JV-HW-010','Hacksaw Blade','Hardware','piece',4,55.00,6),
('JV-HW-011','Electrical Wire #12 (per meter)','Electrical Supplies','meter',120,28.00,30),
('JV-HW-012','Door Knob Set','Hardware','set',9,285.00,3),
('JV-HW-013','Faucet 1/2 inch','Plumbing','piece',14,150.00,4),
('JV-HW-014','Teflon Tape','Plumbing','roll',0,18.00,10),
('JV-HW-015','Padlock 40mm','Hardware','piece',11,135.00,4),
('JV-HW-016','Garden Hose 1/2 inch (per meter)','Household','meter',60,38.00,15);
