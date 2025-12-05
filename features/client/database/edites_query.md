//==================== work order =======================

CREATE TABLE work_orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    delivery_date DATE,
    budget DECIMAL(12,2) DEFAULT 0.00,
    notes TEXT,
    paid DECIMAL(12,2) DEFAULT 0.00,
    remaining DECIMAL(12,2) DEFAULT 0.00,
    deposit_balance DECIMAL(12,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id)
);

CREATE TABLE work_order_invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    work_order_id INT NOT NULL,
    invoice_out_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (work_order_id) REFERENCES work_orders(id),
    FOREIGN KEY (invoice_out_id) REFERENCES invoices_out(id)
);

// ============== customer_transactions =====================
CREATE TABLE customer_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    transaction_date DATE NOT NULL,
    transaction_time TIME NOT NULL,
    type ENUM('invoice', 'payment', 'return', 'deposit', 'adjustment') NOT NULL,
    description VARCHAR(255),
    amount DECIMAL(12,2) NOT NULL,
    balance_before DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    invoice_id INT NULL,
    work_order_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (invoice_id) REFERENCES invoices_out(id),
    FOREIGN KEY (work_order_id) REFERENCES work_orders(id)
);




CREATE TABLE deposits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    work_order_id INT NULL,
    deposit_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    description VARCHAR(255),
    type ENUM('customer', 'workorder') NOT NULL,
    balance_before DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (work_order_id) REFERENCES work_orders(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);



CREATE TABLE returns (
    id INT PRIMARY KEY AUTO_INCREMENT,
    return_number VARCHAR(50) UNIQUE,
    customer_id INT NOT NULL,
    invoice_id INT NOT NULL,
    type ENUM('full', 'partial') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    method ENUM('credit', 'cash', 'bank_transfer') NOT NULL,
    status ENUM('pending', 'completed', 'cancelled') DEFAULT 'pending',
    return_date DATE NOT NULL,
    reason TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (invoice_id) REFERENCES invoices_out(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);



CREATE TABLE return_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    return_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    batch_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (return_id) REFERENCES returns(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (batch_id) REFERENCES batches(id)
);



ALTER TABLE customers 
ADD COLUMN initial_balance DECIMAL(12,2) DEFAULT 0.00,
ADD COLUMN current_balance DECIMAL(12,2) DEFAULT 0.00,
ADD COLUMN total_purchases DECIMAL(12,2) DEFAULT 0.00,
ADD COLUMN deposit_balance DECIMAL(12,2) DEFAULT 0.00,
ADD COLUMN join_date DATE;


ALTER TABLE invoices_out 
ADD COLUMN work_order_id INT NULL,
ADD COLUMN description TEXT,
ADD FOREIGN KEY (work_order_id) REFERENCES work_orders(id);