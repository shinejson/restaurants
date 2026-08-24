// Cart Management System
class Cart {
    constructor() {
        this.cartKey = 'food_cart';
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        this.init();
    }

    init() {
        this.loadCart();
        this.checkClearCart();
        this.updateCartCount();
        this.setupEventListeners();
        this.initialSync();
    }

    checkClearCart() {
        // Automatically clear client-side cart when landing on the confirmation page
        if (window.location.pathname.includes('order_confirmation.php')) {
            console.log('Order confirmed. Clearing cart...');
            this.clearCart();
        }
    }

    async initialSync() {
        // If we have items in localStorage but the server session is empty
        // (can happen if session expires or user clears cookies), we should sync.
        const headerCount = parseInt(document.getElementById('cartCount')?.textContent || '0');
        
        if (headerCount === 0 && this.cart.length > 0) {
            console.log('Performing initial sync with server...');
            for (const item of this.cart) {
                // Use 'update' to set exact quantities and avoid duplication
                await this.syncWithServer('update', item.id, item.quantity);
            }
            // Optional: refresh page if on cart.php to show the synced items
            if (window.location.pathname.includes('cart.php')) {
                window.location.reload();
            }
        }
    }

    loadCart() {
        const cartData = localStorage.getItem(this.cartKey);
        this.cart = cartData ? JSON.parse(cartData) : [];
    }

    saveCart() {
        localStorage.setItem(this.cartKey, JSON.stringify(this.cart));
        this.updateCartCount();
        this.dispatchCartUpdate();
    }

    updateCartCount() {
        const count = this.cart.reduce((total, item) => total + item.quantity, 0);
        const cartCountEl = document.getElementById('cartCount');
        if (cartCountEl) {
            cartCountEl.textContent = count;
        }
    }

    async addItem(item) {
        const existingItem = this.cart.find(cartItem => cartItem.id === item.id);
        
        if (existingItem) {
            existingItem.quantity += item.quantity;
        } else {
            this.cart.push({
                id: item.id,
                name: item.name,
                price: parseFloat(item.price),
                quantity: item.quantity,
                image: item.image,
                specialRequests: item.specialRequests || ''
            });
        }
        
        this.saveCart();
        
        // Sync with server session
        await this.syncWithServer('add', item.id, item.quantity);
        
        this.showNotification('Item added to cart!', 'success');
    }

    async updateQuantity(itemId, quantity) {
        if (quantity < 1) {
            await this.removeItem(itemId);
            return;
        }

        const item = this.cart.find(item => item.id === itemId);
        if (item) {
            item.quantity = quantity;
            this.saveCart();
            await this.syncWithServer('update', itemId, quantity);
        }
    }

    async removeItem(itemId) {
        this.cart = this.cart.filter(item => item.id !== itemId);
        this.saveCart();
        await this.syncWithServer('remove', itemId);
        this.showNotification('Item removed from cart', 'info');
    }

    async syncWithServer(action, itemId, quantity = 1) {
        try {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('item_id', itemId);
            formData.append('quantity', quantity);
            formData.append('csrf', this.csrfToken);

            const response = await fetch(`${BASE_URL}/cart.php`, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) throw new Error('Network response was not ok');
            
            const data = await response.json();
            if (data.success) {
                // Server and local are in sync
                console.log('Cart synced with server');
            }
        } catch (error) {
            console.error('Error syncing cart:', error);
        }
    }

    clearCart() {
        this.cart = [];
        localStorage.removeItem(this.cartKey);
        this.updateCartCount();
        this.dispatchCartUpdate();
    }

    getTotal() {
        return this.cart.reduce((total, item) => total + (item.price * item.quantity), 0);
    }

    getCartItems() {
        return [...this.cart];
    }

    setupEventListeners() {
        // Add to cart buttons
        document.addEventListener('click', (e) => {
            if (e.target.closest('.add-to-cart-btn')) {
                const button = e.target.closest('.add-to-cart-btn');
                this.handleAddToCart(button);
            }
        });

        // Quantity controls in cart page
        document.addEventListener('click', (e) => {
            if (e.target.closest('.quantity-btn')) {
                const button = e.target.closest('.quantity-btn');
                this.handleQuantityChange(button);
            }
        });

        // Remove item buttons
        document.addEventListener('click', (e) => {
            if (e.target.closest('.remove-item')) {
                const button = e.target.closest('.remove-item');
                this.handleRemoveItem(button);
            }
        });
        
        // Listen for standard number input changes too
        document.addEventListener('change', (e) => {
            if (e.target.classList.contains('quantity')) {
                const input = e.target;
                const itemId = input.dataset.id;
                const quantity = parseInt(input.value);
                this.updateQuantity(itemId, quantity);
                this.updateCartDisplay();
            }
        });
    }

    handleAddToCart(button) {
        const item = {
            id: button.dataset.id,
            name: button.dataset.name,
            price: button.dataset.price,
            quantity: 1,
            image: button.dataset.image
        };

        this.addItem(item);
        
        // Visual feedback
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fas fa-check"></i> Added!';
        button.classList.add('added');
        
        setTimeout(() => {
            button.innerHTML = originalText;
            button.classList.remove('added');
        }, 2000);
    }

    handleQuantityChange(button) {
        const itemId = button.dataset.itemId || button.dataset.id;
        const action = button.dataset.action;
        const quantityInput = document.querySelector(`.quantity[data-id="${itemId}"]`) || document.querySelector(`.quantity-input[data-item-id="${itemId}"]`);
        
        if (!quantityInput) return;
        
        let quantity = parseInt(quantityInput.value);
        
        if (action === 'increase') {
            quantity++;
        } else if (action === 'decrease') {
            quantity--;
        }
        
        if (quantity > 0) {
            quantityInput.value = quantity;
            this.updateQuantity(itemId, quantity);
            this.updateCartDisplay();
        }
    }

    handleRemoveItem(button) {
        const itemId = button.dataset.id || button.dataset.itemId;
        if (confirm('Are you sure you want to remove this item from your cart?')) {
            this.removeItem(itemId);
            
            // Remove from DOM if on cart page
            const tableRow = button.closest('tr');
            if (tableRow) {
                tableRow.remove();
                this.updateCartDisplay();
                
                // If cart is empty now, reload to show empty state
                if (this.cart.length === 0) {
                    location.reload();
                }
            }
        }
    }

    updateCartDisplay() {
        // Update cart totals on cart page
        const totalEl = document.getElementById('total');
        
        if (totalEl) {
            const total = this.getTotal();
            // Format to GH₵
            totalEl.textContent = `GH₵${total.toFixed(2)}`;
            
            // Update individual subtotal rows if they exist
            this.cart.forEach(item => {
                const subtotalCell = document.querySelector(`tr:has(.quantity[data-id="${item.id}"]) .item-total`);
                if (subtotalCell) {
                    subtotalCell.textContent = `GH₵${(item.price * item.quantity).toFixed(2)}`;
                }
            });
        }
    }

    showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'}"></i>
                <span>${message}</span>
            </div>
        `;
        
        // Add styles
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: ${type === 'success' ? '#28a745' : '#17a2b8'};
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 9999;
            animation: slideIn 0.3s ease;
        `;
        
        document.body.appendChild(notification);
        
        // Remove after 3 seconds
        setTimeout(() => {
            notification.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }

    dispatchCartUpdate() {
        const event = new CustomEvent('cartUpdated', { detail: { cart: this.cart } });
        document.dispatchEvent(event);
    }
}

// Initialize cart when DOM is loaded
function initCart() {
    window.cart = new Cart();
    
    // Add CSS for notifications
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
    `;
    document.head.appendChild(style);
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { Cart, initCart };
}
