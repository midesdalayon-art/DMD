import json
import hashlib
import hmac
import os
import ctypes
from pathlib import Path
import sys
import threading
import time
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit

import requests
import serial
from serial.tools import list_ports


def load_local_env_file():
    """Load non-committed bridge settings without overriding process env."""
    env_path = Path(__file__).with_name(".env")
    if not env_path.is_file():
        return

    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key, value = key.strip(), value.strip()
        if key and key not in os.environ:
            os.environ[key] = value.strip('"\'')


load_local_env_file()


def configure_log_file():
    log_path = os.getenv("DMD_IOT_LOG_FILE", "").strip()
    if not log_path:
        return

    try:
        path = Path(log_path)
        path.parent.mkdir(parents=True, exist_ok=True)
        stream = path.open("a", encoding="utf-8", buffering=1)
        sys.stdout = stream
        sys.stderr = stream
    except OSError:
        # Keep startup behavior unchanged if the optional log cannot be opened.
        pass


configure_log_file()

BRIDGE_VERSION = "phase5"
BAUD_RATE = 115200
API_URL = os.getenv("DMD_IOT_API_URL", "http://127.0.0.1:8000/api/iot/attendance/fingerprint")
ENROLLMENT_API_BASE_URL = os.getenv(
    "DMD_IOT_ENROLLMENT_API_URL",
    API_URL.removesuffix("/attendance/fingerprint") + "/fingerprint-enrollment",
).rstrip("/")
DEVICE_KEY = os.getenv("DMD_IOT_DEVICE_KEY")
DEVICE_ID = os.getenv("DMD_IOT_DEVICE_ID", "mega-as608-01")
BRIDGE_HOST = "127.0.0.1"
BRIDGE_PORT = int(os.getenv("DMD_IOT_BRIDGE_PORT", "8765"))
BRIDGE_CONTROL_KEY = os.getenv("DMD_IOT_BRIDGE_CONTROL_KEY")
CONFIGURED_SERIAL_PORT = os.getenv("DMD_IOT_SERIAL_PORT", "").strip() or None
API_TIMEOUT_SECONDS = float(os.getenv("DMD_IOT_API_TIMEOUT_SECONDS", "5"))
API_RETRY_COUNT = max(0, int(os.getenv("DMD_IOT_API_RETRY_COUNT", "1")))
API_RETRY_DELAY_SECONDS = max(0.0, float(os.getenv("DMD_IOT_API_RETRY_DELAY_SECONDS", "0.25")))
ENROLLMENT_POLL_SECONDS = max(1.0, float(os.getenv("DMD_IOT_ENROLLMENT_POLL_SECONDS", "2")))
OPERATION_TIMEOUT_SECONDS = 100
DELETE_OPERATION_TIMEOUT_SECONDS = int(os.getenv("DMD_IOT_DELETE_TIMEOUT_SECONDS", "15"))
AUTO_DETECT_SERIAL = os.getenv("DMD_IOT_AUTO_DETECT_SERIAL", "true").lower() in {"1", "true", "yes", "on"}
SERIAL_RECONNECT_DELAY_SECONDS = max(1.0, float(os.getenv("DMD_IOT_SERIAL_RECONNECT_DELAY_SECONDS", "3")))
ser = None
selected_serial_port = None
operation_lock = threading.Lock()
started_at = time.time()
last_successful_attendance_at = None
last_api_status = None
last_api_error = None
last_serial_error = None
_single_instance_handle = None
enrollment_stop_event = threading.Event()

PLAUSIBLE_SERIAL_TERMS = (
    "arduino",
    "mega",
    "atmega",
    "ch340",
    "ch341",
    "usb serial",
    "usb-serial",
    "ftdi",
)


def serial_port_description(port_info):
    return " ".join(
        str(getattr(port_info, field, "") or "")
        for field in ("device", "description", "manufacturer", "product", "hwid")
    ).lower()


def is_plausible_arduino_port(port_info):
    return any(term in serial_port_description(port_info) for term in PLAUSIBLE_SERIAL_TERMS)


def select_serial_port(configured_port=None, available_ports=None, auto_detect=True):
    configured_port = (configured_port or "").strip()
    if configured_port:
        return configured_port

    if not auto_detect:
        raise RuntimeError("DMD_IOT_SERIAL_PORT is not configured and automatic COM detection is disabled.")

    ports = list(list_ports.comports() if available_ports is None else available_ports)
    plausible = [port for port in ports if is_plausible_arduino_port(port)]

    if len(plausible) == 1:
        return plausible[0].device
    if len(plausible) > 1:
        devices = ", ".join(port.device for port in plausible)
        raise RuntimeError(
            f"Multiple plausible Arduino serial devices found ({devices}). "
            "Set DMD_IOT_SERIAL_PORT explicitly."
        )

    raise RuntimeError(
        "No plausible Arduino serial device found. Connect the Arduino or set DMD_IOT_SERIAL_PORT explicitly."
    )


def status_payload():
    return {
        "ok": ser is not None and ser.is_open,
        "serial_connected": ser is not None and ser.is_open,
        "busy": operation_lock.locked(),
        "device": DEVICE_ID,
        "serial_port": selected_serial_port,
        "api_configured": bool(API_URL),
        "last_api_status": last_api_status,
        "last_api_error": last_api_error,
        "last_successful_attendance_at": last_successful_attendance_at,
        "last_serial_error": last_serial_error,
        "uptime_seconds": max(0, int(time.time() - started_at)),
        "version": BRIDGE_VERSION,
    }


def open_serial_connection(port):
    global ser, selected_serial_port, last_serial_error
    selected_serial_port = port
    try:
        ser = serial.Serial(port, BAUD_RATE, timeout=1)
    except (serial.SerialException, OSError):
        ser = None
        raise
    last_serial_error = None
    print(f"Arduino connected on {port}.")


def close_serial_connection():
    global ser
    if ser is not None and ser.is_open:
        ser.close()


def acquire_single_instance():
    """Use a Windows named mutex so disconnected bridges cannot multiply."""
    global _single_instance_handle
    if os.name != "nt":
        return True

    kernel32 = ctypes.WinDLL("kernel32", use_last_error=True)
    handle = kernel32.CreateMutexW(None, False, "Local\\DmdResortFingerprintBridge")
    if not handle:
        raise RuntimeError("Windows could not create the bridge single-instance mutex.")
    if ctypes.get_last_error() == 183:  # ERROR_ALREADY_EXISTS
        kernel32.CloseHandle(handle)
        return False
    _single_instance_handle = (kernel32, handle)
    return True


def release_single_instance():
    global _single_instance_handle
    if _single_instance_handle is not None:
        kernel32, handle = _single_instance_handle
        kernel32.CloseHandle(handle)
        _single_instance_handle = None


def build_device_headers(method, url, body, timestamp=None, nonce=None):
    timestamp = str(int(time.time()) if timestamp is None else timestamp)
    nonce = nonce or uuid.uuid4().hex
    body_hash = hashlib.sha256(body).hexdigest()
    path = urlsplit(url).path or "/"
    canonical = "\n".join([method.upper(), path, timestamp, nonce, body_hash])
    signature = hmac.new(DEVICE_KEY.encode("utf-8"), canonical.encode("utf-8"), hashlib.sha256).hexdigest()

    return {
        "X-Device-Id": DEVICE_ID,
        "X-Device-Timestamp": timestamp,
        "X-Device-Nonce": nonce,
        "X-Device-Signature": signature,
    }


def is_valid_bridge_control_key(provided):
    return bool(BRIDGE_CONTROL_KEY) and hmac.compare_digest(provided, BRIDGE_CONTROL_KEY)


def signed_json_post(url, payload):
    body = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    signed_headers = build_device_headers("POST", url, body)
    return requests.post(url, headers={
        **signed_headers,
        "Accept": "application/json",
        "Content-Type": "application/json",
    }, data=body, timeout=API_TIMEOUT_SECONDS)


def send_fingerprint_match(fingerprint_id, confidence):
    global last_successful_attendance_at, last_api_status, last_api_error

    payload = {
        "fingerprint_id": fingerprint_id, "confidence": confidence, "device": DEVICE_ID,
    }
    body = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")

    for attempt in range(API_RETRY_COUNT + 1):
        # Every attempt gets a fresh timestamp, nonce, and signature. A timed-out
        # request may have reached Laravel even if its response was lost.
        signed_headers = build_device_headers("POST", API_URL, body)
        try:
            response = requests.post(API_URL, headers={
                **signed_headers,
                "Accept": "application/json",
                "Content-Type": "application/json",
            }, data=body, timeout=API_TIMEOUT_SECONDS)
            last_api_status = response.status_code
            last_api_error = None
        except requests.RequestException as error:
            last_api_status = None
            last_api_error = error.__class__.__name__
            if attempt < API_RETRY_COUNT:
                print(f"Laravel API unavailable; retrying ({attempt + 1}/{API_RETRY_COUNT})...")
                time.sleep(API_RETRY_DELAY_SECONDS)
                continue
            print(f"Laravel API unavailable after {attempt + 1} attempt(s): {error}")
            return

        if response.status_code in {408, 429, 500, 502, 503, 504} and attempt < API_RETRY_COUNT:
            print(f"Laravel returned HTTP {response.status_code}; retrying ({attempt + 1}/{API_RETRY_COUNT})...")
            time.sleep(API_RETRY_DELAY_SECONDS)
            continue
        break

    if response.status_code == 401:
        print("Device authentication failed.")
        return
    if response.status_code == 403:
        print("Device request was forbidden.")
        return
    if response.status_code == 422:
        try:
            payload = response.json()
            errors = payload.get("errors", {})
            details = "; ".join(message for messages in errors.values() for message in messages)
            print(f"Attendance rejected: {details or payload.get('message', 'Validation failed.')}")
        except ValueError:
            print("Attendance rejected: Laravel returned an invalid error response.")
        return
    if not response.ok:
        print(f"Laravel attendance error ({response.status_code}): {response.text.strip()[:240]}")
        return
    last_successful_attendance_at = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    try:
        payload = response.json()
    except ValueError:
        content_type = response.headers.get("Content-Type", "unknown")
        body = " ".join(response.text.split())[:240]
        print(
            "Laravel returned a non-JSON success response "
            f"(HTTP {response.status_code}, {content_type}): {body}"
        )
        return
    data = payload.get("data") or {}
    employee = payload.get("employee") or data.get("employee") or {}
    action = payload.get("action")
    message = str(payload.get("message", "Attendance recorded."))
    print(f"Employee: {employee.get('name', 'Unknown employee')}")
    if action == "ignored" or "ignored" in message.lower() or "already complete" in message.lower():
        print(f"Attendance: IGNORED\nReason: {message}")
    elif action == "time_out" or "time out" in message.lower():
        print("Attendance: TIME OUT")
    else:
        print("Attendance: TIME IN")
    print(f"Fingerprint ID: {payload.get('fingerprint_id', fingerprint_id)}")
    print(f"Confidence: {payload.get('confidence', confidence)}\n-----------------------------")


def run_serial_operation(command, success_prefix, failure_prefix, timeout_seconds=None, lock_already_held=False):
    global last_serial_error

    if ser is None or not ser.is_open:
        return {"status": 503, "ok": False, "error": "Arduino serial connection is unavailable."}
    acquired_lock = False
    if not lock_already_held:
        if not operation_lock.acquire(blocking=False):
            return {"status": 409, "ok": False, "error": "Fingerprint bridge is busy."}
        acquired_lock = True
    lines = []
    try:
        ser.reset_input_buffer()
        ser.write((command + "\n").encode("ascii"))
        ser.flush()
        deadline = time.monotonic() + (timeout_seconds or OPERATION_TIMEOUT_SECONDS)
        while time.monotonic() < deadline:
            line = ser.readline().decode("utf-8", errors="ignore").strip()
            if not line:
                continue
            lines.append(line)
            if not line.startswith(("ENROLL_", "DELETE_")):
                print(f"Arduino: {line}")
            if line.startswith(success_prefix):
                return {"status": 200, "ok": True, "lines": lines, "message": line}
            if line.startswith(failure_prefix):
                return {"status": 422, "ok": False, "lines": lines, "error": line}
        return {"status": 504, "ok": False, "lines": lines, "error": "Operation timed out waiting for Arduino."}
    except (serial.SerialException, OSError) as error:
        last_serial_error = str(error)[:240]
        return {"status": 503, "ok": False, "lines": lines, "error": f"Arduino connection failed: {error}"}
    finally:
        if acquired_lock:
            operation_lock.release()


def process_enrollment_job(job):
    """Run one server-assigned enrollment and report only its outcome."""
    try:
        fingerprint_id = int(job["fingerprint_id"])
        operation_id = str(job["id"])
    except (KeyError, TypeError, ValueError):
        print("Laravel returned an invalid fingerprint enrollment job.")
        return

    result = run_serial_operation(
        f"ENROLL:{fingerprint_id}",
        "ENROLL_OK:",
        "ENROLL_FAILED:",
        lock_already_held=True,
    )
    message = result.get("message") or result.get("error") or "Fingerprint enrollment failed."
    completion_url = f"{ENROLLMENT_API_BASE_URL}/jobs/{operation_id}/complete"

    for attempt in range(API_RETRY_COUNT + 1):
        try:
            response = signed_json_post(completion_url, {"ok": bool(result.get("ok")), "message": message})
        except requests.RequestException as error:
            if attempt < API_RETRY_COUNT:
                time.sleep(API_RETRY_DELAY_SECONDS)
                continue
            print(f"Could not report fingerprint enrollment result to Laravel: {error.__class__.__name__}")
            return

        if response.status_code in {408, 429, 500, 502, 503, 504} and attempt < API_RETRY_COUNT:
            time.sleep(API_RETRY_DELAY_SECONDS)
            continue
        break

    if response.ok:
        if result.get("ok"):
            print(f"Fingerprint enrollment completed for sensor ID {fingerprint_id}.")
        else:
            print(f"Fingerprint enrollment failed: {message}")
    else:
        print(f"Laravel rejected the fingerprint enrollment result (HTTP {response.status_code}).")


def enrollment_worker():
    """Poll Railway so the browser never needs to control the local HTTP server."""
    claim_url = f"{ENROLLMENT_API_BASE_URL}/jobs/claim"

    while not enrollment_stop_event.is_set():
        if ser is None or not ser.is_open:
            enrollment_stop_event.wait(ENROLLMENT_POLL_SECONDS)
            continue

        if not operation_lock.acquire(blocking=False):
            enrollment_stop_event.wait(0.2)
            continue

        try:
            try:
                response = signed_json_post(claim_url, {})
            except requests.RequestException as error:
                if not enrollment_stop_event.is_set():
                    print(f"Enrollment polling unavailable: {error.__class__.__name__}")
                response = None

            if response is None:
                pass
            elif response.status_code == 204:
                pass
            elif response.status_code != 200:
                print(f"Enrollment polling returned HTTP {response.status_code}.")
            else:
                try:
                    job = (response.json() or {}).get("data")
                except ValueError:
                    job = None
                if job:
                    process_enrollment_job(job)
        finally:
            if operation_lock.locked():
                operation_lock.release()

        enrollment_stop_event.wait(ENROLLMENT_POLL_SECONDS)


class BridgeHandler(BaseHTTPRequestHandler):
    def do_OPTIONS(self):
        self.send_response(204)
        self.send_header("Access-Control-Allow-Origin", "http://localhost:5173")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, X-Bridge-Key")
        self.end_headers()

    def do_GET(self):
        if self.path != "/status":
            self.respond(404, {"ok": False, "error": "Not found."})
            return
        self.respond(200, status_payload())

    def do_POST(self):
        if self.path not in ("/fingerprints/enroll", "/fingerprints/delete"):
            self.respond(404, {"ok": False, "error": "Not found."})
            return
        if not self.authorized_control_request():
            self.respond(401, {"ok": False, "error": "Unauthorized bridge request."})
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
            payload = json.loads(self.rfile.read(length) or b"{}")
            slot = int(payload["fingerprint_id"])
        except (ValueError, KeyError, json.JSONDecodeError):
            self.respond(400, {"ok": False, "error": "fingerprint_id must be an integer."})
            return
        if not 1 <= slot <= 127:
            self.respond(422, {"ok": False, "error": "Fingerprint ID must be between 1 and 127."})
            return
        if self.path.endswith("/enroll"):
            result = run_serial_operation(f"ENROLL:{slot}", "ENROLL_OK:", "ENROLL_FAILED:")
        else:
            result = run_serial_operation(
                f"DELETE:{slot}", "DELETE_OK:", "DELETE_FAILED:", DELETE_OPERATION_TIMEOUT_SECONDS
            )
        self.respond(result.pop("status"), result)

    def authorized_control_request(self):
        return is_valid_bridge_control_key(self.headers.get("X-Bridge-Key", ""))

    def respond(self, status, payload):
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Access-Control-Allow-Origin", "http://localhost:5173")
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, format, *args):
        return


def start_bridge_server():
    server = ThreadingHTTPServer((BRIDGE_HOST, BRIDGE_PORT), BridgeHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    print(f"Fingerprint control API listening on http://{BRIDGE_HOST}:{BRIDGE_PORT}")
    return server, thread


def main():
    global ser, selected_serial_port, last_serial_error

    if not DEVICE_KEY:
        raise SystemExit("Missing DMD_IOT_DEVICE_KEY. Set the bridge device secret before starting.")
    if not BRIDGE_CONTROL_KEY:
        raise SystemExit("Missing DMD_IOT_BRIDGE_CONTROL_KEY. Set the separate bridge control secret before starting.")

    try:
        if not acquire_single_instance():
            raise SystemExit("Another DMD fingerprint bridge instance is already running.")
    except RuntimeError as error:
        raise SystemExit(str(error)) from error

    print("Starting DMD fingerprint bridge.")
    server = None
    server_thread = None
    try:
        server, server_thread = start_bridge_server()
        enrollment_stop_event.clear()
        enrollment_thread = threading.Thread(target=enrollment_worker, daemon=True)
        enrollment_thread.start()
        print("Waiting for Arduino connection and fingerprint scans...\n")
        while True:
            if ser is None or not ser.is_open:
                print("Arduino serial connection is unavailable; waiting for reconnect...")
                while ser is None or not ser.is_open:
                    try:
                        reconnect_port = CONFIGURED_SERIAL_PORT or select_serial_port(auto_detect=AUTO_DETECT_SERIAL)
                        open_serial_connection(reconnect_port)
                    except (RuntimeError, serial.SerialException, OSError) as error:
                        last_serial_error = str(error)[:240]
                        print(f"Arduino reconnect failed: {last_serial_error}")
                        time.sleep(SERIAL_RECONNECT_DELAY_SECONDS)
                continue

            if operation_lock.locked():
                time.sleep(0.05)
                continue
            try:
                line = ser.readline().decode("utf-8", errors="ignore").strip()
            except (serial.SerialException, OSError) as error:
                last_serial_error = str(error)[:240]
                print(f"Arduino disconnected: {last_serial_error}")
                close_serial_connection()
                continue
            if not line or not line.startswith("FP_MATCH:"):
                continue
            try:
                _, fingerprint_id, confidence = line.split(":")
                fingerprint_id, confidence = int(fingerprint_id), int(confidence)
            except ValueError:
                print(f"Invalid fingerprint message: {line}")
                continue
            print("Fingerprint detected!")
            print(f"Fingerprint ID : {fingerprint_id}")
            print(f"Confidence     : {confidence}")
            send_fingerprint_match(fingerprint_id, confidence)
    except serial.SerialException as error:
        last_serial_error = str(error)[:240]
        print(f"Unable to open Arduino serial port {selected_serial_port}: {error}")
    except KeyboardInterrupt:
        print("\nFingerprint bridge stopped.")
    finally:
        enrollment_stop_event.set()
        if server is not None:
            server.shutdown()
            server.server_close()
        if server_thread is not None:
            server_thread.join(timeout=2)
        close_serial_connection()
        if selected_serial_port:
            print(f"Closed Arduino serial port {selected_serial_port}.")
        release_single_instance()


if __name__ == "__main__":
    main()
