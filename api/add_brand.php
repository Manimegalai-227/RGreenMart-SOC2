<?php
require_once $_SERVER["DOCUMENT_ROOT"] . "/dbconf.php";

if (!isset($_POST["name"]) || trim($_POST["name"]) === "") {
    echo "error: empty name";
    exit;
}

$name = trim($_POST["name"]);
try {
$stmt = $conn->prepare("INSERT INTO brands (name) VALUES (?)");
$stmt->execute([$name]);
} catch (PDOException $e) {
    echo "error: " . $e->getMessage();
}

