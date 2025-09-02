<?php
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (isset($_GET['term'])) {
    $term = "%" . $_GET['term'] . "%";

    $sql = "SELECT id, product_code, name, unit_of_measure, current_stock 
            FROM products 
            WHERE name LIKE ? OR product_code LIKE ?
            ORDER BY name ASC 
            LIMIT 10";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $term, $term);
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            "id"   => $row['id'],
            "label"=> $row['product_code'] . " - " . $row['name'] . " (رصيد: " . $row['current_stock'] . " " . $row['unit_of_measure'] . ")",
            "value"=> $row['name'],
            "stock"=> $row['current_stock'],
            "unit" => $row['unit_of_measure']
        ];
    }

    echo json_encode($data);
    exit;
}
echo json_encode([]);
