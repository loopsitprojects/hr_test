<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use PhpOffice\PhpSpreadsheet\IOFactory;
use Modules\Employee\Models\Department;
use Modules\Employee\Models\Designation;
use Modules\Employee\Models\Employee;
use App\Models\User;
use Modules\Leave\Models\LeaveType;
use Modules\Leave\Models\LeaveRequest;
use Modules\Leave\Models\EmployeeLeaveBalance;
use Modules\Attendance\Models\Attendance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

$file = 'C:/Users/LENOVO/.gemini/antigravity/brain/12d2c8e6-4cdb-4b66-a5d2-40c0eb786534/.user_uploaded/media_1792555628426_dfbd01ed.xlsx';
if (!file_exists($file)) {
    die("File not found: $file\n");
}

echo "Loading workbook: $file\n";
$spreadsheet = IOFactory::load($file);

// 1. Clean up legacy dummy records and old fake attendances
echo "Cleaning up legacy fake test records...\n";
// Remove attendances before 2026-09-01
Attendance::where('date', '<', '2026-09-01')->delete();
// Remove old dummy leave requests created before this dataset import
LeaveRequest::where('created_at', '<', '2026-10-21 00:00:00')->delete();
// Also remove test leaves on or after 2026-10-15 (future leaves created for dummy accounts)
LeaveRequest::where('start_date', '>', '2026-10-14')->delete();

// Delete dummy test employees who do not exist in Loops Digital (Anjalie ID 1, Sahan ID 2, Super Admin ID 6)
foreach ([1, 2, 6] as $dummyId) {
    Attendance::where('employee_id', $dummyId)->delete();
    LeaveRequest::where('employee_id', $dummyId)->delete();
    EmployeeLeaveBalance::where('employee_id', $dummyId)->delete();
    $emp = Employee::find($dummyId);
    if ($emp) {
        $userId = $emp->user_id;
        $emp->delete();
        User::where('id', $userId)->delete();
        echo "Removed legacy dummy employee ID $dummyId\n";
    }
}

// 2. Department setup
$deptNameMap = [
    'Digital Marketing' => ['code' => 'DM', 'desc' => 'Digital Marketing & Social Media'],
    'Brand Management' => ['code' => 'BM', 'desc' => 'Brand Strategy & Client Accounts'],
    'IT' => ['code' => 'IT', 'desc' => 'Information Technology & Development'],
    'Business Development' => ['code' => 'BD', 'desc' => 'Sales & Business Growth'],
    'Finance' => ['code' => 'FIN', 'desc' => 'Accounting & Finance'],
    'Creative' => ['code' => 'CR', 'desc' => 'Design & Creative Direction'],
    'HR & Admin' => ['code' => 'HR', 'desc' => 'Human Resources & Administration'],
    'Management' => ['code' => 'MGMT', 'desc' => 'Executive Management'],
];

$dbDepts = [];
foreach ($deptNameMap as $deptName => $meta) {
    $d = Department::where('name', $deptName)->first();
    if (!$d && $deptName === 'HR & Admin') {
        $d = Department::where('name', 'HR/Admin')->first();
    }
    if (!$d) {
        $d = Department::create([
            'name' => $deptName,
            'code' => $meta['code'],
            'description' => $meta['desc'],
        ]);
    }
    $dbDepts[$deptName] = $d;
    if ($deptName === 'HR & Admin') {
        $dbDepts['HR/Admin'] = $d;
    }
}

function getOrCreateDesignation($title, $deptId) {
    $clean = trim(preg_replace('/\s+/', ' ', $title));
    if (empty($clean)) $clean = 'Executive';
    $desig = Designation::where('name', $clean)->first();
    if (!$desig) {
        $desig = Designation::create(['name' => $clean, 'department_id' => $deptId]);
    }
    return $desig;
}

$leaveTypeMap = [
    'Annual' => 1,
    'Annual Leave' => 1,
    'Casual' => 2,
    'Casual Leave' => 2,
    'Medical' => 3,
    'Medical / Sick Leave' => 3,
    'Medical Leave' => 3,
    'Duty Leave' => 6,
    'Lieu Leave' => 7,
];

// Clean existing September-October attendances and leave requests so we re-import with 100% precision
echo "Purging old import attendances and leave requests to re-sync with 100% accuracy...\n";
Attendance::whereBetween('date', ['2026-09-01', '2026-10-14'])->delete();
LeaveRequest::truncate();

$totalEmployeesImported = 0;
$totalAttendancesImported = 0;
$totalLeavesImported = 0;
$lrSeq = 1;

foreach ($spreadsheet->getSheetNames() as $sheetName) {
    $sheet = $spreadsheet->getSheetByName($sheetName);
    $empNo = trim($sheet->getCell('D7')->getFormattedValue());
    $rawName = trim($sheet->getCell('D8')->getFormattedValue());
    $cleanName = trim(preg_replace('/\s+/', ' ', $rawName));
    $desigTitle = trim($sheet->getCell('D9')->getFormattedValue());
    $deptName = trim($sheet->getCell('H8')->getFormattedValue());
    $teamName = trim($sheet->getCell('H9')->getFormattedValue());

    if (empty($empNo) && empty($cleanName)) continue;

    // Resolve Department & Designation
    $dept = $dbDepts[$deptName] ?? ($dbDepts['HR & Admin'] ?? Department::first());
    $desig = getOrCreateDesignation($desigTitle, $dept->id);

    // Find or create Employee
    $emp = Employee::where('employee_id_number', $empNo)->first();
    if (!$emp) {
        $user = User::where('name', $cleanName)->first();
        if ($user) {
            $emp = Employee::where('user_id', $user->id)->first();
        }
    }

    if ($emp) {
        $emp->employee_id_number = $empNo;
        $emp->department_id = $dept->id;
        $emp->designation_id = $desig->id;
        $emp->save();

        if ($emp->user) {
            $emp->user->name = $cleanName;
            $emp->user->save();
        }
    } else {
        $emailName = strtolower(preg_replace('/[^a-z0-9]/', '.', strtolower($cleanName)));
        $email = "{$emailName}@loopshr.lk";
        if (User::where('email', $email)->exists()) {
            $email = "{$emailName}.{$empNo}@loopshr.lk";
        }
        $username = strtolower(preg_replace('/[^a-z0-9]/', '', strtolower($cleanName))) . $empNo;

        $user = User::create([
            'name' => $cleanName,
            'email' => $email,
            'username' => $username,
            'password' => Hash::make('password123'),
        ]);

        $role = 'Employee';
        if (in_array($empNo, ['9', '4'])) {
            $role = 'Super (Admin)';
        } elseif (in_array($deptName, ['HR & Admin', 'HR/Admin'])) {
            $role = 'HR Lead';
        } elseif (str_contains(strtolower($desigTitle), 'head') || str_contains(strtolower($desigTitle), 'director') || str_contains(strtolower($desigTitle), 'manager')) {
            $role = 'Manager (Team Approvals)';
        }

        $emp = Employee::create([
            'user_id' => $user->id,
            'employee_id_number' => $empNo,
            'department_id' => $dept->id,
            'designation_id' => $desig->id,
            'system_role' => $role,
            'joined_date' => '2025-01-01',
            'job_category' => 'Permanent',
            'staff_category' => 'Executive',
            'basic_salary' => 125000.00,
        ]);
    }

    $totalEmployeesImported++;

    // Ensure Standard Annual Quotas for 2026 (Annual: 14, Casual: 7, Medical: 7) = 28 days total
    $standardQuotas = [
        1 => 14, // Annual
        2 => 7,  // Casual
        3 => 7,  // Medical
        4 => 2,  // Short
        5 => 0,  // Half Day (part of casual/annual)
        6 => 0,  // Duty (special leave)
        7 => 0,  // Lieu (earned off)
    ];

    foreach ($standardQuotas as $tId => $allocatedDays) {
        EmployeeLeaveBalance::updateOrCreate(
            ['employee_id' => $emp->id, 'leave_type_id' => $tId, 'year' => 2026],
            ['allocated' => $allocatedDays, 'used' => 0.00, 'carried_forward' => 0.00, 'allocated_days' => 0.00, 'used_days' => 0.00]
        );
    }

    // Process Attendance & Leave Rows
    $highestRow = $sheet->getHighestRow();
    for ($r = 15; $r <= $highestRow; $r++) {
        $dateStr = trim($sheet->getCell('C' . $r)->getFormattedValue());
        if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dateStr)) continue;

        $date = Carbon::createFromFormat('d/m/Y', $dateStr)->format('Y-m-d');
        $shift = trim($sheet->getCell('D' . $r)->getFormattedValue());
        $day = trim($sheet->getCell('E' . $r)->getFormattedValue());
        $prAb = trim($sheet->getCell('F' . $r)->getFormattedValue());
        $in = trim($sheet->getCell('G' . $r)->getFormattedValue());
        $out = trim($sheet->getCell('H' . $r)->getFormattedValue());
        $in2 = trim($sheet->getCell('I' . $r)->getFormattedValue());
        $out2 = trim($sheet->getCell('K' . $r)->getFormattedValue());
        $late = trim($sheet->getCell('L' . $r)->getFormattedValue());
        $workHours = floatval($sheet->getCell('Q' . $r)->getFormattedValue());
        $leaveType = trim($sheet->getCell('S' . $r)->getFormattedValue());
        $leaveAmt = floatval($sheet->getCell('T' . $r)->getFormattedValue());

        $hasIn = ($in !== '' && $in !== '00:00');
        $hasOut = ($out !== '' && $out !== '00:00');

        // A. Leave Record Handling
        if ($leaveAmt > 0 || !empty($leaveType)) {
            $leaveTypeId = $leaveTypeMap[$leaveType] ?? 2;
            $duration = $leaveAmt > 0 ? $leaveAmt : 1.0;
            $leaveTypeObj = LeaveType::find($leaveTypeId);
            $typeName = $leaveTypeObj?->name ?? 'Casual Leave';

            LeaveRequest::create([
                'req_number' => 'LR-2026-' . str_pad($lrSeq++, 5, '0', STR_PAD_LEFT),
                'employee_id' => $emp->id,
                'leave_type_id' => $leaveTypeId,
                'start_date' => $date,
                'end_date' => $date,
                'duration' => $duration,
                'status' => 'Approved',
                'reason' => 'Approved ' . $typeName . ' (Biometric Record)',
                'covering_status' => 'Approved',
                'manager_status' => 'Approved',
                'hr_status' => 'Approved',
                'applied_at' => $date . ' 08:30:00',
                'is_half_day' => ($duration == 0.5 ? 1 : 0),
                'half_day_slot' => ($duration == 0.5 ? 'Morning' : null),
            ]);

            DB::table('employee_leave_balances')
                ->where('employee_id', $emp->id)
                ->where('leave_type_id', $leaveTypeId)
                ->where('year', 2026)
                ->increment('used', $duration);

            $totalLeavesImported++;
        }

        // B. Attendance Record Handling
        // Skip weekend / scheduled off-day if no punch and no leave
        if ($prAb === '-' && !$hasIn && !$hasOut && $leaveAmt == 0) {
            continue;
        }

        $clockIn = null;
        $clockOut = null;
        $status = 'Absent';
        $hours = $workHours; // Use exact machine-computed work hours

        if ($hasIn) {
            $clockIn = "{$date} {$in}:00";
            if ($hasOut) {
                $clockOut = "{$date} {$out}:00";
            } else {
                // If clock-out missing, do NOT invent fake times
                $clockOut = null;
            }

            if ($leaveAmt > 0) {
                $status = 'Half Day';
            } elseif (strcmp($in, '09:30') > 0) {
                $status = 'Late';
            } else {
                $status = 'Present';
            }
        } else {
            if ($leaveAmt > 0) {
                $status = 'On Leave';
            } else {
                $status = 'Absent';
            }
        }

        $regHours = min(8.0, $hours);
        $otHours = max(0.0, round($hours - 8.0, 2));

        Attendance::create([
            'employee_id' => $emp->id,
            'date' => $date,
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
            'total_hours' => $hours,
            'regular_hours' => $regHours,
            'early_ot_hours' => 0.0,
            'late_ot_hours' => $otHours,
            'total_ot_hours' => $otHours,
            'status' => $status,
            'notes' => 'Biometric Sync',
        ]);

        $totalAttendancesImported++;
    }
}

echo "\n=== SYNC COMPLETE ===\n";
echo "Total Employees: $totalEmployeesImported\n";
echo "Total Attendances: $totalAttendancesImported\n";
echo "Total Approved Leaves: $totalLeavesImported\n";
