# DMD IoT Phase 5: Reliability and Windows startup

## Bridge configuration

Copy `iot-bridge/.env.example` to `iot-bridge/.env` for local use. The
`.env` file is ignored and must contain the real local values. The bridge
also accepts process environment variables, which take precedence.

Set `DMD_IOT_SERIAL_PORT=COM3` when the port is known. If it is empty and
`DMD_IOT_AUTO_DETECT_SERIAL=true`, the bridge examines pyserial descriptors.
It connects only when exactly one plausible Arduino/Mega/USB-serial device is
found. Zero or multiple matches are not selected; the bridge reports a clear
diagnostic and waits/retries so the Arduino can be connected later. A manual
launch shows the same diagnostic in its console.

Attendance requests use a fresh HMAC timestamp, nonce, and signature for every
attempt. Network/transient failures receive one bounded retry by default.
Authentication and business validation failures are not retried.

For temporary diagnosis, set `IOT_TIMING_LOGGING=true` in Laravel and inspect
`storage/logs/laravel.log`. The attendance controller records employee lookup,
transaction, audit, broadcast dispatch, and total durations. Disable it after
diagnosis.

## Windows Task Scheduler Setup

The scheduled task is tied to the Windows computer/user session, not to a
DMD Admin, Manager, or Front Desk login. Do not create or modify the task from
the application UI.

### Recommended task configuration

1. Install Python and the bridge dependencies (`requests`, `pyserial`).
2. Create `iot-bridge/.env` from `.env.example` and set the local values.
   Keep the file ACL restricted to the Windows account that runs the bridge.
3. Run `D:\dmdresort\iot-bridge\start_bridge.bat` manually once and confirm
   the bridge connects or waits for the Arduino.
4. Open the Windows Start menu and launch **Task Scheduler**.
5. In the right-hand Actions pane, select **Create Task...** (not Basic Task).
6. **General** tab:
   - Name: `DMD Fingerprint Bridge`
   - Select the Windows operator account used for the resort PC.
   - Select **Run only when user is logged on** for the local USB device.
   - Select **Run with highest privileges** only if the local Python/COM
     installation requires it.
   - Select **Hidden** if the bridge should run unobtrusively. The BAT file
     remains available for visible manual debugging.
7. **Triggers** tab:
   - Click **New...**
   - Begin the task: **At log on**
   - Select the specific resort Windows account.
   - Enable the trigger and save it.
8. **Actions** tab:
   - Click **New...**
   - Action: **Start a program**
   - Program/script: `D:\dmdresort\iot-bridge\start_bridge.bat`
   - Start in: `D:\dmdresort\iot-bridge`
   - Leave **Add arguments** empty.
   - Never put either IoT secret in the action or arguments.
9. **Conditions** tab for a laptop:
   - Clear **Start the task only if the computer is on AC power** if the
     bridge must work on battery.
   - Clear **Stop if the computer switches to battery power**.
   - Do not require idle time.
10. **Settings** tab:
    - Enable **Allow task to be run on demand**.
    - Set **If the task is already running** to **Do not start a new
      instance**.
    - Enable restart on failure with a **1 minute** restart interval.
    - Set the restart attempt count to **3**.
    - Do not set a short execution time limit; the bridge is intended to run
      continuously.
11. Click **OK**. If Windows asks for credentials, enter them through Task
    Scheduler; do not place them in a script or command line.

### Manual task controls

- Right-click the task → **Run** to test it manually.
- Right-click the task → **End** to stop it.
- Right-click the task → **Disable** to stop automatic logon startup.
- Right-click the task → **Enable** to restore automatic startup.
- Review the task **History** tab and **Last Run Result** for failures.
- Run `start_bridge.bat` manually in a console for detailed Python output.

The bridge remains loopback-only on `127.0.0.1:8765`. The launcher does not
contain credentials.

## Background Task Scheduler Launcher

For normal resort operation, use the background launcher instead of the
visible developer BAT file:

```text
D:\dmdresort\iot-bridge\start_bridge_background.vbs
```

This uses Windows `wscript.exe` to launch `pythonw.exe` with no CMD,
PowerShell, or Windows Terminal window. The VBS waits for the Python process,
so Task Scheduler continues tracking the bridge and **End task** can stop it.
The Python process still loads `iot-bridge\.env` itself, preserves the named
mutex, COM auto-detection, reconnect behavior, HMAC signing, and local API.

The existing `start_bridge.bat` is intentionally unchanged as the visible
developer/debug launcher.

### Edit the existing scheduled task

1. Open **Task Scheduler** and locate `DMD Fingerprint Bridge`.
2. Right-click it → **Properties** → **Actions**.
3. Remove the existing action that starts `start_bridge.bat`.
4. Click **New...** and use:
   - **Action:** Start a program
   - **Program/script:** `C:\Windows\System32\wscript.exe`
   - **Add arguments:** `"D:\dmdresort\iot-bridge\start_bridge_background.vbs"`
   - **Start in:** `D:\dmdresort\iot-bridge`
5. Keep the existing **At log on** trigger.
6. Keep **If the task is already running: Do not start a new instance**.
7. Keep restart-on-failure enabled with a 1-minute interval and 3 attempts.
8. Click **OK**, then right-click the task → **Run**.

Do not put `DMD_IOT_DEVICE_KEY`, `DMD_IOT_BRIDGE_CONTROL_KEY`, database
credentials, or any other secret in the action arguments. If `pythonw.exe` is
not on the scheduled task PATH, set the Windows process environment variable
`DMD_IOT_PYTHONW_EXE` to the full path of that executable, for example the
Python installation's `pythonw.exe`. The VBS itself contains no secrets.

### Background logs and controls

Operational output is written to:

```text
D:\dmdresort\iot-bridge\logs\fingerprint_bridge.log
```

The directory is ignored by source control. It contains startup, reconnect,
attendance, and operation messages, but the bridge does not log signing
secrets or raw credentials.

- To verify operation, open `http://127.0.0.1:8765/status` locally or inspect
  the log file.
- To stop the background bridge, right-click the scheduled task → **End**.
- To disable automatic startup, right-click the task → **Disable**.
- To debug visibly, stop/disable the task first, then double-click
  `D:\dmdresort\iot-bridge\start_bridge.bat`.
- Do not run the visible BAT while the scheduled background task is active;
  the named mutex will reject the second instance.

### Single-instance behavior

The bridge uses a Windows named mutex named
`Local\\DmdResortFingerprintBridge`. A second launch exits with a clear
message before opening the COM port. The bridge HTTP port and serial-port
conflicts provide additional protection, including while the Arduino is
disconnected and multiple processes might otherwise wait for reconnection.

### Manual verification plan

**Test A — Manual Task Run**

1. Right-click the task and choose **Run**.
2. Confirm the bridge is running.
3. Scan a fingerprint and confirm Laravel attendance succeeds.

**Test B — Missing Arduino**

1. Unplug the Arduino and run the task.
2. Confirm the bridge remains alive and reports reconnect attempts.
3. Plug the Arduino in and confirm automatic connection and scanning.

**Test C — Reconnect**

1. Unplug the Arduino while the bridge is running.
2. Wait for a disconnect message.
3. Plug it back in and scan a fingerprint.
4. Confirm attendance succeeds without restarting the task.

**Test D — Windows Login**

1. Confirm the task is enabled.
2. Sign out of Windows and sign back in.
3. Do not open `start_bridge.bat` manually.
4. Confirm the bridge starts and a scan succeeds.

**Test E — PC Restart**

1. Restart Windows and log in to the configured account.
2. Confirm the task starts the bridge.
3. Scan a fingerprint.

**Test F — Duplicate Prevention**

1. While the task is running, right-click it and choose **Run** again.
2. Confirm Task Scheduler does not create a second instance.
3. Launch the BAT manually and confirm it exits with the single-instance
   message.
4. Confirm only one process controls the Arduino.

## Timeout guidance

The previous mismatch was a 5-second one-shot attendance request, a 10-second
Laravel bridge proxy timeout, and a serial operation that could wait up to 100
seconds. Delete operations now have a bounded timeout and Laravel's proxy
timeout is configurable. A single-worker `php artisan serve` can still block
other Laravel requests while a long interactive enrollment is running; use a
concurrent development server or a proper production web server for daily
operation. The bridge keeps the serial lock because the Arduino has one serial
owner, while attendance receives bounded fresh-signed retries.

Production should not rely on `php artisan serve`. Use IIS/Apache/Nginx with
PHP workers, HTTPS, a persistent cache/session backend, and run the bridge as
a supervised Task Scheduler process.

`AttendanceUpdated` is queued rather than broadcast synchronously. For local
realtime Admin attendance refreshes, run a Laravel queue worker alongside the
backend, for example `php artisan queue:work --queue=default`. Without a
worker, attendance is still recorded immediately and the queued realtime
refresh waits until a worker is available.

## Secret rotation checklist

Before deployment, rotate the IoT device signing secret and the separate
bridge-control secret. Update Laravel and the bridge environment together.
Never commit `.env` or place either secret in a batch file, React bundle, or
Task Scheduler command arguments.
