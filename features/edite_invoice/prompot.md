حسنا الان دعنا نعمل علي الاتي 
1- عند الضغط علي زر خصم اضافي يذهب لصفحه تانيه 
فيها
** اجمالي الفاتوره في الاعلي ولما يزود الخصم الاجكالي يظهر جميبه الرقم بعد الخصم يتحدث ثلقائي
** جدول يظهر فيه اسم المنتج كميه المطلوبه بعد المرتجع avilable_to_return 
الخصم السابق سعر الواحد بعد الخصم حقل خصم اضافي  اجمالي بعد الخصم الاضافي ده 
يبقي شبيه 
import React, { useState } from 'react';
import { Calculator, AlertCircle, CheckCircle, DollarSign } from 'lucide-react';

export default function InvoiceAdjustment() {
  const [invoice] = useState({
    id: 1234,
    customer: 'أحمد محمد',
    total: 1500,
    paid: 900,
    remaining: 600,
    items: [
      { id: 1, product: 'منتج A', qty: 10, price: 100, discount_type: null, discount_value: 0, total: 1000 },
      { id: 2, product: 'منتج B', qty: 5, price: 100, discount_type: null, discount_value: 0, total: 500 }
    ]
  });

  const [adjustedItems, setAdjustedItems] = useState(invoice.items);
  const [reason, setReason] = useState('');
  const [refundMethod, setRefundMethod] = useState('balance_reduction');

  // حساب الإجماليات
  const calculateTotals = () => {
    let newTotal = 0;
    adjustedItems.forEach(item => {
      let itemTotal = item.qty * item.price;
      let discount = 0;
      
      if (item.discount_type === 'percent') {
        discount = itemTotal * (item.discount_value / 100);
      } else if (item.discount_type === 'amount') {
        discount = item.discount_value;
      }
      
      newTotal += (itemTotal - discount);
    });

    const difference = newTotal - invoice.total;
    const isPaid = invoice.paid > 0;
    const refundFromPaid = isPaid ? Math.min(Math.abs(difference), invoice.paid) : 0;

    return {
      oldTotal: invoice.total,
      newTotal,
      difference,
      isPaid,
      refundFromPaid,
      refundFromBalance: Math.abs(difference) - refundFromPaid
    };
  };

  const totals = calculateTotals();

  // تحديث بند
  const updateItem = (itemId, field, value) => {
    setAdjustedItems(prev => prev.map(item => 
      item.id === itemId ? { ...item, [field]: parseFloat(value) || 0 } : item
    ));
  };

  // حفظ التعديل
  const handleSave = () => {
    if (!reason.trim()) {
      alert('يرجى كتابة سبب التعديل');
      return;
    }

    const adjustmentData = {
      invoice_id: invoice.id,
      items: adjustedItems,
      reason,
      refund_method: refundMethod
    };

    console.log('إرسال التعديل:', adjustmentData);
    alert('تم حفظ التعديل بنجاح');
  };

  return (
    <div className="min-h-screen bg-gray-50 p-6" dir="rtl">
      <div className="max-w-6xl mx-auto">
        {/* Header */}
        <div className="bg-white rounded-lg shadow-sm p-6 mb-6">
          <div className="flex items-center gap-3 mb-4">
            <Calculator className="text-blue-600" size={28} />
            <h1 className="text-2xl font-bold text-gray-800">تعديل الفاتورة #{invoice.id}</h1>
          </div>
          <div className="grid grid-cols-2 gap-4 text-sm">
            <div><span className="text-gray-600">العميل:</span> <span className="font-semibold">{invoice.customer}</span></div>
            <div><span className="text-gray-600">الإجمالي الأصلي:</span> <span className="font-semibold">{invoice.total} ج.م</span></div>
            <div><span className="text-gray-600">المدفوع:</span> <span className="font-semibold text-green-600">{invoice.paid} ج.م</span></div>
            <div><span className="text-gray-600">المتبقي:</span> <span className="font-semibold text-orange-600">{invoice.remaining} ج.م</span></div>
          </div>
        </div>

        {/* جدول البنود */}
        <div className="bg-white rounded-lg shadow-sm overflow-hidden mb-6">
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-100 border-b">
                <tr>
                  <th className="px-4 py-3 text-right">المنتج</th>
                  <th className="px-4 py-3 text-center">الكمية</th>
                  <th className="px-4 py-3 text-center">السعر</th>
                  <th className="px-4 py-3 text-center">نوع الخصم</th>
                  <th className="px-4 py-3 text-center">قيمة الخصم</th>
                  <th className="px-4 py-3 text-center">الإجمالي</th>
                </tr>
              </thead>
              <tbody>
                {adjustedItems.map((item, idx) => {
                  const subtotal = item.qty * item.price;
                  let discount = 0;
                  if (item.discount_type === 'percent') {
                    discount = subtotal * (item.discount_value / 100);
                  } else if (item.discount_type === 'amount') {
                    discount = item.discount_value;
                  }
                  const total = subtotal - discount;
                  const originalItem = invoice.items[idx];
                  const changed = total !== originalItem.total;

                  return (
                    <tr key={item.id} className={`border-b ${changed ? 'bg-yellow-50' : ''}`}>
                      <td className="px-4 py-3 font-medium">{item.product}</td>
                      <td className="px-4 py-3 text-center text-gray-600">{item.qty}</td>
                      <td className="px-4 py-3">
                        <input
                          type="number"
                          value={item.price}
                          onChange={(e) => updateItem(item.id, 'price', e.target.value)}
                          className="w-24 px-2 py-1 border rounded text-center"
                          step="0.01"
                        />
                      </td>
                      <td className="px-4 py-3">
                        <select
                          value={item.discount_type || ''}
                          onChange={(e) => updateItem(item.id, 'discount_type', e.target.value)}
                          className="w-28 px-2 py-1 border rounded text-sm"
                        >
                          <option value="">لا يوجد</option>
                          <option value="percent">نسبة %</option>
                          <option value="amount">مبلغ</option>
                        </select>
                      </td>
                      <td className="px-4 py-3">
                        <input
                          type="number"
                          value={item.discount_value}
                          onChange={(e) => updateItem(item.id, 'discount_value', e.target.value)}
                          className="w-24 px-2 py-1 border rounded text-center"
                          step="0.01"
                          disabled={!item.discount_type}
                        />
                      </td>
                      <td className="px-4 py-3 text-center">
                        <span className={`font-semibold ${changed ? 'text-blue-600' : ''}`}>
                          {total.toFixed(2)} ج.م
                        </span>
                        {changed && (
                          <div className="text-xs text-gray-500 mt-1">
                            (كان: {originalItem.total} ج.م)
                          </div>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>

        {/* ملخص التعديل */}
        <div className="bg-white rounded-lg shadow-sm p-6 mb-6">
          <h2 className="text-lg font-bold mb-4 flex items-center gap-2">
            <DollarSign className="text-green-600" size={20} />
            ملخص التعديل
          </h2>

          <div className="space-y-3">
            <div className="flex justify-between items-center pb-3 border-b">
              <span className="text-gray-600">الإجمالي القديم</span>
              <span className="font-semibold">{totals.oldTotal.toFixed(2)} ج.م</span>
            </div>
            <div className="flex justify-between items-center pb-3 border-b">
              <span className="text-gray-600">الإجمالي الجديد</span>
              <span className="font-semibold text-blue-600">{totals.newTotal.toFixed(2)} ج.م</span>
            </div>
            <div className="flex justify-between items-center pb-3 border-b">
              <span className="font-bold text-lg">الفرق</span>
              <span className={`font-bold text-xl ${totals.difference < 0 ? 'text-green-600' : totals.difference > 0 ? 'text-red-600' : ''}`}>
                {totals.difference > 0 ? '+' : ''}{totals.difference.toFixed(2)} ج.م
              </span>
            </div>
          </div>

          {/* معالجة الفرق */}
          {totals.difference !== 0 && (
            <div className="mt-6 p-4 bg-blue-50 rounded-lg">
              <h3 className="font-semibold mb-3 text-blue-900">معالجة الفرق المالي</h3>
              
              {totals.difference < 0 ? (
                // تخفيض - نرد للعميل
                <div className="space-y-3">
                  <div className="text-sm text-blue-800">
                    يجب رد <span className="font-bold">{Math.abs(totals.difference).toFixed(2)} ج.م</span> للعميل
                  </div>
                  
                  {totals.isPaid && totals.refundFromPaid > 0 && (
                    <div className="space-y-2">
                      <label className="block text-sm font-medium text-gray-700">
                        طريقة رد المبلغ ({totals.refundFromPaid.toFixed(2)} ج.م من المدفوع):
                      </label>
                      <div className="space-y-2">
                        <label className="flex items-center gap-2 cursor-pointer">
                          <input
                            type="radio"
                            value="cash"
                            checked={refundMethod === 'cash'}
                            onChange={(e) => setRefundMethod(e.target.value)}
                            className="w-4 h-4"
                          />
                          <span>رد نقدي</span>
                        </label>
                        <label className="flex items-center gap-2 cursor-pointer">
                          <input
                            type="radio"
                            value="wallet"
                            checked={refundMethod === 'wallet'}
                            onChange={(e) => setRefundMethod(e.target.value)}
                            className="w-4 h-4"
                          />
                          <span>إضافة للمحفظة</span>
                        </label>
                        <label className="flex items-center gap-2 cursor-pointer">
                          <input
                            type="radio"
                            value="balance_reduction"
                            checked={refundMethod === 'balance_reduction'}
                            onChange={(e) => setRefundMethod(e.target.value)}
                            className="w-4 h-4"
                          />
                          <span>تخفيض من الرصيد المتبقي</span>
                        </label>
                      </div>
                    </div>
                  )}
                  
                  {!totals.isPaid && (
                    <div className="text-sm text-gray-600 bg-white p-3 rounded">
                      سيتم تخفيض الرصيد المتبقي على العميل
                    </div>
                  )}
                </div>
              ) : (
                // زيادة - العميل يدفع
                <div className="text-sm text-blue-800">
                  سيتم إضافة <span className="font-bold">{totals.difference.toFixed(2)} ج.م</span> للرصيد المتبقي على العميل
                </div>
              )}
            </div>
          )}
        </div>

        {/* سبب التعديل */}
        <div className="bg-white rounded-lg shadow-sm p-6 mb-6">
          <label className="block text-sm font-medium text-gray-700 mb-2">
            <AlertCircle className="inline ml-1" size={16} />
            سبب التعديل <span className="text-red-500">*</span>
          </label>
          <textarea
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            className="w-full px-3 py-2 border rounded-lg resize-none"
            rows="3"
            placeholder="اكتب سبب التعديل (مثال: خصم إضافي للعميل لشراء كمية كبيرة)"
          />
        </div>

        {/* أزرار الحفظ */}
        <div className="flex gap-3">
          <button
            onClick={handleSave}
            disabled={totals.difference === 0 || !reason.trim()}
            className="flex-1 bg-blue-600 text-white py-3 rounded-lg font-semibold hover:bg-blue-700 disabled:bg-gray-300 disabled:cursor-not-allowed transition flex items-center justify-center gap-2"
          >
            <CheckCircle size={20} />
            حفظ التعديل
          </button>
          <button
            className="px-6 bg-gray-200 text-gray-700 py-3 rounded-lg font-semibold hover:bg-gray-300 transition"
          >
            إلغاء
          </button>
        </div>
      </div>
    </div>
  );
}


** لمعرفه حاله الفاتوره استخدم هذه الهورازميه
@get_customer_invoices.php (51-56) @get_customer_invoices.php (74) 

اذا كانت مؤجله اعد حاسب الاتي
الفاتوره --> @store_v2_db (1).sql (1174-1177)  `profit_amount` decimal(12,2) DEFAULT 0.00 COMMENT 'اجمالي الربح = total_before_discount - total_cost',@store_v2_db (1).sql (1181-1182) 
لو مربوطه بشغلانه 
@store_v2_db (1).sql (3516-3518) 

البند حدث @store_v2_db (1).sql (1679-1683) 

رصيد الغميل بيحسب بناء علي remaining in invoices

لو مدفوعه طلع ميزه رد نقدي او محفظه 
جزئيه بتخصم من المتبقي لو زاد عن املتبقي يرد للعميل 

كل حركه تسجل 


اعملي بقي زر في الفاتوره وصفحه التعديل وapi لمعالجه السابق وتاكد ان يكون transaction شىي يعني يكله ي لا 
ويبقي  في فالديشن ولايسمح لا حد غير ال admin تاكد من صفحه المعلومات يبقي في loader  لطيف وبعديم يوضح انه تم ويحدث الصفحه ويرجع للصفحه الختاصه بتفاصيل العميله بعد العمليه 