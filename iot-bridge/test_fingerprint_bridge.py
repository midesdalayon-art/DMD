import hashlib
import hmac
import importlib.util
import json
import os
import unittest
from types import SimpleNamespace
from unittest.mock import Mock, patch
from pathlib import Path

import requests


os.environ.setdefault("DMD_IOT_DEVICE_KEY", "device-secret")
os.environ.setdefault("DMD_IOT_BRIDGE_CONTROL_KEY", "bridge-secret")
MODULE_PATH = Path(__file__).with_name("fingerprint_bridge.py")
spec = importlib.util.spec_from_file_location("fingerprint_bridge", MODULE_PATH)
bridge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bridge)


class FingerprintBridgeSecurityTest(unittest.TestCase):
    def test_device_signature_headers_use_canonical_body_hash(self):
        body = json.dumps({"fingerprint_id": 7}, separators=(",", ":")).encode()
        headers = bridge.build_device_headers(
            "POST", "http://127.0.0.1:8000/api/iot/attendance/fingerprint", body,
            timestamp=1700000000, nonce="nonce-1",
        )
        canonical = "\n".join([
            "POST", "/api/iot/attendance/fingerprint", "1700000000", "nonce-1",
            hashlib.sha256(body).hexdigest(),
        ])
        expected = hmac.new(bridge.DEVICE_KEY.encode(), canonical.encode(), hashlib.sha256).hexdigest()
        self.assertEqual(headers["X-Device-Signature"], expected)
        self.assertEqual(headers["X-Device-Id"], bridge.DEVICE_ID)

    def test_control_auth_accepts_only_the_separate_secret(self):
        self.assertTrue(bridge.is_valid_bridge_control_key("bridge-secret"))
        self.assertFalse(bridge.is_valid_bridge_control_key("device-secret"))

    def test_bridge_does_not_expose_delete_all_http_route(self):
        self.assertNotIn("/fingerprints/delete-all", bridge.BridgeHandler.__dict__.get("routes", {}))

    def test_configured_serial_port_wins(self):
        self.assertEqual(bridge.select_serial_port("COM3", []), "COM3")

    def test_auto_detection_accepts_one_plausible_arduino(self):
        ports = [SimpleNamespace(device="COM7", description="Arduino Mega 2560", manufacturer="Arduino")]
        self.assertEqual(bridge.select_serial_port("", ports), "COM7")

    def test_auto_detection_rejects_ambiguous_or_missing_devices(self):
        ports = [
            SimpleNamespace(device="COM7", description="Arduino Mega 2560"),
            SimpleNamespace(device="COM8", description="USB Serial CH340"),
        ]
        with self.assertRaisesRegex(RuntimeError, "Multiple plausible"):
            bridge.select_serial_port("", ports)
        with self.assertRaisesRegex(RuntimeError, "No plausible"):
            bridge.select_serial_port("", [SimpleNamespace(device="COM9", description="Bluetooth Link")])

    def test_timeout_retry_uses_a_new_nonce_and_signature(self):
        success = Mock(status_code=200, ok=True)
        success.json.return_value = {"message": "Attendance recorded.", "action": "time_in"}
        with patch.object(bridge.requests, "post", side_effect=[requests.Timeout("timed out"), success]) as post:
            old_retry_count = bridge.API_RETRY_COUNT
            old_delay = bridge.API_RETRY_DELAY_SECONDS
            bridge.API_RETRY_COUNT = 1
            bridge.API_RETRY_DELAY_SECONDS = 0
            try:
                bridge.send_fingerprint_match(2, 90)
            finally:
                bridge.API_RETRY_COUNT = old_retry_count
                bridge.API_RETRY_DELAY_SECONDS = old_delay

        self.assertEqual(post.call_count, 2)
        first_headers = post.call_args_list[0].kwargs["headers"]
        second_headers = post.call_args_list[1].kwargs["headers"]
        self.assertNotEqual(first_headers["X-Device-Nonce"], second_headers["X-Device-Nonce"])
        self.assertNotEqual(first_headers["X-Device-Signature"], second_headers["X-Device-Signature"])

    def test_authentication_failure_is_not_retried(self):
        response = Mock(status_code=401, ok=False)
        with patch.object(bridge.requests, "post", return_value=response) as post:
            old_retry_count = bridge.API_RETRY_COUNT
            bridge.API_RETRY_COUNT = 1
            try:
                bridge.send_fingerprint_match(2, 90)
            finally:
                bridge.API_RETRY_COUNT = old_retry_count
        self.assertEqual(post.call_count, 1)

    def test_business_rejection_is_not_retried(self):
        response = Mock(status_code=422, ok=False)
        response.json.return_value = {"message": "Fingerprint is not assigned."}
        with patch.object(bridge.requests, "post", return_value=response) as post:
            old_retry_count = bridge.API_RETRY_COUNT
            bridge.API_RETRY_COUNT = 1
            try:
                bridge.send_fingerprint_match(2, 90)
            finally:
                bridge.API_RETRY_COUNT = old_retry_count
        self.assertEqual(post.call_count, 1)

    def test_status_payload_is_safe(self):
        payload = bridge.status_payload()
        self.assertNotIn("DEVICE_KEY", payload)
        self.assertNotIn("BRIDGE_CONTROL_KEY", payload)
        self.assertIn("serial_port", payload)
        self.assertIn("uptime_seconds", payload)

    def test_non_windows_single_instance_helper_is_available(self):
        if os.name != "nt":
            self.assertTrue(bridge.acquire_single_instance())
            bridge.release_single_instance()


if __name__ == "__main__":
    unittest.main()
