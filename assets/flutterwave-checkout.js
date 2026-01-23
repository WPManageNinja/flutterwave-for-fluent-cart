class FlutterwaveCheckout {
    #cdnUrl = 'https://checkout.flutterwave.com/v3.js';
    #publicKey = null;
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
        this.paymentLoader.enableCheckoutButton(this.translate(this.submitButton.text));
        const flutterwaveContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_flutterwave');
        if (flutterwaveContainer) {
            flutterwaveContainer.innerHTML = '';
        }

        this.renderPaymentInfo();

        this.#publicKey = this.data?.payment_args?.public_key;

        window.addEventListener("fluent_cart_payment_next_action_flutterwave", async (e) => {
            const remoteResponse = e.detail?.response;
            const flutterwaveData = remoteResponse?.data?.flutterwave_data;
            const intent = remoteResponse?.data?.intent;

            if (flutterwaveData) {
                if (intent === 'onetime') {
                    this.onetimePaymentHandler(flutterwaveData);
                } else if (intent === 'subscription') {
                    this.subscriptionPaymentHandler(flutterwaveData);
                }
            }
        });
    }

    translate(string) {
        const translations = window.fct_flutterwave_data?.translations || {};
        return translations[string] || string;
    }

    renderPaymentInfo() {
        let html = '<div class="fct-flutterwave-info">';

        html += '<div class="fct-flutterwave-header">';
        html += '<p class="fct-flutterwave-subheading">' + this.$t('Available payment methods on Checkout') + '</p>';
        html += '</div>';

        html += '<div class="fct-flutterwave-methods">';
        html += '<div class="fct-flutterwave-method">';
        html += '<span class="fct-method-name">' + this.$t('Cards') + '</span>';
        html += '</div>';
        html += '<div class="fct-flutterwave-method">';
        html += '<span class="fct-method-name">' + this.$t('Bank Transfer') + '</span>';
        html += '</div>';
        html += '<div class="fct-flutterwave-method">';
        html += '<span class="fct-method-name">' + this.$t('Mobile Money') + '</span>';
        html += '</div>';
        html += '<div class="fct-flutterwave-method">';
        html += '<span class="fct-method-name">' + this.$t('USSD') + '</span>';
        html += '</div>';
        html += '<div class="fct-flutterwave-method">';
        html += '<span class="fct-method-name">' + this.$t('M-Pesa') + '</span>';
        html += '</div>';
        html += '</div>';

        html += '</div>';

        html += `<style>
            .fct-flutterwave-info {
                padding: 20px;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                background: #f9f9f9;
                margin-bottom: 20px;
            }
            
            .fct-flutterwave-header {
                text-align: center;
                margin-bottom: 16px;
            }
            
            .fct-flutterwave-heading {
                margin: 0 0 4px 0;
                font-size: 18px;
                font-weight: 600;
                color: #F5A623;
            }
            
            .fct-flutterwave-subheading {
                margin: 0;
                font-size: 12px;
                color: #999;
                font-weight: 400;
            }
            
            .fct-flutterwave-methods {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
                gap: 10px;
            }
            
            .fct-flutterwave-method {
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 10px;
                background: white;
                border: 1px solid #ddd;
                border-radius: 6px;
                transition: all 0.2s ease;
                cursor: text;
            }
            
            .fct-method-name {
                font-size: 12px;
                font-weight: 500;
                color: #333;
            }
            
            @media (max-width: 768px) {
                .fct-flutterwave-info {
                    padding: 16px;
                }
                
                .fct-flutterwave-heading {
                    font-size: 16px;
                }
                
                .fct-flutterwave-methods {
                    grid-template-columns: repeat(2, 1fr);
                    gap: 8px;
                }
                
                .fct-flutterwave-method {
                    padding: 8px;
                }
            }
        </style>`;

        let container = document.querySelector('.fluent-cart-checkout_embed_payment_container_flutterwave');
        if (container) {
            container.innerHTML = html;
        }
    }

    loadFlutterwaveScript() {
        return new Promise((resolve, reject) => {
            if (typeof FlutterwaveCheckout !== 'undefined' && window.FlutterwaveCheckout) {
                resolve();
                return;
            }

            const existingScript = document.querySelector(`script[src="${this.#cdnUrl}"]`);
            if (existingScript) {
                existingScript.addEventListener('load', resolve);
                existingScript.addEventListener('error', () => reject(new Error('Failed to load Flutterwave script')));
                return;
            }

            const script = document.createElement('script');
            script.src = this.#cdnUrl;
            script.onload = () => {
                resolve();
            };
            script.onerror = () => {
                reject(new Error('Failed to load Flutterwave script'));
            };

            document.head.appendChild(script);
        });
    }

    async onetimePaymentHandler(flutterwaveData) {
        try {
            await this.loadFlutterwaveScript();
        } catch (error) {
            console.error('Flutterwave script failed to load:', error);
            this.handleFlutterwaveError(error);
            return;
        }

        const that = this;

        try {
            // Use Flutterwave Inline popup
            window.FlutterwaveCheckout({
                public_key: flutterwaveData.public_key,
                tx_ref: flutterwaveData.tx_ref,
                amount: flutterwaveData.amount,
                currency: flutterwaveData.currency,
                customer: flutterwaveData.customer,
                meta: flutterwaveData.meta,
                customizations: flutterwaveData.customizations,
                callback: function(response) {
                    // Payment completed - verify and confirm
                    that.handlePaymentSuccess(response);
                },
                onclose: function() {
                    // User closed the popup without completing payment
                    that.handlePaymentCancel();
                }
            });
        } catch (error) {
            console.error('Error opening Flutterwave popup:', error);
            this.handleFlutterwaveError(error);
        }
    }

    async subscriptionPaymentHandler(flutterwaveData) {
        try {
            await this.loadFlutterwaveScript();
        } catch (error) {
            console.error('Flutterwave script failed to load:', error);
            this.handleFlutterwaveError(error);
            return;
        }

        const that = this;

        try {
            // Use Flutterwave Inline popup with payment plan for subscriptions
            window.FlutterwaveCheckout({
                public_key: flutterwaveData.public_key,
                tx_ref: flutterwaveData.tx_ref,
                amount: flutterwaveData.amount,
                currency: flutterwaveData.currency,
                payment_plan: flutterwaveData.payment_plan,
                customer: flutterwaveData.customer,
                meta: flutterwaveData.meta,
                customizations: flutterwaveData.customizations,
                callback: function(response) {
                    // Subscription payment completed - verify and confirm
                    that.handlePaymentSuccess(response);
                },
                onclose: function() {
                    // User closed the popup without completing payment
                    that.handlePaymentCancel();
                }
            });
        } catch (error) {
            console.error('Error opening Flutterwave subscription popup:', error);
            this.handleFlutterwaveError(error);
        }
    }

    handlePaymentSuccess(response) {
        // response contains: transaction_id, tx_ref, flw_ref, status
        const params = new URLSearchParams({
            action: 'fluent_cart_confirm_flutterwave_payment',
            transaction_id: response.transaction_id || '',
            tx_ref: response.tx_ref || '',
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
                        that.paymentLoader.triggerPaymentCompleteEvent(res);
                        that.paymentLoader?.changeLoaderStatus('redirecting');
                        window.location.href = res.redirect_url;
                    } else {
                        that.handleFlutterwaveError(new Error(res?.message || 'Payment confirmation failed'));
                    }
                } catch (error) {
                    that.handleFlutterwaveError(error);
                }
            } else {
                that.handleFlutterwaveError(new Error(that.$t('Network error: ' + xhr.status)));
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
        };

        xhr.send(params.toString());
    }

    handlePaymentCancel() {
        this.paymentLoader.hideLoader();
        this.paymentLoader.enableCheckoutButton(this.submitButton.text);
    }

    handleFlutterwaveError(err) {
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

        let flutterwaveContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_flutterwave');
        let tempMessage = this.$t('Something went wrong');

        if (flutterwaveContainer) {
            flutterwaveContainer.innerHTML += '<div id="fct_loading_payment_processor">' + this.$t(tempMessage) + '</div>';
            flutterwaveContainer.style.display = 'block';
            flutterwaveContainer.querySelector('#fct_loading_payment_processor').style.color = '#dc3545';
            flutterwaveContainer.querySelector('#fct_loading_payment_processor').style.fontSize = '14px';
            flutterwaveContainer.querySelector('#fct_loading_payment_processor').style.padding = '10px';
        }

        this.paymentLoader.hideLoader();
        this.paymentLoader?.enableCheckoutButton(this.submitButton?.text || this.$t('Place Order'));
    }
}

window.addEventListener("fluent_cart_load_payments_flutterwave", function (e) {
    const translate = window.fluentcart.$t;
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
        new FlutterwaveCheckout(e.detail.form, e.detail.orderHandler, response, e.detail.paymentLoader).init();
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
        errorDiv.style.color = 'red';
        errorDiv.style.padding = '10px';
        errorDiv.style.fontSize = '14px';
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
            const loadingMessage = document.createElement('p');
            loadingMessage.id = 'fct_loading_payment_processor';
            const translations = window.fct_flutterwave_data?.translations || {};
            function $t(string) {
                return translations[string] || string;
            }
            loadingMessage.textContent = $t('Loading Payment Processor...');
            flutterwaveButtonContainer.appendChild(loadingMessage);
        }
    }
});
