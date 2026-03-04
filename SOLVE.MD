START TRANSACTION;

SET @work_order_id := 8;
SET @user_id := 8;

SET @customer_id := (
    SELECT customer_id
    FROM invoices_out
    WHERE id IN (809,801,810,819,857)
    LIMIT 1
);

SET @total_overpaid := (
    SELECT SUM(paid_amount - total_after_discount)
    FROM invoices_out
    WHERE id IN (809,801,810,819,857)
    AND paid_amount > total_after_discount
);

SET @reverse_amount := IFNULL(@total_overpaid,0);

SET @balance_before := (
    SELECT balance
    FROM customers
    WHERE id = @customer_id
);

SET @balance_after := @balance_before + @reverse_amount;

INSERT INTO customer_transactions (
    customer_id,
    transaction_type,
    amount,
    description,
    work_order_id,
    balance_before,
    balance_after,
    created_by,
    transaction_date
)
VALUES (
    @customer_id,
    'adjustment',
    @reverse_amount,
    'Reverse duplicate payments بسبب تعليق السيرفر للفواتير: 809,801,810,819,857',
    @work_order_id,
    @balance_before,
    @balance_after,
    @user_id,
    NOW()
);

INSERT INTO invoice_payments (
    invoice_id,
    payment_amount,
    payment_method,
    notes,
    created_by,
    work_order_id,
    created_at
)
SELECT 
    id,
    -(paid_amount - total_after_discount),
    'cash',
    'Reverse duplicate payment بسبب تعليق السيرفر',
    @user_id,
    @work_order_id,
    NOW()
FROM invoices_out
WHERE id IN (809,801,810,819,857)
AND paid_amount > total_after_discount;

UPDATE invoices_out i
SET 
    paid_amount = (
        SELECT COALESCE(SUM(payment_amount),0)
        FROM invoice_payments
        WHERE invoice_id = i.id
    ),
    remaining_amount = total_after_discount - (
        SELECT COALESCE(SUM(payment_amount),0)
        FROM invoice_payments
        WHERE invoice_id = i.id
    )
WHERE id IN (809,801,810,819,857);

UPDATE customers
SET balance = @balance_after
WHERE id = @customer_id;

COMMIT;