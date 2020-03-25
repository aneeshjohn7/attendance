<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    /**
     * checkIn
     * Allows users to check in for work within specified hours (between 9 AM and 5 PM) and records their attendance along with late time if they check in after 9 AM. If the user tries to check in outside of these hours or if they have already checked in for the day, appropriate error messages are displayed.
     * Testing dates
     * @return void
     */
    public function checkIn()
    {
        // Gets the current time using now() and creates Carbon instances representing 9 AM and 5 PM.

        $currentTime = now();
        $nineAm = Carbon::createFromTime(9, 0, 0); // 9 AM
        $fivePm = Carbon::createFromTime(17, 0, 0); // 5 PM
        // Checks if the current time is beyond 5 PM or before 9 AM. If so, it displays an error message indicating that the user cannot check in at that time and redirects back.
        if ($currentTime->greaterThan($fivePm)) {
            // Outside working hours, can't check in
            notify()->error("You cannot check-in after 5 PM.");
            return redirect()->back();
        }

        if ($currentTime->lessThan($nineAm)) {
            // Outside working hours, can't check in yet
            notify()->error("You can check-in from 9 AM.");
            return redirect()->back();
        }
        // queries the database to check if there's already an attendance record for the current user and date. If such a record exists, it displays an error message indicating that the attendance has already been given and redirects back.
        $existingAttendance = Attendance::where(
            "employee_id",
            auth()->user()->id
        )
            ->whereDate("select_date", now()->toDateString())
            ->first();

        if ($existingAttendance) {
            notify()->error("Attendance already given.");
            return redirect()->back();
        }
        // If no existing attendance record is found, it calculates the late time by finding the difference between the current time and 9 AM.
        $late = $currentTime->diff($nineAm)->format("%H:%I:%S");
        // Retrieves the current month name.
        $currentMonth = Carbon::now()->monthName;

        // Creates a new attendance record in the database using the Attendance model's create() method. The record includes details such as the employee ID, name, department name, designation name, check-in time, select date (today's date), month, and late time.
        Attendance::create([
            "employee_id" => auth()->user()->id,
            "name" => auth()->user()->name,
            "department_name" =>
            optional(auth()->user()->employee->department)
                ->department_name ?? "Not specified",
            "designation_name" =>
            optional(auth()->user()->employee->designation)
                ->designation_name ?? "Not specified",
            "check_in" => $currentTime->format("H:i:s"),
            "check_out" => null,
            "select_date" => now(),
            "month" => $currentMonth,
            "late" => $late, // Save late time
        ]);
        // Displays a success message indicating that the attendance has been given successfully
        notify()->success("Attendance given successfully.");
        // Redirects the user back to the previous page
        return redirect()->back();
    }

    /**
     * checkOut
     * Manages the process of checking out from work, calculates overtime if applicable, and updates the relevant attendance records accordingly. It also handles error cases such as attempting to check out without a corresponding check-in record or attempting to check out multiple times in a day.
     * @return void
     */
    public function checkOut()
    {
        // Queries the database to find an attendance record for the currently authenticated user for the current date.
        $existingAttendance = Attendance::where(
            "employee_id",
            auth()->user()->id
        )
            ->whereDate("select_date", now()->toDateString())
            ->first();
        // If an attendance record is found, it proceeds to calculate overtime and update the check-out time.
        if ($existingAttendance) {
            // calculates the check-in time from the attendance record and the current time as the check-out time. It also defines the regular working hours as 5 PM based on the check-in time.
            $checkInTime = Carbon::createFromTimeString(
                $existingAttendance->check_in
            );
            $checkOutTime = now();
            $regularWorkingHours = $checkInTime->copy()->setTime(17, 0, 0);

            // calculates overtime by finding the difference between the check-out time and the regular working hours.
            $overtime = $checkOutTime
                ->diff($regularWorkingHours)
                ->format("%H:%I:%S");
            // If the check-out time is greater than the regular working hours, it means the employee checked out after regular working hours. In this case, it updates the overtime field in the attendance record with the calculated overtime and displays an information message about the overtime.
            if ($checkOutTime->greaterThan($regularWorkingHours)) {
                // If checked out after regular working hours, calculate overtime
                $existingAttendance->update([
                    "overtime" => $overtime,
                ]);

                notify()->info("Overtime: $overtime");
            } else {
                // If the check-out time is within regular working hours, sets the overtime field to null in the attendance record.
                $existingAttendance->update([
                    "overtime" => null,
                ]);
            }
            // It checks if the employee has already checked out for the day. If so, it displays an error message indicating that they have already checked out and redirects back.
            if ($existingAttendance->check_out !== null) {
                notify()->error("You have already checked out for today.");
                return redirect()->back();
            }
            // Updates the check-out time in the attendance record with the current time.
            $existingAttendance->update([
                "check_out" => $checkOutTime->format("H:i:s"),
            ]);

            // Calculates the duration of the work session by finding the difference in minutes between the check-out time and the check-in time, and updates the duration in the attendance record.
            $duration = $checkOutTime->diffInMinutes($checkInTime);
            $existingAttendance->update([
                "duration_minutes" => $duration,
            ]);
            // Displays a success message indicating that the employee has checked out successfully.
            notify()->success("You have checked out successfully.");
        } else {
            // If no attendance record is found for the current user and date, it displays an error message indicating that no check-in was found.
            notify()->error("No check-in found for today.");
        }
        // Redirects the user back to the previous page.
        return redirect()->back();
    }

    /**
     * storeLeave
     * Handles the creation of new leave requests while performing various checks to ensure that the request is valid and within the employee's available leave quota and administrative approval status.
     * @param  mixed $request
     * @return void
     */
    public function storeLeave(Request $request)
    {
        // validates the incoming request data using Laravel's Validator class. It checks if the 'from_date', 'to_date', 'leave_type_id', and 'description' fields are present and meet certain criteria (such as being dates and the 'to_date' being after or equal to the 'from_date').
        $validate = Validator::make($request->all(), [
            "from_date" => "required|date",
            "to_date" => "required|date|after_or_equal:from_date",
            "leave_type_id" => "required",
            "description" => "required",
        ]);
        // If validation fails, it displays error messages containing the validation errors and redirects back to the previous page.
        if ($validate->fails()) {
            notify()->error($validate->getMessageBag());
            return redirect()->back();
        }

        $today = Carbon::today();
        $fromDate = Carbon::parse($request->from_date);

        // Checks if the 'from_date' is a future date. If it's not, it displays an error message indicating that the leave start date should be a future date and redirects back.
        if ($fromDate->lessThanOrEqualTo($today)) {
            notify()->error("Leave start date should be a future date.");
            return redirect()->back();
        }

        // Retrieves the total available leave days for the specified leave type from the database.
        $fromDate = Carbon::parse($request->from_date);
        $toDate = Carbon::parse($request->to_date);
        $totalDays = $toDate->diffInDays($fromDate) + 1; // Calculate total days

        $leaveType = LeaveType::findOrFail($request->leave_type_id);
        $leaveTypeTotalDays = $leaveType->leave_days;

        $userId = auth()->user()->id;
        // calculates the total number of days taken for the specified leave type by the user, considering only approved leave requests.
        $totalTakenDaysForLeaveType = Leave::where("employee_id", $userId)
            ->where("leave_type_id", $request->leave_type_id)
            ->where("status", "approved")
            ->sum("total_days");
        // Checks if the total number of days requested for this leave type, when added to the total days already taken, exceeds the available leave days for this type. If it does, it displays an error message indicating that the leave request exceeds the available leave days and redirects back.
        if ($totalTakenDaysForLeaveType + $totalDays > $leaveTypeTotalDays) {
            notify()->error("Exceeds available leave days for this type.");
            return redirect()->back();
        }

        // Checks if this is the first leave request for the employee. If it's not, it checks if the first leave request has been either rejected or approved by the admin. If it's rejected, it allows reapplication; otherwise, it displays an error message indicating that the employee cannot take leave until their first leave is approved by the admin.
        $firstLeave = Leave::where("employee_id", $userId)->count() === 0;

        if (!$firstLeave) {
            // Check if the employee's first leave is rejected or approved by the admin
            $firstLeaveStatus = Leave::where("employee_id", $userId)
                ->where("status", "!=", "pending") // Exclude pending status (includes rejected and approved)
                ->orderBy("created_at", "asc")
                ->value("status");

            if ($firstLeaveStatus === "rejected") {
                // Allow reapplication if the first leave was rejected
                $firstLeaveStatus = "approved";
            }

            if ($firstLeaveStatus !== "approved") {
                notify()->error(
                    "You cannot take leave until your first leave is approved by the admin."
                );
                return redirect()->back();
            }
        }

        // Check if the previous leave's end date has passed
        $previousLeaveEndDate = Leave::where("employee_id", $userId)
            ->where("status", "approved")
            ->orderBy("to_date", "desc")
            ->value("to_date");
        // Checks if the end date of the previous approved leave request has passed. If it hasn't, it displays an error message indicating that the employee cannot take leave until the previous leave date is over.
        if (
            $previousLeaveEndDate &&
            Carbon::parse($previousLeaveEndDate)->isFuture()
        ) {
            notify()->error(
                "You cannot take leave until your previous leave date is over."
            );
            return redirect()->back();
        }
        // If all check pass, create a new leave record in the database using the Leave model's create() method. It includes details such as employee name, department name, designation name, employee ID, leave start and end dates, total days, leave type ID, and description.
        Leave::create([
            "employee_name" => auth()->user()->name,
            "department_name" =>
            optional(auth()->user()->employee->department)
                ->department_name ?? "Not specified",
            "designation_name" =>
            optional(auth()->user()->employee->designation)
                ->designation_name ?? "Not specified",
            "employee_id" => $userId,
            "from_date" => $fromDate,
            "to_date" => $toDate,
            "total_days" => $totalDays,
            "leave_type_id" => $request->leave_type_id,
            "description" => $request->description,
        ]);
        // Display a success message indicating that the new leave request has been created successfully.
        notify()->success("New Leave created");
        // Redirect the user back to the previous page.
        return redirect()->back();
    }
}
