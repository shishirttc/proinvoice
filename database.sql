-- Create the database if it doesn't exist
CREATE DATABASE IF NOT EXISTS proinvoice;
USE proinvoice;

-- 1. Company Table
CREATE TABLE IF NOT EXISTS company (
    id INT PRIMARY KEY,
    name VARCHAR(255),
    address TEXT,
    phone VARCHAR(20),
    email VARCHAR(100),
    website VARCHAR(100),
    taxId VARCHAR(50),
    logoUrl TEXT
);

-- Insert initial company record if it doesn't exist
INSERT IGNORE INTO company (id, name, address, phone, email, website, taxId, logoUrl) 
VALUES (1, 'Pro Invoice', '123 Main St', '123-456-7890', 'info@proinvoice.com', 'www.proinvoice.com', '123456', '');

-- 2. Customers Table
CREATE TABLE IF NOT EXISTS customers (
    id VARCHAR(50) PRIMARY KEY,
    customerNumber INT AUTO_INCREMENT UNIQUE,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    balance DECIMAL(15, 2) DEFAULT 0.00
);

-- 3. Inventory Table
CREATE TABLE IF NOT EXISTS inventory (
    id VARCHAR(50) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(15, 2) DEFAULT 0.00
);

-- 4. Invoices Table
CREATE TABLE IF NOT EXISTS invoices (
    id VARCHAR(50) PRIMARY KEY,
    invoiceNumber VARCHAR(50) NOT NULL,
    customerId VARCHAR(50),
    date DATE,
    subtotal DECIMAL(15, 2) DEFAULT 0.00,
    taxRate DECIMAL(5, 2) DEFAULT 0.00,
    taxAmount DECIMAL(15, 2) DEFAULT 0.00,
    discount DECIMAL(15, 2) DEFAULT 0.00,
    total DECIMAL(15, 2) DEFAULT 0.00,
    amountPaid DECIMAL(15, 2) DEFAULT 0.00,
    status ENUM('Sent', 'Paid', 'Partially Paid', 'Draft', 'Overdue') DEFAULT 'Sent',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customerId) REFERENCES customers(id) ON DELETE SET NULL
);

-- 5. Invoice Items Table
CREATE TABLE IF NOT EXISTS invoice_items (
    id VARCHAR(50) PRIMARY KEY,
    invoiceId VARCHAR(50),
    productId VARCHAR(50),
    name VARCHAR(255),
    quantity INT DEFAULT 1,
    price DECIMAL(15, 2) DEFAULT 0.00,
    total DECIMAL(15, 2) DEFAULT 0.00,
    FOREIGN KEY (invoiceId) REFERENCES invoices(id) ON DELETE CASCADE
);

-- 6. Payments Table
CREATE TABLE IF NOT EXISTS payments (
    id VARCHAR(50) PRIMARY KEY,
    invoiceId VARCHAR(50),
    date DATE,
    amount DECIMAL(15, 2) DEFAULT 0.00,
    method VARCHAR(50),
    note TEXT,
    FOREIGN KEY (invoiceId) REFERENCES invoices(id) ON DELETE CASCADE
);

-- 7. Activity Log Table
CREATE TABLE IF NOT EXISTS activity_log (
    id VARCHAR(50) PRIMARY KEY,
    action VARCHAR(100),
    entityType VARCHAR(50),
    entityName VARCHAR(255),
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    details TEXT
);
