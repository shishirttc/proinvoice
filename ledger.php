<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = "localhost";
$user = "root";
$pass = "";
$dbname = "proinvoice";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) die("Connection failed");

$customerId = $_GET['id'] ?? null;
if (!$customerId) die("Customer ID required");

// Fetch Customer
$stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->bind_param("s", $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
if (!$customer) die("Customer not found");

// Fetch Invoices (Debits)
$stmt = $conn->prepare("SELECT invoiceNumber as ref, date, total as debit, 0 as credit, 'Invoice' as type FROM invoices WHERE customerId = ?");
$stmt->bind_param("s", $customerId);
$stmt->execute();
$invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch Payments (Credits)
$stmt = $conn->prepare("SELECT i.invoiceNumber as ref, p.date, 0 as debit, p.amount as credit, 'Payment' as type FROM payments p JOIN invoices i ON p.invoiceId = i.id WHERE i.customerId = ?");
$stmt->bind_param("s", $customerId);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$ledger = array_merge($invoices, $payments);
usort($ledger, function($a, $b) {
    return strtotime($a['date']) - strtotime($b['date']);
});

$company_res = $conn->query("SELECT * FROM company WHERE id = 1");
$company = $company_res->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Ledger - <?php echo htmlspecialchars($customer['name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none; }
            body { padding: 0; margin: 0; }
        }
    </style>
</head>
<body class="bg-slate-50 p-4 md:p-10">
    <div class="max-w-4xl mx-auto bg-white shadow-xl rounded-2xl overflow-hidden border border-gray-100">
        <!-- Header -->
        <div class="bg-slate-900 p-8 text-white flex justify-between items-start">
            <div>
                <h1 class="text-3xl font-black tracking-tight">CUSTOMER LEDGER</h1>
                <p class="text-slate-400 mt-1">Transaction Statement</p>
                <div class="mt-6">
                    <h2 class="text-xl font-bold"><?php echo htmlspecialchars($customer['name']); ?></h2>
                    <p class="text-sm text-slate-300"><?php echo htmlspecialchars($customer['address']); ?></p>
                    <p class="text-sm text-slate-300"><?php echo htmlspecialchars($customer['phone']); ?></p>
                </div>
            </div>
            <div class="text-right">
                <h3 class="text-2xl font-black text-blue-500"><?php echo htmlspecialchars($company['name'] ?? 'Pro Invoice'); ?></h3>
                <p class="text-xs text-slate-400 mt-1"><?php echo date('d M, Y'); ?></p>
                <button onclick="window.print()" class="no-print mt-6 px-4 py-2 bg-blue-600 rounded-lg text-sm font-bold hover:bg-blue-700 transition-all">Print Ledger</button>
            </div>
        </div>

        <!-- Ledger Table -->
        <div class="p-0 overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-100 border-b border-gray-200">
                        <th class="px-6 py-4 text-[10px] font-black uppercase text-gray-500 tracking-widest">Date</th>
                        <th class="px-6 py-4 text-[10px] font-black uppercase text-gray-500 tracking-widest">Description</th>
                        <th class="px-6 py-4 text-[10px] font-black uppercase text-gray-500 tracking-widest text-right">Debit (৳)</th>
                        <th class="px-6 py-4 text-[10px] font-black uppercase text-gray-500 tracking-widest text-right">Credit (৳)</th>
                        <th class="px-6 py-4 text-[10px] font-black uppercase text-gray-500 tracking-widest text-right">Balance (৳)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php 
                    $runningBalance = 0;
                    foreach ($ledger as $item): 
                        $runningBalance += ($item['debit'] - $item['credit']);
                    ?>
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="px-6 py-4 text-sm font-medium text-gray-600"><?php echo date('d/m/Y', strtotime($item['date'])); ?></td>
                        <td class="px-6 py-4 text-sm font-bold text-slate-900">
                            <?php echo $item['type']; ?> #<?php echo htmlspecialchars($item['ref']); ?>
                        </td>
                        <td class="px-6 py-4 text-sm font-bold text-right text-rose-600">
                            <?php echo $item['debit'] > 0 ? number_format($item['debit'], 2) : '-'; ?>
                        </td>
                        <td class="px-6 py-4 text-sm font-bold text-right text-emerald-600">
                            <?php echo $item['credit'] > 0 ? number_format($item['credit'], 2) : '-'; ?>
                        </td>
                        <td class="px-6 py-4 text-sm font-black text-right text-slate-900">
                            <?php echo number_format($runningBalance, 2); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-slate-900 text-white font-bold">
                    <tr>
                        <td colspan="4" class="px-6 py-4 text-right uppercase tracking-widest text-xs">Final Net Balance:</td>
                        <td class="px-6 py-4 text-right text-xl font-black text-blue-400">
                            ৳ <?php echo number_format($runningBalance, 2); ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        
        <!-- Footer -->
        <div class="p-8 bg-slate-50 text-center text-[10px] font-bold text-gray-400 uppercase tracking-[0.3em]">
            This is a computer generated document
        </div>
    </div>
</body>
</html>
<?php $conn->close(); ?>
