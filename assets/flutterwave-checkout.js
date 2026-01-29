class FctFlutterwaveHandler {
    #cdnUrl = 'https://checkout.flutterwave.com/v3.js';
    #publicKey = null;
    #flwCheckout = null;
    #isProcessing = false;

    constructor(form, orderHandler, response, paymentLoader) {
        this.form = form;
        this.orderHandler = orderHandler;
        this.data = response;
        this.paymentLoader = paymentLoader;
        this.$t = this.translate.bind(this);
        this.submitButton = window.fluentcart_checkout_vars?.submit_button;
        this.#publicKey = response?.payment_args?.public_key;
    }

    init() {
        const flutterwaveContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_flutterwave');
        const hasCustomContent = flutterwaveContainer && flutterwaveContainer.dataset.hasCustomContent === 'true';
        
        if (flutterwaveContainer && !hasCustomContent) {
            flutterwaveContainer.innerHTML = '';
            this.renderPaymentButton(flutterwaveContainer);
        }

        this.#publicKey = this.data?.payment_args?.public_key;

        this.loadFlutterwaveScript().catch(() => {});
    }

    renderPaymentButton(container) {
        const that = this;

        this.renderPaymentInfo();

        // Create custom Pay button
        const buttonWrapper = document.createElement('div');
        buttonWrapper.className = 'fct-flutterwave-button-wrapper';

        const payButton = document.createElement('button');
        payButton.type = 'button';
        payButton.id = 'fct-flutterwave-pay-button';
        payButton.className = 'fct-flutterwave-pay-button';
        payButton.innerHTML = `
            <span class="fct-flw-btn-text">${this.$t('Pay with Flutterwave')}</span>
            <span class="fct-flw-btn-loader" style="display: none;">
                <svg width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <style>.spinner{transform-origin:center;animation:spinner .75s linear infinite}@keyframes spinner{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}</style>
                    <circle class="spinner" cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="3" stroke-dasharray="31.4 31.4"/>
                </svg>
            </span>
        `;

        payButton.addEventListener('click', async () => {
            if (that.#isProcessing) return;
            await that.handlePayButtonClick(payButton);
        });

        buttonWrapper.appendChild(payButton);
        container.appendChild(buttonWrapper);

        // Extra text
        const extraText = document.createElement('p');
        extraText.className = 'fct-flutterwave-extra-text';
        extraText.textContent = this.$t('Click to pay securely with Flutterwave');
        container.appendChild(extraText);

        // Dispatch success event
        window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_success', {
            detail: { payment_method: 'flutterwave' }
        }));

        const loadingElement = document.getElementById('fct_loading_payment_processor');
        if (loadingElement) {
            loadingElement.remove();
        }
    }

    async handlePayButtonClick(button) {
        const that = this;
        this.#isProcessing = true;

        // Show loading state on button
        const btnText = button.querySelector('.fct-flw-btn-text');
        const btnLoader = button.querySelector('.fct-flw-btn-loader');
        btnText.textContent = this.$t('Processing...');
        btnLoader.style.display = 'inline-block';
        button.disabled = true;

        try {
            if (typeof this.orderHandler !== 'function') {
                throw new Error(this.$t('Order handler not available'));
            }

            const orderResponse = await this.orderHandler();
            
            if (!orderResponse) {
                throw new Error(this.$t('Order creation failed'));
            }
            
            const flutterwaveData = orderResponse?.data?.flutterwave_data;
            const intent = orderResponse?.data?.intent;

            if (!flutterwaveData) {
                throw new Error(this.$t('Payment data not received'));
            }

            // Load Flutterwave script if not loaded
            await this.loadFlutterwaveScript();

            console.log('flutterwaveData', flutterwaveData);

            if (intent === 'subscription') {
                this.openFlutterwavePopup(flutterwaveData, true);
            } else {
                this.openFlutterwavePopup(flutterwaveData, false);
            }

        } catch (error) {
            console.error('Flutterwave payment error:', error);
            this.handleFlutterwaveError(error);
            this.resetPayButton(button);
        }
    }

    resetPayButton(button) {
        this.#isProcessing = false;
        const btnText = button.querySelector('.fct-flw-btn-text');
        const btnLoader = button.querySelector('.fct-flw-btn-loader');
        btnText.textContent = this.$t('Pay with Flutterwave');
        btnLoader.style.display = 'none';
        button.disabled = false;
    }

    openFlutterwavePopup(flutterwaveData, isSubscription = false) {
        const that = this;
        const button = document.getElementById('fct-flutterwave-pay-button');

        const config = {
            public_key: flutterwaveData.public_key,
            tx_ref: flutterwaveData.tx_ref,
            amount: flutterwaveData.amount,
            currency: flutterwaveData.currency,
            customer: flutterwaveData.customer,
            meta: flutterwaveData.meta,
            customizations: flutterwaveData.customizations,

            callback: function(response) {
                that.handlePaymentSuccess(response);
            },
            // onclose is called when customer closes modal
            // incomplete=true means payment was not completed
            onclose: function(incomplete) {
                if (incomplete) {
                    that.handlePaymentCancel();
                    if (button) that.resetPayButton(button);
                }
            }
        };


        if (flutterwaveData.payment_options) {
            config.payment_options = flutterwaveData.payment_options;
        }

        if (isSubscription && flutterwaveData.payment_plan) {
            config.payment_plan = flutterwaveData.payment_plan;
        }

        if (flutterwaveData.configurations) {
            config.configurations = flutterwaveData.configurations;
        }

        console.log('config', config);
        try {
            // FlutterwaveCheckout returns an object with close() method
            this.#flwCheckout = window.FlutterwaveCheckout(config);
        } catch (error) {
            console.error('Error opening Flutterwave popup:', error);
            this.handleFlutterwaveError(error);
            if (button) this.resetPayButton(button);
        }
    }

    translate(string) {
        const translations = window.fct_flutterwave_data?.translations || {};
        return translations[string] || string;
    }

    getPaymentMethodIcons() {
        return {
            cards: `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>`,
            bank: `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"></path><path d="M3 10h18"></path><path d="M5 6l7-3 7 3"></path><path d="M4 10v11"></path><path d="M20 10v11"></path><path d="M8 14v3"></path><path d="M12 14v3"></path><path d="M16 14v3"></path></svg>`,
            mobile: `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>`,
            ussd: `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="M6 8h.01"></path><path d="M10 8h.01"></path><path d="M14 8h.01"></path><path d="M18 8h.01"></path><path d="M8 12h.01"></path><path d="M12 12h.01"></path><path d="M16 12h.01"></path><path d="M7 16h10"></path></svg>`,
            mpesa: `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"></path><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"></path><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"></path></svg>`
        };
    }

    renderPaymentInfo() {
        let container = document.querySelector('.fluent-cart-checkout_embed_payment_container_flutterwave');
        if (!container) {
            return;
        }

        const icons = this.getPaymentMethodIcons();

        const paymentMethods = [
            { key: 'cards', icon: icons.cards, label: this.$t('Cards') },
            { key: 'bank', icon: icons.bank, label: this.$t('Bank Transfer') },
            { key: 'mobile', icon: icons.mobile, label: this.$t('Mobile Money') },
            { key: 'ussd', icon: icons.ussd, label: this.$t('USSD') },
            { key: 'mpesa', icon: icons.mpesa, label: this.$t('M-Pesa') }
        ];

        let html = '<div class="fct-flutterwave-info">';
        
        // Simple header
        html += '<div class="fct-flutterwave-header">';
        html += '<p class="fct-flutterwave-subheading">' + this.$t('Available payment methods') + '</p>';
        html += '</div>';
        
        // Payment methods with icons
        html += '<div class="fct-flutterwave-methods">';
        paymentMethods.forEach(method => {
            html += '<div class="fct-flutterwave-method" title="' + method.label + '">';
            html += '<span class="fct-method-icon">' + method.icon + '</span>';
            html += '<span class="fct-method-name">' + method.label + '</span>';
            html += '</div>';
        });
        html += '</div>';
        
        html += '</div>';

        container.innerHTML = html;
    }

    loadFlutterwaveScript() {
        return new Promise((resolve, reject) => {
            // Check if Flutterwave SDK is already loaded
            if (typeof window.FlutterwaveCheckout === 'function') {
                resolve();
                return;
            }

            const existingScript = document.querySelector(`script[src="${this.#cdnUrl}"]`);
            if (existingScript) {
                // Script tag exists, wait for it to load
                if (typeof window.FlutterwaveCheckout === 'function') {
                    resolve();
                    return;
                }
                existingScript.addEventListener('load', () => resolve());
                existingScript.addEventListener('error', () => reject(new Error('Failed to load Flutterwave script')));
                return;
            }

            const script = document.createElement('script');
            script.src = this.#cdnUrl;
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('Failed to load Flutterwave script'));

            document.head.appendChild(script);
        });
    }

    handlePaymentSuccess(response) {
        // Close the modal if still open
        if (this.#flwCheckout && typeof this.#flwCheckout.close === 'function') {
            this.#flwCheckout.close();
        }

        this.paymentLoader?.changeLoaderStatus(this.$t('Verifying payment...'));

        // Update button state
        const button = document.getElementById('fct-flutterwave-pay-button');
        if (button) {
            const btnText = button.querySelector('.fct-flw-btn-text');
            if (btnText) btnText.textContent = this.$t('Verifying payment...');
        }

        // response contains: transaction_id, tx_ref, flw_ref, status
        const params = new URLSearchParams({
            action: 'fluent_cart_confirm_flutterwave_payment',
            transaction_id: response.transaction_id || '',
            tx_ref: response.tx_ref || '',
            flw_ref: response.flw_ref || '',
            flutterwave_fct_nonce: window.fct_flutterwave_data?.nonce
        });

        const that = this;
        const xhr = new XMLHttpRequest();
        xhr.open('POST', window.fluentcart_checkout_vars.ajaxurl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    const res = JSON.parse(xhr.responseText);
                    if (res?.redirect_url) {
                        that.paymentLoader?.triggerPaymentCompleteEvent(res);
                        that.paymentLoader?.changeLoaderStatus(that.$t('Payment successful! Redirecting...'));
                        
                        // Handle redirect based on checkout mode (modal or single page)
                        if (window.CheckoutHelper) {
                            window.CheckoutHelper.handleCheckoutRedirect(res.redirect_url);
                        } else {
                            window.location.href = res.redirect_url;
                        }
                    } else {
                        that.handleFlutterwaveError(new Error(res?.message || 'Payment confirmation failed'));
                        const btn = document.getElementById('fct-flutterwave-pay-button');
                        if (btn) that.resetPayButton(btn);
                    }
                } catch (error) {
                    that.handleFlutterwaveError(error);
                    const btn = document.getElementById('fct-flutterwave-pay-button');
                    if (btn) that.resetPayButton(btn);
                }
            } else {
                that.handleFlutterwaveError(new Error(that.$t('Network error: ' + xhr.status)));
                const btn = document.getElementById('fct-flutterwave-pay-button');
                if (btn) that.resetPayButton(btn);
            }
        };

        xhr.onerror = function () {
            try {
                const err = JSON.parse(xhr.responseText);
                that.handleFlutterwaveError(err);
            } catch (e) {
                console.error('An error occurred:', e);
                that.handleFlutterwaveError(e);
            }
            const btn = document.getElementById('fct-flutterwave-pay-button');
            if (btn) that.resetPayButton(btn);
        };

        xhr.send(params.toString());
    }

    handlePaymentCancel() {
        this.#isProcessing = false;
        this.paymentLoader?.changeLoaderStatus(this.$t('Payment cancelled'));
        this.paymentLoader?.hideLoader();
        this.paymentLoader?.enableCheckoutButton();
    }

    handleFlutterwaveError(err) {
        this.#isProcessing = false;

        let errorMessage = this.$t('An unknown error occurred');

        if (err?.message) {
            try {
                const jsonMatch = err.message.match(/{.*}/s);
                if (jsonMatch) {
                    errorMessage = JSON.parse(jsonMatch[0]).message || errorMessage;
                } else {
                    errorMessage = err.message;
                }
            } catch {
                errorMessage = err.message || errorMessage;
            }
        }

        // Show error message
        let flutterwaveContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_flutterwave');
        if (flutterwaveContainer) {
            // Remove any existing error messages
            const existingError = flutterwaveContainer.querySelector('.fct-flutterwave-error');
            if (existingError) existingError.remove();

            // Add new error message
            const errorDiv = document.createElement('div');
            errorDiv.className = 'fct-flutterwave-error';
            errorDiv.textContent = errorMessage;
            flutterwaveContainer.appendChild(errorDiv);

            // Auto-remove error after 5 seconds
            setTimeout(() => {
                if (errorDiv.parentNode) errorDiv.remove();
            }, 5000);
        }

        this.paymentLoader?.hideLoader();
        this.paymentLoader?.enableCheckoutButton();
    }
}

window.addEventListener("fluent_cart_load_payments_flutterwave", function (e) {
    // Dispatch loading event
    window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading', {
        detail: { payment_method: 'flutterwave' }
    }));

    const flutterwaveContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_flutterwave');
    if (flutterwaveContainer && flutterwaveContainer.children.length > 0) {
        flutterwaveContainer.dataset.hasCustomContent = 'true';
    }
    
    addLoadingText();
    fetch(e.detail.paymentInfoUrl, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": e.detail.nonce,
        },
        credentials: 'include'
    }).then(async (response) => {
        response = await response.json();
        if (response?.status === 'failed') {
            displayErrorMessage(response?.message);
            return;
        }
        new FctFlutterwaveHandler(e.detail.form, e.detail.orderHandler, response, e.detail.paymentLoader).init();
    }).catch(error => {
        const translations = window.fct_flutterwave_data?.translations || {};
        function $t(string) {
            return translations[string] || string;
        }
        let message = error?.message || $t('An error occurred while loading Flutterwave.');
        displayErrorMessage(message);
    });

    function displayErrorMessage(message) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'fct-error-message';
        errorDiv.textContent = message;

        const flutterwaveContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_flutterwave');
        if (flutterwaveContainer) {
            flutterwaveContainer.appendChild(errorDiv);
        }

        const loadingElement = document.getElementById('fct_loading_payment_processor');
        if (loadingElement) {
            loadingElement.remove();
        }
        return;
    }

    function addLoadingText() {
        let flutterwaveButtonContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_flutterwave');
        if (flutterwaveButtonContainer) {
            if (flutterwaveButtonContainer.dataset.hasCustomContent === 'true') {
                return;
            }
            const loadingMessage = document.createElement('p');
            loadingMessage.id = 'fct_loading_payment_processor';
            loadingMessage.className = 'fct-flutterwave-loading';
            const translations = window.fct_flutterwave_data?.translations || {};
            function $t(string) {
                return translations[string] || string;
            }
            loadingMessage.textContent = $t('Loading Payment Processor...');
            flutterwaveButtonContainer.appendChild(loadingMessage);
        }
    }
});
