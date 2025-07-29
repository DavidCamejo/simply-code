// Dynamic pricing for PMPro JS
jQuery(document).ready(function($) {
    // Verificar que las variables de PHP estén disponibles
    if (typeof nextcloud_pricing === 'undefined') {
        console.log('nextcloud_pricing no está definido');
        return;
    }
    
    // Configuración desde PHP
    var levelId = nextcloud_pricing.level_id || 1;
    var basePrice = parseInt(nextcloud_pricing.base_price) || 0;
    var currencySymbol = nextcloud_pricing.currency_symbol || 'R$';
    var addTb = 120; // 1 TB adicional (fijo)
    
    // Precio base x cantidad de TB
    var storagePrices = {
        '1tb': basePrice,
        '2tb': basePrice + addTb,
        '3tb': basePrice + (addTb * 2),
        '4tb': basePrice + (addTb * 3),
        '5tb': basePrice + (addTb * 4),
        '6tb': basePrice + (addTb * 5),
        '7tb': basePrice + (addTb * 6),
        '8tb': basePrice + (addTb * 7),
        '9tb': basePrice + (addTb * 8),
        '10tb': basePrice + (addTb * 9),
        '15tb': basePrice + (addTb * 14),
        '20tb': basePrice + (addTb * 19),
        '30tb': basePrice + (addTb * 29),
        '40tb': basePrice + (addTb * 39),
        '50tb': basePrice + (addTb * 49),
        '60tb': basePrice + (addTb * 59),
        '70tb': basePrice + (addTb * 69),
        '80tb': basePrice + (addTb * 79),
        '90tb': basePrice + (addTb * 89),
        '100tb': basePrice + (addTb * 99)
    };
    
    var frequencyMonths = {
        'monthly': 1,
        'semiannual': 5.7, // (-5%)
        'annual': 10.8, // (-10%)
        'biennial': 20.4, // (-15%)
        'triennial': 28.8, // (-20%)
        'quadrennial': 36, // (-25%)
        'quinquennial': 42 // (-30%)
    };
    
    // Formatear precio
    function formatPrice(price) {
        return currencySymbol + ' ' + price.toFixed(2).replace('.', ',').replace(/(\d)(?=(\d{3})+\,)/g, '$1.');
    }
    
    // Actualizar precio total
    function updateTotalPrice() {
        var storageValue = $('#storage_space').val();
        var frequencyValue = $('#payment_frequency').val();
        
        var storagePrice = storagePrices[storageValue] || 0;
        var months = frequencyMonths[frequencyValue] || 1;
        
        var totalPrice = storagePrice * months;
        
        $('#total_price_display').val(formatPrice(totalPrice) + getPeriodText(frequencyValue));
    }
    
    // Texto del período
    function getPeriodText(frequency) {
        switch(frequency) {
            case 'monthly': return ' (por mês)';
            case 'semiannual': return ' (por 6 meses)';
            case 'annual': return ' (por ano)';
            case 'biennial': return ' (por 2 anos)';
            case 'triennial': return ' (por 3 anos)';
            case 'quadrennial': return ' (por 4 anos)';
            case 'quinquennial': return ' (por 5 anos)';
            default: return '';
        }
    }
    
    // Event listeners
    $('#storage_space, #payment_frequency').change(updateTotalPrice);
    
    // Inicialización
    $(function() {
        $('#storage_space').val('1tb');
        $('#payment_frequency').val('monthly');
        updateTotalPrice();
        $('.pmpro_checkout-field-price-display').show();
        
        // Debug (opcional)
        console.log('Precio base desde PHP:', basePrice);
        console.log('nextcloud_pricing:', nextcloud_pricing);
    });
});
