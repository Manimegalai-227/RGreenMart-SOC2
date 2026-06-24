const itemsData = <?php echo json_encode($processedItems); ?>;
const gstRate = <?php echo $gstRate; ?>;
const selectors = {
    qtyInputs: document.querySelectorAll('.qty'),
    tableRows: document.querySelectorAll('#productsTable tbody tr'),
    mobileCards: document.querySelectorAll('.mobile-card'),
    totalDisplay: document.getElementById('total'),
    discountTotalDisplay: document.getElementById('discountTotal'),
    netRateDisplay: document.getElementById('netRate'),
    couponDiscountDisplay: document.getElementById('couponDiscount'),
    gstDisplay: document.getElementById('gst'),
    sumgstDisplay: document.getElementById('sumgst'),
    overallTotalDisplay: document.getElementById('overallTotal'),
    finalTotalDisplay: document.getElementById('finalTotal'),
    totalProductsCount: document.querySelector('.total_products_count'),
    discountTotalSpan: document.querySelector('.discount_total'),
    subTotalSpan: document.querySelector('.sub_total'),
    applyCouponButton: document.getElementById('applyCoupon'),
    couponCodeInput: document.getElementById('couponCode'),
    couponCodeHidden: document.getElementById('couponCodeHidden'),
    couponDiscountHidden: document.getElementById('couponDiscountHidden'),
    couponDiscountPercentHidden: document.getElementById('couponDiscountPercentHidden'),
    customerDetailsForm: document.getElementById('customerDetailsForm'),
    categoryButtons: document.querySelector('.category-buttons'),
    brandButtons: document.querySelectorAll('.brand-button'),
    imageModal: document.getElementById('imageModal'),
    modalImage: document.getElementById('modalImage'),
    modalClose: document.querySelector('.modal-close'),
    categoryButtonsContainer: document.querySelector('.category-buttons'),
};

function debounce(func, wait) {
    let timeout;
    return function (...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

function setupLazyLoading() {
    const images = document.querySelectorAll('img[data-src]');
    const observer = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.dataset.src;
                img.classList.add('loaded');
                img.removeAttribute('data-src');
                observer.unobserve(img);
            }
        }, { rootMargin: '0px 0px 100px 0px', threshold: 0.1 });
        images.forEach(img => observer.observe(img));
}

function updateQuantity(idx, change) {
    selectors.qtyInputs.forEach(input => {
        if (input.dataset.idx === idx) {
            const currentValue = parseInt(input.value) || 0;
            input.value = Math.max(0, currentValue + change);
        }
    });
    debouncedRecalcTotals();
}

function resetQuantities() {
    selectors.qtyInputs.forEach(input => input.value = 0);
    document.querySelectorAll('.action-button').forEach(button => button.disabled = true);
    document.getElementById('couponCode').value = '';
    document.getElementById('couponCodeHidden').value = '';
    document.getElementById('couponDiscountHidden').value = '0';
    document.getElementById('couponDiscountPercentHidden').value = '0';
    debouncedRecalcTotals();
}

function recalcTotals() {
    let subtotal = 0, totalDiscountAmount = 0, totalNetRate = 0, totalGst = 0, totalItems = 0;
    const items = [];

    Object.entries(itemsData).forEach(([idx, item]) => {
        const qtyInput = selectors.qtyInputs.find(input => input.dataset.idx === idx);
        const qty = qtyInput ? parseInt(qtyInput.value) || 0 : 0;
        if (qty === 0) return;

        const { grossPrice, discountAmount, netPrice, simpleDiscountedPrice } = item;
        const itemTotal = simpleDiscountedPrice * qty;

        selectors.tableRows.forEach(row => {
            if (row.dataset.idx === idx) {
                row.querySelector('.item-total').textContent = itemTotal.toFixed(2);
            }
        });
        selectors.mobileCards.forEach(card => {
            if (card.dataset.idx === idx) {
                card.querySelector('.item-total').textContent = itemTotal.toFixed(2);
            }
        });

        subtotal += grossPrice * qty;
        totalDiscountAmount += discountAmount * qty;
        totalNetRate += (netPrice - discountAmount) * qty;
        totalGst += ((netPrice - discountAmount) * (gstRate / 100)) * qty;
        totalItems += qty;

        items.push({
            name: item.name,
            grossPrice,
            quantity: qty,
            discount: item.discountRate
        });
    });

    const couponDiscount = parseFloat(document.getElementById('couponDiscountHidden').value) || 0;
    const couponDiscountPercent = parseFloat(document.getElementById('couponDiscountPercentHidden').value) || 0;
    const updatedCouponDiscount = couponDiscountPercent > 0 ? (totalNetRate * couponDiscountPercent) / 100 : couponDiscount;
    document.getElementById('couponDiscountHidden').value = updatedCouponDiscount.toFixed(2);

    selectors.totalDisplay.textContent = `₹${subtotal.toFixed(2)}`;
    selectors.discountTotalDisplay.textContent = `₹${totalDiscountAmount.toFixed(2)}`;
    selectors.netRateDisplay.textContent = `₹${totalNetRate.toFixed(2)}`;
    selectors.gstDisplay.textContent = `₹${totalGst.toFixed(2)}`;
    selectors.sumgstDisplay.textContent = `₹${totalGst.toFixed(2)}`;
    selectors.overallTotalDisplay.textContent = `₹${(totalNetRate + totalGst).toFixed(2)}`;
    selectors.finalTotalDisplay.textContent = `₹${(totalNetRate + (couponDiscountPercent > 0 ? (totalNetRate * couponDiscountPercent / 100) : totalNetRate).toFixed(2)}`;
    selectors.totalProductsCount.textContent = totalItems;
    selectors.netTotalSpan.textContent = totalNetRate.toFixed(2);
    selectors.discountTotalSpan.textContent = totalDiscountAmount.toFixed(2);
    selectors.subTotalSpan.textContent = (totalNetRate + totalGst).toFixed(2);

    return items;
}

function applyCoupon() {
    const couponCode = document.getElementById('couponCode').value.trim();
    if (!couponCode) {
        alert('Please enter a coupon code.');
        return;
    }

    fetch('checkcoupon.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'coupon_code=' + encodeURIComponent(couponCode)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const netRate = parseFloat(selectors.netRateDisplay.textContent.replace('₹', '').replace(',', '')) || 0;
            const couponDiscount = (netRate * data.discount_percent) / 100;
            document.getElementById('couponCodeHidden').value = couponCode;
            document.getElementById('couponDiscountHidden').value = couponDiscount.toFixed(2);
            document.getElementById('couponDiscountPercentHidden').value = data.discount_percent;
            alert('Coupon applied successfully! Discount: ' + data.discount_percent + '%');
        } else {
            document.getElementById('couponCodeHidden').value = '';
            document.getElementById('couponDiscountHidden').value = '0';
            document.getElementById('couponDiscountPercentHidden').value = '0';
            alert('Invalid or expired coupon code.');
        }
        debouncedRecalcTotals();
    })
    .catch(error => {
        console.error('Error checking coupon:', error);
        alert('Error applying coupon. Please try again.');
        document.getElementById('couponCodeHidden').value = '';
        document.getElementById('couponDiscountHidden').value = '0';
        document.getElementById('couponDiscountPercentHidden').value = '0';
        debouncedRecalcTotals();
    });
}

function collectItems() {
    const items = [];
    Object.entries(itemsData).forEach(([idx, item]) => {
        const qtyInput = selectors.qtyInputs.find(input => input.dataset.idx === idx);
        const quantity = qtyInput ? parseInt(qtyInput.value) || 0 : 0;
        if (quantity > 0) {
            items.push({
                name: item.name,
                grossPrice: item.grossPrice,
                quantity,
                discount: item.discountRate
            });
        }
    });
    return items;
}

function updateCategoryButtons(selectedBrand) {
    const categories = [...new Set(Object.values(itemsData).filter(item => selectedBrand === 'all' || item.brand === selectedBrand).map(item => item.category_raw)].sort();
    selectors.categoryButtons.innerHTML = categories.map((category, index) => `
        <button type="button" class="category-button${index === 0 ? ' active' : ''}" data-category="${category}">
            ${category.replace(/&quot;/g, '"')}
        </button>
    `).join('');
    document.getElementById('categoryTitle').textContent = categories[0] ? categories[0].replace(/&quot;/g, '"').charAt(0).toUpperCase() + categories[0].slice(1).toLowerCase() : '';
    selectors.categoryButtons.querySelectorAll('.category-button').forEach(button => {
        button.addEventListener('click', () => {
            selectors.categoryButtons.querySelectorAll('.category-button').forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');
            applyFilters();
        });
    });
}

function attachEventListeners() {
    document.querySelector('.table-container').addEventListener('click', handleQuantityClick);
    document.querySelector('.mobile-cards').addEventListener('click', handleQuantityClick);
    document.querySelector('.table-container').addEventListener('input', handleQuantityInput);
    document.querySelector('.mobile-cards').addEventListener('input', handleQuantityInput);
    selectors.applyCouponButton.addEventListener('click', applyCoupon);
    selectors.customerDetailsForm.addEventListener('submit', handleFormSubmit);
    document.querySelectorAll('.image-container').forEach(container => {
        container.addEventListener('click', () => {
            const imgPath = container.dataset.imgPath;
            if (imgPath) {
                document.getElementById('imageModal').style.display = 'flex';
                document.getElementById('modalImage').src = imgPath;
            }
        });
    });
    document.querySelector('.modal-close').addEventListener('click', () => {
        document.getElementById('imageModal').style.display = 'none';
    });
    document.querySelector('.modal').addEventListener('click', (event) => {
        if (event.target === document.querySelector('.modal')) {
            document.getElementById('imageModal').style.display = 'none';
        }
    });
    setupLazyLoading();
}

window.addEventListener('load', () => {
    setupLazyLoading();
    selectors.brandButtons[0]?.click();
    attachEventListeners();
});
</script>
<xaiArtifact artifact_id="6071eb82-2a6d-4193-ae04-8b5cd9482bfb" artifact_version_id="a7699c35-b94f-4390-bad6-3bf2c992d0c5" title="products.min.css" contentType="text/css">
.sticky-header,.topbar,.section-title,.brand-buttons,.category-buttons,.category-title-container,.table-container,.mobile-cards,.details-container,.summary,.price-container,.quantity-controls,.total-container,.quantity-total-container,.minimum-order,.footer-section,.card,.modal,.modal-content{box-sizing:border-box}.topbar{display:none}@media (min-width:769px){.topbar{display:flex}.mobile-cards{display:none}@media (max-width:768px){.mobile-cards{display:block}.table-container{display:none}}.category-title-container{margin-bottom:20px}#categoryTitle{font-size:1.8rem;text-transform:capitalize;color:#1f2937;text-align:center}.continue-buttons{background:linear-gradient(to right,#dc2626,#f97316);color:#fff;border-radius:9999px;width:20%;margin-bottom:5%;font-weight:bold;transition:all .2s;border:none;cursor:pointer}.coupon-input{height:40%}#applyCoupon.w-25{}@media (max-width:600px){.continue-buttons{width:100%}}.image-container{position:relative}img.lazy-img{opacity:0;transition:opacity .3s ease}img.lazy-img.loaded{opacity:1}.discount-badge{position:absolute;top:5px;left:5px;color:#fff;background:#dc2626;border-radius:8px;padding:5px 10px;font-size:.9rem}.quantity-controls{display:flex;align-items:center;gap:8px}button{width:35px;height:35px;display:flex;align-items:center;justify-content:center;border:1px solid #ccc;border-radius:5px;background:#f9f9f9;cursor:pointer;touch-action:manipulation}.total-container{text-align:right}.item-total{font-weight:bold;color:#1f2937}.price-container .price{font-size:.9rem}.discounted-price{font-size:.9rem}.discount-amount,.gst-amount{display:none}.flex{display:flex}.items-start{align-items:flex-start}.space-x-4>*+*{margin-left:1rem}.justify-between{justify-content:space-between}.items-center{align-items:center}.mb-4{margin-bottom:1rem}.flex-1{flex:1}.w-75{width:75%}.w-25{width:25%}@media (max-width:768px){.quantity-controls button{width:30px;height:30px}.quantity-controls input{width:45px;height:30px;text-align:center;border:1px solid #ccc;border-radius:5px;font-size:.8rem}.total-container p{font-size:.8rem}.modal{display:none}.modal-content{width:60%;height:60%;margin:0 auto;margin-top:5%;padding:20px}