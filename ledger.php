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

// 1. Fetch Invoices with their total summed payments
$stmt = $conn->prepare("SELECT 
    i.id, 
    i.invoiceNumber as ref, 
    i.date, 
    i.total as debit, 
    COALESCE((SELECT SUM(amount) FROM payments WHERE invoiceId = i.id), 0) as credit, 
    'Invoice' as type, 
    COALESCE((SELECT SUM(quantity) FROM invoice_items WHERE invoiceId = i.id), 0) as qty 
FROM invoices i 
WHERE i.customerId = ?");
$stmt->bind_param("s", $customerId);
$stmt->execute();
$invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 2. Fetch only Wallet Deposits (Payments with NULL invoiceId)
$stmt = $conn->prepare("SELECT 
    id, 
    'Wallet Deposit' as ref, 
    date, 
    0 as debit, 
    amount as credit, 
    'Wallet' as type, 
    0 as qty 
FROM payments 
WHERE customerId = ? AND invoiceId IS NULL");
$stmt->bind_param("s", $customerId);
$stmt->execute();
$wallet_deposits = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 3. Merge and Sort
$ledger = array_merge($invoices, $wallet_deposits);
usort($ledger, function($a, $b) {
    return strtotime($a['date']) - strtotime($b['date']);
});

$company_res = $conn->query("SELECT * FROM company WHERE id = 1");
$company = $company_res->fetch_assoc();

// Calculate Totals for Dashboard - Refined Single Source of Truth
$totalBilled = 0;
$totalPaid = 0;
$totalVolumeUSD = 0;

// 1. Sum all Invoice Totals (Debit)
foreach ($invoices as $inv) {
    $totalBilled += (float)$inv['debit'];
    $totalVolumeUSD += (float)$inv['qty'];
}

// 2. Sum all Payments (Credit) including Wallet Deposits
$stmt = $conn->prepare("SELECT SUM(amount) as total_received FROM payments WHERE customerId = ?");
$stmt->bind_param("s", $customerId);
$stmt->execute();
$pay_res = $stmt->get_result()->fetch_assoc();
$totalPaid = (float)($pay_res['total_received'] ?? 0);

// 3. Net Balance
$currentNetBalance = $totalPaid - $totalBilled;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Statement of Account</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background-color: white !important; padding: 0 !important; margin: 0 !important; }
            .ledger-container { box-shadow: none !important; border: none !important; max-width: 100% !important; margin: 0 !important; border-radius: 0 !important; }
            .dashboard-card { border: 1px solid #e2e8f0 !important; }
        }
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 p-4 md:p-10">
    <div class="ledger-container max-w-5xl mx-auto bg-white shadow-[0_20px_50px_rgba(0,0,0,0.05)] rounded-3xl overflow-hidden border border-gray-100">
        <!-- Professional Header -->
        <div class="bg-slate-900 p-8 md:p-12 text-white relative overflow-hidden">
            <div class="absolute top-0 right-0 w-64 h-64 bg-blue-600/10 rounded-full -mr-32 -mt-32 blur-3xl"></div>
            <div class="relative z-10 flex flex-col md:flex-row justify-between items-start md:items-center gap-8">
                <div>
                    <h1 class="text-4xl font-extrabold tracking-tight mb-2">Statement of Account</h1>
                    <div class="flex items-center gap-3 text-blue-400 font-bold uppercase text-[10px] tracking-widest">
                        <span>Customer Ledger</span>
                        <span class="w-1.5 h-1.5 bg-blue-400 rounded-full"></span>
                        <span>Generated: <?php echo date('M d, Y'); ?></span>
                    </div>
                </div>
                <div class="text-right">
                    <h2 class="text-2xl font-bold text-white"><?php echo htmlspecialchars($company['name'] ?? 'Pro Invoice'); ?></h2>
                    <p class="text-slate-400 text-sm max-w-[250px] ml-auto leading-relaxed mt-1"><?php echo nl2br(htmlspecialchars($company['address'] ?? '')); ?></p>
                </div>
            </div>
        </div>

        <!-- Summary Dashboard -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 p-6 md:p-8 bg-slate-50/50 border-b border-gray-100">
            <div class="dashboard-card bg-white p-5 rounded-2xl border border-gray-100 shadow-sm">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Billed</p>
                <p class="text-xl font-extrabold text-slate-900">৳ <?php echo number_format($totalBilled, 2); ?></p>
            </div>
            <div class="dashboard-card bg-white p-5 rounded-2xl border border-gray-100 shadow-sm">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Paid</p>
                <p class="text-xl font-extrabold text-emerald-600">৳ <?php echo number_format($totalPaid, 2); ?></p>
            </div>
            <div class="dashboard-card bg-white p-5 rounded-2xl border border-gray-100 shadow-sm border-l-4 border-l-blue-500">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Total Volume (USD)</p>
                <p class="text-xl font-extrabold text-blue-600"><?php echo number_format($totalVolumeUSD, 2); ?></p>
            </div>
            <div class="dashboard-card bg-blue-600 p-5 rounded-2xl shadow-lg shadow-blue-600/20">
                <p class="text-[10px] font-bold text-blue-100 uppercase tracking-widest mb-1">Current Balance</p>
                <p class="text-xl font-extrabold text-white">৳ <?php echo number_format($currentNetBalance, 2); ?></p>
            </div>
        </div>

        <!-- Customer Info Section -->
        <div class="p-8 md:p-12 flex flex-col md:flex-row justify-between gap-8 bg-white">
            <div class="space-y-1">
                <h3 class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Statement For:</h3>
                <p class="text-2xl font-extrabold text-slate-900"><?php echo htmlspecialchars($customer['name']); ?></p>
                <p class="text-sm text-gray-500 max-w-sm font-medium leading-relaxed"><?php echo nl2br(htmlspecialchars($customer['address'])); ?></p>
                <?php if($customer['phone']): ?>
                    <p class="text-sm text-blue-600 font-bold pt-1 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                        <?php echo htmlspecialchars($customer['phone']); ?>
                    </p>
                <?php endif; ?>
            </div>
            <div class="text-right flex flex-col justify-end items-end gap-3">
                <div class="bg-slate-100 px-5 py-3 rounded-2xl inline-block">
                    <p class="text-[10px] font-bold text-gray-500 uppercase tracking-widest">Customer ID</p>
                    <p class="text-sm font-extrabold text-slate-900">#<?php echo str_pad($customer['customerNumber'], 5, '0', STR_PAD_LEFT); ?></p>
                </div>
                <button onclick="window.print()" class="no-print flex items-center gap-2 px-6 py-3 bg-slate-900 text-white text-xs font-bold uppercase tracking-widest rounded-xl hover:bg-slate-800 transition-all shadow-lg shadow-slate-900/20">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                    Print Statement
                </button>
            </div>
        </div>

        <!-- Enhanced Ledger Table -->
        <div class="px-8 md:px-12 pb-12">
            <div class="overflow-hidden rounded-2xl border border-gray-100 shadow-sm">
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-slate-50 text-left border-b border-gray-100">
                            <th class="px-6 py-4 text-[10px] font-black uppercase text-gray-400 tracking-widest">Date</th>
                            <th class="px-6 py-4 text-[10px] font-black uppercase text-gray-400 tracking-widest">Transaction Info</th>
                            <th class="px-6 py-4 text-[10px] font-black uppercase text-gray-400 tracking-widest text-center">Volume (USD)</th>
                            <th class="px-6 py-4 text-[10px] font-black uppercase text-gray-400 tracking-widest text-right">Debit (৳)</th>
                            <th class="px-6 py-4 text-[10px] font-black uppercase text-gray-400 tracking-widest text-right">Credit (৳)</th>
                            <th class="px-6 py-4 text-[10px] font-black uppercase text-gray-400 tracking-widest text-right">Balance (৳)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50 bg-white">
                        <?php 
                        $runningBalance = 0;
                        foreach ($ledger as $item): 
                            $runningBalance += ($item['credit'] - $item['debit']);
                            $isPayment = ($item['type'] === 'Payment');
                            $balanceColor = ($runningBalance >= 0) ? 'text-emerald-600' : 'text-rose-600';
                        ?>
                        <tr class="hover:bg-slate-50/50 transition-colors <?php echo $isPayment ? 'bg-emerald-50/5' : ''; ?>">
                            <td class="px-6 py-5 text-xs text-gray-500 font-bold whitespace-nowrap">
                                <?php echo date('d M, Y', strtotime($item['date'])); ?>
                            </td>
                            <td class="px-6 py-5">
                                <div class="flex items-center gap-3">
                                    <span class="px-2.5 py-1 rounded-lg text-[9px] font-black uppercase border <?php echo $isPayment ? 'bg-emerald-100 text-emerald-700 border-emerald-200' : 'bg-blue-100 text-blue-700 border-blue-200'; ?>">
                                        <?php echo $item['type']; ?>
                                    </span>
                                    <span class="text-sm font-bold text-slate-900">#<?php echo htmlspecialchars($item['ref']); ?></span>
                                </div>
                            </td>
                            <td class="px-6 py-5 text-center text-sm font-bold <?php echo $item['qty'] > 0 ? 'text-blue-600' : 'text-gray-300'; ?>">
                                <?php echo $item['qty'] > 0 ? number_format($item['qty'], 2) : '-'; ?>
                            </td>
                            <td class="px-6 py-5 text-right text-sm font-bold text-rose-600">
                                <?php echo $item['debit'] > 0 ? number_format($item['debit'], 2) : '-'; ?>
                            </td>
                            <td class="px-6 py-5 text-right text-sm font-bold text-emerald-600">
                                <?php echo $item['credit'] > 0 ? number_format($item['credit'], 2) : '-'; ?>
                            </td>
                            <td class="px-6 py-5 text-right text-sm font-extrabold <?php echo $balanceColor; ?> whitespace-nowrap">
                                ৳ <?php echo number_format($runningBalance, 2); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-slate-900 text-white font-bold">
                        <tr>
                            <td colspan="2" class="px-6 py-6 text-right uppercase tracking-[0.2em] text-[10px] text-slate-400">Grand Totals:</td>
                            <td class="px-6 py-6 text-center text-lg font-black text-blue-400 border-x border-white/5">
                                <?php echo number_format($totalVolumeUSD, 2); ?>
                            </td>
                            <td class="px-6 py-6 text-right text-lg font-black text-rose-400 border-x border-white/5">
                                <?php echo number_format($totalBilled, 2); ?>
                            </td>
                            <td class="px-6 py-6 text-right text-lg font-black text-emerald-400 border-x border-white/5">
                                <?php echo number_format($totalPaid, 2); ?>
                            </td>
                            <td class="px-6 py-6 text-right text-2xl font-black text-white whitespace-nowrap border-l border-white/5">
                                ৳ <?php echo number_format($currentNetBalance, 2); ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Footer -->
        <div class="p-10 bg-slate-50 border-t border-gray-100 flex flex-col md:flex-row justify-between items-center gap-6">
            <div class="text-[10px] font-bold text-gray-400 uppercase tracking-[0.3em] italic">
                Thank you for your valued business.
            </div>
            <div class="text-[9px] font-black text-slate-300 uppercase tracking-widest">
                Computer Generated Document - No Signature Required
            </div>
        </div>
    </div>
    
    <div class="max-w-5xl mx-auto mt-8 text-center no-print">
        <p class="text-xs text-gray-400 font-medium italic">Pro Invoice Management System v2.0</p>
    </div>
</body>
</html>
<?php $conn->close(); ?>
