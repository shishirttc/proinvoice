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
    // --- CUSTOMER SYNC (Recalculate all invoice payments and status) ---
    if (stripos($path, 'sync/') !== false) {
        $parts = explode('sync/', $path);
        $customerId = isset($parts[1]) ? trim($parts[1], '/') : null;
        if ($customerId) {
            // 1. First, update EACH invoice's amountPaid based on ITS OWN payments
            $stmt = $conn->prepare("SELECT id, total FROM invoices WHERE customerId = ?");
            $stmt->bind_param("s", $customerId);
            $stmt->execute();
            $invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            foreach ($invoices as $inv) {
                $invId = $inv['id'];
                $total = (float)$inv['total'];
                
                // Get physical payments for this invoice
                $pStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as paid FROM payments WHERE invoiceId = ?");
                $pStmt->bind_param("s", $invId);
                $pStmt->execute();
                $actualPaid = (float)($pStmt->get_result()->fetch_assoc()['paid'] ?? 0);
                
                $status = 'Sent';
                if ($actualPaid >= $total) {
                    $status = 'Paid';
                } elseif ($actualPaid > 0) {
                    $status = 'Partially Paid';
                }
                
                $uStmt = $conn->prepare("UPDATE invoices SET amountPaid = ?, status = ? WHERE id = ?");
                $uStmt->bind_param("dss", $actualPaid, $status, $invId);
                $uStmt->execute();
            }

            // 2. Now calculate the customer's TRUE net balance from scratch: Total Paid - Total Billed
            $stmt = $conn->prepare("SELECT 
                (SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN invoices i ON p.invoiceId = i.id WHERE i.customerId = ?) as totalPaid,
                (SELECT COALESCE(SUM(total), 0) FROM invoices WHERE customerId = ?) as totalBilled");
            $stmt->bind_param("ss", $customerId, $customerId);
            $stmt->execute();
            $totals = $stmt->get_result()->fetch_assoc();
            $trueBalance = (float)$totals['totalPaid'] - (float)$totals['totalBilled'];
            
            // 3. Update the customer's balance field
            $uStmt = $conn->prepare("UPDATE customers SET balance = ? WHERE id = ?");
            $uStmt->bind_param("ds", $trueBalance, $customerId);
            $uStmt->execute();

            respond(["message" => "Strict sync completed", "trueBalance" => $trueBalance, "totalPaid" => $totals['totalPaid'], "totalBilled" => $totals['totalBilled']]);
        }
    }

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
        
        // --- CUSTOMER LEDGER (Sub-route) ---
        if ($id && stripos($path, '/ledger') !== false) {
            $customerId = $id; // ID extracted from path
            
            // 1. Fetch Invoices
            $stmt = $conn->prepare("SELECT id, invoiceNumber as ref, date, total as debit, 0 as credit, 'Invoice' as type FROM invoices WHERE customerId = ?");
            $stmt->bind_param("s", $customerId);
            $stmt->execute();
            $invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // 2. Fetch Payments (Including Wallet Deposits)
            $stmt = $conn->prepare("SELECT id, COALESCE(invoiceNumber, 'Wallet Deposit') as ref, date, 0 as debit, amount as credit, 'Payment' as type FROM payments WHERE customerId = ?");
            $stmt->bind_param("s", $customerId);
            $stmt->execute();
            $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // 3. Merge and Sort
            $ledger = array_merge($invoices, $payments);
            usort($ledger, function($a, $b) {
                return strtotime($a['date']) - strtotime($b['date']);
            });
            
            // 4. Calculate Running Balance
            $runningBalance = 0;
            foreach ($ledger as &$entry) {
                $runningBalance += ((float)$entry['debit'] - (float)$entry['credit']);
                $entry['balance'] = $runningBalance;
                $entry['debit'] = (float)$entry['debit'];
                $entry['credit'] = (float)$entry['credit'];
            }
            
            respond($ledger);
        }

        if ($method == 'GET') {
            if ($id) {
                // Fetch customer basic info
                $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
                $stmt->bind_param("s", $id);
                $stmt->execute();
                $customer = $stmt->get_result()->fetch_assoc();
                
                if ($customer) {
                    // Use absolute physical reality: Total(Payments) - Total(Invoices)
                    $stmt = $conn->prepare("SELECT 
                        (SELECT COALESCE(SUM(total), 0) FROM invoices WHERE customerId = ?) as totalBilled,
                        (SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN invoices i ON p.invoiceId = i.id WHERE i.customerId = ?) as totalPaid");
                    $stmt->bind_param("ss", $id, $id);
                    $stmt->execute();
                    $calc = $stmt->get_result()->fetch_assoc();
                    
                    $customer['balance'] = (float)$calc['totalPaid'] - (float)$calc['totalBilled'];
                    $customer['customerNumber'] = str_pad($customer['customerNumber'], 5, '0', STR_PAD_LEFT);
                    
                    // Fetch invoices
                    $stmt = $conn->prepare("SELECT i.*, (SELECT SUM(quantity) FROM invoice_items WHERE invoiceId = i.id) as totalQty FROM invoices i WHERE i.customerId = ? ORDER BY i.created_at DESC");
                    $stmt->bind_param("s", $id);
                    $stmt->execute();
                    $customer['invoices'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    
                    foreach ($customer['invoices'] as &$inv) {
                        $inv['total'] = (float)$inv['total'];
                        // Real-time payment sum for this specific invoice
                        $pStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as paid FROM payments WHERE invoiceId = ?");
                        $pStmt->bind_param("s", $inv['id']);
                        $pStmt->execute();
                        $physicalPaid = (float)($pStmt->get_result()->fetch_assoc()['paid'] ?? 0);
                        
                        // Cap amountPaid at invoice total to avoid confusion (50 tk issue)
                        $inv['amountPaid'] = min($inv['total'], $physicalPaid);
                        $inv['totalQty'] = (float)($inv['totalQty'] ?? 0);
                    }
                }
                respond($customer ?: new stdClass());
            }
            
            // For list of customers, also calculate real-time balance
            $result = $conn->query("SELECT c.*, 
                (SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN invoices i ON p.invoiceId = i.id WHERE i.customerId = c.id) as totalPaid,
                (SELECT COALESCE(SUM(total), 0) FROM invoices WHERE customerId = c.id) as totalBilled
                FROM customers c");
            $customers = $result->fetch_all(MYSQLI_ASSOC) ?: [];
            foreach ($customers as &$c) {
                $c['balance'] = (float)$c['totalPaid'] - (float)$c['totalBilled'];
                $c['customerNumber'] = str_pad($c['customerNumber'], 5, '0', STR_PAD_LEFT);
            }
            respond($customers);
        } elseif ($method == 'POST') {
            $id = $data['id'];
            $name = $data['name'];
            $email = $data['email'];
            $phone = $data['phone'];
            $address = $data['address'];
            $balance = isset($data['balance']) ? (float)$data['balance'] : 0.00;

            // Direct update/insert of customer including the manual balance field
            $stmt = $conn->prepare("INSERT INTO customers (id, name, email, phone, address, balance) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name=?, email=?, phone=?, address=?, balance=?");
            $stmt->bind_param("ssssddssssd", $id, $name, $email, $phone, $address, $balance, $name, $email, $phone, $address, $balance);
            
            if ($stmt->execute()) {
                respond(["message" => "Customer saved successfully", "id" => $id, "balance" => $balance]);
            } else {
                throw new Exception($stmt->error);
            }
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
                        $item['quantity'] = (float)$item['quantity'];
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
                $result = $conn->query("SELECT i.*, (SELECT SUM(quantity) FROM invoice_items WHERE invoiceId = i.id) as totalQty FROM invoices i ORDER BY created_at ASC");
                $invoices = $result->fetch_all(MYSQLI_ASSOC) ?: [];
                foreach ($invoices as &$inv) {
                    $inv['subtotal'] = (float)$inv['subtotal'];
                    $inv['taxRate'] = (float)$inv['taxRate'];
                    $inv['taxAmount'] = (float)$inv['taxAmount'];
                    $inv['discount'] = (float)$inv['discount'];
                    $inv['total'] = (float)$inv['total'];
                    $inv['amountPaid'] = (float)$inv['amountPaid'];

                    // --- DYNAMIC STATUS CALCULATION (Real-time) ---
                    $s = strtolower($inv['status'] ?? 'sent');
                    if ($inv['total'] > 0) {
                        if ($inv['amountPaid'] >= $inv['total']) {
                            $inv['status'] = 'Paid';
                        } elseif ($inv['amountPaid'] > 0) {
                            $inv['status'] = 'Partially Paid';
                        } else {
                            // If no payments, respect the current status (Draft/Sent)
                            $inv['status'] = (strtolower($s) === 'draft') ? 'Draft' : 'Sent';
                        }
                    } else {
                        $inv['status'] = (strtolower($s) === 'draft') ? 'Draft' : 'Sent';
                    }

                    $stmt = $conn->prepare("SELECT * FROM invoice_items WHERE invoiceId = ?");
                    $stmt->bind_param("s", $inv['id']);
                    $stmt->execute();
                    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                    foreach ($items as &$item) {
                        $item['quantity'] = (float)$item['quantity'];
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
                $status = $data['status'] ?? 'Sent';
                $invoiceAmountPaid = $amountPaid;
                if ($total > 0) {
                    if ($amountPaid >= $total) {
                        $status = 'Paid';
                        $invoiceAmountPaid = $total; // Cap at total
                    } elseif ($amountPaid > 0) {
                        $status = 'Partially Paid';
                    }
                }
                
                // Final sanitize for database enum/varchar
                if (strtolower($status) === 'partially_paid' || strtolower($status) === 'partially paid') $status = 'Partially Paid';
                if (strtolower($status) === 'paid') $status = 'Paid';
                if (strtolower($status) === 'sent') $status = 'Sent';
                if (strtolower($status) === 'draft') $status = 'Draft';

                // --- AUTO-APPLY CUSTOMER BALANCE ---
                $stmt = $conn->prepare("SELECT balance FROM customers WHERE id = ?");
                $stmt->bind_param("s", $data['customerId']);
                $stmt->execute();
                $cust = $stmt->get_result()->fetch_assoc();
                $customerBalance = (float)($cust['balance'] ?? 0);

                if ($customerBalance > 0 && $total > $amountPaid) {
                    $toApply = min($customerBalance, $total - $amountPaid);
                    
                    // Update customer balance
                    $uStmt = $conn->prepare("UPDATE customers SET balance = balance - ? WHERE id = ?");
                    $uStmt->bind_param("ds", $toApply, $data['customerId']);
                    $uStmt->execute();
                    
                    // Re-calculate amountPaid for final invoice status (without creating a duplicate payment record)
                    $amountPaid += $toApply;
                    $invoiceAmountPaid = $amountPaid;
                    if ($amountPaid >= $total) {
                        $status = 'Paid';
                        $invoiceAmountPaid = $total;
                    } elseif ($amountPaid > 0) {
                        $status = 'Partially Paid';
                    }
                }

                $stmt = $conn->prepare("INSERT INTO invoices (id, invoiceNumber, customerId, date, subtotal, taxRate, taxAmount, discount, total, amountPaid, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE invoiceNumber=?, customerId=?, date=?, subtotal=?, taxRate=?, taxAmount=?, discount=?, total=?, amountPaid=?, status=?, notes=?");
                $stmt->bind_param("ssssddddddssssssdddddds", 
                    $data['id'], $data['invoiceNumber'], $data['customerId'], $date, $subtotal, $taxRate, $taxAmount, $discount, $total, $invoiceAmountPaid, $status, $data['notes'],
                    $data['invoiceNumber'], $data['customerId'], $date, $subtotal, $taxRate, $taxAmount, $discount, $total, $invoiceAmountPaid, $status, $data['notes']
                );
                $stmt->execute();

                $stmt = $conn->prepare("DELETE FROM invoice_items WHERE invoiceId = ?");
                $stmt->bind_param("s", $data['id']);
                $stmt->execute();

                if (isset($data['items']) && is_array($data['items'])) {
                    $stmt = $conn->prepare("INSERT INTO invoice_items (id, invoiceId, productId, name, quantity, price, total) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    foreach ($data['items'] as $item) {
                        $qty = (float)$item['quantity'];
                        $pr = (float)$item['price'];
                        $tl = (float)$item['total'];
                        $stmt->bind_param("ssssddd", $item['id'], $data['id'], $item['productId'], $item['name'], $qty, $pr, $tl);
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
                $date = date('Y-m-d', strtotime($data['date']));
                $amount = (float)$data['amount'];
                $note = isset($data['note']) ? $data['note'] : "";
                $id = $data['id'];
                $method_pay = isset($data['method']) ? $data['method'] : "Cash";

                if (isset($data['invoiceId']) && !empty($data['invoiceId'])) {
                    // --- CASE 1: Payment for an Invoice ---
                    $stmt = $conn->prepare("SELECT total, customerId, invoiceNumber FROM invoices WHERE id = ?");
                    $stmt->bind_param("s", $data['invoiceId']);
                    $stmt->execute();
                    $inv = $stmt->get_result()->fetch_assoc();

                    if ($inv) {
                        $invoiceNumber = $inv['invoiceNumber'];
                        $customerId = $inv['customerId'];
                        $stmt = $conn->prepare("INSERT INTO payments (id, invoiceId, invoiceNumber, date, amount, method, note, customerId) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("ssssdsss", $id, $data['invoiceId'], $invoiceNumber, $date, $amount, $method_pay, $note, $customerId);
                        $stmt->execute();

                        $stmt = $conn->prepare("SELECT SUM(amount) as totalPaid FROM payments WHERE invoiceId = ?");
                        $stmt->bind_param("s", $data['invoiceId']);
                        $stmt->execute();
                        $payResult = $stmt->get_result()->fetch_assoc();
                        $totalSumOfPayments = (float)($payResult['totalPaid'] ?? 0);

                        $invoiceTotal = (float)$inv['total'];
                        $invoiceAmountPaid = $totalSumOfPayments;
                        $status = 'Partially Paid';
                        
                        if ($totalSumOfPayments >= $invoiceTotal) {
                            $status = 'Paid';
                            $invoiceAmountPaid = $invoiceTotal;
                            
                            $stmt = $conn->prepare("SELECT SUM(amount) as prevPaid FROM payments WHERE invoiceId = ? AND id != ?");
                            $stmt->bind_param("ss", $data['invoiceId'], $id);
                            $stmt->execute();
                            $prevPaid = (float)($stmt->get_result()->fetch_assoc()['prevPaid'] ?? 0);
                            
                            $remainingDueBefore = max(0, $invoiceTotal - $prevPaid);
                            $overpayment = $amount - $remainingDueBefore;
                            
                            if ($overpayment > 0) {
                                $stmt = $conn->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?");
                                $stmt->bind_param("ds", $overpayment, $customerId);
                                $stmt->execute();
                            }
                        } elseif ($totalSumOfPayments <= 0) {
                            $status = 'Sent';
                        }

                        $stmt = $conn->prepare("UPDATE invoices SET amountPaid = ?, status = ? WHERE id = ?");
                        $stmt->bind_param("dss", $invoiceAmountPaid, $status, $data['invoiceId']);
                        $stmt->execute();
                        
                        $conn->commit();
                        respond(["message" => "Payment added to invoice", "newStatus" => $status]);
                    } else {
                        throw new Exception("Invoice not found");
                    }
                } else if (isset($data['customerId']) && !empty($data['customerId'])) {
                    // --- CASE 2: Direct Wallet Deposit (No Invoice) ---
                    $customerId = $data['customerId'];
                    $stmt = $conn->prepare("INSERT INTO payments (id, invoiceId, invoiceNumber, date, amount, method, note, customerId) VALUES (?, NULL, 'Wallet Deposit', ?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssssdsss", $id, $date, $amount, $method_pay, $note, $customerId);
                    $stmt->execute();

                    $stmt = $conn->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?");
                    $stmt->bind_param("ds", $amount, $customerId);
                    $stmt->execute();

                    $conn->commit();
                    respond(["message" => "Wallet deposit successful", "amount" => $amount]);
                } else {
                    throw new Exception("Either invoiceId or customerId is required");
                }
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
