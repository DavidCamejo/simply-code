// Dynamic pricing for PMPro JS con bloqueo de downgrades
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
    var pricePerTb = 120; // 1 TB adicional (fijo)
    
    // Obtener configuración actual del usuario (desde el DOM)
    var currentStorage = $('#current_storage').val() || '1tb';
    var usedSpaceTb = parseFloat($('#used_space_tb').val()) || 0;
    
    // Precio base x cantidad de TB
    var storagePrices = {
        '1tb': basePrice,
        '2tb': basePrice + pricePerTb,
        '3tb': basePrice + (pricePerTb * 2),
        '4tb': basePrice + (pricePerTb * 3),
        '5tb': basePrice + (pricePerTb * 4),
        '6tb': basePrice + (pricePerTb * 5),
        '7tb': basePrice + (pricePerTb * 6),
        '8tb': basePrice + (pricePerTb * 7),
        '9tb': basePrice + (pricePerTb * 8),
        '10tb': basePrice + (pricePerTb * 9),
        '15tb': basePrice + (pricePerTb * 14),
        '20tb': basePrice + (pricePerTb * 19),
        '30tb': basePrice + (pricePerTb * 29),
        '40tb': basePrice + (pricePerTb * 39),
        '50tb': basePrice + (pricePerTb * 49),
        '60tb': basePrice + (pricePerTb * 59),
        '70tb': basePrice + (pricePerTb * 69),
        '80tb': basePrice + (pricePerTb * 79),
        '90tb': basePrice + (pricePerTb * 89),
        '100tb': basePrice + (pricePerTb * 99),
        '200tb': basePrice + (pricePerTb * 199),
        '300tb': basePrice + (pricePerTb * 299),
        '400tb': basePrice + (pricePerTb * 399),
        '500tb': basePrice + (pricePerTb * 499)
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
    
    // Bloquear opciones de downgrade no permitidas
    function updateStorageOptions() {
        var currentTb = parseInt(currentStorage.replace('tb', '')) || 1;
        var $storageSelect = $('#storage_space');
        
        $storageSelect.find('option').each(function() {
            var optionTb = parseInt($(this).val().replace('tb', ''));
            var optionText = $(this).text();
            
            // Habilitar/deshabilitar según el espacio usado
            if (optionTb < currentTb && optionTb < usedSpaceTb) {
                $(this).prop('disabled', true);
                $(this).text(optionText + ' (Espaço insuficiente)');
            } else if (optionTb < currentTb) {
                $(this).prop('disabled', true);
                $(this).text(optionText + ' (Downgrade não permitido)');
            } else {
                $(this).prop('disabled', false);
                // Restaurar texto original si fue modificado
                var originalText = optionText.replace(/ \(.*\)$/, '');
                $(this).text(originalText);
            }
        });
        
        // Si la opción seleccionada queda deshabilitada, seleccionar la mínima permitida
        if ($storageSelect.find('option:selected').prop('disabled')) {
            var firstEnabled = $storageSelect.find('option:not(:disabled)').first();
            if (firstEnabled.length) {
                $storageSelect.val(firstEnabled.val());
            }
        }
        
        // Mostrar alerta si es necesario
        if (usedSpaceTb > currentTb) {
            showStorageAlert();
        }
    }
    
    // Mostrar alerta de espacio insuficiente
    function showStorageAlert() {
        var alertHtml = '<div class="pmpro_message pmpro_error" id="storage_alert">' +
                        'Você está usando ' + usedSpaceTb.toFixed(2) + ' TB de armazenamento. ' +
                        'Não é possível reduzir abaixo deste limite.' +
                        '</div>';
        
        if (!$('#storage_alert').length) {
            $('#storage_space').before(alertHtml);
        }
    }
    
    // Event listeners
    $('#storage_space, #payment_frequency').change(updateTotalPrice);
    
    // Inicialización
    $(function() {
        // Agregar campos ocultos con datos del usuario (deberían ser generados por PHP)
        if (!$('#current_storage').length) {
            $('form#pmpro_form').append('<input type="hidden" id="current_storage" value="' + currentStorage + '">');
            $('form#pmpro_form').append('<input type="hidden" id="used_space_tb" value="' + usedSpaceTb + '">');
        }
        
        // Configurar valores iniciales
        $('#storage_space').val(currentStorage);
        $('#payment_frequency').val('monthly');
        
        // Actualizar opciones y precios
        updateStorageOptions();
        updateTotalPrice();
        
        // Mostrar sección de precio
        $('.pmpro_checkout-field-price-display').show();
        
        // Debug (opcional)
        console.log('Precio base desde PHP:', basePrice);
        console.log('Almacenamiento actual:', currentStorage);
        console.log('Espacio usado (TB):', usedSpaceTb);
        console.log('nextcloud_pricing:', nextcloud_pricing);
    });
});
