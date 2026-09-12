<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AttendanceUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $attendanceId,
        public int $employeeId,
        public string $action,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('attendance')];
    }

    public function broadcastAs(): string
    {
        return 'AttendanceUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'attendance_id' => $this->attendanceId,
            'employee_id' => $this->employeeId,
            'action' => $this->action,
        ];
    }
}
