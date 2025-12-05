
function setupNumberInputPrevention() {
    // اختيار جميع حقول الإدخال العددية
 const numberInputs = document.querySelectorAll('input[type="number"]');
   
    
    numberInputs.forEach(input => {
        // منع تغيير القيمة بواسطة عجلة التمرير (scroll)
        input.addEventListener('wheel', function(e) {
            e.preventDefault();
            
        }, { passive: false });
        
        // منع تغيير القيمة بواسطة السهمين لأعلى ولأسفل
        input.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                e.preventDefault();
               
            }
         
        });
        
         
    });
}


    export  {setupNumberInputPrevention};