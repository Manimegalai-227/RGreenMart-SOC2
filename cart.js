function adjust(change) {
    let qty = document.getElementById('qty');
    qty.value = Math.max(1, parseInt(qty.value) + change);
}

// Add to Cart
function addToCart(item) {
    const cart = JSON.parse(localStorage.getItem("cart")) || [];
    
    // Normalize variant fields on incoming item so the cart stores consistent keys
    item.variant_id = item.variant_id ?? item.variantId ?? null;
    item.variant_weight = item.variant_weight ?? item.variantWeight ?? item.weight_value ?? item.weightValue ?? '';
    item.variant_unit = item.variant_unit ?? item.variantUnit ?? item.weight_unit ?? item.weightUnit ?? '';
    item.variant_price = Number(item.variant_price ?? item.variantPrice ?? item.price ?? 0);
    item.variant_old_price = item.variant_old_price ?? item.variantOldPrice ?? item.old_price ?? item.oldamt ?? null;
    item.variant_discount = item.variant_discount ?? item.variantDiscount ?? item.discount ?? item.discountRate ?? null;

    // Debug: log incoming item variant info
    console.log('addToCart called with item', {
        id: item.id ?? null,
        variant_id: item.variant_id ?? null,
        variant_price: item.variant_price ?? null,
        variant_weight: item.variant_weight ?? null,
        variant_unit: item.variant_unit ?? null
    });

    // Always safe: if cart is empty, this returns -1
    // Match existing item by item id + variant id (if provided)
    const existingIndex = cart.findIndex(cartItem => {
        // Compare ids as strings — safe across int/string type mismatch
        if (String(cartItem.id) !== String(item.id)) return false;

        const cartVar = (cartItem.variant_id == null) ? null : String(cartItem.variant_id);
        const itemVar = (item.variant_id     == null) ? null : String(item.variant_id);

        // Both have a variant id → must match exactly
        if (cartVar !== null && itemVar !== null) return cartVar === itemVar;

        // One or both have no variant id → treat as same product, just increment
        return true;
    });

    if (existingIndex !== -1) {
        // Item exists → increase qty (support qty or quantity)
        const inc = item.quantity ?? item.qty ?? 1;
        cart[existingIndex].quantity = (cart[existingIndex].quantity ?? cart[existingIndex].qty ?? 0) + Number(inc);
        // normalize key
        cart[existingIndex].qty = cart[existingIndex].quantity;
        // Ensure variant metadata is preserved on existing cart item
        cart[existingIndex].variant_weight = cart[existingIndex].variant_weight ?? item.variant_weight ?? '';
        cart[existingIndex].variant_unit = cart[existingIndex].variant_unit ?? item.variant_unit ?? '';
        cart[existingIndex].variant_price = cart[existingIndex].variant_price ?? item.variant_price ?? 0;
        cart[existingIndex].variant_old_price = cart[existingIndex].variant_old_price ?? item.variant_old_price ?? null;
        cart[existingIndex].variant_discount = cart[existingIndex].variant_discount ?? item.variant_discount ?? null;
    } else {
        // Item doesn't exist → push new
        // normalize incoming item fields
        item.quantity = item.quantity ?? item.qty ?? 1;
        item.qty = item.quantity;
        // ensure variant fields exist on the stored item
        item.variant_weight = item.variant_weight ?? '';
        item.variant_unit = item.variant_unit ?? '';
        item.variant_price = Number(item.variant_price ?? 0);
        item.variant_old_price = item.variant_old_price ?? null;
        item.variant_discount = item.variant_discount ?? null;
        cart.push(item);
    }

    localStorage.setItem("cart", JSON.stringify(cart));
    updateCartCount();
    showToast(item);
}

function loadCart() {
    const cartContainer = document.getElementById("cartItemsContainer");
    const cart = JSON.parse(localStorage.getItem("cart")) || [];

    cartContainer.innerHTML = "";

    if (cart.length === 0) {
        cartContainer.innerHTML = "<p>No items in cart</p>";

        document.getElementById("totalItems").textContent = 0;
        document.getElementById("totalQty").textContent = 0;
        document.getElementById("grandTotal").textContent = 0;
        return;
    }

    let grandTotal = 0;
    let totalQty = 0;

    cart.forEach((item, index) => {
        // Always use the stored selling price — never recalculate from old_price × discount
        // because floating-point math can produce a different value than the actual DB price.
        let unitPrice = Number(item.variant_price ?? item.price ?? 0);
        const qty = Number(item.quantity ?? item.qty ?? 0);
        const itemTotal = unitPrice * qty;

        grandTotal += itemTotal;
        totalQty += qty;

        const row = document.createElement("div");
        row.classList.add("cart-item-row");

        row.innerHTML = `
            <div class="cart-item-info">
                <img src="${item.image || './images/default.jpg'}" class="cart-img">
                <div class="floatright">
                    <h4 style="font-weight: 800"; font-size: 16px;">${item.name}</h4>
                    <span>${item.brand || ""}</span>
                    ${ (item.variant_weight || item.variant_unit) ? `<div class="variant">Weight: ${item.variant_weight || ''}${item.variant_unit ? ' ' + item.variant_unit : ''}</div>` : '' }
                    <div class="price-meta">

                        ${item.variant_old_price && Number(item.variant_old_price) > unitPrice 
                        ? `<span class="old-price">
                                ₹${Number(item.variant_old_price)}
                        </span>` 
                        : ''}

                        <span class="final-price">
                            ₹${Number(unitPrice)}
                        </span>

                        ${item.variant_discount 
                        ? `<span class="discount">
                                (${Math.floor(item.variant_discount)}% OFF)
                        </span>` 
                        : ''}

                    </div>
                    <br/>
                    <button class=" delete-btn" onclick="removeItem(${index})">
                               Remove
                           </button>
                </div>
            </div>

            <div class="qty custqty">
                <div class="qty-box">
                    ${qty > 1 
                        ? `<button class="qty-btn" onclick="changeQuantity(${index}, -1)">
                                <i class="fa-solid fa-minus"></i>
                           </button>`
                        : `<button class="qty-btn delete-btn" onclick="removeItem(${index})">
                                <i class="fa-solid fa-trash"></i>
                           </button>`
                    }
                    <hr class="hrline">
                    <input id="qty-${index}" class="qty-input" type="number" value=${qty} min="1" onchange="setQty(${index}, this.value)">
                    <hr class="hrline">
                    <button class="qty-btn" onclick="changeQuantity(${index}, 1)">
                        <i class="fa-solid fa-plus"></i>
                    </button>
                </div>
            </div>

            <div class="cart-item-amount">
                <strong class="m-1">₹</strong><span id="amount-${index}" class="itemtotal">${itemTotal.toFixed(2)}</span>
            </div>
        `;

        cartContainer.appendChild(row);
    });

    // Update summary values
    document.getElementById("totalItems").textContent = cart.length;
    document.getElementById("totalQty").textContent = totalQty;
    document.getElementById("grandTotal").textContent = grandTotal.toFixed(2);
}

function showToast(item) {
    const toastContainer = document.getElementById("toast-container");
    if (!toastContainer) return;

    const toast = document.createElement("div");
    toast.classList.add("mytoast");

    // Fallback for image
    const imgSrc = item.image || './images/default.jpg';
    const price = Number(item.variant_price ?? item.price ?? 0).toFixed(2);

    toast.innerHTML = `
        <img src="${imgSrc}" alt="${item.name}">
        <div class="mytoast-content">
            <div class="mytoast-status">Added to Cart</div>
            <div class="mytoast-title">${item.name}</div>
            <div class="mytoast-price">₹${price}</div>
        </div>
        <button onclick="window.location.href='/viewcart.php'">View Cart</button>
    `;

    toastContainer.appendChild(toast);

    // Auto remove after 3 seconds (10 is too long for a simple alert)
    setTimeout(() => {
        toast.style.animation = "slideUp 0.5s forwards";
        setTimeout(() => toast.remove(), 500);
    }, 5000);
}

// Update cart count
function updateCartCount() {
    const cart = JSON.parse(localStorage.getItem("cart")) || [];
    const totalItems = cart.reduce((sum, item) => sum + Number(item.quantity ?? item.qty ?? 0), 0);

    const countElement = document.getElementById("cartCount");
    if (countElement) {
        countElement.textContent = totalItems;
    }
}
function changeQuantity(index, change) {
    let cart = JSON.parse(localStorage.getItem("cart")) || [];
    
    let current = Number(cart[index].quantity ?? cart[index].qty ?? 0);
    let newQty = current + change;

    // If quantity becomes zero → remove item
    if (newQty <= 0) {
        const removedItem = cart[index].name;
        cart.splice(index, 1);
        showToastMessage(`${removedItem} removed from cart`);
    } else {
        cart[index].quantity = newQty;
        cart[index].qty = newQty;

        if (change > 0) {
            showToastMessage("1 item added");
        } else {
            showToastMessage("Item quantity decreased");
        }
    }

    localStorage.setItem("cart", JSON.stringify(cart));
    loadCart();
    updateCartCount();
}
function setQty(index, value) {
    let cart = JSON.parse(localStorage.getItem("cart")) || [];
    const newQty = Math.max(1, Number(value));
    cart[index].quantity = newQty;
    cart[index].qty = newQty;
    localStorage.setItem("cart", JSON.stringify(cart));
    loadCart();
    updateCartCount();
}

function removeItem(index) {
    let cart = JSON.parse(localStorage.getItem("cart")) || [];
    const removedItem = cart[index].name;

    cart.splice(index, 1);
    localStorage.setItem("cart", JSON.stringify(cart));

    showToastMessage(`${removedItem} removed from cart`);
    loadCart();
    updateCartCount();
}

function showToastMessage(message) {
    const toastContainer = document.getElementById("toast-container");
    if (!toastContainer) return console.error("Toast container missing!");
    const toast = document.createElement("div");
    toast.classList.add("mytoast");

    toast.innerHTML = `
        <div class="toast-text">${message}</div>
    `;

    toastContainer.appendChild(toast);

    // Auto disappear after 2 sec
    setTimeout(() => {
        toast.style.opacity = "0";
        toast.style.transform = "translateY(-20px)";
        setTimeout(() => toast.remove(), 400);
    }, 2000);
}

// Load count when page loads
document.addEventListener("DOMContentLoaded", updateCartCount);