CREATE DATABASE IF NOT EXISTS trustinvoice
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE trustinvoice;

CREATE TABLE IF NOT EXISTS invoices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(255) NOT NULL,
    invoice_number VARCHAR(120) NOT NULL,
    invoice_date DATE NOT NULL,
    amount DECIMAL(14, 2) NOT NULL,
    currency CHAR(3) NOT NULL,
    document_hash CHAR(64) NOT NULL,
    identity_hash CHAR(64) NOT NULL,
    status ENUM('APPROVED', 'FLAGGED', 'DUPLICATE') NOT NULL,
    extracted_data JSON NOT NULL,
    issues JSON NOT NULL,
    hedera_transaction_id VARCHAR(100) NULL,
    hedera_topic_id VARCHAR(30) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invoices_identity_hash (identity_hash),
    INDEX idx_invoices_created_at (created_at),
    INDEX idx_invoices_status (status)
) ENGINE=InnoDB;
