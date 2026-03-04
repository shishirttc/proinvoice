<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host = "localhost";
$user = "root";
$pass = "";
$dbname = "proinvoice";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die(json_encode(["error" => "Connection failed: " . $conn->connect_error]));
}

$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$script_name = $_SERVER['SCRIPT_NAME'];
$base_path = str_replace('api.php', '', $script_name);
$path = str_replace($base_path, '', $request_uri);
$path = parse_url($path, PHP_URL_PATH);
$path = trim($path, '/');
$data = json_decode(file_get_contents('php://input'), true);

function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    global $conn;
    $conn->close();
    exit();
}

try {
    // --- Company ---
    if (stripos($path, 'company') !== false) {
        if ($method == 'GET') {
            $result = $conn->query("SELECT * FROM company WHERE id = 1");
            respond($result->fetch_assoc() ?: new stdClass());
        } elseif ($method == 'POST') {
            $stmt = $conn->prepare("INSERT INTO company (id, name, address, phone, email, website, taxId, logoUrl) VALUES (1, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name=?, address=?, phone=?, email=?, website=?, taxId=?, logoUrl=?");
            $stmt->bind_param("ssssssssssssss", $data['name'], $data['address'], $data['phone'], $data['email'], $data['website'], $data['taxId'], $data['logoUrl'], $data['name'], $data['address'], $data['phone'], $data['email'], $data['website'], $data['taxId'], $data['logoUrl']);
            $stmt->execute();
            respond(["message" => "Company updated"]);
        }
    }

    // --- Customers ---
    if (stripos($path, 'customers') !== false) {
        $parts = explode('customers/', $path);
        $id = isset($parts[1]) ? trim($parts[1], '/') : null;
        if ($method == 'GET') {
            $result = $conn->query("SELECT * FROM customers");
            $customers = $result->fetch_all(MYSQLI_ASSOC) ?: [];
            foreach ($customers as &$c) {
                $c['balance'] = (float)$c['balance'];
                // Format customerNumber as 5-digit string (e.g., 00001)
                $c['customerNumber'] = str_pad($c['customerNumber'], 5, '0', STR_PAD_LEFT);
            }
            respond($customers);
        } elseif ($method == 'POST') {
            // Check if customer already exists to avoid overwriting customerNumber
            $stmt = $conn->prepare("SELECT id FROM customers WHERE id = ?");
            $stmt->bind_param("s", $data['id']);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();

            if ($exists) {
                $stmt = $conn->prepare("UPDATE customers SET name=?, email=?, phone=?, address=?, balance=? WHERE id=?");
                $balance = isset($data['balance']) ? (float)$data['balance'] : 0.0;
                $stmt->bind_param("ssssds", $data['name'], $data['email'], $data['phone'], $data['address'], $balance, $data['id']);
            } else {
                $stmt = $conn->prepare("INSERT INTO customers (id, name, email, phone, address, balance) VALUES (?, ?, ?, ?, ?, ?)");
                $balance = isset($data['balance']) ? (float)$data['balance'] : 0.0;
                $stmt->bind_param("sssssd", $data['id'], $data['name'], $data['email'], $data['phone'], $data['address'], $balance);
            }
            $stmt->execute();
            respond(["message" => "Customer saved"]);
        } elseif ($method == 'DELETE' && $id) {
            $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->bind_param("s", $id);
            $stmt->execute();
            respond(["message" => "Customer deleted"]);
        }
    }

    // --- Inventory ---
    if (stripos($path, 'inventory') !== false) {
        $parts = explode('inventory/', $path);
        $id = isset($parts[1]) ? trim($parts[1], '/') : null;
        if ($method == 'GET') {
            $result = $conn->query("SELECT * FROM inventory");
            $items = $result->fetch_all(MYSQLI_ASSOC) ?: [];
            foreach ($items as &$item) {
                $item['price'] = (float)$item['price'];
            }
            respond($items);
        } elseif ($method == 'POST') {
            $stmt = $conn->prepare("INSERT INTO inventory (id, name, description, price) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE name=?, description=?, price=?");
            $price = (float)$data['price'];
            $stmt->bind_param("sssdssd", $data['id'], $data['name'], $data['description'], $price, $data['name'], $data['description'], $price);
            $stmt->execute();
            respond(["message" => "Product saved"]);
        } elseif ($method == 'DELETE' && $id) {
            $stmt = $conn->prepare("DELETE FROM inventory WHERE id = ?");
            $stmt->bind_param("s", $id);
            $stmt->execute();
            respond(["message" => "Product deleted"]);
        }
    }

    // --- Invoices ---
    if (stripos($path, 'invoices') !== false) {
        $parts = explode('invoices/', $path);
        $id = isset($parts[1]) ? trim($parts[1], '/') : null;
        if ($method == 'GET') {
            if ($id) {
                $stmt = $conn->prepare("SELECT * FROM invoices WHERE id = ?");
                $stmt->bind_param("s", $id);
                $stmt->execute();
                $inv = $stmt->get_result()->fetch_assoc();
                if ($inv) {
                    $inv['subtotal'] = (float)$inv['subtotal'];
                    $inv['taxRate'] = (float)$inv['taxRate'];
                    $inv['taxAmount'] = (float)$inv['taxAmount'];
                    $inv['discount'] = (float)$inv['discount'];
                    $inv['total'] = (float)$inv['total'];
                    $inv['amountPaid'] = (float)$inv['amountPaid'];

                    $stmt = $conn->prepare("SELECT * FROM invoice_items WHERE invoiceId = ?");
                    $stmt->bind_param("s", $id);
                    $stmt->execute();
                    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                    foreach ($items as &$item) {
                        $item['quantity'] = (int)$item['quantity'];
                        $item['price'] = (float)$item['price'];
                        $item['total'] = (float)$item['total'];
                    }
                    $inv['items'] = $items;
                    
                    $stmt = $conn->prepare("SELECT * FROM payments WHERE invoiceId = ?");
                    $stmt->bind_param("s", $id);
                    $stmt->execute();
                    $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                    foreach ($payments as &$p) {
                        $p['amount'] = (float)$p['amount'];
                    }
                    $inv['payments'] = $payments;
                }
                respond($inv ?: new stdClass());
            } else {
                $result = $conn->query("SELECT * FROM invoices");
                $invoices = $result->fetch_all(MYSQLI_ASSOC) ?: [];
                foreach ($invoices as &$inv) {
                    $inv['subtotal'] = (float)$inv['subtotal'];
                    $inv['taxRate'] = (float)$inv['taxRate'];
                    $inv['taxAmount'] = (float)$inv['taxAmount'];
                    $inv['discount'] = (float)$inv['discount'];
                    $inv['total'] = (float)$inv['total'];
                    $inv['amountPaid'] = (float)$inv['amountPaid'];

                    // Force map status for UI display
                    $s = strtolower($inv['status']);
                    if ($s === 'partially_paid') $inv['status'] = 'Partially Paid';
                    else if ($s === 'paid') $inv['status'] = 'Paid';
                    else if ($s === 'sent') $inv['status'] = 'Sent';
                    else if ($s === 'draft') $inv['status'] = 'Draft';
                    else $inv['status'] = ucwords(str_replace('_', ' ', $s));

                    $stmt = $conn->prepare("SELECT * FROM invoice_items WHERE invoiceId = ?");
                    $stmt->bind_param("s", $inv['id']);
                    $stmt->execute();
                    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                    foreach ($items as &$item) {
                        $item['quantity'] = (int)$item['quantity'];
                        $item['price'] = (float)$item['price'];
                        $item['total'] = (float)$item['total'];
                    }
                    $inv['items'] = $items;

                    $stmt = $conn->prepare("SELECT * FROM payments WHERE invoiceId = ?");
                    $stmt->bind_param("s", $inv['id']);
                    $stmt->execute();
                    $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                    foreach ($payments as &$p) {
                        $p['amount'] = (float)$p['amount'];
                    }
                    $inv['payments'] = $payments;
                }
                respond($invoices);
            }
        } elseif ($method == 'POST') {
            $conn->begin_transaction();
            try {
                // --- SYNC amountPaid FROM PAYMENTS TABLE ---
                // We don't trust the frontend's amountPaid to prevent overwriting
                $stmt = $conn->prepare("SELECT SUM(amount) as totalPaid FROM payments WHERE invoiceId = ?");
                $stmt->bind_param("s", $data['id']);
                $stmt->execute();
                $payResult = $stmt->get_result()->fetch_assoc();
                $amountPaid = (float)($payResult['totalPaid'] ?? 0);

                $date = date('Y-m-d', strtotime($data['date']));
                $subtotal = (float)$data['subtotal'];
                $taxRate = (float)$data['taxRate'];
                $taxAmount = (float)$data['taxAmount'];
                $discount = (float)$data['discount'];
                $total = (float)$data['total'];
                
                // --- SMART STATUS CALCULATION ---
                $status = $data['status'];
                if ($amountPaid >= $total && $total > 0) {
                    $status = 'Paid';
                } elseif ($amountPaid > 0 && $amountPaid < $total) {
                    $status = 'Partially Paid';
                } else {
                    if (empty($status) || strtolower($status) === 'sent' || $status === 'partially_paid') $status = 'Sent';
                    if (strtolower($status) === 'draft') $status = 'Draft';
                    if ($status === 'Paid') $status = 'Paid';
                }

                $stmt = $conn->prepare("INSERT INTO invoices (id, invoiceNumber, customerId, date, subtotal, taxRate, taxAmount, discount, total, amountPaid, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE invoiceNumber=?, customerId=?, date=?, subtotal=?, taxRate=?, taxAmount=?, discount=?, total=?, amountPaid=?, status=?, notes=?");
                $stmt->bind_param("ssssddddddssssssdddddds", 
                    $data['id'], $data['invoiceNumber'], $data['customerId'], $date, $subtotal, $taxRate, $taxAmount, $discount, $total, $amountPaid, $status, $data['notes'],
                    $data['invoiceNumber'], $data['customerId'], $date, $subtotal, $taxRate, $taxAmount, $discount, $total, $amountPaid, $status, $data['notes']
                );
                $stmt->execute();

                $stmt = $conn->prepare("DELETE FROM invoice_items WHERE invoiceId = ?");
                $stmt->bind_param("s", $data['id']);
                $stmt->execute();

                if (isset($data['items']) && is_array($data['items'])) {
                    $stmt = $conn->prepare("INSERT INTO invoice_items (id, invoiceId, productId, name, quantity, price, total) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    foreach ($data['items'] as $item) {
                        $qty = (int)$item['quantity'];
                        $pr = (float)$item['price'];
                        $tl = (float)$item['total'];
                        $stmt->bind_param("ssssidd", $item['id'], $data['id'], $item['productId'], $item['name'], $qty, $pr, $tl);
                        $stmt->execute();
                    }
                }
                $conn->commit();
                respond(["message" => "Invoice saved"]);
            } catch (Exception $e) {
                $conn->rollback();
                respond(["error" => $e->getMessage()], 500);
            }
        } elseif ($method == 'DELETE' && $id) {
            $stmt = $conn->prepare("DELETE FROM invoices WHERE id = ?");
            $stmt->bind_param("s", $id);
            $stmt->execute();
            respond(["message" => "Invoice deleted"]);
        }
    }

    // --- Activities ---
    if (stripos($path, 'activities') !== false) {
        if ($method == 'GET') {
            $result = $conn->query("SELECT * FROM activity_log ORDER BY timestamp DESC");
            respond($result->fetch_all(MYSQLI_ASSOC) ?: []);
        } elseif ($method == 'POST') {
            $stmt = $conn->prepare("INSERT INTO activity_log (id, action, entityType, entityName, timestamp, details) VALUES (?, ?, ?, ?, ?, ?)");
            $timestamp = date('Y-m-d H:i:s', strtotime($data['timestamp']));
            $stmt->bind_param("ssssss", $data['id'], $data['action'], $data['entityType'], $data['entityName'], $timestamp, $data['details']);
            $stmt->execute();
            respond(["message" => "Activity logged"]);
        }
    }

    // --- Payments ---
    if (stripos($path, 'payments') !== false) {
        if ($method == 'POST') {
            $conn->begin_transaction();
            try {
                // 1. Insert the new payment
                $stmt = $conn->prepare("INSERT INTO payments (id, invoiceId, date, amount, method, note) VALUES (?, ?, ?, ?, ?, ?)");
                $date = date('Y-m-d', strtotime($data['date']));
                $amount = (float)$data['amount'];
                $stmt->bind_param("sssdss", $data['id'], $data['invoiceId'], $date, $amount, $data['method'], $data['note']);
                $stmt->execute();

                // 2. Sum ALL payments for this invoice to be 100% accurate
                $stmt = $conn->prepare("SELECT SUM(amount) as totalPaid FROM payments WHERE invoiceId = ?");
                $stmt->bind_param("s", $data['invoiceId']);
                $stmt->execute();
                $payResult = $stmt->get_result()->fetch_assoc();
                $totalPaid = (float)($payResult['totalPaid'] ?? 0);

                // 3. Get invoice total to compare
                $stmt = $conn->prepare("SELECT total FROM invoices WHERE id = ?");
                $stmt->bind_param("s", $data['invoiceId']);
                $stmt->execute();
                $inv = $stmt->get_result()->fetch_assoc();

                if ($inv) {
                    $invoiceTotal = (float)$inv['total'];
                    $status = 'Partially Paid';
                    
                    if ($totalPaid >= $invoiceTotal) {
                        $status = 'Paid';
                    } elseif ($totalPaid <= 0) {
                        $status = 'Sent';
                    }

                    // 4. Force update the invoice table
                    $stmt = $conn->prepare("UPDATE invoices SET amountPaid = ?, status = ? WHERE id = ?");
                    $stmt->bind_param("dss", $totalPaid, $status, $data['invoiceId']);
                    $stmt->execute();
                }

                $conn->commit();
                respond(["message" => "Payment added", "newStatus" => $status, "totalPaid" => $totalPaid]);
            } catch (Exception $e) {
                $conn->rollback();
                respond(["error" => $e->getMessage()], 500);
            }
        }
    }

} catch (Exception $e) {
    respond(["error" => $e->getMessage()], 500);
}

respond(["error" => "Route not found", "path" => $path], 404);
?>
