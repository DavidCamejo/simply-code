/**
 * PMPro Banda Dynamic Pricing - JavaScript CON PRORRATEO VISUAL v2.7.7
 * 
 * RESPONSABILIDAD: Cálculos dinámicos de precio, prorrateo y actualización de UI
 * CORREGIDO: Sistema completo de prorrateo para upgrades
 * MEJORADO: Sanitización defensiva, control de doble init, bloqueo de downgrades
 * 
 * @version 2.7.7
 */

/* global jQuery, window, console */

(function($) {
    'use strict';

    // ====
    // CONFIGURACIÓN Y CONSTANTES
    // ====

    const BANDA_CONFIG = {
        version: '2.7.7',
        debug: false,
        selectors: {
            storageField: '#storage_space',
            usersField: '#num_users',
            frequencyField: '#payment_frequency',
            priceDisplay: '#total_price_display',
            priceLabel: '.pmpro_checkout-field-price-display label',
            submitButtons: 'input[name="submit"], button[type="submit"], #pmpro_btn_submit'
        },
        classes: {
            proratedPrice: 'prorated-price',
            proratedLabel: 'prorated-label',
            proratedNotice: 'pmpro-prorated-notice',
            downgradeWarning: 'pmpro-downgrade-warning'
        },
        debounceDelay: 100,
        animationDuration: 50,
        initTimeout: 1000
    };

    // Variables globales
    let pricingData = null;
    let currentProrationData = null;
    let debounceTimer = null;
    let isCalculating = false;
    let isInitialized = false;
    let originalTextsCache = {};
    let initialUserValues = {};

    // ====
    // SISTEMA DE LOGGING
    // ====

    function log(level, message, data = null) {
        if (!BANDA_CONFIG.debug && level === 'debug') return;
        
        const timestamp = new Date().toISOString();
        const logMessage = `[PMPro Banda ${BANDA_CONFIG.version}] ${message}`;
        
        if (level === 'error') {
            console.error(logMessage, data || '');
        } else if (level === 'warn') {
            console.warn(logMessage, data || '');
        } else if (level === 'info') {
            console.info(logMessage, data || '');
        } else {
            console.log(logMessage, data || '');
        }
    }

    // ====
    // INICIALIZACIÓN Y VALIDACIÓN
    // ====

    function waitForPricingData(callback, timeoutMs = BANDA_CONFIG.initTimeout) {
        if (typeof window.nextcloud_banda_pricing !== 'undefined') {
            return callback(window.nextcloud_banda_pricing);
        }
        
        let waited = 0;
        const interval = setInterval(function() {
            waited += 100;
            if (typeof window.nextcloud_banda_pricing !== 'undefined') {
                clearInterval(interval);
                callback(window.nextcloud_banda_pricing);
            } else if (waited >= timeoutMs) {
                clearInterval(interval);
                callback(null);
            }
        }, 100);
    }

    function initializePricingSystem() {
        if (isInitialized) {
            log('debug', 'System already initialized, skipping');
            return;
        }

        log('info', 'Initializing pricing system...');

        // Verificar disponibilidad de datos
        if (typeof window.nextcloud_banda_pricing === 'undefined') {
            log('error', 'Pricing data not available in window.nextcloud_banda_pricing');
            return false;
        }

        pricingData = window.nextcloud_banda_pricing;
        BANDA_CONFIG.debug = pricingData.debug || false;

        log('debug', 'Pricing data loaded', {
            level_id: pricingData.level_id,
            base_price: pricingData.base_price,
            has_active_membership: pricingData.hasActiveMembership,
            current_subscription_data: pricingData.current_subscription_data
        });

        // Verificar que estamos en el nivel correcto
        if (!pricingData.level_id || pricingData.level_id !== 2) {
            log('debug', 'Not on Banda level, skipping initialization', { level_id: pricingData.level_id });
            return false;
        }

        // Verificar elementos DOM con timeout
        waitForRequiredElements(function(elementsFound) {
            if (!elementsFound) {
                log('warn', 'Required elements not found after timeout');
                return;
            }

            // Inicializar valores y eventos
            initializeFieldValues();
            storeInitialUserValues();
            bindEvents();
            setInitialValues();
            
            // Marcar como inicializado
            isInitialized = true;
            
            log('info', 'PMPro Banda Dynamic Pricing initialized successfully', {
                version: BANDA_CONFIG.version,
                debug: BANDA_CONFIG.debug,
                hasActiveMembership: pricingData.hasActiveMembership,
                currentSubscriptionData: !!pricingData.current_subscription_data
            });
        });

        return true;
    }

    function waitForRequiredElements(callback, timeoutMs = 3000) {
        const requiredElements = [
            BANDA_CONFIG.selectors.storageField,
            BANDA_CONFIG.selectors.usersField,
            BANDA_CONFIG.selectors.frequencyField,
            BANDA_CONFIG.selectors.priceDisplay
        ];

        let waited = 0;
        const interval = setInterval(function() {
            const foundElements = requiredElements.filter(selector => $(selector).length > 0);
            
            if (foundElements.length >= 3) { // Al menos 3 de 4 elementos
                clearInterval(interval);
                callback(true);
            } else {
                waited += 100;
                if (waited >= timeoutMs) {
                    clearInterval(interval);
                    log('warn', 'Timeout waiting for elements', { 
                        found: foundElements.length, 
                        required: requiredElements.length 
                    });
                    callback(false);
                }
            }
        }, 100);
    }

    // ====
    // INICIALIZACIÓN DE CAMPOS
    // ====

    function initializeFieldValues() {
        let defaultStorage = '1tb';
        let defaultUsers = 2;
        let defaultFrequency = 'monthly';

        // Verificar si hay membresía activa Y configuración previa
        if (pricingData.hasActiveMembership && pricingData.has_previous_config && 
            pricingData.current_subscription_data) {
            defaultStorage = pricingData.current_subscription_data.storage_space || '1tb';
            defaultUsers = pricingData.current_subscription_data.num_users || 2;
            defaultFrequency = pricingData.current_subscription_data.payment_frequency || 'monthly';
            
            log('debug', 'Using previous config values for active membership', { 
                storage: defaultStorage, 
                users: defaultUsers, 
                frequency: defaultFrequency 
            });
        } else {
            log('debug', 'Using default values (no active membership or no previous config)', { 
                storage: defaultStorage, 
                users: defaultUsers, 
                frequency: defaultFrequency,
                hasActiveMembership: pricingData.hasActiveMembership,
                hasPreviousConfig: pricingData.has_previous_config
            });
        }

        const $storageField = $(BANDA_CONFIG.selectors.storageField);
        const $usersField = $(BANDA_CONFIG.selectors.usersField);
        const $frequencyField = $(BANDA_CONFIG.selectors.frequencyField);

        if ($storageField.length && (!$storageField.val() || $storageField.val() === '')) {
            $storageField.val(defaultStorage);
        }
        if ($usersField.length && (!$usersField.val() || $usersField.val() === '')) {
            $usersField.val(defaultUsers);
        }
        if ($frequencyField.length && (!$frequencyField.val() || $frequencyField.val() === '')) {
            $frequencyField.val(defaultFrequency);
        }

        log('debug', 'Field values initialized', {
            storage: $storageField.val(),
            users: $usersField.val(),
            frequency: $frequencyField.val()
        });
    }

    function storeInitialUserValues() {
        initialUserValues = {
            storage: $(BANDA_CONFIG.selectors.storageField).val() || '1tb',
            users: parseInt($(BANDA_CONFIG.selectors.usersField).val(), 10) || 2,
            frequency: $(BANDA_CONFIG.selectors.frequencyField).val() || 'monthly',
            hasPreviousConfig: !!(pricingData && pricingData.has_previous_config && pricingData.hasActiveMembership),
            hasActiveMembership: !!(pricingData && pricingData.hasActiveMembership),
            subscriptionData: pricingData.current_subscription_data || null
        };
        
        log('debug', 'Initial user values stored', initialUserValues);
    }

    // ====
    // CÁLCULOS DE PRECIO
    // ====

    function calculatePrice(storageSpace, numUsers, paymentFrequency) {
        if (!pricingData) {
            log('error', 'Pricing data not available for calculation');
            return pricingData?.base_price || 70.00;
        }

        try {
            // Sanitización defensiva
            const sanitizedStorage = String(storageSpace || '1tb').toLowerCase();
            const sanitizedUsers = parseInt(numUsers, 10) || 2;
            const sanitizedFrequency = String(paymentFrequency || 'monthly').toLowerCase();

            // Validar parámetros
            if (!sanitizedStorage || !sanitizedUsers || !sanitizedFrequency) {
                log('warn', 'Invalid parameters for price calculation', {
                    storageSpace: sanitizedStorage, 
                    numUsers: sanitizedUsers, 
                    paymentFrequency: sanitizedFrequency
                });
                return pricingData.base_price;
            }

            // Extraer TB del storage con sanitización
            const storageTb = parseInt(sanitizedStorage.replace('tb', '').replace('gb', '')) || 1;
            const users = sanitizedUsers;

            // Calcular precio base con almacenamiento adicional
            const additionalTb = Math.max(0, storageTb - pricingData.base_storage_included);
            const storagePrice = pricingData.base_price + (pricingData.price_per_tb * additionalTb);

            // Calcular precio por usuarios adicionales
            const additionalUsers = Math.max(0, users - pricingData.base_users_included);
            const userPrice = pricingData.price_per_user * additionalUsers;

            // Precio combinado
            const combinedPrice = storagePrice + userPrice;

            // Aplicar multiplicador de frecuencia con fallback
            const frequencyMultiplier = pricingData.frequency_multipliers[sanitizedFrequency];
            if (typeof frequencyMultiplier === 'undefined') {
                log('warn', 'Unknown frequency, using 1.0 multiplier', { frequency: sanitizedFrequency });
            }
            const multiplier = frequencyMultiplier || 1.0;
            const totalPrice = Math.ceil(combinedPrice * multiplier);

            log('debug', 'Price calculated', {
                storageSpace: sanitizedStorage,
                storageTb,
                additionalTb,
                numUsers: users,
                additionalUsers,
                paymentFrequency: sanitizedFrequency,
                storagePrice,
                userPrice,
                combinedPrice,
                frequencyMultiplier: multiplier,
                totalPrice
            });

            return totalPrice;

        } catch (error) {
            log('error', 'Error calculating price', error);
            return pricingData.base_price;
        }
    }

    // ====
    // SISTEMA DE PRORRATEO
    // ====

    function isUpgrade(newStorage, newUsers, newFrequency) {
        if (!pricingData.current_subscription_data) {
            return false;
        }

        const current = pricingData.current_subscription_data;
        
        // Convertir storage a números con sanitización
        const currentStorageTb = parseInt(String(current.storage_space || '1tb').toLowerCase().replace('tb', ''));
        const newStorageTb = parseInt(String(newStorage).toLowerCase().replace('tb', ''));
        
        // Comparar frecuencias por orden
        const frequencyOrder = {
            'monthly': 1,
            'semiannual': 2,
            'annual': 3,
            'biennial': 4,
            'triennial': 5,
            'quadrennial': 6,
            'quinquennial': 7
        };
        
        const currentFreqOrder = frequencyOrder[current.payment_frequency] || 1;
        const newFreqOrder = frequencyOrder[newFrequency] || 1;
        
        // Es upgrade si hay más storage, más usuarios o frecuencia más larga
        const storageUpgrade = newStorageTb > currentStorageTb;
        const usersUpgrade = parseInt(newUsers) > (current.num_users || 2);
        const frequencyUpgrade = newFreqOrder > currentFreqOrder;
        
        const isUpgradeResult = storageUpgrade || usersUpgrade || frequencyUpgrade;
        
        log('debug', 'Upgrade analysis', {
            current: current,
            new: { storage: newStorage, users: newUsers, frequency: newFrequency },
            storageUpgrade,
            usersUpgrade,
            frequencyUpgrade,
            isUpgrade: isUpgradeResult
        });
        
        return isUpgradeResult;
    }

    function calculateProration(newTotalPrice, callback) {
        if (!pricingData.hasActiveMembership || !pricingData.current_subscription_data) {
            log('debug', 'No active membership for proration');
            callback(null);
            return;
        }

        const storageSpace = $(BANDA_CONFIG.selectors.storageField).val();
        const numUsers = $(BANDA_CONFIG.selectors.usersField).val();
        const paymentFrequency = $(BANDA_CONFIG.selectors.frequencyField).val();

        // Verificar si es upgrade
        if (!isUpgrade(storageSpace, numUsers, paymentFrequency)) {
            log('debug', 'Not an upgrade, no proration needed');
            callback(null);
            return;
        }

        $.ajax({
            url: pricingData.ajax_url,
            type: 'POST',
            data: {
                action: 'nextcloud_banda_calculate_proration',
                nonce: pricingData.nonce,
                storage_space: storageSpace,
                num_users: numUsers,
                payment_frequency: paymentFrequency
            },
            success: function(response) {
                try {
                    // Validar estructura básica
                    if (!response || typeof response !== 'object') {
                        log('warn', 'Invalid AJAX response structure', response);
                        callback(null);
                        return;
                    }

                    // Unificar data (algunos setups envían directamente los campos)
                    const data = response.data && typeof response.data === 'object' ? response.data : response;

                    if (!(response.success || data.success) || !(data.is_upgrade || data.isUpgrade)) {
                        log('debug', 'No upgrade detected by server', data);
                        callback(null);
                        return;
                    }

                    // Funciones de saneo
                    const safeNum = (n, min = 0) => {
                        const v = Number(n);
                        return Number.isFinite(v) ? Math.max(min, v) : Math.max(min, 0);
                    };
                    const safeInt = (n, min = 0) => {
                        const v = parseInt(n, 10);
                        return Number.isFinite(v) ? Math.max(min, v) : Math.max(min, 0);
                    };

                    // Soportar snake_case del backend con fallback camelCase
                    const sanitized = {
                        isUpgrade: true,
                        newTotalPrice: safeNum(data.new_total_price ?? data.newTotalPrice ?? newTotalPrice, 0),
                        proratedAmount: safeNum(data.prorated_amount ?? data.proratedAmount, NaN),
                        daysRemaining: safeInt(data.days_remaining ?? data.daysRemaining, 0),
                        currentAmount: safeNum(data.current_amount ?? data.currentAmount, 0),
                        savings: safeNum(data.savings ?? data.Savings, 0),
                        totalDays: Math.max(1, safeInt(data.total_days ?? data.totalDays, 0))
                    };

                    log('info', 'Proration calculated successfully (sanitized)', sanitized);
                    callback(sanitized);
                } catch (e) {
                    log('error', 'Error sanitizing proration response', { error: e.message, response });
                    callback(null);
                }
            },
            error: function(xhr, status, error) {
                log('error', 'AJAX error calculating proration', { status, error });
                callback(null);
            }
        });
    }

    // ====
    // ACTUALIZACIÓN DE UI
    // ====

    function updatePriceDisplay(price, prorationData = null) {
        const $priceField = $(BANDA_CONFIG.selectors.priceDisplay);
        const $priceLabel = $(BANDA_CONFIG.selectors.priceLabel);

        if (!$priceField.length) {
            log('warn', 'Price display field not found');
            return;
        }

        // Limpiar clases y mensajes anteriores
        $priceField.removeClass(BANDA_CONFIG.classes.proratedPrice);
        $priceLabel.removeClass(BANDA_CONFIG.classes.proratedLabel);
        $('.' + BANDA_CONFIG.classes.proratedNotice).remove();
        $('.' + BANDA_CONFIG.classes.downgradeWarning).remove();

        // Defensas de número
        const safeNum = (n, min = 0) => {
            const v = Number(n);
            return Number.isFinite(v) ? Math.max(min, v) : Math.max(min, 0);
        };

        let displayPrice = safeNum(price);
        let labelText = 'Preço total';

        if (prorationData && prorationData.isUpgrade) {
            // Usar valores saneados y respetar totalDays del servidor
            const newTotalPrice = safeNum(prorationData.newTotalPrice ?? prorationData.new_total_price, displayPrice);
            const daysRemaining = Math.max(0, parseInt(prorationData.daysRemaining ?? prorationData.days_remaining, 10) || 0);
            const currentAmount = safeNum(prorationData.currentAmount ?? prorationData.current_amount, 0);
            const totalDays = Math.max(1, parseInt(prorationData.totalDays ?? prorationData.total_days, 10) || 0);

            // Calcular crédito y prorrateo
            const currentCredit = totalDays > 0 ? (currentAmount * daysRemaining) / totalDays : 0;
            const newPlanProrated = totalDays > 0 ? (newTotalPrice * daysRemaining) / totalDays : newTotalPrice;

            // Si el backend ya envió el valor prorrateado final, respétalo; si no, usa nuestro cálculo
            const backendProrated = safeNum(prorationData.proratedAmount ?? prorationData.prorated_amount, NaN);
            if (Number.isFinite(backendProrated)) {
                displayPrice = backendProrated;
            } else {
                // Clamp a 0 para evitar negativos por redondeos extremos
                displayPrice = Math.max(0, newPlanProrated - currentCredit);
            }

            labelText = 'Valor a pagar agora';

            // Aplicar estilos de prorrateo
            $priceField.addClass(BANDA_CONFIG.classes.proratedPrice);
            $priceLabel.addClass(BANDA_CONFIG.classes.proratedLabel);

            // Mensaje informativo con desglose completo
            const noticeHtml = `
                <div class="${BANDA_CONFIG.classes.proratedNotice}" style="background: #e8f4fd; border: 1px solid #bee5eb; padding: 15px; border-radius: 8px; margin: 15px 0; font-size: 0.9em;">
                    <h4 style="margin: 0 0 12px 0; color: #0c5460;">🚀 Upgrade da configuração</h4>
                    
                    <div style="margin-bottom: 10px;">
                        <strong>Preço integral da nova configuração:</strong> R$ ${formatPrice(newTotalPrice)}
                    </div>
                    
                    <div style="margin-bottom: 10px;">
                        <strong>Período restante:</strong> ${daysRemaining} dias
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin: 10px 0; border-left: 3px solid #17a2b8;">
                        <div style="margin-bottom: 8px;">
                            <strong>Preço prorratado da nova configuração:</strong> R$ ${formatPrice(newPlanProrated)}
                        </div>
                        <div style="margin-bottom: 8px;">
                            <strong>Crédito da configuração atual:</strong> -R$ ${formatPrice(currentCredit)}
                        </div>
                        <div style="border-top: 1px solid #dee2e6; padding-top: 8px; margin-top: 8px;">
                            <strong style="color: #0c5460;">Valor final a pagar agora: R$ ${formatPrice(displayPrice)}</strong>
                        </div>
                    </div>
                    
                    <div style="font-size: 0.85em; color: #6c757d; margin-top: 10px;">
                        💡 Você paga apenas a diferença prorratada agora.<br/>➡️ O valor integral da nova configuração só será cobrado no próximo ciclo.
                    </div>
                </div>
            `;
            $priceField.closest('.pmpro_checkout-field-price-display').after(noticeHtml);

            log('debug', 'Proration data breakdown (safe)', {
                prorationData,
                newTotalPrice,
                daysRemaining,
                currentAmount,
                totalDays,
                currentCredit,
                newPlanProrated,
                displayPrice
            });
        }

        // Actualizar precio con animación
        $priceField.fadeOut(BANDA_CONFIG.animationDuration / 2, function() {
            $(this).val('R$ ' + formatPrice(displayPrice)).fadeIn(BANDA_CONFIG.animationDuration / 2);
        });

        // Actualizar label
        if ($priceLabel.length) {
            $priceLabel.text(labelText);
        }

        log('debug', 'Price display updated (SAFE VERSION)', {
            price: displayPrice,
            originalPrice: price,
            isProrated: !!prorationData,
            labelText
        });
    }

    function formatPrice(price) {
        const n = Number(price);
        const safe = Number.isFinite(n) ? n : 0;
        return safe.toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    // ====
    // BLOQUEO DE DOWNGRADES
    // ====

    function updateFieldOptions() {
        if (!initialUserValues.hasPreviousConfig) return;

        updateStorageOptions();
        updateUserOptions();
        updateFrequencyOptions();
    }

    function updateStorageOptions() {
        const $storageSelect = $(BANDA_CONFIG.selectors.storageField);
        if (!$storageSelect.length) return;

        const currentStorageValue = parseStorageValue(initialUserValues.storage);
        
        $storageSelect.find('option').each(function() {
            const $option = $(this);
            const optionValue = parseStorageValue($option.val());
            const key = 'storage_space_' + $option.val();
            
            // Guardar texto original
            if (!originalTextsCache[key]) {
                originalTextsCache[key] = $option.text();
            }
            
            if (optionValue < currentStorageValue) {
                $option.prop('disabled', true);
                $option.text(originalTextsCache[key] + ' (Não elegível)');
            } else {
                $option.prop('disabled', false);
                $option.text(originalTextsCache[key]);
            }
        });
    }

    function updateUserOptions() {
        const $userSelect = $(BANDA_CONFIG.selectors.usersField);
        if (!$userSelect.length) return;

        const currentUsers = initialUserValues.users;
        
        $userSelect.find('option').each(function() {
            const $option = $(this);
            const optionValue = parseInt($option.val(), 10);
            const key = 'num_users_' + $option.val();
            
            // Guardar texto original
            if (!originalTextsCache[key]) {
                originalTextsCache[key] = $option.text();
            }
            
            if (optionValue < currentUsers) {
                $option.prop('disabled', true);
                $option.text(originalTextsCache[key] + ' (Não elegível)');
            } else {
                $option.prop('disabled', false);
                $option.text(originalTextsCache[key]);
            }
        });
    }

        function updateFrequencyOptions() {
                const $frequencySelect = $(BANDA_CONFIG.selectors.frequencyField);
                if (!$frequencySelect.length) return;

                const frequencyOrder = {
                        'monthly': 1,
                        'semiannual': 2,
                        'annual': 3,
                        'biennial': 4,
                        'triennial': 5,
                        'quadrennial': 6,
                        'quinquennial': 7
                };

                const currentFrequency = initialUserValues.frequency || 'monthly';
                const currentFreqOrder = frequencyOrder[currentFrequency] || 1;

                $frequencySelect.find('option').each(function() {
                        const $option = $(this);
                        const optionValue = $option.val();
                        const optionOrder = frequencyOrder[optionValue] || 0;
                        const key = 'payment_frequency_' + optionValue;

                        // Guardar texto original
                        if (!originalTextsCache[key]) {
                                originalTextsCache[key] = $option.text();
                        }

                        if (optionOrder < currentFreqOrder) {
                                $option.prop('disabled', true);
                                $option.text(originalTextsCache[key] + ' (Não elegível)');
                        } else {
                                $option.prop('disabled', false);
                                $option.text(originalTextsCache[key]);
                        }
                });

                log('debug', 'Frequency options updated', {
                        currentFrequency: currentFrequency,
                        currentFreqOrder: currentFreqOrder
                });
        }

    function parseStorageValue(value) {
        if (typeof value !== 'string') return 1;
        const sanitized = String(value).toLowerCase();
        const match = sanitized.match(/^(\d+(?:\.\d+)?)\s*(tb|gb)$/i);
        if (match) {
            const num = parseFloat(match[1]);
            const unit = match[2].toLowerCase();
            return unit === 'gb' ? num / 1024 : num;
        }
        return parseFloat(value) || 1;
    }

    function showDowngradeWarning(reason) {
        $('.' + BANDA_CONFIG.classes.downgradeWarning).remove();
        
        const $warning = $(`
            <div class="${BANDA_CONFIG.classes.downgradeWarning}" style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 4px; margin: 15px 0; font-size: 0.9em; color: #721c24;">
                <strong>⚠️ Ação bloqueada:</strong> ${reason}
            </div>
        `);
        
        const $priceField = $(BANDA_CONFIG.selectors.priceDisplay).closest('.pmpro_checkout-field-price-display');
        if ($priceField.length) {
            $warning.insertBefore($priceField);
        } else {
            $('.pmpro_form').prepend($warning);
        }
        
        // Desabilitar botões de submit
        $(BANDA_CONFIG.selectors.submitButtons).prop('disabled', true).css('opacity', '0.6');
        
        log('debug', 'Downgrade warning applied', { reason });
    }

    function clearDowngradeWarning() {
        $('.' + BANDA_CONFIG.classes.downgradeWarning).remove();
        $(BANDA_CONFIG.selectors.submitButtons).prop('disabled', false).css('opacity', '1');
    }

    // ====
    // MANEJO DE EVENTOS
    // ====

    function handleFieldChange() {
        if (isCalculating) {
            log('debug', 'Calculation already in progress, skipping');
            return;
        }

        // Verificar downgrades antes de calcular
        if (initialUserValues.hasPreviousConfig && hasUserMadeChanges()) {
            if (isDowngradeAttempt()) {
                showDowngradeWarning('Downgrades não são permitidos. Entre em contato com o suporte para alterações.');
                return;
            } else {
                clearDowngradeWarning();
                updateFieldOptions();
            }
        }

        // Debounce para evitar cálculos excesivos
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function() {
            performPriceCalculation();
        }, BANDA_CONFIG.debounceDelay);
    }

    function performPriceCalculation() {
        isCalculating = true;
        
        const storageSpace = $(BANDA_CONFIG.selectors.storageField).val();
        const numUsers = $(BANDA_CONFIG.selectors.usersField).val();
        const paymentFrequency = $(BANDA_CONFIG.selectors.frequencyField).val();

        log('debug', 'Performing price calculation', {
            storageSpace, numUsers, paymentFrequency
        });

        // Calcular precio base
        const newPrice = calculatePrice(storageSpace, numUsers, paymentFrequency);

        // Calcular prorrateo si aplica
        calculateProration(newPrice, function(prorationData) {
            currentProrationData = prorationData;
            updatePriceDisplay(newPrice, prorationData);
            isCalculating = false;
        });
    }

    function hasUserMadeChanges() {
        if (!initialUserValues.hasPreviousConfig) return false;
        
        const currentStorage = $(BANDA_CONFIG.selectors.storageField).val();
        const currentUsers = $(BANDA_CONFIG.selectors.usersField).val();
        const currentFrequency = $(BANDA_CONFIG.selectors.frequencyField).val();
        
        return (
            currentStorage !== initialUserValues.storage ||
            currentUsers !== initialUserValues.users.toString() ||
            currentFrequency !== initialUserValues.frequency
        );
    }

    function isDowngradeAttempt() {
        if (!initialUserValues.hasPreviousConfig) return false;

        const currentStorage = parseStorageValue(initialUserValues.storage);
        const selectedStorage = parseStorageValue($(BANDA_CONFIG.selectors.storageField).val());
        const currentUsers = initialUserValues.users;
        const selectedUsers = parseInt($(BANDA_CONFIG.selectors.usersField).val(), 10);

        // Bloquear reducción de frecuencia (orden menor)
        const frequencyOrder = {
            'monthly': 1,
            'semiannual': 2,
            'annual': 3,
            'biennial': 4,
            'triennial': 5,
            'quadrennial': 6,
            'quinquennial': 7
        };
        const currentFreqOrder = frequencyOrder[initialUserValues.frequency] || 1;
        const selectedFreqOrder = frequencyOrder[$(BANDA_CONFIG.selectors.frequencyField).val()] || 1;

        return (
            selectedStorage < currentStorage ||
            selectedUsers < currentUsers ||
            selectedFreqOrder < currentFreqOrder // <- activa bloqueo de menor frequência
        );
    }

    // ====
    // CONFIGURACIÓN INICIAL DE CAMPOS
    // ====

    function setInitialValues() {
        if (!pricingData) return;

        log('debug', 'Setting initial values', {
            current_storage: pricingData.current_storage,
            current_users: pricingData.current_users,
            current_frequency: pricingData.current_frequency,
            has_previous_config: pricingData.has_previous_config
        });

        // Calcular precio inicial
        performPriceCalculation();
        
        // Actualizar opciones de campos si hay configuración previa
        if (initialUserValues.hasPreviousConfig) {
            updateFieldOptions();
        }
    }

    function bindEvents() {
        log('debug', 'Binding events to form fields');

        // Eventos de cambio en los campos (delegados para robustez)
        $(document)
            .off('.pmproband')
            .on('change.pmproband', BANDA_CONFIG.selectors.storageField, handleFieldChange)
            .on('change.pmproband', BANDA_CONFIG.selectors.usersField, handleFieldChange)
            .on('change.pmproband', BANDA_CONFIG.selectors.frequencyField, handleFieldChange);

        // Evento de envío del formulario para validación final
        $(document).on('submit.pmproband', 'form#pmpro_form', function(e) {
            if (currentProrationData && currentProrationData.isUpgrade) {
                log('info', 'Form submitted with proration data', currentProrationData);
            }
            
            // Limpiar warnings al enviar
            clearDowngradeWarning();
        });

        log('debug', 'Events bound successfully');
    }

    // ====
    // SISTEMA DE TOOLTIPS (OPCIONAL)
    // ====

    function initializeTooltips() {
        const tooltipData = [
            {
                field: BANDA_CONFIG.selectors.storageField,
                text: 'Selecione o espaço de armazenamento necessário para sua equipe. 1TB está incluído no plano base.'
            },
            {
                field: BANDA_CONFIG.selectors.usersField,
                text: 'Número de usuários que terão acesso ao Nextcloud. 2 usuários estão incluídos no plano base.'
            },
            {
                field: BANDA_CONFIG.selectors.frequencyField,
                text: 'Escolha a frequência de pagamento. Planos mais longos oferecem desconto progressivo.'
            }
        ];

        tooltipData.forEach(function(tooltip) {
            const $field = $(tooltip.field);
            const $label = $field.closest('.pmpro_checkout-field-price-display').find('label');
            
            if ($label.length && !$label.find('.pmpro-tooltip-trigger').length) {
                $label.append(` <span class="pmpro-tooltip-trigger" title="${tooltip.text}">ℹ</span>`);
            }
        });
    }

    // ====
    // INICIALIZACIÓN PRINCIPAL
    // ====

    function main() {
        log('info', 'PMPro Banda Dynamic Pricing starting...');

        // Verificar si jQuery está disponible
        if (typeof $ === 'undefined') {
            log('error', 'jQuery not available');
            return;
        }

        // Inicializar sistema
        if (!initializePricingSystem()) {
            log('info', 'Pricing system not initialized (not applicable for current context)');
            return;
        }

        // Configurar tooltips
        initializeTooltips();
    }

    // ====
    // PUNTO DE ENTRADA
    // ====

    // Ejecutar cuando el DOM esté listo con control de doble inicialización
    $(document).ready(function() {
        if (isInitialized) return;
        
        // Esperar datos de pricing con timeout
        waitForPricingData(function(config) {
            if (!config) {
                log('warn', 'Pricing data not found after wait; initialization skipped');
                return;
            }
            
            // Pequeño delay para asegurar que todos los scripts estén cargados
            setTimeout(main, 100);
        });
    });

    // Exponer funciones para debugging (solo en modo debug)
    if (typeof window !== 'undefined') {
        $(document).ready(function() {
            if (pricingData?.debug || false) {
                window.BandaPricingDebug = {
                    calculatePrice: calculatePrice,
                    calculateProration: calculateProration,
                    isUpgrade: isUpgrade,
                    currentProrationData: function() { return currentProrationData; },
                    pricingData: function() { return pricingData; },
                    initialUserValues: function() { return initialUserValues; },
                    version: BANDA_CONFIG.version,
                    isInitialized: function() { return isInitialized; }
                };
                log('debug', 'Debug functions exposed to window.BandaPricingDebug');
            }
        });
    }

})(jQuery);
