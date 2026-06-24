<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";
try {
    $stmt = $conn->prepare("SELECT * FROM admin_details LIMIT 1");
    $stmt->execute();
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);

    $address = $contact['shopaddress'] ?? 'Madurai, Tamil Nadu';
    $mobile  = $contact['phone'] ?? '';
    $office  = $contact['office'] ?? '';
    $email   = $contact['email'] ?? 'sales@rgreenmart.com';

} catch (PDOException $e) {
    // Handle error gracefully
    echo "Database error: " . $e->getMessage();
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About RGreenMart </title>
    <script src="../cart.js"></script>
    <link rel="icon" type="image/png" href="../images/LOGO.jpg">
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 1rem;
        }
        #about {
            padding: 5rem 0;
            background: linear-gradient(to bottom, #ffffff, rgba(239, 68, 68, 0.1));
        }
        .about-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 3rem;
            align-items: center;
        }
        @media (min-width: 1024px) {
            .about-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        .content-side {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }
        .content-side h2 {
            font-size: 2.25rem;
            font-weight: bold;
            color: #dc2626; /* red-600 */
            margin-bottom: 1.5rem;
            line-height: 1.2;
        }
        @media (min-width: 768px) {
            .content-side h2 {
                font-size: 3rem;
            }
        }
        .content-side p {
            font-size: 1.125rem;
            color: #374151; /* gray-700 */
            line-height: 1.75;
            margin-bottom: 2rem;
        }
        .content-side p:last-child {
            color: #4b5563; /* gray-600 */
        }
        .highlights-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }
        @media (min-width: 768px) {
            .highlights-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        .highlight-card {
            background: #ffffff;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border: 2px solid transparent;
            transition: all 0.3s;
        }
        .highlight-card:hover {
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
            border-color: #fee2e2; /* red-200 */
        }
        .highlight-card .icon-container {
            background: linear-gradient(to right, #dc2626, #f97316); /* red-500 to orange-400 */
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            transition: transform 0.3s;
        }
        .highlight-card:hover .icon-container {
            transform: scale(1.1);
        }
        .highlight-card svg {
            width: 1.75rem;
            height: 1.75rem;
            color: #ffffff;
        }
        .highlight-card h3 {
            font-weight: bold;
            color: #1f2937; /* gray-800 */
            text-align: center;
            margin-bottom: 0.5rem;
        }
        .highlight-card p {
            font-size: 0.875rem;
            color: #4b5563; /* gray-600 */
            text-align: center;
            line-height: 1.5;
        }
        .image-side {
            position: relative;
        }
        .image-container {
            position: relative;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.2);
        }
        .image-container img {
            width: 100%;
            height: 400px;
            object-fit: cover;
        }
        .image-container .gradient-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(155, 28, 28, 0.3), transparent);
        }
        .celebration {
            position: relative;
            border-radius: 1rem;
            margin-bottom: 2rem;
            overflow: hidden;
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.2);
        }
        .celebration img {
            width: 100%;
            height: 400px;
            object-fit: cover;
        }
        .celebration .gradient-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(155, 28, 28, 0.3), transparent);
        }
        .stat-card {
            position: absolute;
            background: #ffffff;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.2);
            text-align: center;
        }
        .stat-card.years {
            bottom: -1.5rem;
            left: -1.5rem;
            border: 4px solid #064e3b; /* red-100 */
        }
        .stat-card.customers {
            top: -1.5rem;
            right: -1.5rem;
            color: #ffffff;
        }
        .stat-card .value {
            font-size: 1.875rem;
            font-weight: bold;
            color: #064e3b; /* red-600 */
        }
        .stat-card.customers .value {
            color: #ffffff;
        }
        .stat-card .label {
            font-size: 0.875rem;
            color: #4b5563; /* gray-600 */
        }
        .stat-card.customers .label {
            color: #064e3b; /* green-100 */
        }
        .contact-section {
            padding: 5rem 0;
            background: #f5f5f5;
        }
        .contact-container {
            max-width: 1100px;
            margin: 0 auto;
            background: #ffffff;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            border-radius: 0.5rem;
            padding: 2.5rem;
        }
        .contact-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 2.5rem;
        }
        .contact-form {
            flex: 2;
            min-width: 300px;
        }
        .contact-form h2 {
            font-size: 2rem;
            font-weight: bold;
            color: #064e3b; /* red-600 */
            margin-bottom: 1.5rem;
        }
        .form-group {
            display: flex;
            gap: 1.25rem;
            margin-bottom: 1rem;
        }
        .contact-form input,
        .contact-form textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d5db; /* gray-300 */
            border-radius: 0.5rem;
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        .contact-form input:focus,
        .contact-form textarea:focus {
            border-color: #064e3b; /* red-600 */
            outline: none;
        }
        .contact-form textarea {
            height: 100px;
            resize: vertical;
        }
        .contact-form button {
            background: linear-gradient(to right, #064e3b, #f97316); /* red-600 to orange-400 */
            color: #ffffff;
            font-weight: bold;
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .contact-form button:hover {
            box-shadow: 0 4px 8px rgba(239, 68, 68, 0.2);
        }
        .contact-info {
            flex: 1;
            min-width: 250px;
            background: #f7f7fa;
            border-radius: 0.5rem;
            padding: 1.5rem;
        }
        .contact-info h3 {
            font-size: 1.5rem;
            font-weight: bold;
            color: #064e3b; /* red-600 */
            margin-bottom: 1rem;
        }
        .contact-info p {
            font-size: 1rem;
            color: #4b5563; /* gray-600 */
            margin-bottom: 0.5rem;
        }
        .contact-info div {
            margin-bottom: 0.75rem;
        }
        .contact-info b {
            color: #1f2937; /* gray-800 */
        }
        .map-section {
            background: #ffffff;
            padding: 2rem 0;
        }
        .map-section iframe {
            width: 100%;
            height: 250px;
            border: 0;
        }
        @media (max-width: 767px) {
            .contact-grid {
                flex-direction: column;
            }
            .form-group {
                flex-direction: column;
                gap: 0.75rem;
            }
            .contact-form h2 {
                font-size: 1.75rem;
            }
            .contact-info h3 {
                font-size: 1.25rem;
            }
        }
        .stat-box {
            background: #fff;
            border: 1px solid #064e3b; /* light red border */
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border-radius: 12px;
            padding: 24px;
            transition: transform 0.2s ease;
        }
        .stat-box:hover {
            transform: translateY(-4px);
        }
        .stat-box .value {
            font-size: 2rem;
            font-weight: bold;
            color: #064e3b; /* red-600 */
        }
        .stat-box .label {
            margin-top: 8px;
            font-size: 1rem;
            color: #4b5563; /* gray-600 */
        }
        .vision-values, .why-choose {
            padding: 2rem 0;
            text-align: center;
        }
        .vision-values h3, .why-choose h3 {
            font-size: 2rem;
            font-weight: bold;
            color: var(--accent); /* red-600 */
            margin-bottom: 1.5rem;
        }
        .vision-values ul, .why-choose ul {
            list-style: none;
            padding: 0;
            font-size: 1.125rem;
            color: #374151; /* gray-700 */
            line-height: 1.75;
        }
        .vision-values ul li, .why-choose ul li {
            margin-bottom: 0.75rem;
        }
        .why-choose ul li:before {
            content: '✅';
            margin-right: 0.5rem;
        }
        .info-float {
    position: fixed;
    width: 100px;
    height: 100px;
    bottom: 110px; /* Placed above the WhatsApp button (60px height + 10px spacing) */
    right: 20px;
    color: #FFF;
    border-radius: 50%;
    text-align: center;
    font-size: 24px;
    line-height: 50px;
    box-shadow: 2px 2px 3px #999;
    z-index: 1001;
    cursor: pointer;
    transition: background-color 0.3s ease;
}

.info-float:hover {
    background-color: transparent;
}
    </style>
    
</head>
<body>
    <?php include './header.php'; ?>

    <section id="about" class="py-20 bg-gradient-to-b from-white to-red-50/30">
        <div class="container">
            <div class="about-grid max-w-7xl mx-auto">
                <!-- Content Side -->
                <div class="content-side">
                    <div>
                        <h1 class="branded ">Welcome to RGreenMart </h1>
                        <p class="text-muted secclr">Dried Fruits & Nuts – Fresh. Pure. Premium</p>
                        <p>Founded in 1975, RGreenMart has been a trusted name in the trading business for nearly five decades. What began as a modest venture has now grown into a well-established trading house that has successfully served customers across different sectors. Today, the company proudly stands as a third-generation family-run enterprise, combining traditional values with modern business practices.</p>
                        <p>
                            Over the years, RGreenMart has built its reputation on trust, quality, and reliability. With a diverse portfolio that includes trading, seasonal products, and other wholesale businesses, the company has catered to customers with consistency and excellence.
                        </p>
                    </div>

                    <!-- Highlights Cards -->
                    <div class="highlights-grid">
                        <div class="highlight-card">
                            <div class="icon-container">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                          d="M12 3c-2.5 0-4.5 1.5-4.5 3.5 0 1.5 1 2.5 2.5 3.5 1.5-1 2.5-2 2.5-3.5 0-2-2-3.5-4.5-3.5zm0 0c2.5 0 4.5 1.5 4.5 3.5 0 1.5-1 2.5-2.5 3.5-1.5-1-2.5-2-2.5-3.5zm-2 6l-2 3 2 3 2-3zm4 0l2 3-2 3-2-3zm-6 6h12v2H6z" />
                                </svg>
                            </div>
                            <h3>Made in India</h3>
                            <p>Proudly manufactured in India with top-notch quality and craftsmanship.</p>
                        </div>
                        <div class="highlight-card">
                            <div class="icon-container">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.5 3.5 0 001.948-.806 3.5 3.5 0 014.434 0 3.5 3.5 0 001.948.806 3.5 3.5 0 013.509 3.555 3.5 3.5 0 01-.806 1.948 3.5 3.5 0 010 4.434 3.5 3.5 0 01.806 1.948 3.5 3.5 0 01-3.509 3.555 3.5 3.5 0 01-1.948.806 3.5 3.5 0 01-4.434 0 3.5 3.5 0 01-1.948-.806 3.5 3.5 0 01-.806-1.948 3.5 3.5 0 010-4.434 3.5 3.5 0 01.806-1.948 3.5 3.5 0 013.509-3.555z" />
                                </svg>
                            </div>
                            <h3>Safe Manufacturing</h3>
                            <p>State-of-the-art safety protocols ensuring every product meets strict safety guidelines</p>
                        </div>
                        <div class="highlight-card">
                            <div class="icon-container">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                                </svg>
                            </div>
                            <h3>Eco-Friendly Options</h3>
                            <p>Environment-conscious with reduced emissions and biodegradable packaging</p>
                        </div>
                    </div>
                </div>

                <!-- Image Side -->
                <div class="image-side">
                    <div class="celebration">
                    <img src="../images/Celebration.jpg" alt="RGreenMart Embelem" >
                    
    </div>
                    <div class="image-container">
                        <img src="https://images.unsplash.com/photo-1695548487486-3649bfc8dd9a?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3w3Nzg4Nzd8MHwxfHNlYXJjaHwxfHxlY28lMjBmcmllbmRseSUyMGZhY3RvcnklMjBtYW51ZmFjdHVyaW5nfGVufDF8fHx8MTc1NTU0NDIwOHww&ixlib=rb-4.1.0&q=80&w=1080&utm_source=figma&utm_medium=referral" alt="Eco-friendly manufacturing facility">
                        <div class="gradient-overlay"></div>
                    </div>
                   
                </div>
            </div>
        </div>
    </section>

    <!-- Vision & Values and Why Choose Sections -->
    <section class="vision-values">
        <div class="container">
            <h3>Vision & Values</h3>
            <ul>
                <li>To continue the legacy of ethical and sustainable trading.</li>
                <li>To offer quality products at affordable prices.</li>
                <li>To blend traditional trust with modern efficiency.</li>
                <li>To grow into one of the most reliable trading companies in South India.</li>
            </ul>
        </div>
    </section>
    <section class="why-choose">
        <div class="container">
            <h3>Why Choose Us?</h3>
            <ul>
                <li>Premium Grade A Quality</li>
                <li>Fresh Batch Every Week</li>
                <li>No Chemicals, No Polish, No Preservatives</li>
                <li>Zip-lock Freshness Packaging</li>
                <li>Fast Delivery across India</li>
            </ul>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="py-16 bg-white">
        <div class="container">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 text-center">
                <!-- Brand -->
                <div class="stat-box">
                    <div class="value">5+</div>
                    <div class="label secclr">Category</div>
                </div>
                <!-- Customers -->
                <div class="stat-box">
                    <div class="value">500+</div>
                    <div class="label secclr">Customers</div>
                </div>
                <!-- Years -->
                <div class="stat-box">
                    <div class="value">50+</div>
                    <div class="label secclr">Years of Excellence</div>
                </div>
                <!-- Delivery -->
                <div class="stat-box">
                    <div class="value">10,000+</div>
                    <div class="label secclr">Successful Deliveries</div>
                </div>
            </div>
        </div>
    </section>
 
    <?php include './footer.php'; ?>
</body>
</html>