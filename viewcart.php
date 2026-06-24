<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RGreenMart</title>
    <link rel="stylesheet" type="text/css" href="./Styles.css">
    <script src="./cart.js"></script>
    <style>

header,
.header,
.site-header {
    width: 100%;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
}

.cartsplit {
    display: flex;
    gap: 30px;
    padding: 20px 40px;
    align-items: stretch;
}

.itemscontainer {
    flex: 2;
    background: #fff;
    padding: 20px;
    border-radius: 10px;
}

.checkoutcontainer {
    flex: 1;
    background: #fff;
    padding: 20px;
    border-radius: 10px;
    position: sticky;
    top: 20px;
    height: fit-content;
}

.cart-item-row {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr;
    align-items: center;
    padding: 15px 0;
    border-bottom: 1px solid #eee;
}

.cart-item-info {
    display: flex;
    gap: 15px;
    align-items: center;
}

.cart-img {
    width: 80px;
    height: 80px;
    object-fit: cover;
    border-radius: 8px;
}

.cart-item-amount {
    font-weight: 600;
    font-size: 16px;
}

@media (max-width: 768px) {

    .cartsplit {
        display: grid !important;
        grid-template-columns: 1fr !important; /* Force single column */
    }

    .itemscontainer,
    .checkoutcontainer {
        grid-column: 1 / -1;  /* Force full width */
        width: 100%;
    }

    .checkoutcontainer {
        position: static !important;
        margin-top: 20px;
    }

    .cart-item-row {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }

    .col3 {
        display: none;
    }
}
    </style>
</head>
<body>

    <?php require_once $_SERVER["DOCUMENT_ROOT"] . "/includes/header.php"; ?>
    <div class="cartsplit">
        <div class="itemscontainer">
        <div class="col3">
            <h6 class="heading">Items</h6>
            <h6 class="heading">Quantity</h6>
            <h6 class="heading">Amount</h6>
        </div>

        <div  id="cartItemsContainer"></div>
        </div>
        <div class="checkoutcontainer">
            <h3 class="summary-title">Order Summary</h3>
            <p>Total Items: <span id="totalItems">0</span></p>
            <p>Total Quantity: <span id="totalQty">0</span></p>
            <p>Total Amount: <span id="grandTotal" class="finalTotal">0</span></p>
            <button onclick="window.location.href='add_delivery_address.php'" 
                    class="checkout-btn w-full text-white font-semibold py-3 px-6  transition-all duration-300"
                    style="background: linear-gradient(135deg, #e91e63, #6a1b9a); border: none;">
                Checkout
            </button>
        </div>

    </div>
 <?php require_once $_SERVER["DOCUMENT_ROOT"] ."/includes/footer.php"; ?>
    
    <div id="toast-container"></div>
    <script>
loadCart();
/** Update Quantity (+ or -) */
function updateQty(index, change) {
    let cart = JSON.parse(localStorage.getItem("cart")) || [];

    cart[index].qty = Math.max(1, Number(cart[index].qty) + change);
    localStorage.setItem("cart", JSON.stringify(cart));

    loadCart();
    updateCartCount();
}

/** Manually change qty using input */
function setQty(index, value) {
    let cart = JSON.parse(localStorage.getItem("cart")) || [];

    cart[index].qty = Math.max(1, Number(value));
    localStorage.setItem("cart", JSON.stringify(cart));

    loadCart();
    updateCartCount();
}

/** Update cart badge count */
function updateCartCount() {
    const cart = JSON.parse(localStorage.getItem("cart")) || [];
    const totalQty = cart.reduce((sum, item) => sum + Number(item.qty), 0);

    const countElement = document.getElementById("cartCount");
    if (countElement) countElement.textContent = totalQty;
}

document.addEventListener("DOMContentLoaded", () => {
    loadCart();
    updateCartCount();
});
</script>
</body>
</html>
