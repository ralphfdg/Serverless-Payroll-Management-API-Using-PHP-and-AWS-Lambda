<?php

require __DIR__ . '/vendor/autoload.php';

use Bramus\Router\Router;
use Dompdf\Dompdf;
use Dompdf\Options;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

$router = new Router();

/**
 * Helper: Send JSON Response
 */
function sendJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Helper: Read CSV file into an array of associative arrays
 */
function readCsv(string $filePath): array
{
    if (!file_exists($filePath) || !is_readable($filePath)) {
        sendJson([
            'success' => false,
            'message' => 'CSV file not found: ' . basename($filePath)
        ], 500);
    }

    $rows = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
        $headers = fgetcsv($handle);
        if ($headers !== false) {
            // Remove potential UTF-8 BOM from first header column
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
            $headers = array_map('trim', $headers);

            while (($data = fgetcsv($handle)) !== false) {
                if (count($headers) === count($data)) {
                    $rows[] = array_combine($headers, $data);
                }
            }
        }
        fclose($handle);
    }

    return $rows;
}

/**
 * Helper: Find a specific payroll record and join its employee details
 */
function getPayslipRecord(string $payrollId): ?array
{
    $payrolls = readCsv(__DIR__ . '/data/payroll.csv');
    $employees = readCsv(__DIR__ . '/data/employees.csv');

    // Index employees by their 'id' for fast lookup
    $employeeMap = [];
    foreach ($employees as $emp) {
        $employeeMap[$emp['id']] = $emp;
    }

    foreach ($payrolls as $row) {
        if ((string)$row['id'] === (string)$payrollId) {
            $empId = $row['employee_id'];
            $employee = $employeeMap[$empId] ?? null;

            $gross = (float)$row['gross_pay'];
            $net = (float)$row['net_pay'];
            $deductions = round($gross - $net, 2);

            return [
                'payroll_id'       => (int)$row['id'],
                'employee_id'      => (int)$empId,
                'employee_name'    => $employee ? $employee['full_name'] : 'Unknown Employee',
                'email'            => $employee['email'] ?? 'N/A',
                'department_id'    => isset($employee['department_id']) && $employee['department_id'] !== '' ? (int)$employee['department_id'] : null,
                'pay_period_start' => $row['pay_period_start'],
                'pay_period_end'   => $row['pay_period_end'],
                'gross_pay'        => round($gross, 2),
                'deductions'       => $deductions,
                'net_pay'          => round($net, 2),
            ];
        }
    }

    return null;
}

// Optional root health-check route
$router->get('/', function () {
    sendJson([
        'success' => true,
        'message' => 'Serverless Payroll Management API is running.'
    ]);
});

/**
 * ENDPOINT 1: GET /api/employees
 * Return employee records in JSON using actual column names and values.
 */
$router->get('/api/employees', function () {
    $employeesRaw = readCsv(__DIR__ . '/data/employees.csv');
    $employees = [];

    foreach ($employeesRaw as $emp) {
        $employees[] = [
            'id'            => (int)$emp['id'],
            'full_name'     => $emp['full_name'],
            'email'         => $emp['email'],
            'position_id'   => $emp['position_id'] !== '' ? (int)$emp['position_id'] : null,
            'department_id' => $emp['department_id'] !== '' ? (int)$emp['department_id'] : null,
            'manager_id'    => $emp['manager_id'] !== '' ? (int)$emp['manager_id'] : null,
        ];
    }

    sendJson([
        'success' => true,
        'data'    => $employees
    ]);
});

/**
 * ENDPOINT 2: GET /api/reports/payroll
 * Generate a payroll summary, including total gross pay, deductions, and net pay.
 */
$router->get('/api/reports/payroll', function () {
    $payrolls = readCsv(__DIR__ . '/data/payroll.csv');

    $totalRecords = 0;
    $totalGross = 0.0;
    $totalNet = 0.0;
    $totalDeductions = 0.0;

    foreach ($payrolls as $row) {
        $gross = (float)$row['gross_pay'];
        $net = (float)$row['net_pay'];
        $deductions = $gross - $net;

        $totalRecords++;
        $totalGross += $gross;
        $totalNet += $net;
        $totalDeductions += $deductions;
    }

    sendJson([
        'success' => true,
        'data'    => [
            'total_payroll_records' => $totalRecords,
            'total_gross_pay'       => round($totalGross, 2),
            'total_deductions'      => round($totalDeductions, 2),
            'total_net_pay'         => round($totalNet, 2),
        ]
    ]);
});

/**
 * ENDPOINT 3: GET /api/payslips/{payroll_id}
 * Return an individual payslip containing employee details and payroll figures.
 */
$router->get('/api/payslips/(\d+)', function ($payroll_id) {
    $payslip = getPayslipRecord((string)$payroll_id);

    if (!$payslip) {
        sendJson([
            'success' => false,
            'message' => "Payroll record {$payroll_id} not found."
        ], 404);
    }

    sendJson([
        'success' => true,
        'data'    => [
            'payroll_id'    => $payslip['payroll_id'],
            'employee_name' => $payslip['employee_name'],
            'gross_pay'     => $payslip['gross_pay'],
            'deductions'    => $payslip['deductions'],
            'net_pay'       => $payslip['net_pay']
        ]
    ]);
});

/**
 * ENDPOINT 4: POST /api/payslips/{payroll_id}/generate
 * Generate a PDF payslip, upload it to S3, and return the S3 object URL/key.
 */
$router->post('/api/payslips/(\d+)/generate', function ($payroll_id) {
    $payslip = getPayslipRecord((string)$payroll_id);

    if (!$payslip) {
        sendJson([
            'success' => false,
            'message' => "Payroll record {$payroll_id} not found."
        ], 404);
    }

    // 1. Build readable HTML for the PDF
    $grossFormatted      = number_format($payslip['gross_pay'], 2);
    $deductionsFormatted = number_format($payslip['deductions'], 2);
    $netFormatted        = number_format($payslip['net_pay'], 2);

    $html = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Payslip #{$payslip['payroll_id']}</title>
        <style>
            body { font-family: Helvetica, Arial, sans-serif; color: #333; margin: 40px; }
            h1 { color: #1e293b; border-bottom: 2px solid #1e293b; padding-bottom: 10px; }
            .section { margin-top: 20px; }
            table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            th, td { border: 1px solid #cbd5e1; padding: 10px; text-align: left; }
            th { background-color: #f1f5f9; }
            .total-row { font-weight: bold; background-color: #e2e8f0; }
        </style>
    </head>
    <body>
        <h1>Employee Payslip</h1>
        <div class='section'>
            <p><strong>Payroll ID:</strong> {$payslip['payroll_id']}</p>
            <p><strong>Employee ID:</strong> {$payslip['employee_id']}</p>
            <p><strong>Employee Name:</strong> {$payslip['employee_name']}</p>
            <p><strong>Email:</strong> {$payslip['email']}</p>
            <p><strong>Department ID:</strong> {$payslip['department_id']}</p>
            <p><strong>Pay Period:</strong> {$payslip['pay_period_start']} to {$payslip['pay_period_end']}</p>
        </div>

        <div class='section'>
            <h3>Payroll Summary</h3>
            <table>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Gross Pay</td>
                        <td>\${$grossFormatted}</td>
                    </tr>
                    <tr>
                        <td>Deductions</td>
                        <td>-\${$deductionsFormatted}</td>
                    </tr>
                    <tr class='total-row'>
                        <td>Net Pay</td>
                        <td>\${$netFormatted}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </body>
    </html>";

    // 2. Render PDF in memory using Dompdf (pointing temp directories to /tmp for AWS Lambda)
    $tempDir = sys_get_temp_dir();
    $options = new Options();
    $options->setTempDir($tempDir);
    $options->setFontDir($tempDir);
    $options->setFontCache($tempDir);
    $options->setChroot($tempDir);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $pdfContent = $dompdf->output();

    // 3. Upload PDF directly to S3
    $bucketName = getenv('S3_BUCKET_NAME') ?: 'ralph-payroll-bucket';
    $region     = getenv('AWS_REGION') ?: 'ap-southeast-2';
    $s3Key      = "payslips/{$payslip['payroll_id']}.pdf";

    try {
        $s3Client = new S3Client([
            'version' => 'latest',
            'region'  => $region,
        ]);

        $s3Client->putObject([
            'Bucket'      => $bucketName,
            'Key'         => $s3Key,
            'Body'        => $pdfContent,
            'ContentType' => 'application/pdf',
        ]);

        $s3Url = "https://{$bucketName}.s3.{$region}.amazonaws.com/{$s3Key}";

        sendJson([
            'success' => true,
            'message' => 'Payslip generated and uploaded.',
            'data'    => [
                'payroll_id'   => $payslip['payroll_id'],
                'payslip_file' => $s3Url
            ]
        ]);
    } catch (AwsException $e) {
        sendJson([
            'success' => false,
            'message' => 'Failed to upload PDF to S3: ' . $e->getAwsErrorMessage()
        ], 500);
    }
});

$router->run();