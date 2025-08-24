/**
 * PMPro Dynamic Pricing - Frontend JavaScript Optimizado v2.1
 * Estructura de snippet con optimizaciones de rendimiento
 * 
 * @version 2.1.0
 */

jQuery(document).ready(function($) {
    'use strict';
    
    // ====================================================================
    // CONFIGURACIÓN Y VARIABLES GLOBALES
    // ====================================================================
    
    var PLUGIN_VERSION = '2.1.0';
    var DEBUG_MODE = typeof console !== 'undefined' && (typeof nextcloud_pricing !== 'undefined' && nextcloud_pricing.debug);
    var CACHE_EXPIRY = 60000; // 1 minuto
    var TOOLTIP_DELAY = 300;
    
    // Cache simple para optimización
    var priceCache = {};
    var originalTextsCache = {};
    var activeTooltip = null;
    var showTooltipTimer = null;
    var hideTooltipTimer = null;
    
    // ====================================================================
    // SISTEMA DE LOGGING
    // ====================================================================
    
    function log(level, message, data) {
        if (!DEBUG_MODE) return;
        
        var prefix = '[PMPro Dynamic ' + level + ']';
        if (data && typeof data === 'object') {
            console.log(prefix, message, data);
        } else {
            console.log(prefix, message);
        }
    }
    
    function logError(message, data) { log('ERROR', message, data); }
    function logWarn(message, data) { log('WARN', message, data); }
    function logInfo(message, data) { log('INFO', message, data); }
    function logDebug(message, data) { log('DEBUG', message, data); }
    
    // ====================================================================
    // VALIDACIÓN DE DEPENDENCIAS
    // ====================================================================
    
    function validateDependencies() {
        var checks = {
            nextcloud_pricing: typeof nextcloud_pricing !== 'undefined',
            jquery: typeof $ !== 'undefined',
            required_elements: $('#storage_space, #office_suite, #payment_frequency').length >= 3
        };
        
        var missing = [];
        for (var check in checks) {
            if (!checks[check]) {
                missing.push(check);
            }
        }
        
        if (missing.length > 0) {
            logError('Missing dependencies', { missing: missing });
            return false;
        }
        
        logInfo('Dependencies validated successfully');
        return true;
    }
    
    // ====================================================================
    // CONFIGURACIÓN DINÁMICA
    // ====================================================================
    
    function getConfig() {
        if (typeof nextcloud_pricing === 'undefined') {
            logError('nextcloud_pricing configuration not available');
            return null;
        }
        
        return {
            levelId: nextcloud_pricing.level_id || 1,
            basePrice: parseInt(nextcloud_pricing.base_price) || 0,
            currencySymbol: nextcloud_pricing.currency_symbol || 'R$',
            currentStorage: nextcloud_pricing.current_storage || '1tb',
            currentSuite: nextcloud_pricing.current_suite || '20users',
            usedSpaceTb: parseFloat(nextcloud_pricing.used_space_tb) || 0,
            pricePerTb: 120,
            officeUserPrice: 25,
            frequencyMultipliers: {
                'monthly': 1.0,
                'semiannual': 5.7,
                'annual': 10.8,
                'biennial': 20.4,
                'triennial': 28.8,
                'quadrennial': 36.0,
                'quinquennial': 42.0
            }
        };
    }
    
    // ====================================================================
    // SISTEMA DE CACHÉ
    // ====================================================================
    
    function getCachedPrice(key) {
        var cached = priceCache[key];
        if (cached && (Date.now() - cached.timestamp) < CACHE_EXPIRY) {
            logDebug('Cache hit for price', { key: key });
            return cached.value;
        }
        return null;
    }
    
    function setCachedPrice(key, value) {
        priceCache[key] = {
            value: value,
            timestamp: Date.now()
        };
        logDebug('Price cached', { key: key });
    }
    
    function clearPriceCache() {
        priceCache = {};
        logDebug('Price cache cleared');
    }
    
    // ====================================================================
    // SISTEMA DE TOOLTIPS OPTIMIZADO
    // ====================================================================
    
    var tooltipContent = {
        'office-suite-tooltip': '<p>Integração do Collabora Online com o Nextcloud.</p>' +
            '<p><strong>Collabora Online Development Edition (CODE)</strong> suporta uma média de 20 usuários simultaneos.</p>' +
            '<p>Para um número maior de usuários, e para evitar instabilidade ou perda de documentos, você deve selecionar uma licença:</p>' +
            '<p><strong>• Collabora Online for Business</strong> (até 99 usuários)<br>' +
            '<strong>• Collabora Online for Enterprise</strong> (≥ 100 usuários)</p>' +
            '<p><strong>ATENÇÃO:</strong> O número de usuários suportados pelo Collabora Online não limita o número de usuários suportados pelo Nextcloud.</p>'
    };
    
    function createTooltip() {
        if (activeTooltip && activeTooltip.length) {
            activeTooltip.remove();
        }
        
        activeTooltip = $('<div class="pmpro-tooltip" role="tooltip"></div>').css({
            position: 'absolute',
            background: '#333',
            color: 'white',
            padding: '16px 20px',
            borderRadius: '8px',
            fontSize: '14px',
            lineHeight: '1.6',
            width: '420px',
            maxWidth: '95vw',
            zIndex: 10001,
            opacity: 0,
            visibility: 'hidden',
            transition: 'opacity 0.3s ease, visibility 0.3s ease',
            boxShadow: '0 8px 24px rgba(0, 0, 0, 0.2)',
            wordWrap: 'break-word',
            whiteSpace: 'normal',
            textAlign: 'left',
            boxSizing: 'border-box'
        });
        
        $('body').append(activeTooltip);
        return activeTooltip;
    }
    
    function positionTooltip(tooltip, trigger) {
        var triggerOffset = trigger.offset();
        var triggerWidth = trigger.outerWidth();
        var triggerHeight = trigger.outerHeight();
        var windowWidth = $(window).width();
        var windowHeight = $(window).height();
        var scrollTop = $(window).scrollTop();
        
        // Mostrar temporalmente para obtener dimensiones
        tooltip.css({ visibility: 'visible', opacity: 0 });
        
        var tooltipWidth = tooltip.outerWidth();
        var tooltipHeight = tooltip.outerHeight();
        
        // Calcular posición horizontal
        var left = triggerOffset.left + (triggerWidth / 2) - (tooltipWidth / 2);
        
        // Ajustar si se sale de la pantalla
        if (left + tooltipWidth > windowWidth - 20) {
            left = windowWidth - tooltipWidth - 20;
        }
        if (left < 20) {
            left = 20;
        }
        
        // Calcular posición vertical
        var top = triggerOffset.top - tooltipHeight - 12;
        
        // Si no cabe arriba, mostrarlo abajo
        if (top < scrollTop + 20) {
            top = triggerOffset.top + triggerHeight + 12;
        }
        
        // Aplicar posición
        tooltip.css({
            left: left + 'px',
            top: top + 'px',
            visibility: 'visible',
            opacity: 1
        });
        
        logDebug('Tooltip positioned', { left: left, top: top });
    }
    
    function showTooltip(trigger, contentKey) {
        clearTimeout(hideTooltipTimer);
        
        if (showTooltipTimer) {
            clearTimeout(showTooltipTimer);
        }
        
        showTooltipTimer = setTimeout(function() {
            var content = tooltipContent[contentKey];
            if (!content) {
                logWarn('No content found for tooltip', { contentKey: contentKey });
                return;
            }
            
            var tooltip = createTooltip();
            tooltip.html(content);
            positionTooltip(tooltip, trigger);
            
            logDebug('Tooltip shown', { contentKey: contentKey });
        }, TOOLTIP_DELAY);
    }
    
    function hideTooltip() {
        clearTimeout(showTooltipTimer);
        
        if (hideTooltipTimer) {
            clearTimeout(hideTooltipTimer);
        }
        
        hideTooltipTimer = setTimeout(function() {
            if (activeTooltip && activeTooltip.length) {
                activeTooltip.css({ opacity: 0, visibility: 'hidden' });
                
                setTimeout(function() {
                    if (activeTooltip && activeTooltip.length) {
                        activeTooltip.remove();
                        activeTooltip = null;
                    }
                }, 300);
            }
            
            logDebug('Tooltip hidden');
        }, 200);
    }
    
    function initTooltips() {
        logDebug('Initializing tooltip system');
        
        // Limpiar eventos previos
        $(document).off('mouseenter.tooltip mouseleave.tooltip keydown.tooltip');
        $(window).off('resize.tooltip scroll.tooltip');
        
        // Eventos de tooltip
        $(document).on('mouseenter.tooltip', '.pmpro-tooltip-trigger', function(e) {
            var $trigger = $(e.currentTarget);
            var tooltipId = $trigger.data('tooltip-id');
            
            if (tooltipId && tooltipContent[tooltipId]) {
                showTooltip($trigger, tooltipId);
            }
        });
        
        $(document).on('mouseleave.tooltip', '.pmpro-tooltip-trigger', function() {
            hideTooltip();
        });
        
        // Cerrar con Escape
        $(document).on('keydown.tooltip', function(e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                hideTooltip();
            }
        });
        
        // Reposicionar en scroll/resize
        $(window).on('resize.tooltip scroll.tooltip', function() {
            if (activeTooltip && activeTooltip.css('opacity') == '1') {
                hideTooltip();
            }
        });
        
        var triggerCount = $('.pmpro-tooltip-trigger').length;
        logInfo('Tooltip system initialized', { triggerCount: triggerCount });
    }
    
    // ====================================================================
    // GESTIÓN DE PRECIOS
    // ====================================================================
    
    function getStoragePrices(config) {
        var prices = {};
        var options = ['1tb', '2tb', '3tb', '4tb', '5tb', '6tb', '7tb', '8tb', '9tb', '10tb',
                       '15tb', '20tb', '30tb', '40tb', '50tb', '60tb', '70tb', '80tb', '90tb', '100tb',
                       '200tb', '300tb', '400tb', '500tb'];
        
        for (var i = 0; i < options.length; i++) {
            var option = options[i];
            var tb = parseInt(option.replace('tb', ''));
            prices[option] = config.basePrice + (config.pricePerTb * Math.max(0, tb - 1));
        }
        
        return prices;
    }
    
    function getOfficePrices(config) {
        return {
            '20users': 0,
            '30users': config.officeUserPrice * 30,
            '50users': config.officeUserPrice * 50,
            '80users': config.officeUserPrice * 80,
            '100users': (config.officeUserPrice - 3.75) * 100,
            '150users': (config.officeUserPrice - 3.75) * 150,
            '200users': (config.officeUserPrice - 3.75) * 200,
            '300users': (config.officeUserPrice - 3.75) * 300,
            '400users': (config.officeUserPrice - 3.75) * 400,
            '500users': (config.officeUserPrice - 3.75) * 500
        };
    }
    
    function formatPrice(price, currencySymbol) {
        var formatted = Math.ceil(price).toFixed(2)
            .replace('.', ',')
            .replace(/(\d)(?=(\d{3})+\,)/g, '$1.');
        
        return currencySymbol + ' ' + formatted;
    }
    
    function getPeriodText(frequency) {
        var periods = {
            'monthly': ' (por mês)',
            'semiannual': ' (por 6 meses)',
            'annual': ' (por ano)',
            'biennial': ' (por 2 anos)',
            'triennial': ' (por 3 anos)',
            'quadrennial': ' (por 4 anos)',
            'quinquennial': ' (por 5 anos)'
        };
        
        return periods[frequency] || '';
    }
    
    function calculateTotalPrice(config) {
        var storageValue = $('#storage_space').val() || config.currentStorage;
        var officeValue = $('#office_suite').val() || config.currentSuite;
        var frequencyValue = $('#payment_frequency').val() || 'monthly';
        
        // Verificar caché
        var cacheKey = storageValue + '_' + officeValue + '_' + frequencyValue + '_' + config.basePrice;
        var cached = getCachedPrice(cacheKey);
        if (cached !== null) {
            return cached;
        }
        
        var storagePrices = getStoragePrices(config);
        var officePrices = getOfficePrices(config);
        
        var storagePrice = storagePrices[storageValue] || config.basePrice;
        var officePrice = officePrices[officeValue] || 0;
        var multiplier = config.frequencyMultipliers[frequencyValue] || 1.0;
        
        var calculation = {
            storageValue: storageValue,
            officeValue: officeValue,
            frequencyValue: frequencyValue,
            storagePrice: storagePrice,
            officePrice: officePrice,
            multiplier: multiplier,
            totalPrice: Math.ceil((storagePrice + officePrice) * multiplier)
        };
        
        setCachedPrice(cacheKey, calculation);
        
        logDebug('Price calculated', calculation);
        return calculation;
    }
    
    function updatePriceDisplay(config) {
        try {
            var calculation = calculateTotalPrice(config);
            var formattedPrice = formatPrice(calculation.totalPrice, config.currencySymbol);
            var periodText = getPeriodText(calculation.frequencyValue);
            var displayText = formattedPrice + periodText;
            
            var $display = $('#total_price_display');
            if ($display.length) {
                $display.val(displayText);
                logDebug('Price display updated', { displayText: displayText });
            }
            
            // Trigger evento personalizado
            $(document).trigger('pmpropricing:updated', [calculation]);
            
        } catch (error) {
            logError('Error updating price display', { error: error.message });
        }
    }
    
    // ====================================================================
    // GESTIÓN DE OPCIONES
    // ====================================================================
    
    function storeOriginalTexts() {
        $('#office_suite option, #storage_space option, #payment_frequency option').each(function() {
            var $option = $(this);
            var selectId = $option.closest('select').attr('id');
            var key = selectId + '_' + $option.val();
            originalTextsCache[key] = $option.text();
        });
        
        logDebug('Original texts stored', { count: Object.keys(originalTextsCache).length });
    }
    
    function updateStorageOptions(config) {
        var currentTb = parseInt(config.currentStorage.replace('tb', '')) || 1;
        var $storageSelect = $('#storage_space');
        
        if (!$storageSelect.length) return;
        
        $storageSelect.find('option').each(function() {
            var $option = $(this);
            var optionTb = parseInt($option.val().replace('tb', ''));
            var originalKey = 'storage_space_' + $option.val();
            var originalText = originalTextsCache[originalKey] || $option.text();
            
            // Limpiar texto previo
            var cleanText = originalText.replace(/ \(.*\)$/, '');
            
            if (optionTb < currentTb && optionTb < config.usedSpaceTb) {
                $option.prop('disabled', true);
                $option.text(cleanText + ' (Espaço insuficiente)');
            } else if (optionTb < currentTb) {
                $option.prop('disabled', true);
                $option.text(cleanText + ' (Downgrade não permitido)');
            } else {
                $option.prop('disabled', false);
                $option.text(cleanText);
            }
        });
        
        // Ajustar selección si está deshabilitada
        if ($storageSelect.find('option:selected').prop('disabled')) {
            var $firstEnabled = $storageSelect.find('option:not(:disabled)').first();
            if ($firstEnabled.length) {
                $storageSelect.val($firstEnabled.val()).trigger('change');
            }
        }
        
        // Mostrar alerta si es necesario
        if (config.usedSpaceTb > currentTb) {
            showStorageAlert(config);
        }
        
        logDebug('Storage options updated', { currentTb: currentTb, usedSpaceTb: config.usedSpaceTb });
    }
    
    function updateOfficeOptions(config) {
        var currentUsers = parseInt(config.currentSuite.replace('users', '')) || 20;
        var $officeSelect = $('#office_suite');
        
        if (!$officeSelect.length) return;
        
        $officeSelect.find('option').each(function() {
            var $option = $(this);
            var optionUsers = parseInt($option.val().replace('users', ''));
            var originalKey = 'office_suite_' + $option.val();
            var originalText = originalTextsCache[originalKey] || $option.text();
            
            if (optionUsers < currentUsers && currentUsers > 20) {
                if (originalText.indexOf('(Redução de licenças)') === -1) {
                    $option.text(originalText + ' - Redução de licenças');
                }
            } else {
                $option.text(originalText);
            }
            
            $option.prop('disabled', false);
        });
        
        logDebug('Office options updated', { currentUsers: currentUsers });
    }
    
    function showStorageAlert(config) {
        var alertId = 'storage_alert';
        
        if ($('#' + alertId).length) return;
        
        var alertHtml = '<div class="pmpro_message pmpro_error" id="' + alertId + '">' +
            '<strong>Atenção:</strong> Você está usando ' + config.usedSpaceTb.toFixed(2) + ' TB de armazenamento. ' +
            'Não é possível reduzir abaixo deste limite.' +
            '</div>';
        
        $('#storage_space').before(alertHtml);
        logInfo('Storage alert shown', { usedSpace: config.usedSpaceTb });
    }
    
    // ====================================================================
    // INICIALIZACIÓN PRINCIPAL
    // ====================================================================
    
    function initializePMProDynamic() {
        logInfo('Starting PMPro Dynamic initialization', { version: PLUGIN_VERSION });
        
        // Verificar dependencias
        if (!validateDependencies()) {
            logError('Dependencies validation failed');
            return false;
        }
        
        // Obtener configuración
        var config = getConfig();
        if (!config) {
            logError('Configuration not available');
            return false;
        }
        
        try {
            // 1. Guardar textos originales
            storeOriginalTexts();
            
            // 2. Configurar valores iniciales
            var $storage = $('#storage_space');
            var $office = $('#office_suite');
            var $frequency = $('#payment_frequency');
            
            if ($storage.length && !$storage.val()) {
                $storage.val(config.currentStorage);
            }
            
            if ($office.length && !$office.val()) {
                $office.val(config.currentSuite);
            }
            
            if ($frequency.length && !$frequency.val()) {
                $frequency.val('monthly');
            }
            
            // 3. Actualizar opciones
            updateStorageOptions(config);
            updateOfficeOptions(config);
            
            // 4. Calcular precio inicial
            updatePriceDisplay(config);
            
            // 5. Inicializar tooltips
            initTooltips();
            
            // 6. Configurar event listeners
            $('#storage_space, #office_suite, #payment_frequency')
                .off('change.pmpro')
                .on('change.pmpro', function() {
                    logDebug('Field changed', { 
                        field: $(this).attr('id'), 
                        value: $(this).val() 
                    });
                    
                    // Limpiar caché
                    clearPriceCache();
                    
                    // Actualizar precio
                    updatePriceDisplay(config);
                });
            
            // 7. Mostrar sección de precio
            $('.pmpro_checkout-field-price-display').show();
            
            logInfo('PMPro Dynamic initialized successfully', {
                fieldsFound: $('#storage_space, #office_suite, #payment_frequency').length,
                tooltipsFound: $('.pmpro-tooltip-trigger').length,
                basePrice: config.basePrice
            });
            
            return true;
            
        } catch (error) {
            logError('Exception during initialization', { 
                message: error.message, 
                stack: error.stack 
            });
            return false;
        }
    }
    
    // ====================================================================
    // ESTRATEGIA DE INICIALIZACIÓN MÚLTIPLE
    // ====================================================================
    
    var initializationAttempts = 0;
    var maxAttempts = 5;
    var initializationDelays = [100, 250, 500, 1000, 2000];
    
    function attemptInitialization() {
        if (initializationAttempts >= maxAttempts) {
            logError('Maximum initialization attempts reached');
            return;
        }
        
        var delay = initializationDelays[initializationAttempts] || 2000;
        initializationAttempts++;
        
        setTimeout(function() {
            logDebug('Initialization attempt ' + initializationAttempts + '/' + maxAttempts);
            
            if (initializePMProDynamic()) {
                logInfo('Initialization successful');
                return;
            }
            
            // Si falló, intentar de nuevo
            if (initializationAttempts < maxAttempts) {
                logWarn('Initialization failed, retrying in ' + initializationDelays[initializationAttempts] + 'ms');
                attemptInitialization();
            }
        }, delay);
    }
    
    // Iniciar proceso
    attemptInitialization();
    
    // Backup: inicializar cuando la página esté cargada
    $(window).on('load', function() {
        if ($('.pmpro-tooltip-trigger').length === 0 || $('#total_price_display').val() === '') {
            logInfo('Window load backup initialization');
            setTimeout(initializePMProDynamic, 500);
        }
    });
    
    // API para debugging (solo en modo debug)
    if (DEBUG_MODE) {
        window.PMProDynamic = {
            version: PLUGIN_VERSION,
            reinitialize: initializePMProDynamic,
            clearCache: clearPriceCache,
            getConfig: getConfig,
            log: {
                error: logError,
                warn: logWarn,
                info: logInfo,
                debug: logDebug
            }
        };
        
        logInfo('Debug mode enabled - API available in window.PMProDynamic');
    }
});
