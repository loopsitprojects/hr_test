<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== LEAVE TYPES ===\n";
foreach (\Modules\Leave\Models\LeaveType::all() as $t) {
    echo "ID: {$t->id} | Name: {$t->name} | Code: {$t->code} | Days: {$t->days_allowed}\n";
}

echo "\n=== EMPLOYEES ===\n";
foreach (\Modules\Employee\Models\Employee::with(['user', 'department', 'designation'])->get() as $e) {
    $dept = $e->department->name ?? 'N/A';
    $desig = $e->designation->title ?? 'N/A';
    echo "ID: {$e->id} | EmpNo: {$e->employee_id_number} | Name: {$e->user->name} | Dept: {$dept} | Desig: {$desig}\n";
}

echo "\n=== ATTENDANCE & LEAVES STATS ===\n";
echo "Total Attendances: " . \Modules\Attendance\Models\Attendance::count() . "\n";
echo "Min Date: " . \Modules\Attendance\Models\Attendance::min('date') . "\n";
echo "Max Date: " . \Modules\Attendance\Models\Attendance::max('date') . "\n";
echo "Total Leave Requests: " . \Modules\Leave\Models\LeaveRequest::count() . "\n";
foreach (\Modules\Leave\Models\LeaveRequest::with('leaveType')->get() as $lr) {
    echo "ID: {$lr->id} | Emp: {$lr->employee_id} | Type: " . ($lr->leaveType->name ?? 'N/A') . " | Start: {$lr->start_date} | End: {$lr->end_date} | Duration: {$lr->duration} | Status: {$lr->status}\n";
}

