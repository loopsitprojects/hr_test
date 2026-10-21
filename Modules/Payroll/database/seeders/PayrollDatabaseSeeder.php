<?php

namespace Modules\Payroll\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Employee\Models\Employee;
use Modules\Payroll\Models\Payroll;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\LoanType;
use Modules\Payroll\Models\EmployeeLoan;
use Modules\Payroll\Models\SalaryAdvance;
use Modules\Payroll\Models\OvertimeRecord;
use Modules\Payroll\Models\LoanRepayment;
use Modules\Payroll\Services\PayrollCalculationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PayrollDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $employees = Employee::with(['user', 'department', 'designation'])->get();
        if ($employees->isEmpty()) {
            return;
        }

        // 1. Seed Standard Loan Types
        $loanDistress = LoanType::firstOrCreate(['code' => 'DISTRESS'], [
            'name' => 'Distress / Welfare Loan',
            'description' => 'Interest-free emergency financial assistance for employee medical or distress needs.',
            'max_amount' => 150000.00,
            'interest_rate_annual' => 0.00,
            'max_repayment_months' => 24,
            'status' => 'Active',
        ]);

        $loanHousing = LoanType::firstOrCreate(['code' => 'HOUSING'], [
            'name' => 'Housing & Renovation Loan',
            'description' => 'Subsidized low-interest loan for home purchase, building or renovation.',
            'max_amount' => 500000.00,
            'interest_rate_annual' => 4.50,
            'max_repayment_months' => 48,
            'status' => 'Active',
        ]);

        $loanFestival = LoanType::firstOrCreate(['code' => 'FESTIVAL'], [
            'name' => 'Festival Advance / Loan',
            'description' => 'Seasonal advance given during festival seasons.',
            'max_amount' => 50000.00,
            'interest_rate_annual' => 0.00,
            'max_repayment_months' => 10,
            'status' => 'Active',
        ]);

        // 2. Sample Active Loans & Advances on valid employees
        $empArosh = Employee::find(4) ?? $employees->skip(1)->first();
        if ($empArosh) {
            EmployeeLoan::firstOrCreate(['loan_number' => 'LN-2026-001'], [
                'employee_id' => $empArosh->id,
                'loan_type_id' => $loanHousing->id,
                'loan_title' => 'Housing Construction Loan',
                'principal_amount' => 360000.00,
                'interest_rate' => 4.50,
                'repayment_months' => 24,
                'monthly_installment' => 15000.00,
                'total_paid' => 45000.00,
                'remaining_balance' => 315000.00,
                'purpose' => 'House renovation and roof upgrades.',
                'status' => 'Active',
                'start_date' => '2026-05-01',
                'applied_at' => '2026-04-20 09:15:00',
                'approved_at' => '2026-04-25 16:00:00',
                'approved_by_user_id' => 3,
            ]);
        }

        $empShimal = Employee::find(5) ?? $employees->skip(2)->first();
        if ($empShimal) {
            SalaryAdvance::firstOrCreate(['advance_number' => 'ADV-2026-001'], [
                'employee_id' => $empShimal->id,
                'amount' => 15000.00,
                'month' => 9,
                'year' => 2026,
                'reason' => 'Emergency medical checkup',
                'status' => 'Approved',
                'requested_date' => '2026-09-01',
                'approved_at' => '2026-09-03 11:20:00',
                'approved_by_user_id' => 3,
            ]);

            $hourlyRate = round(($empShimal->basic_salary ?: 150000) / 200, 2);
            OvertimeRecord::firstOrCreate(['ot_number' => 'OT-2026-001'], [
                'employee_id' => $empShimal->id,
                'ot_date' => '2026-09-15',
                'start_time' => '17:30',
                'end_time' => '20:30',
                'hours' => 3.0,
                'rate_multiplier_type' => 'Standard (1.5x)',
                'multiplier' => 1.5,
                'hourly_rate' => $hourlyRate,
                'estimated_amount' => round(3.0 * $hourlyRate * 1.5, 2),
                'reason' => 'Production sprint deployment & release testing.',
                'status' => 'Approved',
                'approved_at' => '2026-09-16 10:00:00',
                'approved_by_user_id' => 3,
            ]);
        }

        // 3. Process August 2026, September 2026, and October 2026 Payroll Cycles for ALL Employees
        $calcService = app(PayrollCalculationService::class);
        $cycles = [
            ['month' => 8, 'year' => 2026],
            ['month' => 9, 'year' => 2026],
            ['month' => 10, 'year' => 2026],
        ];

        foreach ($cycles as $cycle) {
            $m = $cycle['month'];
            $y = $cycle['year'];
            $monthName = Carbon::createFromDate($y, $m, 1)->format('F');
            $cycleName = "{$monthName} {$y} Payroll";

            $payroll = Payroll::firstOrCreate(
                ['month' => $m, 'year' => $y],
                ['cycle_name' => $cycleName, 'status' => 'Processed', 'processed_by_user_id' => 3]
            );

            $totBasic = 0;
            $totAllowances = 0;
            $totGross = 0;
            $totEpfQualifying = 0;
            $totEpfEmp = 0;
            $totEpfEmployer = 0;
            $totEtfEmployer = 0;
            $totApit = 0;
            $totNet = 0;
            $totDirectSalaries = 0;
            $totIndirectSalaries = 0;
            $totDirectEpfEmployer = 0;
            $totIndirectEpfEmployer = 0;
            $totDirectEtfEmployer = 0;
            $totIndirectEtfEmployer = 0;
            $totExpenseRecovery = 0;
            $totLoanDeductions = 0;
            $totAdvanceDeductions = 0;

            foreach ($employees as $emp) {
                $calc = $calcService->calculateEmployeePayroll($emp, $m, $y);

                Payslip::updateOrCreate(
                    ['payroll_id' => $payroll->id, 'employee_id' => $emp->id],
                    [
                        'month' => $m,
                        'year' => $y,
                        'staff_category' => $calc['staff_category'],
                        'payment_method' => $calc['payment_method'],
                        'department_name' => $calc['department_name'],
                        'cost_classification' => $calc['cost_classification'],

                        'basic_salary' => $calc['basic_salary'],
                        'increments_basic' => $calc['increments_basic'],
                        'budget_allowance' => $calc['budget_allowance'],
                        'total_base_pay' => $calc['total_base_pay'],

                        'travelling_allowance' => $calc['travelling_allowance'],
                        'cost_of_living_allowance' => $calc['cost_of_living_allowance'],
                        'increments_allowance' => $calc['increments_allowance'],
                        'fixed_allowance' => $calc['fixed_allowance'],
                        'total_fixed_allowance' => $calc['total_fixed_allowance'],

                        'ot_hours' => $calc['ot_hours'],
                        'ot_amount' => $calc['ot_amount'],
                        'shift_allowance' => $calc['shift_allowance'],
                        'performance_incentive' => $calc['incentive_commission'],
                        'incentive_commission' => $calc['incentive_commission'],
                        'salary_arrears_basic' => $calc['salary_arrears_basic'],
                        'salary_arrears_allowance' => $calc['salary_arrears_allowance'],
                        'total_variable_pay' => $calc['total_variable_pay'],

                        'no_pay_days' => $calc['no_pay_days'],
                        'no_pay_basic_deduction' => $calc['no_pay_basic_deduction'],
                        'no_pay_allowance_deduction' => $calc['no_pay_allowance_deduction'],
                        'total_no_pay_deduction' => $calc['total_no_pay_deduction'],
                        'no_pay_deduction' => $calc['total_no_pay_deduction'],

                        'gross_salary' => $calc['gross_salary'],
                        'total_for_epf' => $calc['total_for_epf'],
                        'epf_employee' => $calc['epf_employee'],
                        'epf_employer' => $calc['epf_employer'],
                        'etf_employer' => $calc['etf_employer'],
                        'apit_tax' => $calc['apit_tax'],

                        'loan_installment' => $calc['loan_installment'],
                        'salary_advance' => $calc['salary_advance'],
                        'personal_expense_recovery' => $calc['personal_expense_recovery'],
                        'other_deductions' => $calc['other_deductions'],
                        'total_deductions' => $calc['total_deductions'],

                        'net_salary' => $calc['net_salary'],
                        'status' => 'Processed',
                    ]
                );

                $totBasic += $calc['total_base_pay'];
                $totAllowances += ($calc['total_fixed_allowance'] + $calc['total_variable_pay']);
                $totGross += $calc['gross_salary'];
                $totEpfQualifying += $calc['total_for_epf'];
                $totEpfEmp += $calc['epf_employee'];
                $totEpfEmployer += $calc['epf_employer'];
                $totEtfEmployer += $calc['etf_employer'];
                $totApit += $calc['apit_tax'];
                $totNet += $calc['net_salary'];
                $totExpenseRecovery += $calc['personal_expense_recovery'];
                $totLoanDeductions += $calc['loan_installment'];
                $totAdvanceDeductions += $calc['salary_advance'];

                if ($calc['cost_classification'] === 'Direct') {
                    $totDirectSalaries += $calc['gross_salary'];
                    $totDirectEpfEmployer += $calc['epf_employer'];
                    $totDirectEtfEmployer += $calc['etf_employer'];
                } else {
                    $totIndirectSalaries += $calc['gross_salary'];
                    $totIndirectEpfEmployer += $calc['epf_employer'];
                    $totIndirectEtfEmployer += $calc['etf_employer'];
                }
            }

            $payroll->update([
                'cycle_name' => $cycleName,
                'status' => 'Processed',
                'total_basic' => $totBasic,
                'total_allowances' => $totAllowances,
                'total_gross' => $totGross,
                'total_epf_qualifying' => $totEpfQualifying,
                'total_epf_employee' => $totEpfEmp,
                'total_epf_employer' => $totEpfEmployer,
                'total_etf_employer' => $totEtfEmployer,
                'total_apit_tax' => $totApit,
                'total_net_pay' => $totNet,
                'total_direct_salaries' => $totDirectSalaries,
                'total_indirect_salaries' => $totIndirectSalaries,
                'total_direct_epf_employer' => $totDirectEpfEmployer,
                'total_indirect_epf_employer' => $totIndirectEpfEmployer,
                'total_direct_etf_employer' => $totDirectEtfEmployer,
                'total_indirect_etf_employer' => $totIndirectEtfEmployer,
                'total_expense_recovery' => $totExpenseRecovery,
                'total_loan_deductions' => $totLoanDeductions,
                'total_advance_deductions' => $totAdvanceDeductions,
                'processed_at' => now(),
            ]);
        }
    }
}
