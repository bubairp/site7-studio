/**
 * Payment Module for RP Craft
 * Handles selection modal and payment gateway coordination
 */
export default class Payments {
    constructor() {
        this.modal = document.getElementById('paymentModal');
        this.payButton = document.getElementById('confirmPaymentBtn');
        this.init();
    }

    init() {
        if (!this.modal) return;

        // Listen for Alpine.js custom hydrated event to populate checkout data
        window.addEventListener('payment-modal-hydrated', (event) => {
            // Store current data for final submission
            this.currentData = {
                amount: event.detail.amount,
                currency: event.detail.currency,
                plan: event.detail.plan
            };
        });

        // Handle the final "Proceed to Pay" button
        if (this.payButton) {
            this.payButton.addEventListener('click', () => this.handlePayment());
        }
    }

    async handlePayment() {
        const nameInput = this.modal.querySelector('#checkoutName');
        const emailInput = this.modal.querySelector('#checkoutEmail');
        const phoneInput = this.modal.querySelector('#checkoutPhone');

        const userName = nameInput ? nameInput.value.trim() : '';
        const userEmail = emailInput ? emailInput.value.trim() : '';
        const userPhone = phoneInput ? phoneInput.value.trim() : '';

        let isValid = true;

        if (nameInput) {
            if (!userName) {
                nameInput.classList.add('is-invalid');
                isValid = false;
            } else {
                nameInput.classList.remove('is-invalid');
            }
        }

        if (emailInput) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!userEmail || !emailRegex.test(userEmail)) {
                emailInput.classList.add('is-invalid');
                isValid = false;
            } else {
                emailInput.classList.remove('is-invalid');
            }
        }

        if (phoneInput) {
            if (!userPhone) {
                phoneInput.classList.add('is-invalid');
                isValid = false;
            } else {
                phoneInput.classList.remove('is-invalid');
            }
        }

        if (!isValid) {
            return;
        }

        const selectedGateway = this.modal.querySelector('input[name="paymentGateway"]:checked');
        const isFree = this.currentData.amount === 0;

        if (!isFree && !selectedGateway) {
            alert('Please select a payment gateway.');
            return;
        }

        const gateway = isFree ? 'free' : selectedGateway.value;

        const csrfToken = document.querySelector('input[name="CRAFT_CSRF_TOKEN"]')?.value
            || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        const data = {
            ...this.currentData,
            customerName: userName,
            customerEmail: userEmail,
            customerPhone: userPhone,
            gateway: gateway,
            CRAFT_CSRF_TOKEN: csrfToken
        };

        const originalText = this.payButton.innerHTML;
        this.payButton.disabled = true;
        this.payButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';

        try {
            // 1. Create Order on Server
            const response = await fetch('/payment-gateway/create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            let result;
            try {
                result = await response.json();
            } catch (e) {
                const errorText = await response.text();
                throw new Error(`Server error: ${response.status} ${response.statusText}. ${errorText}`);
            }

            if (!response.ok || !result.success) {
                alert(result.message || 'Payment initialization failed');
                this.resetButton(originalText);
                return;
            }

            if (isFree && result.success) {
                // Free checkout completed directly via backend
                window.location.href = '/payment-gateway/success?gateway=free';
                return;
            }

            // 2. Load Gateway SDK and Handle Redirection
            if (result.order && result.order.redirect_url) {
                window.location.href = result.order.redirect_url;
                return;
            }

            if (gateway === 'razorpay') {
                this.handleRazorpay(result.order, data, originalText);
            } else if (gateway === 'stripe') {
                this.handleStripe(result.order, data, originalText);
            } else {
                alert(`Gateway ${gateway} is not fully configured for redirection.`);
                this.resetButton(originalText);
            }

        } catch (error) {
            console.error('Payment Error:', error);
            alert(error.message || 'An unexpected error occurred during payment initialization.');
            this.resetButton(originalText);
        }
    }

    handleRazorpay(order, data, originalText) {
        if (typeof Razorpay === 'undefined') {
            alert('Razorpay SDK not loaded. Please ensure the script is included in your template.');
            this.resetButton(originalText);
            return;
        }

        const options = {
            "key": order.key_id,
            "amount": order.amount * 100, // in paise
            "currency": order.currency,
            "name": data.plan || "Payment",
            "order_id": order.order_id,
            "prefill": {
                "name": order.customerName || data.customerName,
                "email": order.customerEmail || data.customerEmail,
                "contact": order.customerPhone || data.customerPhone
            },
            "handler": async (response) => {
                // 3. Verify on server (JS Fallback/Manual success)
                const verifyRes = await fetch('/payment-gateway/verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        gateway: 'razorpay',
                        order_id: order.order_id,
                        payment_id: response.razorpay_payment_id,
                        signature: response.razorpay_signature,
                        CRAFT_CSRF_TOKEN: data.CRAFT_CSRF_TOKEN
                    })
                });

                const verifyResult = await verifyRes.json();
                if (verifyResult.success) {
                    window.location.href = `/payment-gateway/success?gateway=razorpay&order_id=${order.order_id}&payment_id=${response.razorpay_payment_id}`;
                } else {
                    window.location.href = `/payment-gateway/success?gateway=razorpay&order_id=${order.order_id}&payment_id=${response.razorpay_payment_id}`;
                }
            },
            "modal": {
                "ondismiss": () => {
                    window.location.href = `/payment-gateway/cancel?gateway=razorpay&order_id=${order.order_id}`;
                }
            }
        };

        const rzp = new Razorpay(options);
        rzp.open();
        
        // Start polling for status (especially for QR/UPI flows)
        this.startPaymentPolling(order.order_id);
    }

    startPaymentPolling(orderId) {
        let attempts = 0;

        const interval = setInterval(async () => {
            attempts++;

            try {
                const res = await fetch(`/payment-gateway/check-status?order_id=${orderId}`);
                const data = await res.json();

                if (data.status === 'paid') {
                    clearInterval(interval);
                    window.location.href = `/payment-gateway/success?gateway=razorpay&order_id=${orderId}`;
                }

                // stop after ~2 minutes (40 * 3s)
                if (attempts > 40) {
                    clearInterval(interval);
                    console.warn('Payment polling timeout');
                }

            } catch (err) {
                console.error('Polling error', err);
            }

        }, 3000);
    }

    async handleStripe(order, data, originalText) {
        if (order.redirect_url) {
            window.location.href = order.redirect_url;
        } else {
            alert('Stripe redirect URL not found.');
            this.resetButton(originalText);
        }
    }

    resetButton(text) {
        this.payButton.disabled = false;
        this.payButton.innerHTML = text;
    }
}
