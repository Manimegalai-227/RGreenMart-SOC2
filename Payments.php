<?php require_once './includes/header.php'; ?>

<?php
$imagePath = $_SERVER['DOCUMENT_ROOT'] . '/images/Payment.jpg';
$imageExists = file_exists($imagePath);
$cacheBust = $imageExists ? filemtime($imagePath) : 0;
?>

<center>
<?php if ($imageExists): ?>
    <img src="/images/Payment.jpg?v=<?= $cacheBust ?>" alt="Payment Information">
<?php else: ?>
    <p style="padding: 2rem; color: #888;">Payment information image not available. Please contact the admin.</p>
<?php endif; ?>
</center>

<?php require_once './includes/footer.php'; ?>