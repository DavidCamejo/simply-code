/**
 * PMPro Banda Dynamic Pricing - JavaScript CON PRORRATEO VISUAL v2.8.0
 * 
 * Nombre del archivo: nextcloud-banda-dynamic-pricing.js
 * 
 * RESPONSABILIDAD: Cálculos dinámicos de precio, prorrateo y actualización de UI
 * CORREGIDO: Sistema completo de prorrateo para upgrades
 * MEJORADO: Sanitización defensiva, control de doble init, bloqueo de downgrades
 *
 * @version 2.8.0
 */

/* global jQuery, window, console */

(function($) {
    'use strict';

    // ====
    // CONFIGURACIÓN Y CONSTANTES
    // ====

    const BANDA_CONFIG = {
        version: '2.8.0',
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
            downgradeWarning: 'pmpro-downgrade-warning',
            messageNotice: 'pmpro-proration-message'
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
            return true;
        }

        log('info', 'Initializing pricing system...');

        // Esperar a que los datos estén disponibles
        waitForPricingData(function(config) {
            if (!config) {
                log('error', 'Pricing data not available');
                return false;
            }

            pricingData = config;
            BANDA_CONFIG.debug = pricingData.debug || false;
            log('debug', 'Pricing data loaded', {
                level_id: pricingData.level_id,
                base_price: pricingData.base_price,
                has_active_membership: pricingData.hasActiveMembership,
                current_subscription_data: pricingData.current_subscription_data
            });

            if (!pricingData.level_id || parseInt(pricingData.level_id, 10) !== 2) {
                log('debug', 'Not on Banda level, skipping initialization', { level_id: pricingData.level_id });
                return false;
            }

            waitForRequiredElements(function(elementsFound) {
                if (!elementsFound) {
                    log('warn', 'Required elements not found after timeout');
                    return;
                }

                initializeFieldValues();
                storeInitialUserValues();
                bindEvents();
                setInitialValues();

                isInitialized = true;

                log('info', 'PMPro Banda Dynamic Pricing initialized successfully', {
                    version: BANDA_CONFIG.version,
                    debug: BANDA_CONFIG.debug,
                    hasActiveMembership: pricingData.hasActiveMembership,
                    currentSubscriptionData: !!pricingData.current_subscription_data
                });
                
                // Disparar evento de inicialización completada
                $(document).trigger('nextcloud_banda_initialized');
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

            if (foundElements.length >= 3) {
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

        if (pricingData.hasActiveMembership && pricingData.has_previous_config && pricingData.current_subscription_data) {
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
            return pricingData?.base_price || 70.0;
        }

        try {
            const sanitizedStorage = String(storageSpace || '1tb').toLowerCase();
            const sanitizedUsers = parseInt(numUsers, 10) || 2;
            const sanitizedFrequency = String(paymentFrequency || 'monthly').toLowerCase();

            if (!sanitizedStorage || !sanitizedUsers || !sanitizedFrequency) {
                log('warn', 'Invalid parameters for price calculation', {
                    storageSpace: sanitizedStorage,
                    numUsers: sanitizedUsers,
                    paymentFrequency: sanitizedFrequency
                });
                return pricingData.base_price;
            }

            const storageTb = parseInt(
                sanitizedStorage.replace('tb', '').replace('gb', ''),
                10
            ) || 1;
            const users = sanitizedUsers;

            const additionalTb = Math.max(0, storageTb - pricingData.base_storage_included);
            const storagePrice = pricingData.base_price + (pricingData.price_per_tb * additionalTb);

            const additionalUsers = Math.max(0, users - pricingData.base_users_included);
            const userPrice = pricingData.price_per_user * additionalUsers;

            const combinedPrice = storagePrice + userPrice;

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
            log('debug', 'No current subscription data for upgrade check');
            return false;
        }

        const current = pricingData.current_subscription_data;
        
        log('debug', 'Upgrade check input data', {
            current: current,
            new: { storage: newStorage, users: newUsers, frequency: newFrequency }
        });

        // Parsear storage correctamente
        const parseStorageValue = (value) => {
            if (typeof value !== 'string') return 1;
            const sanitized = String(value).toLowerCase();
            const match = sanitized.match(/^(\d+(?:\.\d+)?)\s*(tb|gb)$/i);
            if (match) {
                const num = parseFloat(match[1]);
                const unit = match[2].toLowerCase();
                return unit === 'gb' ? num / 1024 : num;
            }
            return parseFloat(value) || 1;
        };

        const currentStorageValue = parseStorageValue(current.storage_space || '1tb');
        const newStorageValue = parseStorageValue(newStorage);
        
        log('debug', 'Storage values parsed', {
            currentStorageValue: currentStorageValue,
            newStorageValue: newStorageValue
        });

        const currentUsers = parseInt(current.num_users || 2, 10);
        const newUsersParsed = parseInt(newUsers, 10);
        
        log('debug', 'Users values parsed', {
            currentUsers: currentUsers,
            newUsersParsed: newUsersParsed
        });

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
        
        log('debug', 'Frequency orders', {
            current: current.payment_frequency,
            currentOrder: currentFreqOrder,
            new: newFrequency,
            newOrder: newFreqOrder
        });

        const storageUpgrade = newStorageValue > currentStorageValue;
        const usersUpgrade = newUsersParsed > currentUsers;
        const frequencyUpgrade = newFreqOrder > currentFreqOrder;

        const isUpgradeResult = storageUpgrade || usersUpgrade || frequencyUpgrade;

        log('debug', 'Upgrade analysis result', {
            storageUpgrade: storageUpgrade,
            usersUpgrade: usersUpgrade,
            frequencyUpgrade: frequencyUpgrade,
            isUpgrade: isUpgradeResult,
            currentStorage: currentStorageValue,
            newStorage: newStorageValue,
            currentUser: currentUsers,
            newUser: newUsersParsed
        });

        return isUpgradeResult;
    }

    // Función auxiliar para sanitizar datos de prorrateo - CORREGIDA
    function sanitizeProrationData(data, newTotalPrice) {
        const safeNum = (n, fallback = 0) => {
            const v = Number(n);
            return Number.isFinite(v) ? v : fallback;
        };

        const safeInt = (n, fallback = 0) => {
            const v = parseInt(n, 10);
            return Number.isFinite(v) ? v : fallback;
        };

        if (!data || typeof data !== 'object') {
            return {
                raw: null,
                isUpgrade: false,
                shouldDisplay: false,
                message: 'Dados de prorrateo indisponíveis.',
                priceDiff: 0,
                amountDueNow: safeNum(newTotalPrice, 0),
                creditAmount: 0,
                newTotalPrice: safeNum(newTotalPrice, 0),
                currentAmount: 0,
                currentProratedAmount: 0,
                newProratedAmount: 0,
                fractionRemaining: 0,
                daysRemaining: 0,
                totalDays: 1,
                nextPaymentDate: '',
                currentFrequency: 'monthly',
                newFrequency: 'monthly',
                currentCycleLabel: '',
                newCycleLabel: ''
            };
        }

        const amountDueNow = safeNum(
            data.amount_due_now ??
            data.amountDueNow ??
            data.prorated_amount ??
            data.proratedAmount ??
            NaN,
            NaN
        );

        const creditAmount = safeNum(
            data.credit_amount ??
            data.creditAmount ??
            data.current_prorated_amount ??
            data.currentProratedAmount ??
            0
        );

        const sanitized = {
            raw: data,
            isUpgrade: Boolean(
                data.is_upgrade ??
                data.isUpgrade ??
                (safeNum(data.price_diff ?? data.priceDiff, 0) > 0) ??
                (Number.isFinite(amountDueNow) && amountDueNow > 0)
            ),
            shouldDisplay: Boolean(data.success ?? data.shouldDisplay ?? false),
            message: String(data.message ?? data.Message ?? ''),
            priceDiff: safeNum(data.price_diff ?? data.priceDiff ?? 0),
            amountDueNow: Number.isFinite(amountDueNow) ? amountDueNow : safeNum(newTotalPrice, 0),
            creditAmount: creditAmount,
            newTotalPrice: safeNum(data.new_total_price ?? data.newTotalPrice ?? newTotalPrice, 0),
            currentAmount: safeNum(data.current_price ?? data.currentAmount ?? 0),
            currentProratedAmount: safeNum(data.current_prorated_amount ?? data.currentProratedAmount ?? creditAmount),
            newProratedAmount: safeNum(data.new_prorated_amount ?? data.newProratedAmount ?? data.prorated_new_amount ?? 0),
            fractionRemaining: safeNum(data.fraction_remaining ?? data.fractionRemaining ?? 0),
            daysRemaining: safeInt(data.days_remaining ?? data.daysRemaining ?? 0),
            totalDays: Math.max(1, safeInt(data.total_days ?? data.totalDays ?? 1)),
            nextPaymentDate: String(data.next_payment_date ?? data.nextPaymentDate ?? ''),
            currentFrequency: String(data.current_frequency ?? data.currentFrequency ?? 'monthly'),
            newFrequency: String(data.new_frequency ?? data.newFrequency ?? 'monthly'),
            currentCycleLabel: String(data.current_cycle_label ?? data.currentCycleLabel ?? ''),
            newCycleLabel: String(data.new_cycle_label ?? data.newCycleLabel ?? '')
        };

        sanitized.shouldDisplay =
            sanitized.shouldDisplay ||
            sanitized.amountDueNow > 0 ||
            (sanitized.message && sanitized.message !== 'Success');

        return sanitized;
    }

    function calculateProration(newTotalPrice, callback) {
        if (!pricingData.hasActiveMembership || !pricingData.current_subscription_data) {
            log('debug', 'No active membership for proration', {
                hasActiveMembership: pricingData?.hasActiveMembership,
                hasSubscriptionData: !!pricingData?.current_subscription_data
            });
            callback(null);
            return;
        }

        const storageSpace = $(BANDA_CONFIG.selectors.storageField).val();
        const numUsers = $(BANDA_CONFIG.selectors.usersField).val();
        const paymentFrequency = $(BANDA_CONFIG.selectors.frequencyField).val();

        // Verificar que los valores sean válidos
        if (!storageSpace || !numUsers || !paymentFrequency) {
            log('warn', 'Missing field values for proration calculation', {
                storageSpace, numUsers, paymentFrequency
            });
            callback(null);
            return;
        }

        log('debug', 'Starting AJAX proration calculation', {
            action: 'nextcloud_banda_calculate_proration',
            level_id: pricingData.level_id,
            storage: storageSpace,
            users: numUsers,
            frequency: paymentFrequency
        });

        $.ajax({
            url: pricingData.ajax_url,
            type: 'POST',
            dataType: 'json',
            timeout: 10000,
            data: {
                action: 'nextcloud_banda_calculate_proration',
                security: pricingData.nonce,
                level_id: pricingData.level_id,
                storage: storageSpace,
                users: numUsers,
                frequency: paymentFrequency
            },
            success: function(response) {
                try {
                    log('debug', 'Proration AJAX response received', {
                        success: response?.success,
                        hasData: !!response?.data,
                        rawData: response
                    });

                    if (!response) {
                        log('warn', 'Empty AJAX response');
                        callback(null);
                        return;
                    }

                    // Manejar respuesta de éxito o error
                    if (response.success) {
                        const data = response.data || response;
                        
                        if (!data || typeof data !== 'object') {
                            log('warn', 'Invalid data structure in response', { response });
                            callback(null);
                            return;
                        }

                        // Sanitizar y procesar datos
                        const prorationResult = sanitizeProrationData(data, newTotalPrice);
                        log('debug', 'Processed proration result', prorationResult);
                        callback(prorationResult);
                        
                    } else {
                        // Manejar errores del servidor
                        const errorMessage = response.data?.message || 'Erro no cálculo de prorrateo';
                        log('warn', 'Server error in proration calculation', { 
                            message: errorMessage,
                            data: response.data
                        });
                        
                        callback({
                            isUpgrade: false,
                            shouldDisplay: true,
                            message: errorMessage,
                            priceDiff: 0,
                            proratedAmount: 0,
                            newTotalPrice: newTotalPrice,
                            currentAmount: 0,
                            currentProratedAmount: 0,
                            newProratedAmount: 0,
                            fractionRemaining: 0,
                            daysRemaining: 0,
                            totalDays: 1,
                            nextPaymentDate: '',
                            currentFrequency: 'monthly',
                            newFrequency: 'monthly',
                            raw: response.data
                        });
                    }
                } catch (error) {
                    log('error', 'Error processing proration response', { 
                        error: error.message,
                        stack: error.stack
                    });
                    callback(null);
                }
            },
            error: function(xhr, status, error) {
                log('error', 'AJAX error calculating proration', { 
                    status: status, 
                    error: error,
                    responseText: xhr.responseText,
                    statusCode: xhr.status
                });
                
                // Manejar error de red o timeout
                let errorMessage = 'Não foi possível calcular o prorrateo. ';
                if (status === 'timeout') {
                    errorMessage += 'Tempo limite excedido.';
                } else if (xhr.status === 401) {
                    errorMessage += 'Usuário não autenticado.';
                } else if (xhr.status === 403) {
                    errorMessage += 'Acesso negado.';
                } else {
                    errorMessage += 'Tente novamente.';
                }
                
                callback({
                    isUpgrade: false,
                    shouldDisplay: true,
                    message: errorMessage,
                    priceDiff: 0,
                    proratedAmount: 0,
                    newTotalPrice: newTotalPrice,
                    currentAmount: 0,
                    currentProratedAmount: 0,
                    newProratedAmount: 0,
                    fractionRemaining: 0,
                    daysRemaining: 0,
                    totalDays: 1,
                    nextPaymentDate: '',
                    currentFrequency: 'monthly',
                    newFrequency: 'monthly',
                    raw: null
                });
            }
        });
    }

    // ====
    // ACTUALIZACIÓN DE UI (VERSIÓN SEGURA) - CORREGIDA
    // ====

    function updatePriceDisplaySafe(price, prorationData = null) {
        const $priceField = $(BANDA_CONFIG.selectors.priceDisplay);
        const $priceLabel = $(BANDA_CONFIG.selectors.priceLabel);

        if (!$priceField.length) {
            log('warn', 'Price display field not found');
            return;
        }

        const toFloat = (value, fallback = null) => {
            if (value === null || typeof value === 'undefined') {
                return fallback;
            }

            let normalized = value;

            if (typeof normalized === 'string') {
                normalized = normalized.replace(/\s+/g, '');
                normalized = normalized.replace(/[^\d,.-]/g, '');

                const commaCount = (normalized.match(/,/g) || []).length;
                const dotCount = (normalized.match(/\./g) || []).length;

                if (commaCount > 0 && dotCount > 0) {
                    if (normalized.lastIndexOf(',') > normalized.lastIndexOf('.')) {
                        normalized = normalized.replace(/\./g, '').replace(',', '.');
                    } else {
                        normalized = normalized.replace(/,/g, '');
                    }
                } else if (commaCount > 0 && dotCount === 0) {
                    normalized = normalized.replace(',', '.');
                }
            }

            const parsed = Number(normalized);
            return Number.isFinite(parsed) ? parsed : fallback;
        };

        const safeNum = (n, fallback = 0) => {
            const num = toFloat(n, null);
            if (num === null) {
                return fallback;
            }
            const min = toFloat(fallback, 0);
            return Math.max(min, num);
        };

        $priceField.removeClass(BANDA_CONFIG.classes.proratedPrice);
        $priceLabel.removeClass(BANDA_CONFIG.classes.proratedLabel);
        $('.' + BANDA_CONFIG.classes.proratedNotice).remove();
        $('.' + BANDA_CONFIG.classes.downgradeWarning).remove();
        $('.' + BANDA_CONFIG.classes.messageNotice).remove();

        let displayPrice = safeNum(price);
        let labelText = 'Preço total';
        let shouldShowProration = false;

        if (prorationData && prorationData.shouldDisplay) {
            const amountDueNow = toFloat(prorationData.amountDueNow, null);
            const creditAmount = safeNum(
                prorationData.creditAmount ?? prorationData.currentProratedAmount ?? 0,
                0
            );

            if (amountDueNow !== null && amountDueNow >= 0) {
                displayPrice = amountDueNow;
                labelText = 'Valor a pagar agora';
                shouldShowProration = true;
            } else if (prorationData.isUpgrade) {
                displayPrice = safeNum(prorationData.newTotalPrice, displayPrice);
                labelText = 'Valor a pagar agora';
                shouldShowProration = true;
            } else {
                displayPrice = safeNum(prorationData.newTotalPrice, displayPrice);
                labelText = 'Preço total';
                shouldShowProration =
                    Boolean(prorationData.message && prorationData.message !== 'Success');
            }

            if (shouldShowProration) {
                $priceField.addClass(BANDA_CONFIG.classes.proratedPrice);
                $priceLabel.addClass(BANDA_CONFIG.classes.proratedLabel);
            }

            const newTotalPrice = safeNum(prorationData.newTotalPrice, displayPrice);
            const daysRemaining = Math.max(0, parseInt(prorationData.daysRemaining, 10) || 0);
            const totalDays = Math.max(1, parseInt(prorationData.totalDays, 10) || 1);

            const fraction = Number.isFinite(prorationData.fractionRemaining)
                ? prorationData.fractionRemaining
                : (totalDays > 0 ? daysRemaining / totalDays : 0);
            const fractionPercent = (fraction * 100).toFixed(2);

            const currentFrequencyLabel =
                prorationData.currentCycleLabel ||
                getFrequencyLabel(prorationData.currentFrequency || 'monthly');
            const newFrequencyLabel =
                prorationData.newCycleLabel ||
                getFrequencyLabel(prorationData.newFrequency || 'monthly');

            let noticeContent = '';

            if (prorationData.isUpgrade && amountDueNow !== null && amountDueNow >= 0) {
                noticeContent = `
                    <h4 style="margin: 0 0 12px 0; color: #0c5460;">🚀 Upgrade da configuração</h4>
                    <div style="margin-bottom: 10px;">
                        <strong>Preço da nova configuração:</strong> R$ ${formatPrice(newTotalPrice)}
                    </div>
                    <div style="margin-bottom: 10px;">
                        <strong>Ciclo de pagamento atual:</strong> ${currentFrequencyLabel}
                    </div>
                    <div style="margin-bottom: 10px;">
                        <strong>Novo ciclo de pagamento:</strong> ${newFrequencyLabel}
                    </div>
                    <div style="margin-bottom: 10px;">
                        <strong>Período restante:</strong> ${daysRemaining} dias (${fractionPercent}% do ciclo)
                    </div>
                    <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin: 10px 0;">
                        <div style="margin-bottom: 8px;">
                            <strong>Crédito por dias não utilizados:</strong> -R$ ${formatPrice(creditAmount)}
                        </div>
                        <div style="border-top: 1px solid #dee2e6; padding-top: 8px; margin-top: 8px;">
                            <strong style="color: #0c5460;">Valor a pagar agora: R$ ${formatPrice(displayPrice)}</strong>
                        </div>
                    </div>
                    <div style="font-size: 0.85em; color: #6c757d; margin-top: 10px;">
                        💡 Você paga apenas a diferença prorratada agora.<br/>➡️ O valor integral da nova configuração só será cobrado no próximo ciclo.
                    </div>
                `;
            } else if (prorationData.isUpgrade) {
                noticeContent = `
                    <h4 style="margin: 0 0 12px 0; color: #0c5460;">🚀 Upgrade da configuração</h4>
                    <div style="margin-bottom: 10px;">
                        <strong>Preço da nova configuração:</strong> R$ ${formatPrice(newTotalPrice)}
                    </div>
                    <div style="margin-bottom: 10px;">
                        <strong>Ciclo de pagamento atual:</strong> ${currentFrequencyLabel}
                    </div>
                    <div style="margin-bottom: 10px;">
                        <strong>Novo ciclo de pagamento:</strong> ${newFrequencyLabel}
                    </div>
                    ${
                        daysRemaining > 0
                            ? `<div style="margin-bottom: 10px;">
                                   <strong>Período restante:</strong> ${daysRemaining} dias
                               </div>`
                            : ''
                    }
                    <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin: 10px 0;">
                        <strong style="color: #0c5460;">Valor a pagar agora: R$ ${formatPrice(displayPrice)}</strong>
                    </div>
                    <div style="font-size: 0.85em; color: #6c757d; margin-top: 10px;">
                        🔄 Esta é uma atualização da sua configuração.
                    </div>
                `;
            } else {
                noticeContent = `
                    <h4 style="margin: 0 0 12px 0; color: #0c5460;">📋 Detalhes da sua configuração</h4>
                    <div style="margin-bottom: 10px;">
                        <strong>Preço total da configuração:</strong> R$ ${formatPrice(newTotalPrice)}
                    </div>
                    <div style="margin-bottom: 10px;">
                        <strong>Ciclo de pagamento:</strong> ${newFrequencyLabel}
                    </div>
                    ${
                        daysRemaining > 0
                            ? `<div style="margin-bottom: 10px;">
                                   <strong>Período restante do ciclo atual:</strong> ${daysRemaining} dias
                               </div>`
                            : ''
                    }
                    <div style="font-size: 0.85em; color: #6c757d; margin-top: 10px;">
                        ℹ️ Esta é a configuração que você selecionou.
                    </div>
                `;
            }

            const noticeHtml = `
                <div class="${BANDA_CONFIG.classes.proratedNotice}" style="background: #e8f4fd; border: 1px solid #bee5eb; padding: 15px; border-radius: 8px; margin: 15px 0; font-size: 0.9em;">
                    ${noticeContent}
                </div>
            `;

            const $targetElement = $priceField.closest('.pmpro_checkout-field-price-display');
            if ($targetElement.length > 0) {
                $targetElement.after(noticeHtml);
            } else {
                $priceField.after(noticeHtml);
            }

            if (prorationData.message && prorationData.message !== 'Success') {
                const messageHtml = `
                    <div class="${BANDA_CONFIG.classes.messageNotice}" style="background: #fff3cd; border: 1px solid #ffe8a1; padding: 12px; border-radius: 6px; margin: 10px 0; font-size: 0.85em; color: #856404;">
                        <strong>ℹ️ ${prorationData.message}</strong>
                    </div>
                `;
                $priceField.closest('.pmpro_checkout-field-price-display').after(messageHtml);
            }
        } else if (prorationData && prorationData.message && prorationData.message !== 'Success') {
            const messageHtml = `
                <div class="${BANDA_CONFIG.classes.messageNotice}" style="background: #fff3cd; border: 1px solid #ffe8a1; padding: 12px; border-radius: 6px; margin: 10px 0; font-size: 0.85em; color: #856404;">
                    <strong>ℹ️ ${prorationData.message}</strong>
                </div>
            `;
            $priceField.closest('.pmpro_checkout-field-price-display').after(messageHtml);
        }

        $priceField.fadeOut(BANDA_CONFIG.animationDuration / 2, function () {
            $(this)
                .val('R$ ' + formatPrice(displayPrice))
                .fadeIn(BANDA_CONFIG.animationDuration / 2);
        });

        if ($priceLabel.length) {
            $priceLabel.text(labelText);
        }

        log('debug', 'Price display updated completely', {
            finalPrice: displayPrice,
            labelText: labelText,
        });
    }

    /**
     * Convierte frequency key a etiqueta legible en portugués
     */
    function getFrequencyLabel(frequencyKey) {
        const labels = {
            'monthly': 'Mensal',
            'semiannual': 'Semestral',
            'annual': 'Anual',
            'biennial': 'Bienal',
            'triennial': 'Trienal',
            'quadrennial': 'Quadrienal',
            'quinquennial': 'Quinquenal',
            'weekly': 'Semanal',
            'biweekly': 'Quinzenal',
            'daily': 'Diário'
        };
        
        return labels[frequencyKey] || frequencyKey;
    }

    /**
     * Alias para compatibilidad con scripts existentes.
     * Mantiene la firma original y dispara eventos públicos.
     */
    function updatePriceDisplay(price, prorationData = null, options = {}) {
        if (!options || typeof options !== 'object') {
            options = {};
        }

        updatePriceDisplaySafe(price, prorationData);

        log('debug', 'updatePriceDisplay alias invoked', {
            price,
            hasProration: !!(prorationData && (prorationData.isUpgrade || prorationData.shouldDisplay)),
            options
        });

        $(document).trigger('nextcloud_banda_price_updated', [price, prorationData, options]);
    }

    if (typeof window !== 'undefined') {
        window.updatePriceDisplaySafe = updatePriceDisplaySafe;
        window.updatePriceDisplay = updatePriceDisplay;
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
            monthly: 1,
            semiannual: 2,
            annual: 3,
            biennial: 4,
            triennial: 5,
            quadrennial: 6,
            quinquennial: 7
        };

        const currentFrequency = initialUserValues.frequency || 'monthly';
        const currentFreqOrder = frequencyOrder[currentFrequency] || 1;

        $frequencySelect.find('option').each(function() {
            const $option = $(this);
            const optionValue = $option.val();
            const optionOrder = frequencyOrder[optionValue] || 0;
            const key = 'payment_frequency_' + optionValue;

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
            currentFrequency,
            currentFreqOrder
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

        if (initialUserValues.hasPreviousConfig && hasUserMadeChanges()) {
            if (isDowngradeAttempt()) {
                showDowngradeWarning('Downgrades não são permitidos. Entre em contato com o suporte para alterações.');
                return;
            } else {
                clearDowngradeWarning();
                updateFieldOptions();
            }
        }

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
            storageSpace, 
            numUsers, 
            paymentFrequency,
            hasActiveMembership: pricingData?.hasActiveMembership,
            hasCurrentSubscriptionData: !!pricingData?.current_subscription_data,
            pricingDataAvailable: !!pricingData
        });

        const newPrice = calculatePrice(storageSpace, numUsers, paymentFrequency);

        // Debugging adicional antes del prorrateo
        if (pricingData) {
            const shouldCalculateProration = pricingData.hasActiveMembership && pricingData.current_subscription_data;
            const isUpgradeCheck = isUpgrade(storageSpace, numUsers, paymentFrequency);
            
            log('debug', 'Proration eligibility check', {
                shouldCalculate: shouldCalculateProration,
                isUpgrade: isUpgradeCheck,
                hasActiveMembership: pricingData.hasActiveMembership,
                hasSubscriptionData: !!pricingData.current_subscription_data
            });

            if (shouldCalculateProration && isUpgradeCheck) {
                calculateProration(newPrice, function(prorationData) {
                    log('debug', 'Proration callback received', {
                        hasData: !!prorationData,
                        isUpgrade: prorationData?.isUpgrade,
                        shouldDisplay: prorationData?.shouldDisplay,
                        proratedAmount: prorationData?.proratedAmount
                    });
                    
                    currentProrationData = prorationData;
                    updatePriceDisplay(newPrice, prorationData);
                    isCalculating = false;
                });
            } else {
                log('debug', 'Skipping proration calculation - conditions not met');
                // Solo mostrar información básica si es upgrade pero no se puede calcular prorrateo
                if (isUpgradeCheck) {
                    const basicProrationData = {
                        isUpgrade: true,
                        shouldDisplay: true,
                        message: 'Atualização de configuração detectada',
                        priceDiff: 0,
                        proratedAmount: newPrice,
                        newTotalPrice: newPrice,
                        currentAmount: 0,
                        currentProratedAmount: 0,
                        newProratedAmount: 0,
                        fractionRemaining: 0,
                        daysRemaining: 0,
                        totalDays: 1,
                        nextPaymentDate: '',
                        currentFrequency: paymentFrequency,
                        newFrequency: paymentFrequency,
                        raw: null
                    };
                    updatePriceDisplay(newPrice, basicProrationData);
                } else {
                    updatePriceDisplay(newPrice, null);
                }
                isCalculating = false;
            }
        } else {
            log('debug', 'No pricing data available, updating display without proration');
            updatePriceDisplay(newPrice, null);
            isCalculating = false;
        }
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

        const frequencyOrder = {
            monthly: 1,
            semiannual: 2,
            annual: 3,
            biennial: 4,
            triennial: 5,
            quadrennial: 6,
            quinquennial: 7
        };
        const currentFreqOrder = frequencyOrder[initialUserValues.frequency] || 1;
        const selectedFreqOrder = frequencyOrder[$(BANDA_CONFIG.selectors.frequencyField).val()] || 1;

        return (
            selectedStorage < currentStorage ||
            selectedUsers < currentUsers ||
            selectedFreqOrder < currentFreqOrder
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

        performPriceCalculation();

        if (initialUserValues.hasPreviousConfig) {
            updateFieldOptions();
        }
    }

    function bindEvents() {
        log('debug', 'Binding events to form fields');

        $(document)
            .off('.pmproband')
            .on('change.pmproband', BANDA_CONFIG.selectors.storageField, handleFieldChange)
            .on('change.pmproband', BANDA_CONFIG.selectors.usersField, handleFieldChange)
            .on('change.pmproband', BANDA_CONFIG.selectors.frequencyField, handleFieldChange);

        $(document).on('submit.pmproband', 'form#pmpro_form', function() {
            if (currentProrationData && (currentProrationData.isUpgrade || currentProrationData.shouldDisplay)) {
                log('info', 'Form submitted with proration data', currentProrationData);
            }
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

        if (typeof $ === 'undefined') {
            log('error', 'jQuery not available');
            return;
        }

        if (!initializePricingSystem()) {
            log('info', 'Pricing system not initialized (not applicable for current context)');
            return;
        }

        initializeTooltips();
    }

    // ====
    // PUNTO DE ENTRADA
    // ====

    $(document).ready(function() {
        if (isInitialized) return;

        waitForPricingData(function(config) {
            if (!config) {
                log('warn', 'Pricing data not found after wait; initialization skipped');
                return;
            }

            setTimeout(main, 100);
        });
    });

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
                    isInitialized: function() { return isInitialized; },
                    updatePriceDisplaySafe,
                    updatePriceDisplay
                };
                log('debug', 'Debug functions exposed to window.BandaPricingDebug');
            }
        });
    }

    // Función de diagnóstico para verificar el estado del sistema
    function diagnosePricingSystem() {
        console.group('=== PMPro Banda Pricing System Diagnosis ===');
        
        console.log('Window pricing data:', window.nextcloud_banda_pricing);
        console.log('Has pricing data:', !!window.nextcloud_banda_pricing);
        
        if (window.nextcloud_banda_pricing) {
            console.log('Has active membership:', window.nextcloud_banda_pricing.hasActiveMembership);
            console.log('Has subscription data:', !!window.nextcloud_banda_pricing.current_subscription_data);
            console.log('Current subscription data:', window.nextcloud_banda_pricing.current_subscription_data);
        }
        
        console.log('Required DOM elements:');
        console.log('Storage field:', $(BANDA_CONFIG.selectors.storageField).length);
        console.log('Users field:', $(BANDA_CONFIG.selectors.usersField).length);
        console.log('Frequency field:', $(BANDA_CONFIG.selectors.frequencyField).length);
        console.log('Price display:', $(BANDA_CONFIG.selectors.priceDisplay).length);
        
        console.log('Is initialized:', isInitialized);
        console.log('Pricing data loaded:', !!pricingData);
        
        if (pricingData) {
            console.log('Pricing data details:', {
                hasActiveMembership: pricingData.hasActiveMembership,
                hasPreviousConfig: pricingData.has_previous_config,
                currentSubscriptionData: !!pricingData.current_subscription_data,
                levelId: pricingData.level_id
            });
        }
        
        console.groupEnd();
    }

    // Exponer para debugging
    if (typeof window !== 'undefined' && (window.nextcloud_banda_pricing?.debug || false)) {
        window.diagnoseBandaPricing = diagnosePricingSystem;
    }

})(jQuery);
