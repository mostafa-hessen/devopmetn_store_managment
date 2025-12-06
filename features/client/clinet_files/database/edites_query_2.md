-- 1. جدول أوامر الشغل
CREATE TABLE work_orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    status ENUM('pending', 'active', 'completed', 'cancelled') DEFAULT 'pending',
    total_amount DECIMAL(12,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id)
);

-- 2. جدول حركات العميل (مبسط جداً)
CREATE TABLE customer_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    type ENUM('invoice', 'payment', 'return', 'deposit') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    
    -- الربط
    invoice_id INT NULL,
    work_order_id INT NULL,
    return_id INT NULL,
    deposit_id INT NULL,
    
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (invoice_id) REFERENCES invoices_out(id),
    FOREIGN KEY (work_order_id) REFERENCES work_orders(id)
);

-- 3. جدول إيداعات بسيط
CREATE TABLE deposits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    notes VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id)
);

-- 4. جدول المرتجعات بسيط
CREATE TABLE returns (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    invoice_id INT NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    method ENUM('cash', 'wallet', 'credit') DEFAULT 'credit',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (invoice_id) REFERENCES invoices_out(id)
);

-- 5. تعديلات بسيطة على العملاء
ALTER TABLE customers 
ADD COLUMN balance DECIMAL(12,2) DEFAULT 0.00 COMMENT 'مدين (+) أو دائن (-)',
ADD COLUMN wallet DECIMAL(12,2) DEFAULT 0.00 COMMENT 'رصيد المحفظة للإيداعات';

-- 6. تعديل بسيط على الفواتير
ALTER TABLE invoices_out 
ADD COLUMN work_order_id INT NULL,
ADD COLUMN returned_qty
ADD COLUMN is_fully_returned false

ADD FOREIGN KEY (work_order_id) REFERENCES work_orders(id);