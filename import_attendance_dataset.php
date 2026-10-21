<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

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

$csvFile = base_path('storage/app/full_attendance_data.csv');
if (!file_exists($csvFile)) {
    die("CSV file not found at {$csvFile}\n");
}

$lines = file($csvFile);
echo "Total CSV lines: " . count($lines) . "\n";

// 1. Departments Map
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
    // Find department by name or code
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
echo "Configured " . count($dbDepts) . " department mappings.\n";

// 2. Leave Types Map
$leaveTypes = LeaveType::all()->keyBy('id');
$leaveTypeMap = [
    'Annual' => 1,
    'Annual Leave' => 1,
    'Casual' => 2,
    'Casual Leave' => 2,
    'Medical' => 3,
    'Medical / Sick Leave' => 3,
    'Medical Leave' => 3,
    'Short Leave' => 4,
    'Half Day' => 5,
    'Half Day Leave' => 5,
    'Duty Leave' => 6,
    'Lieu Leave' => 7,
];

// Helper to find or create designation
function getDesignation($title, $deptId) {
    $cleanTitle = trim(preg_replace('/\s+/', ' ', $title));
    if (empty($cleanTitle)) {
        $cleanTitle = 'Executive';
    }
    $desig = Designation::where('name', $cleanTitle)->first();
    if (!$desig) {
        $desig = Designation::create([
            'name' => $cleanTitle,
            'department_id' => $deptId,
        ]);
    }
    return $desig;
}

// 3. Parse CSV Employee Blocks and Data
$employeeBlocks = [];
$curBlock = null;

foreach ($lines as $line) {
    $row = str_getcsv($line);
    if (!isset($row[2])) continue;

    if ($row[2] === 'Number :') {
        if ($curBlock) {
            $employeeBlocks[] = $curBlock;
        }
        $curBlock = [
            'emp_no' => trim($row[3] ?? ''),
            'company' => trim($row[7] ?? ''),
            'rows' => [],
        ];
    } elseif ($row[2] === 'Name :' && $curBlock) {
        $curBlock['name'] = trim($row[3] ?? '');
        $curBlock['dept'] = trim($row[7] ?? '');
    } elseif ($row[2] === 'Designation :' && $curBlock) {
        $curBlock['desig'] = trim($row[3] ?? '');
        $curBlock['team'] = trim($row[7] ?? '');
    } elseif ($curBlock && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', trim($row[2]))) {
        $curBlock['rows'][] = $row;
    }
}
if ($curBlock) {
    $employeeBlocks[] = $curBlock;
}

echo "Extracted " . count($employeeBlocks) . " employee blocks from CSV.\n";

// Start DB Transaction for safe bulk import
DB::beginTransaction();

try {
    $importedEmployeesCount = 0;
    $importedAttendancesCount = 0;
    $importedLeavesCount = 0;

    // Track leave request numbers
    $lastLrId = LeaveRequest::max('id') ?? 0;
    $lrSequence = $lastLrId + 1;

    foreach ($employeeBlocks as $block) {
        $empNo = trim($block['emp_no']);
        $rawName = trim($block['name']);
        $cleanName = trim(preg_replace('/\s+/', ' ', $rawName));
        $deptRaw = trim($block['dept']);
        $desigRaw = trim($block['desig']);

        // Resolve Department
        $dept = $dbDepts[$deptRaw] ?? ($dbDepts['HR & Admin'] ?? Department::first());
        $deptId = $dept->id;

        // Resolve Designation
        $desig = getDesignation($desigRaw, $deptId);
        $desigId = $desig->id;

        // Check if employee already exists in DB
        $emp = null;
        if ($empNo === '111') {
            $emp = Employee::find(4); // Arosh
        } elseif ($empNo === '1000') {
            $emp = Employee::find(5); // Shimal
        } elseif ($empNo === '190') {
            $emp = Employee::find(3); // Supuni
        }

        if (!$emp) {
            $emp = Employee::where('employee_id_number', $empNo)->first();
        }

        if (!$emp) {
            // Check by User name exact or fuzzy
            $user = User::where('name', $cleanName)->first();
            if ($user) {
                $emp = Employee::where('user_id', $user->id)->first();
            }
        }

        if ($emp) {
            // Update existing employee records
            $emp->employee_id_number = $empNo;
            $emp->department_id = $deptId;
            $emp->designation_id = $desigId;
            $emp->save();

            // Update user name
            if ($emp->user) {
                $emp->user->name = $cleanName;
                $emp->user->save();
            }
        } else {
            // Create new User and Employee
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

            $systemRole = 'Employee';
            if (in_array($empNo, ['9', '4'])) {
                $systemRole = 'Super (Admin)';
            } elseif (in_array($deptRaw, ['HR & Admin', 'HR/Admin'])) {
                $systemRole = 'HR Lead';
            } elseif (str_contains(strtolower($desigRaw), 'head') || str_contains(strtolower($desigRaw), 'director') || str_contains(strtolower($desigRaw), 'manager')) {
                $systemRole = 'Manager (Team Approvals)';
            }

            $emp = Employee::create([
                'user_id' => $user->id,
                'employee_id_number' => $empNo,
                'department_id' => $deptId,
                'designation_id' => $desigId,
                'system_role' => $systemRole,
                'joined_date' => '2025-01-01',
                'job_category' => 'Permanent',
                'staff_category' => 'Executive',
                'basic_salary' => 125000.00,
            ]);
        }

        $importedEmployeesCount++;

        // Ensure 2026 Leave Balances exist
        $quotas = [
            1 => 14, // Annual
            2 => 7,  // Casual
            3 => 7,  // Medical
            4 => 2,  // Short
            5 => 7,  // Half Day
            6 => 30, // Duty
            7 => 5,  // Lieu
        ];

        foreach ($quotas as $typeId => $quotaDays) {
            EmployeeLeaveBalance::firstOrCreate(
                [
                    'employee_id' => $emp->id,
                    'leave_type_id' => $typeId,
                    'year' => 2026,
                ],
                [
                    'allocated' => $quotaDays,
                    'used' => 0.00,
                    'carried_forward' => 0.00,
                    'allocated_days' => 0.00,
                    'used_days' => 0.00,
                ]
            );
        }

        // Process Attendance and Leave Rows
        foreach ($block['rows'] as $r) {
            $rawDate = trim($r[2]);
            $date = Carbon::createFromFormat('d/m/Y', $rawDate)->format('Y-m-d');

            $shift = trim($r[3] ?? 'Default Shift');
            $day = trim($r[4] ?? 'Working Day');
            $prAb = trim($r[5] ?? '');
            $inStr = trim($r[6] ?? '');
            $outStr = trim($r[7] ?? '');
            $workHours = floatval($r[16] ?? 0);
            $leaveTypeRaw = trim($r[18] ?? '');
            $leaveAmount = floatval($r[19] ?? 0);

            $hasIn = ($inStr !== '' && $inStr !== '00:00');
            $hasOut = ($outStr !== '' && $outStr !== '00:00');

            // 1. Process Leave if present
            if ($leaveAmount > 0 || !empty($leaveTypeRaw)) {
                $leaveTypeId = $leaveTypeMap[$leaveTypeRaw] ?? 2; // Default to Casual
                $leaveTypeModel = $leaveTypes[$leaveTypeId] ?? null;
                $leaveTypeName = $leaveTypeModel?->name ?? 'Casual Leave';
                $actualDuration = $leaveAmount > 0 ? $leaveAmount : 1.0;

                // Check existing leave request
                $existingLr = LeaveRequest::where('employee_id', $emp->id)
                    ->where('start_date', $date)
                    ->where('leave_type_id', $leaveTypeId)
                    ->first();

                if (!$existingLr) {
                    LeaveRequest::create([
                        'req_number' => 'LR-2026-' . str_pad($lrSequence++, 5, '0', STR_PAD_LEFT),
                        'employee_id' => $emp->id,
                        'leave_type_id' => $leaveTypeId,
                        'start_date' => $date,
                        'end_date' => $date,
                        'duration' => $actualDuration,
                        'status' => 'Approved',
                        'reason' => 'Approved ' . $leaveTypeName . ' (Biometric Record)',
                        'covering_status' => 'Approved',
                        'manager_status' => 'Approved',
                        'hr_status' => 'Approved',
                        'applied_at' => $date . ' 08:30:00',
                        'is_half_day' => ($actualDuration == 0.5 ? 1 : 0),
                        'half_day_slot' => ($actualDuration == 0.5 ? 'Morning' : null),
                    ]);

                    // Increment employee leave balance
                    DB::table('employee_leave_balances')
                        ->where('employee_id', $emp->id)
                        ->where('leave_type_id', $leaveTypeId)
                        ->where('year', 2026)
                        ->increment('used', $actualDuration);

                    $importedLeavesCount++;
                }
            }

            // 2. Process Attendance Record
            // If it's a weekend or off-day with no clock-in and no leave, skip inserting empty off-day attendance
            if ($prAb === '-' && !$hasIn && !$hasOut && $leaveAmount == 0) {
                continue;
            }

            $clockIn = null;
            $clockOut = null;
            $hours = 0.0;
            $status = 'Absent';

            if ($hasIn) {
                $clockIn = "{$date} {$inStr}:00";
                if ($hasOut) {
                    $clockOut = "{$date} {$outStr}:00";
                    $hours = $workHours > 0 ? $workHours : round((strtotime($clockOut) - strtotime($clockIn)) / 3600, 2);
                } else {
                    // Estimated standard departure if missing clock_out punch
                    $outTime = (strcmp($inStr, '16:00') >= 0) ? date('H:i', strtotime($inStr) + 3600) : '17:00';
                    $clockOut = "{$date} {$outTime}:00";
                    $hours = max(1.0, round((strtotime($clockOut) - strtotime($clockIn)) / 3600, 2));
                }

                if ($leaveAmount > 0) {
                    $status = 'Half Day';
                } elseif (strcmp($inStr, '09:30') > 0) {
                    $status = 'Late';
                } else {
                    $status = 'Present';
                }
            } else {
                if ($leaveAmount > 0) {
                    $status = 'On Leave';
                } else {
                    $status = 'Absent';
                }
            }

            // OT hours calculation
            if ($hours > 8.0) {
                $regHours = 8.0;
                $otHours = round($hours - 8.0, 2);
            } else {
                $regHours = $hours;
                $otHours = 0.0;
            }

            Attendance::updateOrCreate(
                [
                    'employee_id' => $emp->id,
                    'date' => $date,
                ],
                [
                    'clock_in' => $clockIn,
                    'clock_out' => $clockOut,
                    'total_hours' => $hours,
                    'regular_hours' => $regHours,
                    'early_ot_hours' => 0.00,
                    'late_ot_hours' => $otHours,
                    'total_ot_hours' => $otHours,
                    'status' => $status,
                    'notes' => 'Biometric Sync',
                ]
            );

            $importedAttendancesCount++;
        }
    }

    DB::commit();
    echo "\n=== IMPORT COMPLETED SUCCESSFULLY ===\n";
    echo "Total Employees Processed: {$importedEmployeesCount}\n";
    echo "Total Attendances Inserted/Updated: {$importedAttendancesCount}\n";
    echo "Total Leave Requests Created & Approved: {$importedLeavesCount}\n";

} catch (\Exception $e) {
    DB::rollBack();
    echo "\n[ERROR] Import failed: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
