صفحه مبنيه 
html css js php mysql
الصفحه تعرض تقارير المبيعات 
اريد الاتي
1- الفلاتر تاتي في الجانب وقسم الفواتير في النصف الاخر مثال الصفحه دي
<style>
        /* جميع الـ styles داخل pending-invoices-page لتجنب override */


        
        /* منع scroll على body عند وجود delivered-invoices-page */
        /* body:has(.delivered-invoices-page) {
            overflow-x: hidden;
        } */

        .delivered-invoices-page .shell {
            display: flex;
            flex-direction: column;
            gap: 16px;
            min-height: calc(100vh - 70px); /* 70px navbar + 40px padding */
            overflow: hidden;
        }

        .delivered-invoices-page header.top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
            flex-shrink: 0;
        }

        .delivered-invoices-page .brand {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .delivered-invoices-page .logo {
            width: 56px;
            height: 56px;
            border-radius: var(--radius-sm, 8px);
            background: var(--grad-1, linear-gradient(135deg, #667eea 0%, #764ba2 100%));
            color: #fff;
            display: grid;
            place-items: center;
            font-weight: 700;
            box-shadow: var(--shadow-1, 0 1px 3px rgba(0,0,0,0.1));
        }

        .delivered-invoices-page h1 {
            margin: 0;
            font-size: 1.2rem;
            color: var(--text, #1f2937);
        }

        .delivered-invoices-page .sub {
            color: var(--muted, #6b7280);
            font-size: 0.9rem;
        }

        /* top stats */
        .delivered-invoices-page .top-stats {
            display: flex;
            gap: 12px;
            /* align-items: center; */
            flex-wrap: wrap;
        }

        .delivered-invoices-page .stat {
            background: var(--surface, #fff);
            padding: 12px 16px;
            border-radius: var(--radius-sm, 8px);
            box-shadow: var(--shadow-1, 0 1px 3px rgba(0,0,0,0.1));
            min-width: 140px;
            border: 1px solid var(--border, #e5e7eb);
        }

        .delivered-invoices-page .stat .lbl {
            color: var(--muted, #6b7280);
            font-size: 0.85rem;
            font-weight: 600;
        }

        .delivered-invoices-page .stat .num {
            font-weight: 800;
            margin-top: 4px;
            color: var(--text, #1f2937);
            font-size: 1.1rem;
        }

        /* main layout - بدون scroll خارجي */
        .delivered-invoices-page .delivered-invoices-main {
            display: flex;
            gap: 16px;
            flex: 1;
            min-height: 0;
            overflow: hidden;
            padding: 20px 0px;
        }

        .delivered-invoices-page .delivered-invoices-main.row {
            margin: 0;
        }

        .delivered-invoices-page .filters-section {
            background: var(--surface, #fff);
            border-radius: var(--radius, 12px);
            padding: 16px;
            box-shadow: var(--shadow-1, 0 1px 3px rgba(0,0,0,0.1));
            border: 1px solid var(--border, #e5e7eb);
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            flex-shrink: 0;
           max-height: 67vh;
        }

        .delivered-invoices-page .filters-section.col-3 {
            max-width: 100%;
            flex: 0 0 25%; /* 25% من العرض */
            min-width: 250px; /* الحد الأدنى للعرض */
            width: 25%;
        }

        .delivered-invoices-page .content {
            background: transparent;
            display: flex;
            flex-direction: column;
            gap: 16px;
            flex: 1;
            min-height: 0;
            max-height:67vh;
            /* overflow-y: hidden; */
        }

        .delivered-invoices-page .content.col-9 {
            max-width: 100%;
            flex: 1 1 auto;
            min-width: 300px; /* الحد الأدنى للعرض */
            width: 100%;
        }

        /* filters */
        .delivered-invoices-page .filter-title {
            font-weight: 800;
            margin-bottom: 12px;
            color: var(--text, #1f2937);
            font-size: 1rem;
        }

        .delivered-invoices-page .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 12px;
            margin-bottom: 12px;
        }

        .delivered-invoices-page .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .delivered-invoices-page .field label {
            font-size: 0.9rem;
            color: var(--text-soft, #4b5563);
            font-weight: 700;
        }

        .delivered-invoices-page .field input[type="text"],
        .delivered-invoices-page .field input[type="number"],
        .delivered-invoices-page .field input[type="date"],
        .delivered-invoices-page .field textarea,
        .delivered-invoices-page .field select {
            padding: 10px 12px;
            border-radius: var(--radius-sm, 8px);
            border: 1px solid var(--border, #e5e7eb);
            background: var(--surface-2, #f9fafb);
            font-size: 0.95rem;
            color: var(--text, #1f2937);
            width: 100%;
        }

        .delivered-invoices-page .field input:focus,
        .delivered-invoices-page .field select:focus,
        .delivered-invoices-page .field textarea:focus {
            border-color: var(--primary, #3b82f6);
            box-shadow: var(--ring, 0 0 0 3px rgba(59, 130, 246, 0.1));
            outline: none;
        }

        .delivered-invoices-page .field input::placeholder {
            color: var(--muted, #6b7280);
        }

        .delivered-invoices-page .small-hint {
            font-size: 0.82rem;
            color: var(--muted, #6b7280);
        }

        .delivered-invoices-page .filters-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            flex-wrap: wrap;
        }

        .delivered-invoices-page .btn.apply {
            background: var(--primary, #3b82f6);
            color: #fff;
            box-shadow: var(--shadow-2, 0 4px 6px rgba(0,0,0,0.1));
            padding: 10px 20px;
            border-radius: var(--radius-sm, 8px);
            border: 0;
            cursor: pointer;
            font-weight: 700;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .delivered-invoices-page .btn.apply:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-2, 0 6px 12px rgba(0,0,0,0.15));
        }

        .delivered-invoices-page .btn.reset {
            background: transparent;
            border: 1px solid var(--border, #e5e7eb);
            color: var(--text, #1f2937);
            padding: 10px 20px;
            border-radius: var(--radius-sm, 8px);
            cursor: pointer;
            font-weight: 700;
            transition: background 0.2s;
        }

        .delivered-invoices-page .btn.reset:hover {
            background: var(--surface-2, #f9fafb);
        }

        /* summary cards */
        .delivered-invoices-page .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .delivered-invoices-page .summary-card {
            background: var(--surface, #fff);
            border-radius: var(--radius, 12px);
            padding: 16px;
            box-shadow: var(--shadow-1, 0 1px 3px rgba(0,0,0,0.1));
            border: 1px solid var(--border, #e5e7eb);
        }

        .delivered-invoices-page .summary-card .title {
            font-weight: 700;
            color: var(--text-soft, #4b5563);
            font-size: 0.9rem;
            margin-bottom: 8px;
        }

        .delivered-invoices-page .summary-card .value {
            font-weight: 800;
            color: var(--text, #1f2937);
            font-size: 1.3rem;
        }

        /* list area */
        .delivered-invoices-page .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }

        .delivered-invoices-page .toolbar .small {
            color: var(--muted, #6b7280);
            font-size: 0.9rem;
        }

        .delivered-invoices-page .list-wrapper {
            background: var(--surface, #fff);
            border-radius: var(--radius, 12px);
            padding: 16px;
            box-shadow: var(--shadow-1, 0 1px 3px rgba(0,0,0,0.1));
            border: 1px solid var(--border, #e5e7eb);
            overflow-y: auto;
         
            flex: 1;
            min-height: 0;
            /* max-height: 100%; */
            -webkit-overflow-scrolling: touch;
        }

        .delivered-invoices-page .list {
            display: grid;
            gap: 12px;
        }

        /* invoice card improved */
        .delivered-invoices-page .invoice {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            background: var(--surface, #fff);
            padding: 16px;
            border-radius: var(--radius-sm, 8px);
            box-shadow: var(--shadow-1, 0 1px 3px rgba(0,0,0,0.1));
            border: 1px solid var(--border, #e5e7eb);
            align-items: flex-start;
            transition: transform 0.2s, box-shadow 0.2s;
            width: 100%;
        }

        .delivered-invoices-page .invoice:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-2, 0 4px 6px rgba(0,0,0,0.1));
        }

        .delivered-invoices-page .invoice-left {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            min-width: 0;
            flex: 1;
            max-width: 100%;
        }

        .delivered-invoices-page .invoice-left .badge {
            background: var(--grad-1, linear-gradient(135deg, #667eea 0%, #764ba2 100%));
            color: #fff;
            padding: 8px 12px;
            border-radius: var(--radius-sm, 8px);
            font-weight: 800;
            font-size: 0.9rem;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .delivered-invoices-page .meta {
            min-width: 0;
            flex: 1;
            max-width: 100%;
            overflow: hidden;
        }

        .delivered-invoices-page .meta .name {
            font-weight: 800;
            color: var(--text, #1f2937);
            font-size: 1rem;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .delivered-invoices-page .meta .name::before {
            content: "👤";
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .delivered-invoices-page .meta .notes {
            color: var(--muted, #6b7280);
            font-size: 0.9rem;
            line-height: 1.5;
            margin-top: 8px;
            min-height: 1.5em;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-word;
        }

        .delivered-invoices-page .meta .extra {
            display: flex;
            gap: 12px;
            margin-top: 8px;
            color: var(--muted, #6b7280);
            font-size: 0.85rem;
            flex-wrap: wrap;
        }

        .delivered-invoices-page .meta .extra > div {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .delivered-invoices-page .invoice-right {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            flex-shrink: 0;
            justify-content: flex-end;
        }

        .delivered-invoices-page .amount {
            font-weight: 800;
            min-width: 120px;
            text-align: left;
            color: var(--text, #1f2937);
            font-size: 1.1rem;
        }

        .delivered-invoices-page .amount-with-discount {
            display: flex;
            flex-direction: column;
            gap: 4px;
            align-items: flex-end;
            min-width: 140px;
        }

        .delivered-invoices-page .amount-original {
            text-decoration: line-through;
            color: var(--muted, #6b7280);
            font-size: 0.9rem;
            font-weight: 600;
        }

        .delivered-invoices-page .amount-final {
            font-weight: 800;
            color: var(--primary, #3b82f6);
            font-size: 1.2rem;
        }

        .delivered-invoices-page .discount-badge {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #92400e;
            padding: 4px 10px;
            border-radius: var(--radius-sm, 8px);
            font-size: 0.8rem;
            font-weight: 700;
            border: 1px solid #fbbf24;
        }

        .delivered-invoices-page .status {
            padding: 6px 12px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 0.85rem;
        }

        .delivered-invoices-page .status.delivered {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #92400e;
        }

        .delivered-invoices-page .status.paid {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
        }

        .delivered-invoices-page .status.overdue {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
        }

        .delivered-invoices-page .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .delivered-invoices-page .actions button {
            padding: 8px 12px;
            border-radius: var(--radius-sm, 8px);
            border: 0;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.85rem;
            transition: transform 0.2s;
        }

        .delivered-invoices-page .actions button:hover {
            transform: translateY(-1px);
        }

        .delivered-invoices-page .actions .deliver {
            background: var(--teal, #14b8a6);
            color: #fff;
        }

        .delivered-invoices-page .actions .cancel {
            background: var(--rose, #f43f5e);
            color: #fff;
        }

        .delivered-invoices-page .actions .show {
            background: var(--primary, #3b82f6);
            color: #fff;
        }

        .delivered-invoices-page .actions .edit {
            background: var(--surface-2, #f9fafb);
            color: var(--text, #1f2937);
            border: 1px solid var(--border, #e5e7eb);
        }

        /* pagination */
        .delivered-invoices-page .pager {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        /* toast */
        .delivered-invoices-page .ipc-toast {
            position: fixed;
            right: 20px;
            bottom: 20px;
            background: #111827;
            color: #fff;
            padding: 8px 12px;
            border-radius: 8px;
            z-index: 16000;
            opacity: 0;
            transform: translateY(8px);
            transition: all .28s;
        }

        .delivered-invoices-page .ipc-toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        .delivered-invoices-page .rim-qty-input {
            width: 80px;
        }

        .delivered-invoices-page .rim-delete-btn {
            color: #b00;
            cursor: pointer;
        }

        .delivered-invoices-page .swal2-container {
            z-index: 10000 !important;
        }

        /* Responsive - ممتاز */
        @media (max-width: 1200px) {
            /* إزالة تحويل layout إلى عمودي - نريد أن يبقى side-by-side */
            .delivered-invoices-page .delivered-invoices-main {
                flex-direction: row;
            }

            .delivered-invoices-page .filters-section {
                max-height: none;
                /* height: 100%; */
                flex: 0 0 30%; /* زيادة العرض قليلاً على الشاشات المتوسطة */
                width: 30%;
                min-width: 250px;
            }
            
            .delivered-invoices-page .content.col-9 {
                flex: 1 1 70%; /* 70% للـ content */
                min-width: 400px; /* زيادة الحد الأدنى */
            }
            
            /* فقط على الشاشات الصغيرة جداً نجعله عمودي */
            @media (max-height: 600px) {
                .delivered-invoices-page .delivered-invoices-main {
                    flex-direction: column;
                }
                
                .delivered-invoices-page .filters-section {
                    max-height: 300px;
                    width: 100% !important;
                }
                
              
            }
        }

        @media (max-width: 992px) {
            .delivered-invoices-page {
                padding: 12px;
                margin-top: 70px; /* الحفاظ على المسافة تحت navbar */
            }
            
            /* على الشاشات المتوسطة، يمكن تحويل layout إلى عمودي */
            .delivered-invoices-page .delivered-invoices-main {
                flex-direction: column;
            }
            
            .delivered-invoices-page .filters-section {
                max-height: 400px;
                height: auto;
                width: 100% !important;
                flex: 0 0 auto !important;
            }
            
        

            .delivered-invoices-page header.top {
                flex-direction: column;
                align-items: flex-start;
            }

            .delivered-invoices-page .top-stats {
                width: 100%;
            }

            .delivered-invoices-page .stat {
                flex: 1;
                min-width: 120px;
            }
        }

        @media (max-width: 768px) {
            .delivered-invoices-page {
                padding: 8px;
                margin-top: 70px; /* الحفاظ على المسافة تحت navbar */
            }

            .delivered-invoices-page .filters-grid {
                grid-template-columns: 1fr;
            }

            .delivered-invoices-page .summary-cards {
                grid-template-columns: 1fr;
            }

            .delivered-invoices-page .invoice {
                flex-direction: column;
                align-items: flex-start;
            }

            .delivered-invoices-page .invoice-right {
                width: 100%;
                justify-content: space-between;
                margin-top: 12px;
            }

            .delivered-invoices-page .amount,
            .delivered-invoices-page .amount-with-discount {
                min-width: auto;
                width: 100%;
            }

            .delivered-invoices-page .actions {
                width: 100%;
                justify-content: flex-start;
            }

            .delivered-invoices-page .actions button {
                flex: 1;
                min-width: 80px;
            }

            .delivered-invoices-page .filters-actions {
                flex-direction: column;
            }

            .delivered-invoices-page .filters-actions .btn {
                width: 100%;
            }
        }

        @media (max-width: 576px) {
            .delivered-invoices-page h1 {
                font-size: 1rem;
            }

            .delivered-invoices-page .sub {
                font-size: 0.8rem;
            }

            .delivered-invoices-page .logo {
                width: 48px;
                height: 48px;
                font-size: 0.9rem;
            }

            .delivered-invoices-page .stat {
                padding: 10px 12px;
                min-width: 100px;
            }

            .delivered-invoices-page .stat .num {
                font-size: 1rem;
            }

            .delivered-invoices-page .invoice {
                padding: 12px;
            }

            .delivered-invoices-page .meta .name {
                font-size: 0.9rem;
            }

            .delivered-invoices-page .meta .extra {
                font-size: 0.75rem;
                gap: 8px;
            }
        }

        @media print {
            .delivered-invoices-page .no-print {
                display: none !important;
            }
        }
    </style>

    <div class="delivered-invoices-page">
        <div class="shell container-fluid">
            <header class="top pt-2">
                <div class="brand">
                    <div class="logo">INV</div>
                    <div>
                        <h1>الفواتير المسلمه</h1>
                        <div class="sub">فلترة متقدمة — عرض واضح ومعلومات مُكملة لكل فاتورة</div>
                    </div>
                </div>

                <div class="top-stats">
                    <div class="stat"><div class="lbl">عدد الفواتير</div><div class="num" id="stat-count"><?php echo ($result && $result->num_rows > 0) ? $result->num_rows : 0; ?></div></div>
                   <?php
                // حساب إجمالي الفواتير المعروضة بعد الخصم
                $displayed_total_after_discount = 0;
                $displayed_total_before_discount = 0;
                if ($result && $result->num_rows > 0) {
                    $result->data_seek(0);
                    while ($row = $result->fetch_assoc()) {
                        $total_before = floatval($row["total_before_discount"] ?? 0);
                        $total_after = floatval($row["total_after_discount"] ?? 0);
                        $invoice_total = floatval($row["invoice_total"] ?? 0);
                        
                        if ($total_before <= 0) {
                            $total_before = $invoice_total;
                        }
                        if ($total_after <= 0) {
                            $total_after = $total_before;
                        }
                        
                        $displayed_total_before_discount += $total_before;
                        $displayed_total_after_discount += $total_after;
                    }
                    $result->data_seek(0); // إعادة تعيين المؤشر
                }
                ?>
                    <!-- <div class="summary-card">
                        <div class="title">💰 الإجمالي الكلي (جميع الفواتير المعلقة)</div>
                        <div class="value" style="color:var(--primary)"><?php echo number_format($grand_total_all_delivered, 2); ?> ج.م</div>
                        <?php if ($grand_total_all_delivered < $grand_total_all_delivered_before): ?>
                            <div style="font-size:0.85rem; color:var(--muted); margin-top:4px">
                                قبل الخصم: <span style="text-decoration:line-through"><?php echo number_format($grand_total_all_delivered_before, 2); ?> ج.م</span>
                            </div>
                        <?php endif; ?>
                    </div> -->
                    <div class="summary-card">
                        <div class="title">📊 الإجمالي للفواتير المعروضة</div>
                        <div class="value" style="color:var(--teal)"><?php echo number_format($displayed_total_after_discount, 2); ?> ج.م</div>
                        <?php if ($displayed_total_after_discount < $displayed_total_before_discount): ?>
                            <div style="font-size:0.85rem; color:var(--muted); margin-top:4px">
                                قبل الخصم: <span style="text-decoration:line-through"><?php echo number_format($displayed_total_before_discount, 2); ?> ج.م</span>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
              
            </header>


            <div class="delivered-invoices-main row  ">
                <!-- الفلاتر داخل main-content -->
                <section class="filters-section col-12 col-md-3" id=aria-label="مرشحات الفواتير">
                    <div class="filter-title">🔍 مرشحات البحث</div>

                    <form method="get" action="<?php echo $current_page_link; ?>" id="filterForm">
                        <div class="filters-grid">
                           <div class="row  ">
                             <div class="col-6 col-md-6 field">
                                <label for="fInvoice">بحث برقم الفاتورة</label>
                                <input id="fInvoice" name="invoice_q" type="text" placeholder="مثال: 123" value="<?php echo e($invoice_q); ?>" />
                            </div>

                            <div class="col-6 col-md-6 field">
                                <label for="fPhone"> برقم هاتف العميل</label>
                                <input id="fPhone" name="mobile_q" type="text" placeholder="مثال: 01012345678" value="<?php echo e($mobile_q); ?>" />
                            </div>

                           </div>
                            <div class="row">
                                <div class="col-12 field">
                                <label for="fNotes">بحث حسب الملاحظات</label>
                                <input id="fNotes" name="notes_q" type="text" placeholder="كلمات من الملاحظات..." value="<?php echo e($notes_q); ?>" />
                            </div>
                            </div>

                         <div class="row">
                              
                            <div class="col-6   field">
                                <label>من تاريخ</label>
                                <input id="fFrom" name="date_from" type="date" value="<?php echo isset($_GET['date_from']) ? htmlspecialchars($_GET['date_from']) : ''; ?>" />
                            </div>

                            <div class="col-6 field">
                                <label>إلى تاريخ</label>
                                <input id="fTo" name="date_to" type="date" value="<?php echo isset($_GET['date_to']) ? htmlspecialchars($_GET['date_to']) : ''; ?>" />
                            </div>
                         </div>
                        </div>

                        <div class="filters-actions">
                            <button type="submit" class="btn apply">تطبيق الفلاتر</button>
                            <a href="<?php echo $current_page_link; ?>" class="btn reset">إعادة</a>
                            <a href="<?php echo $pending_invoices_link; ?>" class="btn" style="background:var(--amber); color:#fff">عرض الفواتير المؤجله</a>
                        </div>
                    </form>
                </section>

            <!-- CONTENT -->
            <main class="content col-12 col-md-12 col-lg-8" id="contentArea">
                <!-- كارد الإجماليات -->
              <div class="top-actions" style="display: flex; gap: 10px; align-items: center; margin-top: 10px;">
                        <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                            <input type="checkbox" id="selectAllInvoices">
                            تحديد الكل
                        </label>
                        <button id="printSelectedInvoices" class="btn" style="background: var(--primary); color: white; padding: 8px 16px;">
                            🖨️ طباعة الفواتير المحددة
                        </button>
                    </div>


                <div class="list-wrapper">
                    <section id="list" class="list" aria-label="قائمة الفواتير">
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php 
                        $result->data_seek(0); // إعادة تعيين المؤشر
                        while ($row = $result->fetch_assoc()):
                            $current_invoice_total_for_row = floatval($row["invoice_total"] ?? 0);
                            $displayed_invoices_sum += $current_invoice_total_for_row;
                            
                            // حساب الخصم
                            $total_before_discount = floatval($row["total_before_discount"] ?? 0);
                            $total_after_discount = floatval($row["total_after_discount"] ?? 0);
                            $discount_amount = floatval($row["discount_amount"] ?? 0);
                            $discount_type = $row["discount_type"] ?? 'percent';
                            $discount_value = floatval($row["discount_value"] ?? 0);
                            
                            // إذا كان total_before_discount = 0 أو null، استخدم invoice_total
                            if ($total_before_discount <= 0) {
                                $total_before_discount = $current_invoice_total_for_row;
                            }
                            if ($total_after_discount <= 0) {
                                $total_after_discount = $total_before_discount;
                            }
                            
                            // التحقق من وجود خصم فعلي
                            $has_discount = ($discount_amount > 0 && abs($total_after_discount - $total_before_discount) > 0.01);
                            $final_amount = $has_discount ? $total_after_discount : $total_before_discount;
                            
                            $noteText = trim((string)($row['notes'] ?? ''));
                            $noteDisplay = $noteText;
                            if (mb_strlen($noteDisplay) > 30) {
                                $noteDisplay = mb_substr($noteDisplay, 0, 30) . '...';
                            }
                            $created_date = date('m/d/Y', strtotime($row["created_at"]));
                        ?>
                            <article class="invoice">
                                <div class="invoice-left">
                                            <input type="checkbox" class="invoice-checkbox" data-invoice-id=<?php echo e($row["id"]); ?>>
                                                                                                                    
                                    <div class="badge">#<?php echo e($row["id"]); ?></div>
                                    <div class="meta">
                                        <div class="name"><?php echo e($row["customer_name"]); ?></div>
                                        <?php if ($noteDisplay): ?>
                                            <div class="notes" title="<?php echo e($noteText); ?>"><?php echo e($noteDisplay); ?></div>
                                        <?php endif; ?>
                                        <div class="extra">
                                            <div class="phone">📞 <?php echo e($row["customer_mobile"]); ?></div>
                                            <div class="creator">👤 <?php echo e($row["creator_name"] ?? 'غير معروف'); ?></div>
                                            <div>📅 <?php echo e($created_date); ?></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="invoice-right">
                                    <?php if ($has_discount): ?>
                                        <div class="amount-with-discount">
                                            <div class="amount-original"><?php echo number_format($total_before_discount, 2); ?> ج.م</div>
                                            <div class="amount-final"><?php echo number_format($total_after_discount, 2); ?> ج.م</div>
                                            <div class="discount-badge">
                                                <?php 
                                                if ($discount_type === 'percent') {
                                                    echo number_format($discount_value, 2) . '% خصم';
                                                } else {
                                                    echo number_format($discount_amount, 2) . ' ج.م خصم';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="amount"><?php echo number_format($final_amount, 2); ?> ج.م</div>
                                    <?php endif; ?>
                                    
                                    <div class="status paid">
                                        مسلمه
                                    </div>
                                    
                                    <div class="actions">
                                        <button class="show btn-open-modal" data-invoice-id="<?php echo e($row["id"]); ?>">عرض</button>
                                        
                                      <?php if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
                                            <!-- return to delivered -->
                                            <form method="post" action="<?php echo $current_page_link; ?>" class="d-inline ms-1" style="display:inline-block" onsubmit="return confirm('سيتم إرجاع الفاتورة #<?php echo e($row['id']); ?> إلى الفواتير المؤجلة. هل أنت متأكد؟');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="invoice_id" value="<?php echo e($row["id"]); ?>">
                                                <button type="submit" name="mark_pending" class="btn btn-outline-secondary btn-sm" title="إرجاع للمؤجلة"><i class="fas fa-undo"></i></button>
                                            </form>

                                        
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align:center;padding:40px;color:var(--muted)">
                            لا توجد فواتير غير مستلمة حالياً.
                        </div>
                    <?php endif; ?>
                    </section>
                </div>
            </main>
        </div>
    </div>


2- اريد الفلاتر تحسين قوي جدا اازي + ماهي الفلاتر
1- تاريخ من والي
2- بحث بالملاحظه 
3- بحث بنوع الفاتور مؤجل مدفوع جزئي
4- رقم الفاتوره 
5- الشغلانه اقدر ابحث عن شغلانه معينه تبع عميل معين
6- رقم العميل 
7-يوم اسبوع شهر  من اول المده من اةل 2020
بحث بالملاحظه 

اهم جزء في الفلاتر اريد ميضطرش يعمل ريفرش في الصفحه كلها بيوترني ومش مريح اريد يبقي في سلالسه في البحث ولودر لذيذ 
او تاثير skelaton


3- الفاتوره نفسها 
** يعرض total_before_discount --> احمالي قبل الخصن
**total_after_discount -> بعد الخصم والمرتجع

4- عند الضغط علي زر العين يفتح مودال فيه بنود المنتج 
السمه رقمه الكميه المرتججع المتبقي 
والاجمالي بعد المتبقي 
يعني 
المتبقي في السعر unit_price_after_discount
لو البند returned_flag= 1
لا يعرضه ولا يدخل حسابات 
للجداول 
تفضل

CREATE TABLE `invoices_out` (
  `id` int(11) NOT NULL COMMENT 'المعرف التلقائي للفاتورة',
  `customer_id` int(11) NOT NULL COMMENT 'معرف العميل المرتبط بالفاتورة',
  `delivered` enum('yes','no','canceled','reverted','partial') NOT NULL DEFAULT 'no',
  `invoice_group` enum('group1','group2','group3','group4','group5','group6','group7','group8','group9','group10','group11') NOT NULL COMMENT 'مجموعة الفاتورة (من 1 إلى 11)',
  `created_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي أنشأ الفاتورة',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ ووقت الإنشاء',
  `updated_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي آخر من عدل الفاتورة',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'تاريخ ووقت آخر تعديل',
  `notes` text DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `revert_reason` varchar(255) DEFAULT NULL,
  `total_before_discount` decimal(12,2) DEFAULT 0.00 COMMENT 'مجموع البيع قبل أي خصم',
  `discount_type` enum('percent','amount') DEFAULT 'percent' COMMENT 'نوع الخصم',
  `discount_value` decimal(10,2) DEFAULT 0.00 COMMENT 'قيمة الخصم: إذا percent -> تخزن النسبة (مثال: 10) وإلا قيمة المبلغ',
  `discount_amount` decimal(12,2) DEFAULT 0.00 COMMENT 'مبلغ الخصم المحسوب بالعملة',
  `total_after_discount` decimal(12,2) DEFAULT 0.00 COMMENT 'المجموع النهائي بعد الخصم',
  `total_cost` decimal(12,2) DEFAULT 0.00 COMMENT 'اجمالي التكلفة (مخزن للتقارير)',
  `profit_amount` decimal(12,2) DEFAULT 0.00 COMMENT 'اجمالي الربح = total_before_discount - total_cost',
  `paid_amount` decimal(12,2) DEFAULT 0.00,
  `remaining_amount` decimal(12,2) DEFAULT 0.00,
  `work_order_id` int(11) DEFAULT NULL,
  `discount_scope` enum('invoice','items','mixed') DEFAULT 'invoice' COMMENT 'مكان تطبيق الخصم'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول فواتير العملاء الصادرة';

CREATE TABLE `invoice_out_items` (
  `id` int(11) NOT NULL COMMENT 'المعرف التلقائي لبند الفاتورة',
  `invoice_out_id` int(11) NOT NULL COMMENT 'معرف الفاتورة الصادرة (مفتاح أجنبي لجدول invoices_out)',
  `product_id` int(11) NOT NULL COMMENT 'معرف المنتج (مفتاح أجنبي لجدول products)',
  `quantity` decimal(10,2) NOT NULL COMMENT 'الكمية المباعة من المنتج',
  `total_before_discount` decimal(10,2) NOT NULL COMMENT 'السعر الإجمالي للبند قبل الخصم (الكمية * سعر الوحدة)',
  `cost_price_per_unit` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ إضافة البند',
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'تاريخ آخر تعديل للبند',
  `selling_price` decimal(10,2) NOT NULL,
  `price_type` enum('retail','wholesale') NOT NULL DEFAULT 'wholesale',
  `returned_quantity` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'الكمية المرتجعة',
  `return_flag` tinyint(1) GENERATED ALWAYS AS (case when `returned_quantity` = `quantity` then 1 else 0 end) STORED COMMENT '1 إذا تم إرجاع البند بالكامل (تمام)، 0 جزئي',
  `available_for_return` decimal(10,2) GENERATED ALWAYS AS (`quantity` - `returned_quantity`) STORED COMMENT 'الكمية المتاحة للمرتجع',
  `discount_type` enum('percent','amount') DEFAULT NULL,
  `discount_value` decimal(10,2) DEFAULT 0.00,
  `discount_amount` decimal(12,2) DEFAULT 0.00,
  `total_after_discount` decimal(12,2) DEFAULT 0.00
  `unit_price_after_discount` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--CREATE TABLE `work_orders` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL COMMENT 'عنوان الشغلانة',
  `description` text DEFAULT NULL COMMENT 'وصف تفصيلي',
  `status` enum('pending','in_progress','completed','cancelled') DEFAULT 'pending',
  `start_date` date NOT NULL COMMENT 'تاريخ البدء',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات إضافية',
  `total_invoice_amount` decimal(12,2) DEFAULT 0.00 COMMENT 'إجمالي فواتير الشغلانة',
  `total_paid` decimal(12,2) DEFAULT 0.00 COMMENT 'إجمالي المدفوع',
  `total_remaining` decimal(12,2) DEFAULT 0.00 COMMENT 'إجمالي المتبقي',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
+
زر المرتجعات يعرض المرتجعات الحاصه بالفاتوره
 من خلال 

CREATE TABLE `returns` (
  `id` int(11) PRIMARY KEY AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `return_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `return_type` ENUM('full','partial','exchange') DEFAULT 'partial',
  `status` ENUM('pending','approved','completed','rejected') DEFAULT 'pending',
  `reason` TEXT,
  `approved_by` int(11) NULL,
  `approved_at` DATETIME NULL,
  `created_by` int(11) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `notes` TEXT
);

CREATE TABLE `return_items` (
  `id` int(11) PRIMARY KEY AUTO_INCREMENT,
  `return_id` int(11) NOT NULL,
  `invoice_item_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` DECIMAL(10,2) NOT NULL,
  `return_price` DECIMAL(10,2) NOT NULL, -- السعر وقت الإرجاع
  `total_amount` DECIMAL(10,2) NOT NULL,
  `batch_allocations` JSON, -- لتتبع أي دفعات تم إرجاعها
  `status` ENUM('pending','restocked','discarded') DEFAULT 'pending',
  `restocked_qty` DECIMAL(10,2) DEFAULT 0.00,
  `restocked_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);




فكره جديده عندما اريد ان اقرا البنود بقتح زر العين عشان اشوف تفاصيل الفاتوره هذا مرهق
الفكره اضف زر يعرض بنود الفاتوره المحتاره في sidebar 
يظهر في الشمال فوق العناصر يظهر فيها بالتفصيل كل التفاصيل اللي محتاج اعرفها عن الفاتوره
حالتها 
تبع مين
تبع شغلانه ولا لا
بنودها اجمالي خصومات الح
مع امكانيه اني اتنقل جواها ك slider
اتنقل فيه رجعوا وقدوما بين تقاصيل الفواتير الاخري



الطباعه 
امنثل للاتي+
امكانيه طباعه فواتير متعدده من خلال
    generateInvoicePrintContent(invoice) {
    const customer = AppData.currentCustomer;

    // تنسيق التاريخ
    const date = new Date(invoice.date);
    const options = { year: 'numeric', month: '2-digit', day: '2-digit' };
    const formattedDate = date.toLocaleDateString('ar-SA', options);
    const timeString = invoice.time || '12:00 م';

    // حساب المدفوع والمتبقي
    const paid = invoice.paid || 0;
    const remaining = invoice.remaining || 0;
    const status = invoice.status;
    

    
    // حساب بيانات الخصم
    const discountAmount = parseFloat(invoice.discount_amount || 0);
    const discountValue = parseFloat(invoice.discount_value || 0);
    const discountType = invoice.discount_type || 'percent';
    let beforeDiscount =0;
    const afterDiscount = parseFloat(invoice.total_after_discount || invoice.total || 0);

    // إنشاء بنود الفاتورة
    let itemsHTML = '';
    let subtotal = 0;

    invoice.items.forEach((item) => {
        
        if (!item.fullyReturned) {
            const remainingQuantity = (item.available_for_return|| 0);
            if (remainingQuantity > 0) {
                const itemTotal = remainingQuantity * item.selling_price;
                beforeDiscount += itemTotal;

                itemsHTML += `
            <tr>
                <td style="width:10%; text-align:center;">
                    ${item.id}
                </td>

                <td style="width:40%; text-align:right; padding-right:5px;">
                    ${item.product_name}
                </td>

                <td style="width:15%; text-align:center;">
                    ${remainingQuantity.toFixed(2)}
                </td>

                <td style="width:15%; text-align:left; padding-left:5px;">
                    ${item.selling_price.toFixed(2)}
                </td>

                <td style="width:20%; text-align:left; padding-left:5px;">
                    ${itemTotal.toFixed(2)}
                </td>
            </tr>
        `;
            }
        }
    });

    // بناء HTML كامل للطباعة
    return `
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>فاتورة ${invoice.id}</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            
            body {
                padding: 10px;
                background: white;
                color: #000;
                font-size: 12px;
            }
            
            .invoice {
                width: 280px;
                margin: 0 auto;
                padding: 10px;
                border: 1px solid #000;
            }
            
            .header {
                text-align: center;
                padding-bottom: 10px;
                margin-bottom: 10px;
                border-bottom: 2px dashed #000;
            }
            
            .store-name {
                font-weight: 900;
                font-size: 16px;
                margin-bottom: 5px;
                color: #000;
            }
            
            .store-info {
                font-weight: 700;
                font-size: 10px;
                margin-bottom: 2px;
                color: #555;
            }
            
            .invoice-info {
                display: flex;
                justify-content: space-between;
                margin-bottom: 10px;
                font-weight: 700;
                font-size: 10px;
            }
            
            .customer-info {
                margin-bottom: 10px;
                padding: 8px;
                background: #f8f9fa;
                border-radius: 4px;
                font-weight: 700;
                font-size: 10px;
            }
            
            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 10px;
                font-weight: 700;
                font-size: 10px;
            }
            
            th, td {
                padding: 6px 2px;
                text-align: center;
                border-bottom: 1px dashed #ddd;
            }
            
            th {
                background: #f1f8ff;
                font-weight: 900;
            }
            
            .totals {
                margin-top: 10px;
                font-size: 11px;
            }
            
            .total-row {
                display: flex;
                justify-content: space-between;
                padding: 4px 0;
            }
            
            .total-final {
                border-top: 2px dashed #000;
                margin-top: 5px;
                padding-top: 8px;
                font-weight: 900;
            }
            
            .payment-info {
                margin: 10px 0;
                padding: 8px;
                background: #f8f9fa;
                border-radius: 4px;
                font-weight: 700;
                font-size: 10px;
            }
            
            .payment-details {
                margin-top: 5px;
            }
            
            .payment-row {
                display: flex;
                justify-content: space-between;
                padding: 2px 0;
            }
            
            .footer {
                text-align: center;
                margin-top: 15px;
                padding-top: 10px;
                border-top: 2px dashed #000;
                font-weight: 700;
                font-size: 9px;
                color: #555;
            }
            
            .barcode {
                text-align: center;
                margin: 10px 0;
                font-family: monospace;
                font-size: 16px;
                letter-spacing: 3px;
                font-weight: 900;
            }
            
            .status {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 10px;
                font-weight: 700;
                margin-top: 5px;
            }
            
            .status-pending { background: #fff3cd; color: #856404; }
            .status-partial { background: #d1ecf1; color: #0c5460; }
            .status-paid { background: #d4edda; color: #155724; }
            .status-returned { background: #f8d7da; color: #721c24; }
            
            /* تصميم الخصم الجديد */
            .discount-section {
                margin: 10px 0;
                padding: 8px;
                background: #fff3cd;
                border-radius: 4px;
                border: 1px dashed #856404;
            }
            
            .discount-row {
                display: flex;
                justify-content: space-between;
                padding: 3px 0;
            }
            
            .original-price {
                text-decoration: line-through;
                color: #6c757d;
            }
            
            .discount-badge {
                display: inline-block;
                padding: 2px 8px;
                background: #dc3545;
                color: white;
                border-radius: 12px;
                font-size: 10px;
                font-weight: bold;
            }
            
            @media print {
                body {
                    padding: 0;
                    margin: 0;
                }
                
                .invoice {
                    border: none;
                    width: 100%;
                    max-width: 280px;
                }
            }
        </style>
    </head>
    <body>
        <div class="invoice">
            <div class="header">
                <div class="store-name">نظام الفواتير الإلكتروني</div>
                <div class="store-info">السجل التجاري: 1234567890</div>
                <div class="store-info">الهاتف: 01096590768</div>
            </div>
            
            <div class="invoice-info">
                <div>
                    <div>رقم الفاتورة: ${invoice.id}</div>
                    <div>التاريخ: ${formattedDate}</div>
                </div>
                <div>
                    <div>الوقت: ${timeString}</div>
                    <div>الكاشير: ${invoice.createdByName || 'مدير النظام'}</div>
                </div>
            </div>
            
            <div class="customer-info">
                <div>العميل: ${customer.name}</div>
                <div>الهاتف: ${customer.mobile}</div>
                <div class="status status-${status}">
                    حالة الفاتورة:
                    ${status === 'pending' ? 'مؤجل' :
            status === 'partial' ? 'جزئي' :
                status === 'paid' ? 'مسلم' : 'مرتجع'}
                </div>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>المنتج</th>
                        <th>الكمية</th>
                        <th>السعر</th>
                        <th>الإجمالي</th>
                    </tr>
                </thead>
                <tbody>
                    ${itemsHTML}
                </tbody>
            </table>
            
            <!-- قسم الخصم إذا كان موجودًا -->
            ${discountAmount > 0 ? `
            <div class="discount-section">
                <div style="text-align: center; font-weight: 900; margin-bottom: 5px; color: #856404;">
                    <i class="fas fa-tag"></i> تفاصيل الخصم
                </div>
                <div class="discount-row">
                    <span>الإجمالي قبل الخصم:</span>
                    <span class="original-price">${beforeDiscount.toFixed(2)} ج.م</span>
                </div>
                <div class="discount-row">
                    <span>قيمة الخصم:</span>
                    <span class="text-danger">-${discountAmount.toFixed(2)} ج.م</span>
                </div>
              
                <div class="discount-row" style="border-top: 1px dashed #856404; padding-top: 5px;">
                    <span>الإجمالي بعد الخصم:</span>
                    <span class="fw-bold">${afterDiscount.toFixed(2)} ج.م</span>
                </div>
            </div>
            ` : ''}
            
            <div class="totals">
              
                
                <div class="total-row">
                    <span>المدفوع:</span>
                    <span>${paid.toFixed(2)} ج.م</span>
                </div>
                
                <div class="total-row">
                    <span>المتبقي:</span>
                    <span>${remaining.toFixed(2)} ج.م</span>
                </div>
                
                <div class="total-row total-final">
                    <span>صافي المبلغ:</span>
                    <span>${remaining.toFixed(2)} ج.م</span>
                </div>
            </div>
            

            <div class="barcode">*${invoice.id}*</div>
            
            <div class="footer">
                <div>شكراً لتعاملكم معنا</div>
                <div>للاستفسار: 01096590768</div>
                <div style="margin-top: 5px; font-size: 8px;">${new Date().toLocaleDateString('ar-EG')} ${new Date().toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' })}</div>
            </div>
        </div>
        
        <script>
            // طباعة تلقائية بعد تحميل الصفحة
            window.onload = function() {
                setTimeout(() => {
                    window.print();
                }, 300);
            };
        </script>
    </body>
    </html>
`;
},  printMultipleInvoices(invoices=[],workOrder=null) {
    let invoiceIds = invoices;

    if(!workOrder){

        const selectedCheckboxes = document.querySelectorAll(
            ".print-invoice-checkbox:checked"
        );
        if (selectedCheckboxes.length === 0) {
            Swal.fire("تحذير", "يرجى اختيار فواتير للطباعة.", "warning");
            return;
        }
    
         invoiceIds = Array.from(selectedCheckboxes).map((checkbox) =>
            parseInt(checkbox.dataset.invoiceId)
        );
    }

    
    // إنشاء تقرير مجمع
    const report = {
        invoicesCount: invoiceIds.length,
        items: [],
        totals: {
            beforeDiscount: 0,
            afterDiscount: 0,
            discountAmount: 0,
            totalCost: 0,
            profitAmount: 0,
            discountType: 'percent' // الافتراضي
        },
        payments: {
            totalPaid: 0,
            totalRemaining: 0
        },
        invoices: [],
        customerName: AppData.currentCustomer?.name || 'غير محدد',
        workOrder: workOrder?workOrder.name: null
    };

    // تجميع بيانات الفواتير المحددة
    invoiceIds.forEach((inv) => {

        const invoice = workOrder ? inv : AppData.invoices.find((i) => i.id === inv);
        
        if (invoice) {
            // بناء كائن الفاتورة كما في قاعدة البيانات
            report.invoices.push({
                id: invoice.id,
                customer_id: invoice.customer_id,
                delivered: invoice.delivered,
                invoice_group: invoice.invoice_group,
                total_before_discount: invoice.total_before_discount || invoice.total || 0,
                total_after_discount: invoice.total_after_discount || invoice.total || 0,
                discount_amount: invoice.discount_amount || 0,
                discount_type: invoice.discount_type || 'percent',
                discount_value: invoice.discount_value || 0,
                total_cost: invoice.total_cost || 0,
                profit_amount: invoice.profit_amount || 0,
                paid_amount: invoice.paid_amount || invoice.paid || 0,
                remaining_amount: invoice.remaining_amount || invoice.remaining || 0,
                notes: invoice.notes,
                created_at: invoice.created_at || invoice.date,
                customer_name: invoice.customer_name || AppData.currentCustomer?.name
            });

            // جمع الإجماليات
            report.totals.beforeDiscount += invoice.total_before_discount || invoice.total || 0;
            report.totals.afterDiscount += invoice.total_after_discount || invoice.total || 0;
            report.totals.discountAmount += invoice.discount_amount || 0;
            report.totals.totalCost += invoice.total_cost || 0;
            report.totals.profitAmount += invoice.profit_amount || 0;
            
            // جمع المدفوعات والمتبقي
            report.payments.totalPaid += invoice.paid_amount || invoice.paid || 0;
            report.payments.totalRemaining += invoice.remaining_amount || invoice.remaining || 0;

            // إضافة البنود غير المرتجعة بالكامل
            invoice.items.forEach((item) => {
                if (!item.fullyReturned) {
                    const remainingQuantity =
                        item.quantity - (item.returned_quantity || 0);
                    if (remainingQuantity > 0) {
                        // البحث عن المنتج إذا كان موجودًا بالفعل
                        const existingItem = report.items.find(
                            (i) =>
                                i.name === item.product_name && 
                                i.price === item.selling_price
                        );
                        if (existingItem) {
                            existingItem.quantity += remainingQuantity;
                            existingItem.total += remainingQuantity * item.selling_price;
                            existingItem.cost_total += remainingQuantity * (item.cost_price || 0);
                        } else {
                            report.items.push({
                                id: item.id,
                                name: item.product_name,
                                quantity: remainingQuantity,
                                price: item.selling_price,
                                total: remainingQuantity * item.selling_price,
                                cost_price: item.cost_price || 0,
                                cost_total: remainingQuantity * (item.cost_price || 0)
                            });
                        }
                    }
                }
            });
        }
    });

    // استخدام دالة الطباعة المجمعة
    this.printAggregatedReport(report);

    // إغلاق المودال
    // const modal = bootstrap.Modal.getInstance(
    //     document.getElementById("printMultipleModal")
    // );
    // modal.hide();
},  generateAggregatedReportContent(report) {
    
    
    const today = new Date();
    const formattedDate = today.toLocaleDateString('ar-SA');
    const formattedTime = today.toLocaleTimeString('ar-SA', {
        hour: '2-digit',
        minute: '2-digit'
    });

    // استخدام بيانات المدفوعات من الـ report
    const totalPaid = report.payments.totalPaid || 0;
    const totalRemaining = report.payments.totalRemaining || 0;
    
    // حساب إجمالي تكلفة وربح المنتجات
    const totalCost = report.items.reduce((sum, item) => sum + (item.cost_total || 0), 0);
    const totalSales = report.items.reduce((sum, item) => sum + (item.total || 0), 0);
    const totalProfit = totalSales - totalCost;

    // إنشاء بنود المنتجات
    let itemsHTML = '';
    report.items.forEach((item, index) => {
        itemsHTML += `
            <tr>
                <td style="width:10%; text-align:center;">${index + 1}</td>
                <td style="width:45%; text-align:right; padding-right:5px;">
                    ${item.name}
                </td>
                <td style="width:15%; text-align:center;">${item.quantity.toFixed(2)}</td>
                <td style="width:15%; text-align:center;">${item.price?.toFixed(2)}</td>
                <td style="width:20%; text-align:left; padding-left:5px;">
                    ${item.total.toFixed(2)} 
                </td>
            </tr>
        `;
    });

    // إنشاء قائمة الفواتير المختارة
    let invoicesListHTML = '';
    if (report.invoices && report.invoices.length > 0) {
        report.invoices.forEach((inv, index) => {
            const status = inv.delivered === 'yes' ? 'مسلم' : 
                          inv.delivered === 'partial' ? 'جزئي' : 
                          inv.delivered === 'no' ? 'معلق' :
                          inv.delivered === 'canceled' ? 'ملغى' : 'مرتجع';
            
            invoicesListHTML += `
            <div style="padding: 3px 0; border-bottom: 1px dashed #eee; font-size: 9px;">
                <div style="display: flex; justify-content: space-between;">
                    <span>#${inv.id}</span>
                    <span>${status}</span>
                    <span>${inv.total_after_discount?.toFixed(2) || '0.00'}</span>
                </div>
            </div>
            `;
        });
    }

    return `
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
          ${report.workOrder ? `<title> فواتير شغلانه ${report.workOrder}</title>`: `<title>تقرير فواتير مجمع</title>`}
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            
            body {
                padding: 10px;
                background: white;
                color: #000;
                font-size: 12px;
            }
            
            .report {
                width: 280px;
                margin: 0 auto;
                padding: 10px;
                border: 1px solid #000;
            }
            
            .header {
                text-align: center;
                padding-bottom: 10px;
                margin-bottom: 10px;
                border-bottom: 2px dashed #000;
            }
            
            .report-title {
                font-weight: 900;
                font-size: 16px;
                margin-bottom: 5px;
                color: #000;
            }
            
            .report-info {
                display: flex;
                justify-content: space-between;
                margin-bottom: 10px;
                font-weight: 700;
                font-size: 10px;
            }
            
            .stats {
                display: flex;
                justify-content: space-between;
                margin-bottom: 10px;
                padding: 10px;
                background: #f8f9fa;
                border-radius: 4px;
            }
            
            .stat-item {
                text-align: center;
            }
            
            .stat-value {
                font-weight: 900;
                font-size: 14px;
                display: block;
            }
            
            .stat-label {
                font-size: 9px;
                color: #555;
            }
            
            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 10px;
                font-weight: 700;
                font-size: 10px;
            }
            
            th, td {
                padding: 6px 2px;
                text-align: center;
                border-bottom: 1px dashed #ddd;
            }
            
            th {
                background: #f1f8ff;
                font-weight: 900;
            }
            
            .totals {
                margin-top: 10px;
                font-size: 11px;
            }
            
            .total-row {
                display: flex;
                justify-content: space-between;
                padding: 4px 0;
            }
            
            .total-final {
                border-top: 2px dashed #000;
                margin-top: 5px;
                padding-top: 8px;
                font-weight: 900;
            }
            
            .payment-info {
                margin: 10px 0;
                padding: 8px;
                background: #f8f9fa;
                border-radius: 4px;
                font-weight: 700;
                font-size: 10px;
            }
            
            .payment-details {
                margin-top: 5px;
            }
            
            .payment-row {
                display: flex;
                justify-content: space-between;
                padding: 2px 0;
            }
            
            .invoices-list {
                margin: 10px 0;
                padding: 8px;
                background: #f0f7ff;
                border-radius: 4px;
                max-height: 120px;
                overflow-y: auto;
            }
            
            .invoices-header {
                font-weight: 900;
                text-align: center;
                margin-bottom: 5px;
                padding-bottom: 3px;
                border-bottom: 1px solid #ccc;
            }
            
            .footer {
                text-align: center;
                margin-top: 15px;
                padding-top: 10px;
                border-top: 2px dashed #000;
                font-weight: 700;
                font-size: 9px;
                color: #555;
            }
            
            .positive { color: #28a745; }
            .negative { color: #dc3545; }
            .neutral { color: #6c757d; }
            
            @media print {
                body {
                    padding: 0;
                    margin: 0;
                }
                
                .report {
                    border: none;
                    width: 100%;
                    max-width: 280px;
                }
            }
        </style>
    </head>
    <body>
        <div class="report">
            <div class="header">
          ${report.workOrder ? `<div class="report-title"> فواتير شغلانه ${report.workOrder}</div>`: `                <div class="report-title">تقرير فواتير مجمع</div>
`}

                <div style="font-size: 10px;">نظام الفواتير الإلكتروني</div>
            </div>
            
            <div class="report-info">
                <div>
                    <div>عدد الفواتير: ${report.invoicesCount}</div>
                    <div>التاريخ: ${formattedDate}</div>
                </div>
                <div>
                    <div>الوقت: ${formattedTime}</div>
                    <div>العميل: ${report.customerName}</div>
                </div>
            </div>
            
            <div class="stats">
                <div class="stat-item">
                    <span class="stat-value">${report.invoicesCount}</span>
                    <span class="stat-label">فواتير</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value">${report.items.length}</span>
                    <span class="stat-label">منتج</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value">${report.totals.afterDiscount.toFixed(2)}</span>
                    <span class="stat-label">الإجمالي</span>
                </div>
            </div>
            
          
            
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>المنتج</th>
                        <th>الكمية</th>
                        <th>س. البيع</th>
                        <th>الإجمالي</th>
                    </tr>
                </thead>
                <tbody>
                    ${itemsHTML}
                </tbody>
            </table>
            
            <div class="totals">
                <div class="total-row">
                    <span>إجمالي المبيعات:</span>
                    <span>${totalSales.toFixed(2)} ج.م</span>
                </div>
                
                
                
                <div class="total-row">
                    <span>الخصومات:</span>
                    <span class="negative">- ${report.totals.discountAmount.toFixed(2)} ج.م</span>
                </div>
                <div class="total-row">
                    <span>المطلوب بعد الخصم:</span>
                    <span > ${report.totals.afterDiscount.toFixed(2)} ج.م</span>
                </div>
                
                <!-- قسم المدفوعات والمتبقي -->
                <div class="payment-info">
                    <div style="font-weight: 900; margin-bottom: 5px; text-align: center;">بيانات الدفع</div>
                    <div class="payment-details">
                        <div class="payment-row">
                            <span>المدفوع:</span>
                            <span class="positive">${totalPaid.toFixed(2)} ج.م</span>
                        </div>
                        <div class="payment-row">
                            <span>المتبقي:</span>
                            <span class="negative">${totalRemaining.toFixed(2)} ج.م</span>
                        </div>
                        <div class="payment-row" style="border-top: 1px dashed #ccc; padding-top: 4px;">
                            <span>نسبة السداد:</span>
                            <span style="font-weight: 900;">
                                ${report.totals.afterDiscount > 0 ? 
                                    ((totalPaid / report.totals.afterDiscount) * 100).toFixed(1) : 0}%
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="total-row total-final">
                    <span>صافي المطلوب:</span>
                    <span style="font-weight: 900; font-size: 12px;">
                        ${totalRemaining.toFixed(2)} ج.م
                        
                    </span>
                </div>
            </div>
            
            <div class="footer">
                <div>تمت الطباعة بواسطة النظام الإلكتروني</div>
                <div>${formattedDate} - ${formattedTime}</div>
            </div>
        </div>
        
        <script>
            window.onload = function() {
                setTimeout(() => {
                    window.print();
                }, 300);
            };
        </script>
    </body>
    </html>
    `;
},
    // في PrintManager:
    printWorkOrderInvoices(workOrderId) {
        const workOrder = AppData.workOrders.find(wo => wo.id === workOrderId);
        if (!workOrder) {
            Swal.fire('خطأ', 'الشغلانة غير موجودة', 'error');
            return;
        }

        const relatedInvoices =        AppData.invoices.filter(inv =>{
            console.log(inv.status);
            
            return inv.work_order_id === workOrderId && inv.status !== 'returned';
        });


        

        if (relatedInvoices.length === 0) {
            Swal.fire('تحذير', 'لا توجد فواتير في هذه الشغلانة', 'warning');
            return;
        }

        // إنشاء محتوى الطباعة المجمع
         this.printMultipleInvoices( relatedInvoices , workOrder);

        // فتح نافذة طباعة جديدة
     

        // الانتظار قليلاً ثم الطباعة
    
    },

    generateWorkOrderPrintContent(workOrder, invoices) {
        const customer = AppData.currentCustomer;
        const today = new Date().toLocaleDateString('ar-SA');

        // حساب الإجماليات
        const totalInvoices = invoices.reduce((sum, inv) => sum + inv.total, 0);
        const totalPaid = invoices.reduce((sum, inv) => sum + inv.paid, 0);
        const totalRemaining = totalInvoices - totalPaid;

        // إنشاء قائمة الفواتير
        let invoicesHTML = '';
        invoices.forEach((invoice, index) => {
            invoicesHTML += `
            <tr>
                <td style="width: 10%; text-align: center;">${index + 1}</td>
                <td style="width: 20%; text-align: center;">${invoice.number}</td>
                <td style="width: 20%; text-align: center;">${invoice.date}</td>
                <td style="width: 25%; text-align: left; padding-left: 5px;">${invoice.total.toFixed(2)}</td>
                <td style="width: 25%; text-align: left; padding-left: 5px;">${invoice.remaining.toFixed(2)}</td>
            </tr>
        `;
        });

        // بناء HTML كامل
        return `
        <!DOCTYPE html>
        <html lang="ar" dir="rtl">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>تقرير الشغلانة - ${workOrder.name}</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                }
                
                body {
                    padding: 10px;
                    background: white;
                    color: #000;
                    font-size: 12px;
                }
                
                .report {
                    width: 280px;
                    margin: 0 auto;
                    padding: 10px;
                    border: 1px solid #000;
                }
                
                .header {
                    text-align: center;
                    padding-bottom: 10px;
                    margin-bottom: 10px;
                    border-bottom: 2px dashed #000;
                }
                
                .report-title {
                    font-weight: 900;
                    font-size: 16px;
                    margin-bottom: 5px;
                    color: #000;
                }
                
                .work-order-info {
                    margin-bottom: 15px;
                    padding: 10px;
                    background: #f8f9fa;
                    border-radius: 4px;
                    font-weight: 700;
                    font-size: 10px;
                }
                
                .work-order-detail {
                    margin-bottom: 5px;
                }
                
                .stats {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 15px;
                }
                
                .stat-card {
                    text-align: center;
                    padding: 10px;
                    background: #f1f8ff;
                    border-radius: 4px;
                    width: 32%;
                }
                
                .stat-value {
                    font-weight: 900;
                    font-size: 14px;
                    margin-bottom: 2px;
                }
                
                .stat-label {
                    font-size: 9px;
                    color: #555;
                }
                
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 15px;
                    font-weight: 700;
                    font-size: 10px;
                }
                
                th, td {
                    padding: 6px 2px;
                    text-align: center;
                    border-bottom: 1px dashed #ddd;
                }
                
                th {
                    background: #f1f8ff;
                    font-weight: 900;
                }
                
                .summary {
                    margin-top: 15px;
                    padding-top: 10px;
                    border-top: 2px dashed #000;
                }
                
                .summary-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 4px 0;
                }
                
                .summary-total {
                    font-weight: 900;
                    border-top: 1px solid #000;
                    padding-top: 8px;
                    margin-top: 8px;
                }
                
                .footer {
                    text-align: center;
                    margin-top: 15px;
                    padding-top: 10px;
                    border-top: 1px dashed #000;
                    font-weight: 700;
                    font-size: 9px;
                    color: #555;
                }
                
                @media print {
                    body {
                        padding: 0;
                        margin: 0;
                    }
                    
                    .report {
                        border: none;
                        width: 100%;
                        max-width: 280px;
                    }
                }
            </style>
        </head>
        <body>
            <div class="report">
                <div class="header">
                    <div class="report-title">تقرير فواتير الشغلانة</div>
                    <div style="font-size: 10px;">تاريخ التقرير: ${today}</div>
                </div>
                
                <div class="work-order-info">
                    <div class="work-order-detail"><strong>اسم الشغلانة:</strong> ${workOrder.name}</div>
                    <div class="work-order-detail"><strong>الوصف:</strong> ${workOrder.description}</div>
                    <div class="work-order-detail"><strong>تاريخ البدء:</strong> ${workOrder.startDate}</div>
                    <div class="work-order-detail"><strong>عدد الفواتير:</strong> ${invoices.length}</div>
                </div>
                
               
                
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>رقم الفاتورة</th>
                            <th>التاريخ</th>
                            <th>الإجمالي</th>
                            <th>المتبقي</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${invoicesHTML}
                    </tbody>
                </table>
                
                <div class="summary">
                    <div class="summary-row">
                        <span>إجمالي قيمة الفواتير:</span>
                        <span>${totalInvoices.toFixed(2)} ج.م</span>
                    </div>
                    <div class="summary-row">
                        <span>إجمالي المدفوع:</span>
                        <span>${totalPaid.toFixed(2)} ج.م</span>
                    </div>
                    <div class="summary-row summary-total">
                        <span>إجمالي المتبقي:</span>
                        <span>${totalRemaining.toFixed(2)} ج.م</span>
                    </div>
                </div>
                
                <div class="footer">
                    <div>تم الطباعة من نظام إدارة العملاء والمخزون</div>
                    <div>التاريخ: ${new Date().toLocaleDateString('ar-EG')}</div>
                    <div>الوقت: ${new Date().toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' })}</div>
                </div>
            </div>
            
            <script>
                window.onload = function() {
                    setTimeout(() => {
                        window.print();
                    }, 300);
                };
            </script>
        </body>
        </html>
    `;
    },




//


هل عند اي اسئله اسالني