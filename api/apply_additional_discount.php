<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config.php';

// التحقق من الجلسة والصلاحيات
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'ليس لديك صلاحية للوصول إلى هذه الصفحة'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من CSRF token
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'رمز التحقق غير صالح'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// جلب البيانات من الطلب
$invoiceId = isset($_POST['invoice_id']) ? intval($_POST['invoice_id']) : 0;
$itemsData = isset($_POST['items']) ? json_decode($_POST['items'], true) : [];
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
$refundMethod = isset($_POST['refund_method']) ? $_POST['refund_method'] : 'balance_reduction'; // 'cash', 'wallet', 'balance_reduction'
$userId = intval($_SESSION['id'] ?? 0);

// التحقق من البيانات
if ($invoiceId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'معرف الفاتورة غير صالح'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($itemsData) || !is_array($itemsData)) {
    echo json_encode([
        'success' => false,
        'message' => 'بيانات البنود غير صالحة'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($reason)) {
    echo json_encode([
        'success' => false,
        'message' => 'يجب كتابة سبب التعديل'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// بداية المعاملة
$conn->begin_transaction();

try {
    // 1️⃣ جلب بيانات الفاتورة
    $stmt = $conn->prepare("
        SELECT 
            i.*,
            c.id AS customer_id,
            c.balance AS customer_balance,
            c.wallet AS customer_wallet,
            w.id AS work_order_id,
            w.title AS work_order_title
        FROM invoices_out i
        JOIN customers c ON i.customer_id = c.id
        LEFT JOIN work_orders w ON i.work_order_id = w.id
        WHERE i.id = ? 
        AND i.delivered NOT IN ('canceled', 'reverted')
        FOR UPDATE
    ");
    $stmt->bind_param("i", $invoiceId);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$invoice) {
        throw new Exception("الفاتورة غير موجودة أو غير قابلة للتعديل");
    }
    
    // تحديد حالة الفاتورة
    $invoiceStatus = 'pending';
    if ($invoice['delivered'] == 'reverted') {
        $invoiceStatus = 'returned';
    } elseif ($invoice['remaining_amount'] == 0) {
        $invoiceStatus = 'paid';
    } elseif ($invoice['paid_amount'] > 0 && $invoice['remaining_amount'] > 0) {
        $invoiceStatus = 'partial';
    }
    
    // 2️⃣ جلب البنود الحالية
    $stmt = $conn->prepare("
        SELECT ioi.*, p.name AS product_name
        FROM invoice_out_items ioi
        JOIN products p ON ioi.product_id = p.id
        WHERE ioi.invoice_out_id = ? 
        AND ioi.returned_quantity < ioi.quantity
        ORDER BY ioi.id
    ");
    $stmt->bind_param("i", $invoiceId);
    $stmt->execute();
    $currentItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (empty($currentItems)) {
        throw new Exception("لا توجد بنود قابلة للتعديل في الفاتورة");
    }
    
    // 3️⃣ تحديث البنود وحساب الإجماليات الجديدة
    // ملاحظة: نحسب الإجماليات بناءً على الكمية المتبقية فقط (بعد المرتجع) لضمان صحة رصيد العميل
    $updatedItemsMap = []; // خريطة للبنود المعدلة
    $totalDiscountIncrease = 0;
    $updatedItemsCount = 0;
    
    // أولاً: تحديث البنود المعدلة
    foreach ($itemsData as $itemData) {
        $itemId = intval($itemData['id'] ?? 0);
        $additionalDiscountType = $itemData['additional_discount_type'] ?? null; // 'percent' or 'amount'
        $additionalDiscountValue = floatval($itemData['additional_discount_value'] ?? 0);
        
        // البحث عن البند في البنود الحالية
        $currentItem = null;
        foreach ($currentItems as $ci) {
            if ($ci['id'] == $itemId) {
                $currentItem = $ci;
                break;
            }
        }
        
        if (!$currentItem || $additionalDiscountValue <= 0 || !$additionalDiscountType) {
            continue; // تخطي البنود التي لا يوجد عليها تعديل
        }
        
        // حساب الكمية المتاحة (بعد المرتجع)
        $returnedQuantity = floatval($currentItem['returned_quantity'] ?? 0);
        $totalQuantity = floatval($currentItem['quantity']);
        $availableQuantity = $totalQuantity - $returnedQuantity;
        
        if ($returnedQuantity > 0) {
            throw new Exception("البند #{$itemId} ({$currentItem['product_name']}) يحتوي على مرتجعات ولا يمكن تعديله.");
        }
        
        if ($availableQuantity <= 0) {
            continue;
        }
        
        // حساب الخصم الإضافي للبند
        $itemTotalBefore = floatval($currentItem['total_before_discount']);
        $currentItemDiscount = floatval($currentItem['discount_amount'] ?? 0);
        $currentDiscountType = $currentItem['discount_type'] ?? null;
        $currentDiscountValue = floatval($currentItem['discount_value'] ?? 0);
        
        $itemAdditionalDiscount = 0;
        
        if ($additionalDiscountType === 'percent') {
            if ($additionalDiscountValue < 0 || $additionalDiscountValue > 100) {
                throw new Exception("نسبة الخصم للبند #{$itemId} يجب أن تكون بين 0 و 100");
            }
            $itemAdditionalDiscount = $itemTotalBefore * ($additionalDiscountValue / 100);
        } else {
            // amount
            if ($additionalDiscountValue < 0) {
                throw new Exception("قيمة الخصم للبند #{$itemId} يجب أن تكون موجبة");
            }
            $itemAdditionalDiscount = $additionalDiscountValue;
        }
        
        // التأكد من عدم تجاوز total_after_discount (الحد الأقصى للخصم)
        $currentTotalAfterDiscount = floatval($currentItem['total_after_discount'] ?? 0);
        if ($itemAdditionalDiscount > $currentTotalAfterDiscount) {
            // لا يمكن أن يكون الخصم الإضافي أكبر من total_after_discount
            throw new Exception("الخصم الإضافي للبند #{$itemId} يتجاوز الحد الأقصى المسموح به ({$currentTotalAfterDiscount} ج.م)");
        }
        
        // حساب الخصم الإجمالي الجديد
        $newItemDiscount = $currentItemDiscount + $itemAdditionalDiscount;
        
        // التأكد من عدم تجاوز الإجمالي قبل الخصم
        if ($newItemDiscount > $itemTotalBefore) {
            $newItemDiscount = $itemTotalBefore;
            $itemAdditionalDiscount = $newItemDiscount - $currentItemDiscount;
        }
        
        // حساب discount_value و discount_type الجديدين
        // إذا كان الخصم الإضافي نسبة، نحسب النسبة الإجمالية
        // إذا كان الخصم الإضافي قيمة، نجمع القيمة
        $newDiscountType = null;
        $newDiscountValue = 0;
        
        if ($additionalDiscountType === 'percent') {
            // إذا كان الخصم القديم نسبة أو null، نحسب النسبة الإجمالية
            if ($currentDiscountType === 'percent' || $currentDiscountType === null) {
                $newDiscountType = 'percent';
                $newDiscountValue = ($newItemDiscount / $itemTotalBefore) * 100;
            } else {
                // إذا كان الخصم القديم قيمة، نحفظه كقيمة
                $newDiscountType = 'amount';
                $newDiscountValue = $newItemDiscount;
            }
        } else {
            // الخصم الإضافي قيمة
            if ($currentDiscountType === 'amount' || $currentDiscountType === null) {
                $newDiscountType = 'amount';
                $newDiscountValue = $newItemDiscount;
            } else {
                // إذا كان الخصم القديم نسبة، نحوله لقيمة ثم نضيف
                $newDiscountType = 'amount';
                $newDiscountValue = $newItemDiscount;
            }
        }
        
        // حساب الإجمالي الجديد للبند
        $newItemTotalAfterDiscount = $itemTotalBefore - $newItemDiscount;
        
        // حساب unit_price_after_discount
        $newUnitPriceAfterDiscount = $totalQuantity > 0 ? $newItemTotalAfterDiscount / $totalQuantity : 0;
        
        // تحديث البند في قاعدة البيانات
        $stmtUpdate = $conn->prepare("
            UPDATE invoice_out_items 
            SET discount_type = ?,
                discount_value = ?,
                discount_amount = ?,
                total_after_discount = ?,
                unit_price_after_discount = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmtUpdate->bind_param("sddddi", 
            $newDiscountType,
            $newDiscountValue,
            $newItemDiscount,
            $newItemTotalAfterDiscount,
            $newUnitPriceAfterDiscount,
            $itemId
        );
        $stmtUpdate->execute();
        $stmtUpdate->close();
        
        // حفظ البيانات المحدثة
        $updatedItemsMap[$itemId] = [
            'new_total_after_discount' => $newItemTotalAfterDiscount,
            'new_discount_amount' => $newItemDiscount,
            'new_unit_price' => $newUnitPriceAfterDiscount
        ];
        
        $totalDiscountIncrease += $itemAdditionalDiscount;
        $updatedItemsCount++;
    }
    
    if ($updatedItemsCount == 0) {
        throw new Exception("لم يتم تطبيق أي تعديل على البنود");
    }
    
    // 4️⃣ إعادة حساب إجماليات الفاتورة بالكامل (بناءً على الكميات المتبقية فقط)
    // هذا يضمن أن الإجمالي يعكس الواقع بعد المرتجعات والتعديلات الجديدة
    $newInvoiceTotalBefore = 0;
    $newTotalAfterDiscount = 0;
    $newInvoiceTotalCost = 0;
    
    foreach ($currentItems as $item) {
        $itemId = intval($item['id']);
        $availableQty = floatval($item['quantity']) - floatval($item['returned_quantity']);
        $unitPriceBefore = floatval($item['selling_price']);
        $unitCost = floatval($item['cost_price_per_unit']);
        
        $newInvoiceTotalBefore += ($unitPriceBefore * $availableQty);
        $newInvoiceTotalCost += ($unitCost * $availableQty);
        
        if (isset($updatedItemsMap[$itemId])) {
            // بند معدل - نستخدم سعر الوحدة الجديد
            $newTotalAfterDiscount += ($updatedItemsMap[$itemId]['new_unit_price'] * $availableQty);
        } else {
            // بند غير معدل - نستخدم سعر الوحدة الحالي
            $newTotalAfterDiscount += (floatval($item['unit_price_after_discount']) * $availableQty);
        }
    }
    
    // حساب الخصم الإجمالي الجديد للفاتورة (الفرق بين الإجمالي قبل وبعد الخصم للكميات المتبقية)
    $newInvoiceDiscountAmount = $newInvoiceTotalBefore - $newTotalAfterDiscount;
    $oldTotalAfterDiscount = floatval($invoice['total_after_discount']);
    
    // حساب discount_type و discount_value للفاتورة
    $newInvoiceDiscountType = 'percent';
    $newInvoiceDiscountValue = $newInvoiceTotalBefore > 0 ? ($newInvoiceDiscountAmount / $newInvoiceTotalBefore) * 100 : 0;
    
    // حساب الربح الجديد
    $newProfitAmount = $newTotalAfterDiscount - $newInvoiceTotalCost;
    
    // 5️⃣ تحديث الفاتورة الرئيسية
    $paidAmount = floatval($invoice['paid_amount'] ?? 0);
    $oldRemainingAmount = floatval($invoice['remaining_amount'] ?? 0);
    $newRemainingAmount = max(0, $newTotalAfterDiscount - $paidAmount);
    
    $stmtUpdateInvoice = $conn->prepare("
        UPDATE invoices_out 
        SET total_before_discount = ?,
            discount_type = ?,
            discount_value = ?,
            discount_amount = ?,
            total_after_discount = ?,
            total_cost = ?,
            remaining_amount = ?,
            profit_amount = ?,
            updated_by = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmtUpdateInvoice->bind_param("dsdddddddi",
        $newInvoiceTotalBefore,
        $newInvoiceDiscountType,
        $newInvoiceDiscountValue,
        $newInvoiceDiscountAmount,
        $newTotalAfterDiscount,
        $newInvoiceTotalCost,
        $newRemainingAmount,
        $newProfitAmount,
        $userId,
        $invoiceId
    );
    $stmtUpdateInvoice->execute();
    $stmtUpdateInvoice->close();
    
    // 5️⃣ معالجة رصيد العميل والإرجاع حسب حالة الفاتورة
    $customerId = intval($invoice['customer_id']);
    $currentBalance = floatval($invoice['customer_balance']);
    $currentWallet = floatval($invoice['customer_wallet']);
    $balanceChange = 0;
    $walletChange = 0;
    $refundAmount = 0;
    $refundTransactionId = null;
    
    $amountDifference = $newTotalAfterDiscount - $oldTotalAfterDiscount; // سالب = تخفيض
    
    if ($invoiceStatus === 'pending') {
        // فاتورة مؤجلة: تقليل الرصيد المتبقي فقط
        $balanceChange = $amountDifference; // سالب (تقليل الدين)
        $newBalance = $currentBalance + $balanceChange;
        
    } elseif ($invoiceStatus === 'partial') {
        // فاتورة جزئية: تقليل المتبقي، وإذا تجاوز المتبقي نرجع الفرق للعميل
        $oldRemaining = floatval($invoice['remaining_amount']);
        $discountAmount = abs($amountDifference); // مقدار الخصم (موجب)
        
        // إذا كان الخصم أقل من أو يساوي المتبقي، فقط تقليل المتبقي
        if ($discountAmount <= $oldRemaining) {
            // لا يوجد إرجاع مطلوب، فقط تقليل الرصيد
            $balanceChange = $amountDifference; // سالب (تقليل الدين)
            $newBalance = $currentBalance + $balanceChange;
        } else {
            // الخصم أكبر من المتبقي، يجب إرجاع الفرق
            // المبلغ الزائد الذي يجب إرجاعه = الخصم - المتبقي القديم
            $refundAmount = $discountAmount - $oldRemaining; // الفرق الزائد
            
            // تحديث المتبقي إلى صفر
            $balanceChange = -$oldRemaining; // تقليل المتبقي القديم فقط
            $newBalance = $currentBalance + $balanceChange;
            
            // إذا كان هناك إرجاع مطلوب، يجب اختيار طريقة إرجاع (cash أو wallet)
            if ($refundAmount > 0) {
                // التحقق من طريقة الإرجاع (يجب أن تكون cash أو wallet فقط)
                if ($refundMethod !== 'cash' && $refundMethod !== 'wallet') {
                    throw new Exception("يجب اختيار طريقة إرجاع: نقدي أو محفظة، لأن الخصم ({$discountAmount} ج.م) أكبر من المتبقي ({$oldRemaining} ج.م)");
                }
                
                if ($refundMethod === 'cash') {
                    // إنشاء دفعة سالبة (إرجاع نقدي)
                    $stmtPayment = $conn->prepare("
                        INSERT INTO invoice_payments 
                        (invoice_id, payment_amount, payment_method, notes, created_by, created_at)
                        VALUES (?, ?, 'cash', ?, ?, NOW())
                    ");
                    $paymentAmountNegative = -$refundAmount; // سالب للإرجاع
                    $paymentNotes = "إرجاع نقدي - خصم إضافي على الفاتورة #{$invoiceId}: {$reason}";
                    $stmtPayment->bind_param("idss", $invoiceId, $paymentAmountNegative, $paymentNotes, $userId);
                    $stmtPayment->execute();
                    $refundTransactionId = $conn->insert_id;
                    $stmtPayment->close();
                    
                    // تحديث المدفوع والمتبقي
                    $newPaidAmount = max(0, $paidAmount - $refundAmount);
                    $newRemainingAmount = max(0, $newTotalAfterDiscount - $newPaidAmount);
                    $stmtUpdatePaid = $conn->prepare("UPDATE invoices_out SET paid_amount = ?, remaining_amount = ? WHERE id = ?");
                    $stmtUpdatePaid->bind_param("ddi", $newPaidAmount, $newRemainingAmount, $invoiceId);
                    $stmtUpdatePaid->execute();
                    $stmtUpdatePaid->close();
                    
                } elseif ($refundMethod === 'wallet') {
                    // إضافة للمحفظة
                    $walletChange = $refundAmount;
                    $newWallet = $currentWallet + $walletChange;
                    
                    $stmtUpdateWallet = $conn->prepare("UPDATE customers SET wallet = ? WHERE id = ?");
                    $stmtUpdateWallet->bind_param("di", $newWallet, $customerId);
                    $stmtUpdateWallet->execute();
                    $stmtUpdateWallet->close();
                    
                    // تسجيل حركة المحفظة
                    $stmtWalletTransaction = $conn->prepare("
                        INSERT INTO wallet_transactions 
                        (customer_id, type, amount, description, wallet_before, wallet_after, created_by, created_at)
                        VALUES (?, 'refund', ?, ?, ?, ?, ?, NOW())
                    ");
                    $walletDescription = "إرجاع للمحفظة - خصم إضافي على الفاتورة #{$invoiceId}: {$reason}";
                    $stmtWalletTransaction->bind_param("idsddi", $customerId, $walletChange, $walletDescription, $currentWallet, $newWallet, $userId);
                    $stmtWalletTransaction->execute();
                    $stmtWalletTransaction->close();
                    
                    // تحديث المدفوع والمتبقي
                    $newPaidAmount = max(0, $paidAmount - $refundAmount);
                    $newRemainingAmount = max(0, $newTotalAfterDiscount - $newPaidAmount);
                    $stmtUpdatePaid = $conn->prepare("UPDATE invoices_out SET paid_amount = ?, remaining_amount = ? WHERE id = ?");
                    $stmtUpdatePaid->bind_param("ddi", $newPaidAmount, $newRemainingAmount, $invoiceId);
                    $stmtUpdatePaid->execute();
                    $stmtUpdatePaid->close();
                }
            } else {
                // إذا لم يتم اختيار طريقة إرجاع، نستخدم balance_reduction (لا شيء)
                $refundAmount = 0;
            }
        }
        
    } elseif ($invoiceStatus === 'paid') {
        // فاتورة مدفوعة بالكامل: يجب إرجاع الفرق
        $refundAmount = abs($amountDifference);
        
        // التحقق من طريقة الإرجاع (يجب أن تكون cash أو wallet فقط)
        if ($refundMethod !== 'cash' && $refundMethod !== 'wallet') {
            throw new Exception("الفواتير المدفوعة تتطلب اختيار طريقة إرجاع: نقدي أو محفظة");
        }
        
        // تحديث المدفوع والمتبقي (المدفوع ينقص لأنه تم إرجاع جزء منه)
        $oldPaidAmount = floatval($invoice['paid_amount']);
        $newPaidAmount = max(0, $oldPaidAmount - $refundAmount);
        
        if ($refundMethod === 'cash') {
            // إنشاء دفعة سالبة (إرجاع نقدي)
            $stmtPayment = $conn->prepare("
                INSERT INTO invoice_payments 
                (invoice_id, payment_amount, payment_method, notes, created_by, created_at)
                VALUES (?, ?, 'cash', ?, ?, NOW())
            ");
            $paymentAmountNegative = -$refundAmount; // سالب للإرجاع
            $paymentNotes = "إرجاع نقدي - خصم إضافي على الفاتورة #{$invoiceId}: {$reason}";
            $stmtPayment->bind_param("idss", $invoiceId, $paymentAmountNegative, $paymentNotes, $userId);
            $stmtPayment->execute();
            $refundTransactionId = $conn->insert_id;
            $stmtPayment->close();
            
        } elseif ($refundMethod === 'wallet') {
            // إضافة للمحفظة
            $walletChange = $refundAmount;
            $newWallet = $currentWallet + $walletChange;
            
            $stmtUpdateWallet = $conn->prepare("UPDATE customers SET wallet = ? WHERE id = ?");
            $stmtUpdateWallet->bind_param("di", $newWallet, $customerId);
            $stmtUpdateWallet->execute();
            $stmtUpdateWallet->close();
            
            // تسجيل حركة المحفظة
            $stmtWalletTransaction = $conn->prepare("
                INSERT INTO wallet_transactions 
                (customer_id, type, amount, description, wallet_before, wallet_after, created_by, created_at)
                VALUES (?, 'refund', ?, ?, ?, ?, ?, NOW())
            ");
            $walletDescription = "إرجاع للمحفظة - خصم إضافي على الفاتورة #{$invoiceId}: {$reason}";
            $stmtWalletTransaction->bind_param("idsddi", $customerId, $walletChange, $walletDescription, $currentWallet, $newWallet, $userId);
            $stmtWalletTransaction->execute();
            $stmtWalletTransaction->close();
        }
        
        // تحديث المدفوع والمتبقي (بعد الإرجاع)
        $newRemainingAmount = max(0, $newTotalAfterDiscount - $newPaidAmount);
        $stmtUpdatePaid = $conn->prepare("UPDATE invoices_out SET paid_amount = ?, remaining_amount = ? WHERE id = ?");
        $stmtUpdatePaid->bind_param("ddi", $newPaidAmount, $newRemainingAmount, $invoiceId);
        $stmtUpdatePaid->execute();
        $stmtUpdatePaid->close();
        
        // تحديث الرصيد - لا يتغير لأن الفاتورة كانت مدفوعة بالكامل
        // الرصيد لم يكن مرتبط بها أصلاً
        $balanceChange = 0;
        $newBalance = $currentBalance;
    }
    
    // 6️⃣ تحديث رصيد العميل
    if ($balanceChange != 0) {
        $stmtUpdateCustomer = $conn->prepare("UPDATE customers SET balance = ? WHERE id = ?");
        $newBalance = $currentBalance + $balanceChange;
        $stmtUpdateCustomer->bind_param("di", $newBalance, $customerId);
        $stmtUpdateCustomer->execute();
        $stmtUpdateCustomer->close();
    } else {
        $newBalance = $currentBalance;
    }
    
    if ($walletChange == 0) {
        $newWallet = $currentWallet;
    }
    
    // 7️⃣ تسجيل حركة العميل (customer_transactions) - فقط إذا كان هناك تغيير في الرصيد أو المحفظة
    if ($balanceChange != 0 || $walletChange != 0) {
        // بناء الوصف مع تفاصيل الحركة والشغلانة
        $workOrderInfo = '';
        if (!empty($invoice['work_order_id']) && !empty($invoice['work_order_title'])) {
            $workOrderInfo = " - الشغلانة: #{$invoice['work_order_id']} ({$invoice['work_order_title']})";
        }
        
        $transactionType = '';
        if ($balanceChange != 0 && $walletChange != 0) {
            $transactionType = "تعديل رصيد ومحفظة";
        } elseif ($balanceChange != 0) {
            $transactionType = "تعديل رصيد";
        } elseif ($walletChange != 0) {
            $transactionType = "تعديل محفظة";
        }
        
        $description = "خصم إضافي على الفاتورة #{$invoiceId} - {$transactionType} - مبلغ الخصم: " . number_format($totalDiscountIncrease, 2) . " ج.م{$workOrderInfo} - السبب: {$reason}";
        
        $stmtTransaction = $conn->prepare("
            INSERT INTO customer_transactions 
            (customer_id, transaction_type, amount, description, invoice_id, 
             balance_before, balance_after, wallet_before, wallet_after, created_by, created_at)
            VALUES (?, 'adjustment', ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmtTransaction->bind_param("idsiddddi",
            $customerId,
            $balanceChange,
            $description,
            $invoiceId,
            $currentBalance,
            $newBalance,
            $currentWallet,
            $newWallet,
            $userId
        );
        $stmtTransaction->execute();
        $stmtTransaction->close();
    }
    
    // 8️⃣ تحديث الشغلانة إذا كانت مرتبطة
    if ($invoice['work_order_id']) {
        $workOrderId = intval($invoice['work_order_id']);
        
        // جلب بيانات الشغلانة
        $stmtWO = $conn->prepare("SELECT total_invoice_amount, total_remaining FROM work_orders WHERE id = ? FOR UPDATE");
        $stmtWO->bind_param("i", $workOrderId);
        $stmtWO->execute();
        $workOrder = $stmtWO->get_result()->fetch_assoc();
        $stmtWO->close();
        
        if ($workOrder) {
            // حساب التغيير في المتبقي (oldRemainingAmount - newRemainingAmount)
            $remainingChange =-($oldRemainingAmount - $newRemainingAmount);
            
            // تحديث إجمالي الفواتير والمتبقي
            $stmtWorkOrder = $conn->prepare("
                UPDATE work_orders 
                SET total_invoice_amount = total_invoice_amount + ?,
                    total_remaining = GREATEST(0, total_remaining + ?),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmtWorkOrder->bind_param("ddi", 
                $amountDifference, // سالب إذا كان خصم (يقلل إجمالي الفواتير)
                $remainingChange,  // التغيير في المتبقي
                $workOrderId
            );
            $stmtWorkOrder->execute();
            $stmtWorkOrder->close();
        }
    }
    
    // 9️⃣ تسجيل التعديل في جدول invoice_adjustments
    // التحقق من وجود الجدول أولاً
    // $checkTable = $conn->query("SHOW TABLES LIKE 'invoice_adjustments'");
    // if ($checkTable && $checkTable->num_rows > 0) {
    //     $oldProfitAmount = floatval($invoice['profit_amount'] ?? 0);
    //     $itemsDataJson = json_encode($itemsData, JSON_UNESCAPED_UNICODE);
    //     $workOrderId = $invoice['work_order_id'] ? intval($invoice['work_order_id']) : null;
        
    //     // معالجة work_order_id (يمكن أن يكون null)
    //     if ($workOrderId === null || $workOrderId == 0) {
    //         // إذا كان null، نستخدم NULL مباشرة في SQL
    //         $stmtAdjustment = $conn->prepare("
    //             INSERT INTO invoice_adjustments 
    //             (invoice_id, adjustment_type, discount_type, discount_value, discount_amount, 
    //              old_total_after_discount, new_total_after_discount,
    //              old_remaining_amount, new_remaining_amount,
    //              old_profit_amount, new_profit_amount,
    //              refund_method, refund_amount, 
    //              reason, items_data,
    //              customer_balance_before, customer_balance_after,
    //              customer_wallet_before, customer_wallet_after,
    //              work_order_id, created_by, created_at)
    //             VALUES (?, 'discount_add', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NOW())
    //         ");
            
    //         if (!$stmtAdjustment) {
    //             throw new Exception("خطأ في إعداد الاستعلام: " . $conn->error);
    //         }
            
    //         $stmtAdjustment->bind_param("isdddddddddsddddi",
    //             $invoiceId,
    //             $newInvoiceDiscountType,
    //             $newInvoiceDiscountValue,
    //             $totalDiscountIncrease,
    //             $oldTotalAfterDiscount,
    //             $newTotalAfterDiscount,
    //             $oldRemainingAmount,
    //             $newRemainingAmount,
    //             $oldProfitAmount,
    //             $newProfitAmount,
    //             $refundMethod,
    //             $refundAmount,
    //             $reason,
    //             $itemsDataJson,
    //             $currentBalance,
    //             $newBalance,
    //             $currentWallet,
    //             $newWallet,
    //             $userId
    //         );
    //     }
    //  else {
    //         // إذا كان له قيمة، نستخدمه
    //         $stmtAdjustment = $conn->prepare("
    //             INSERT INTO invoice_adjustments 
    //             (invoice_id, adjustment_type, discount_type, discount_value, discount_amount, 
    //              old_total_after_discount, new_total_after_discount,
    //              old_remaining_amount, new_remaining_amount,
    //              old_profit_amount, new_profit_amount,
    //              refund_method, refund_amount, 
    //              reason, items_data,
    //              customer_balance_before, customer_balance_after,
    //              customer_wallet_before, customer_wallet_after,
    //              work_order_id, created_by, created_at)
    //             VALUES (?, 'discount_add', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    //         ");
            
    //         if (!$stmtAdjustment) {
    //             throw new Exception("خطأ في إعداد الاستعلام: " . $conn->error);
    //         }
            
    //         $stmtAdjustment->bind_param("isdddddddddsddddiii",
    //             $invoiceId,                    // i - invoice_id
    //             $newInvoiceDiscountType,        // s - discount_type
    //             $newInvoiceDiscountValue,       // d - discount_value
    //             $totalDiscountIncrease,         // d - discount_amount
    //             $oldTotalAfterDiscount,         // d - old_total_after_discount
    //             $newTotalAfterDiscount,         // d - new_total_after_discount
    //             $oldRemainingAmount,           // d - old_remaining_amount
    //             $newRemainingAmount,           // d - new_remaining_amount
    //             $oldProfitAmount,              // d - old_profit_amount
    //             $newProfitAmount,              // d - new_profit_amount
    //             $refundMethod,                 // s - refund_method
    //             $refundAmount,                  // d - refund_amount
    //             $reason,                       // s - reason
    //             $itemsDataJson,                 // s - items_data
    //             $currentBalance,                // d - customer_balance_before
    //             $newBalance,                   // d - customer_balance_after
    //             $currentWallet,                // d - customer_wallet_before
    //             $newWallet,                    // d - customer_wallet_after
    //             $workOrderId,                  // i - work_order_id
    //             $userId                        // i - created_by
    //         );

    //     }
        
    //     $stmtAdjustment->execute();
    //     $stmtAdjustment->close();
    // }

    $itemsDataJson = json_encode($itemsData, JSON_UNESCAPED_UNICODE);
$workOrderId = !empty($invoice['work_order_id']) ? intval($invoice['work_order_id']) : null;

$stmtAdjustment = $conn->prepare("
    INSERT INTO invoice_adjustments
    (invoice_id, adjustment_type, discount_type, discount_value, discount_amount,
     old_total_after_discount, new_total_after_discount,
     old_remaining_amount, new_remaining_amount,
     old_profit_amount, new_profit_amount,
   
     reason, items_data,
     customer_balance_before, customer_balance_after,
     customer_wallet_before, customer_wallet_after,
     work_order_id, created_by, created_at)
    VALUES (?, 'discount_add', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,  ?, ?, NOW())
");

if (!$stmtAdjustment) {
    throw new Exception("Prepare failed: " . $conn->error);
}

$stmtAdjustment->bind_param(
    "isddddddddsddddiii",
    $invoiceId,                 // i
    $newInvoiceDiscountType,     // s
    $newInvoiceDiscountValue,    // d
    $totalDiscountIncrease,      // d
    $oldTotalAfterDiscount,      // d
    $newTotalAfterDiscount,      // d
    $oldRemainingAmount,         // d
    $newRemainingAmount,         // d
    $oldProfitAmount,            // d
    $newProfitAmount,            // d
    // $refundMethod,               // s
    // $refundAmount,               // d
    $reason,                     // s
    $itemsDataJson,              // s
    $currentBalance,             // d
    $newBalance,                 // d
    $currentWallet,              // d
    $newWallet,                  // d
    $workOrderId,                // i (NULL مسموح)
    $userId                      // i
);

$stmtAdjustment->execute();
$stmtAdjustment->close();

    
    // تأكيد المعاملة
    $conn->commit();
    
    // إرجاع النتيجة
    echo json_encode([
        'success' => true,
        'message' => "تم تطبيق التعديل بنجاح",
        'invoice' => [
            'id' => $invoiceId,
            'old_total_after_discount' => $oldTotalAfterDiscount,
            'new_total_after_discount' => $newTotalAfterDiscount,
            'discount_increase' => $totalDiscountIncrease,
            'new_remaining_amount' => $newRemainingAmount,
            'new_profit_amount' => $newProfitAmount
        ],
        'customer' => [
            'id' => $customerId,
            'old_balance' => $currentBalance,
            'new_balance' => $newBalance,
            'balance_change' => $balanceChange,
            'old_wallet' => $currentWallet,
            'new_wallet' => $newWallet,
            'wallet_change' => $walletChange
        ],
        'refund' => [
            'amount' => $refundAmount,
            'method' => $refundMethod,
            'transaction_id' => $refundTransactionId
        ],
        'status' => $invoiceStatus,
        'items_updated' => $updatedItemsCount
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    
} catch (Exception $e) {
    // تراجع عن كل التغييرات
    $conn->rollback();
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>

