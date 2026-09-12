#include <Adafruit_Fingerprint.h>

#define FP_TOUCH_PIN    2
#define TOUCH_IRQ       0
#define QR_BAUD         9600
#define FP_BAUD         57600
#define SERIAL_BAUD     115200
#define MAX_FP_ID       127
#define MAX_LOGS        50
#define FP_DEBOUNCE_MS  2000

const char* PAYMENT_KEYWORDS[] = { "gcash", "paymaya", "ref", "qph", "ph.ppmi" };
const uint8_t KEYWORD_COUNT = 5;

Adafruit_Fingerprint finger = Adafruit_Fingerprint(&Serial2);

struct PaymentLog {
  char     ref[48];
  uint32_t timestamp;
  uint16_t entryNumber;
  bool     valid;
};

struct AttendanceLog {
  char     data[32];
  uint32_t timestamp;
  uint16_t entryNumber;
};

PaymentLog    payLogs[MAX_LOGS];
AttendanceLog attLogs[MAX_LOGS];

uint16_t payCount      = 0;
uint16_t payTotal      = 0;
uint16_t attCount      = 0;
uint16_t attTotal      = 0;
uint16_t slotsReserved = 0;

volatile bool fingerTouched   = false;
unsigned long lastScanTime    = 0;
unsigned long lastFPPoll      = 0;
bool          scanInProgress  = false;
bool          waitingForInput = false;

// Used by website/Python bridge enrollment.
bool fingerprintCommandBusy = false;
String serialCommandBuffer = "";

// Function declarations
void printHelp();
void handleSerialCommands();

void onFingerTouch() {
  fingerTouched = true;
}

void setup() {
  Serial.begin(SERIAL_BAUD);
  while (!Serial) { ; }

  Serial.println(F("========================================="));
  Serial.println(F("   Slot Reservation + Staff Attendance   "));
  Serial.println(F("   QR = Client Payment | FP = Staff      "));
  Serial.println(F("   WEBSITE ENROLLMENT READY              "));
  Serial.println(F("=========================================\n"));

  Serial1.begin(QR_BAUD);
  Serial.println(F("[QR]  Ready on pins 18/19 (9600 baud)"));

  Serial2.begin(FP_BAUD);
  finger.begin(FP_BAUD);
  delay(200);

  if (finger.verifyPassword()) {
    Serial.println(F("[FP]  AS608 found and verified"));
  } else {
    Serial.println(F("[FP]  AS608 NOT found - check wiring!"));
    while (true) { delay(1000); }
  }

  finger.getParameters();
  Serial.print(F("[FP]  Capacity : ")); Serial.println(finger.capacity);
  Serial.print(F("[FP]  Enrolled : ")); Serial.println(finger.templateCount);

  pinMode(FP_TOUCH_PIN, INPUT);
  attachInterrupt(TOUCH_IRQ, onFingerTouch, RISING);
  Serial.println(F("[FP]  Touch interrupt on PIN 2\n"));

  printHelp();
}

void loop() {
  handleQR();

  // Read website/Python commands every loop.
  handleSerialCommands();

  // Do not run normal attendance while enrollment/delete is active.
  if (!waitingForInput && !fingerprintCommandBusy) {
    handleFingerprint();
  }
}

// ============================================================
// QR logic unchanged
// ============================================================
void handleQR() {
  static String        qrBuffer     = "";
  static unsigned long lastCharTime = 0;
  static bool          receiving    = false;

  while (Serial1.available()) {
    char c = (char)Serial1.read();
    if (c == 0x00 || (c < 0x20 && c != '\n' && c != '\r')) continue;

    if (c == '\n' || c == '\r') {
      if (qrBuffer.length() > 0) {
        processPaymentQR(qrBuffer);
        qrBuffer     = "";
        lastCharTime = 0;
        receiving    = false;
      }
    } else {
      qrBuffer    += c;
      lastCharTime = millis();
      receiving    = true;
    }
  }

  if (receiving && qrBuffer.length() > 0 && millis() - lastCharTime > 100) {
    processPaymentQR(qrBuffer);
    qrBuffer     = "";
    lastCharTime = 0;
    receiving    = false;
  }
}

void processPaymentQR(String data) {
  data.trim();
  if (data.length() == 0) return;

  static String        lastQR     = "";
  static unsigned long lastQRTime = 0;

  if (data == lastQR && millis() - lastQRTime < 1000) {
    Serial.println(F("[QR]  Duplicate scan ignored"));
    return;
  }

  lastQR     = data;
  lastQRTime = millis();

  Serial.println(F("\n========================================"));
  Serial.println(F("          PAYMENT QR SCANNED            "));
  Serial.println(F("========================================"));
  Serial.print(F("  Raw data : ")); Serial.println(data);

  bool isPayment = false;
  String dataLower = data;
  dataLower.toLowerCase();

  for (uint8_t i = 0; i < KEYWORD_COUNT; i++) {
    if (dataLower.indexOf(PAYMENT_KEYWORDS[i]) >= 0) {
      isPayment = true;
      break;
    }
  }

  uint16_t idx = payCount % MAX_LOGS;
  data.toCharArray(payLogs[idx].ref, sizeof(payLogs[idx].ref));
  payLogs[idx].timestamp   = millis();
  payLogs[idx].entryNumber = ++payTotal;
  payLogs[idx].valid       = isPayment;

  if (payCount < MAX_LOGS) payCount++;

  if (isPayment) {
    slotsReserved++;
    Serial.println(F("  Status    : PAYMENT CONFIRMED"));
    Serial.print(F("  Slot #    : ")); Serial.println(slotsReserved);
    Serial.print(F("  Entry #   : ")); Serial.println(payTotal);
    Serial.println(F("  >> SLOT RESERVED SUCCESSFULLY <<"));
  } else {
    Serial.println(F("  Status    : INVALID - Not a payment QR"));
    Serial.println(F("  >> NO SLOT RESERVED <<"));
    Serial.println(F("  Tip: QR must contain GCash/PayMaya/REF keyword"));
  }

  Serial.println(F("========================================\n"));
}

// ============================================================
// Fingerprint attendance logic preserved
// ============================================================
void handleFingerprint() {
  if (scanInProgress) return;
  if (millis() - lastScanTime < FP_DEBOUNCE_MS) return;

  if (millis() - lastFPPoll < 50) return;
  lastFPPoll = millis();

  uint8_t imageResult = FINGERPRINT_NOFINGER;

  bool triggered = fingerTouched;
  if (!triggered) {
    imageResult = finger.getImage();
    if (imageResult == FINGERPRINT_OK) triggered = true;
  }

  if (triggered) {
    fingerTouched  = false;
    scanInProgress = true;
    Serial.println(F("\n[FP]  Staff finger detected - scanning..."));

    if (imageResult != FINGERPRINT_OK) {
      imageResult = finger.getImage();
    }

    if (imageResult != FINGERPRINT_OK) {
      Serial.println(F("[FP]  Poor image - press finger firmly and flat"));
      lastScanTime   = millis();
      scanInProgress = false;
      return;
    }

    uint8_t p = finger.image2Tz();
    if (p != FINGERPRINT_OK) {
      Serial.println(F("[FP]  Poor image - press finger firmly and flat"));
      lastScanTime   = millis();
      scanInProgress = false;
      return;
    }

    p = finger.fingerSearch();
     
    if (p == FINGERPRINT_OK) {
       // Machine-readable fingerprint result for DMD website bridge
      Serial.print(F("FP_MATCH:"));
      Serial.print(finger.fingerID);
      Serial.print(F(":"));
      Serial.println(finger.confidence);

      Serial.println(F("\n========================================"));
      Serial.println(F("         STAFF ATTENDANCE LOGGED        "));
      Serial.println(F("========================================"));
      Serial.print(F("  Staff ID  : ")); Serial.println(finger.fingerID);
      Serial.print(F("  Confidence: ")); Serial.println(finger.confidence);
      Serial.print(F("  Entry #   : ")); Serial.println(attTotal + 1);

      if (finger.confidence < 50) {
        Serial.println(F("  Warning   : Low confidence - re-enroll recommended"));
      }

      Serial.println(F("========================================\n"));

      uint16_t idx = attCount % MAX_LOGS;
      snprintf(attLogs[idx].data, sizeof(attLogs[idx].data),
               "ID:%-3d Conf:%d", finger.fingerID, finger.confidence);
      attLogs[idx].timestamp   = millis();
      attLogs[idx].entryNumber = ++attTotal;

      if (attCount < MAX_LOGS) attCount++;

    } else if (p == FINGERPRINT_NOTFOUND) {
      Serial.println(F("[FP]  No match - staff not enrolled"));
    } else {
      Serial.print(F("[FP]  Error: 0x")); Serial.println(p, HEX);
    }

    lastScanTime   = millis();
    scanInProgress = false;
  }
}

// ============================================================
// Logs
// ============================================================
void viewPaymentLogs() {
  Serial.println(F("\n========================================"));
  Serial.println(F("          PAYMENT LOG (QR Scans)        "));
  Serial.println(F("========================================"));

  if (payCount == 0) {
    Serial.println(F("  No entries yet.\n"));
    return;
  }

  uint16_t start = (payCount == MAX_LOGS) ? (payTotal % MAX_LOGS) : 0;

  for (uint16_t i = 0; i < payCount; i++) {
    uint16_t idx = (start + i) % MAX_LOGS;
    Serial.print(F("  #")); Serial.print(payLogs[idx].entryNumber);
    Serial.print(F("\t| "));
    Serial.print(payLogs[idx].valid ? F("PAID   ") : F("INVALID"));
    Serial.print(F("\t| T+")); Serial.print(payLogs[idx].timestamp / 1000);
    Serial.print(F("s\t| ")); Serial.println(payLogs[idx].ref);
  }

  Serial.println(F("----------------------------------------"));
  Serial.print(F("  Total scans   : ")); Serial.println(payTotal);
  Serial.print(F("  Slots reserved: ")); Serial.println(slotsReserved);
  Serial.println(F("========================================\n"));
}

void viewAttendanceLogs() {
  Serial.println(F("\n========================================"));
  Serial.println(F("        STAFF ATTENDANCE LOG (FP)       "));
  Serial.println(F("========================================"));

  if (attCount == 0) {
    Serial.println(F("  No entries yet.\n"));
    return;
  }

  uint16_t start = (attCount == MAX_LOGS) ? (attTotal % MAX_LOGS) : 0;

  for (uint16_t i = 0; i < attCount; i++) {
    uint16_t idx = (start + i) % MAX_LOGS;
    Serial.print(F("  #")); Serial.print(attLogs[idx].entryNumber);
    Serial.print(F("\t| T+")); Serial.print(attLogs[idx].timestamp / 1000);
    Serial.print(F("s\t| ")); Serial.println(attLogs[idx].data);
  }

  Serial.println(F("----------------------------------------"));
  Serial.print(F("  Total check-ins: ")); Serial.println(attTotal);
  Serial.println(F("========================================\n"));
}

void clearPaymentLogs() {
  payCount = 0;
  payTotal = 0;
  slotsReserved = 0;
  Serial.println(F("[LOG] Payment logs cleared."));
}

void clearAttendanceLogs() {
  attCount = 0;
  attTotal = 0;
  Serial.println(F("[LOG] Attendance logs cleared."));
}

// ============================================================
// Website/Python bridge enrollment
// ============================================================
bool enrollFingerprint(uint8_t id) {
  Serial.print(F("\n[FP]  Enrolling Staff ID #"));
  Serial.println(id);
  Serial.println(F(">>> Place finger firmly on sensor..."));

  uint8_t p = FINGERPRINT_NOFINGER;
  unsigned long startedAt = millis();

  while (p != FINGERPRINT_OK) {
    p = finger.getImage();

    if (p == FINGERPRINT_NOFINGER) {
      if (millis() - startedAt > 30000) {
        Serial.println(F("[FP]  Timeout waiting for first scan"));
        return false;
      }
      delay(100);
    } else if (p != FINGERPRINT_OK) {
      Serial.println(F("[FP]  Image error"));
      return false;
    }
  }

  if (finger.image2Tz(1) != FINGERPRINT_OK) {
    Serial.println(F("[FP]  Template 1 failed"));
    return false;
  }

  Serial.println(F(">>> Remove finger..."));
  delay(2000);

  startedAt = millis();
  do {
    p = finger.getImage();

    if (millis() - startedAt > 30000) {
      Serial.println(F("[FP]  Timeout waiting for finger removal"));
      return false;
    }

    delay(80);
  } while (p != FINGERPRINT_NOFINGER);

  Serial.println(F(">>> Place SAME finger again..."));

  p = FINGERPRINT_NOFINGER;
  startedAt = millis();

  while (p != FINGERPRINT_OK) {
    p = finger.getImage();

    if (p == FINGERPRINT_NOFINGER) {
      if (millis() - startedAt > 30000) {
        Serial.println(F("[FP]  Timeout waiting for second scan"));
        return false;
      }
      delay(100);
    } else if (p != FINGERPRINT_OK) {
      Serial.println(F("[FP]  Image error"));
      return false;
    }
  }

  if (finger.image2Tz(2) != FINGERPRINT_OK) {
    Serial.println(F("[FP]  Template 2 failed"));
    return false;
  }

  p = finger.createModel();

  if (p == FINGERPRINT_ENROLLMISMATCH) {
    Serial.println(F("[FP]  Did not match - try again"));
    return false;
  } else if (p != FINGERPRINT_OK) {
    Serial.println(F("[FP]  Model failed"));
    return false;
  }

  p = finger.storeModel(id);

  if (p == FINGERPRINT_OK) {
    Serial.print(F("[FP]  SUCCESS - Staff enrolled at ID #"));
    Serial.println(id);
    return true;
  }

  Serial.print(F("[FP]  Store error: "));
  Serial.println(p);
  return false;
}

bool deleteFingerprint(uint8_t id) {
  uint8_t p = finger.deleteModel(id);

  if (p == FINGERPRINT_OK) {
    Serial.print(F("[FP]  Deleted ID #"));
    Serial.println(id);
    return true;
  }

  Serial.print(F("[FP]  Delete error: "));
  Serial.println(p);
  return false;
}
bool deleteAllFingerprints() {
  uint8_t p = finger.emptyDatabase();

  if (p == FINGERPRINT_OK) {
    Serial.println(F("[FP]  All fingerprints deleted"));
    return true;
  }

  Serial.print(F("[FP]  Delete all error: "));
  Serial.println(p);
  return false;
}


// Existing code continues ↓
void runBridgeEnroll(uint8_t id) {
  
  if (id < 1 || id > MAX_FP_ID) {
    Serial.println(F("ENROLL_FAILED:Invalid ID. Must be 1-127."));
    return;
  }

  fingerprintCommandBusy = true;
  waitingForInput = true;

  Serial.print(F("ENROLL_STARTED:"));
  Serial.println(id);

  bool ok = enrollFingerprint(id);

  if (ok) {
    Serial.print(F("ENROLL_OK:"));
    Serial.println(id);
  } else {
    Serial.println(F("ENROLL_FAILED:Scanner enrollment failed"));
  }

  waitingForInput = false;
  fingerprintCommandBusy = false;
}

void runBridgeDelete(uint8_t id) {
  if (id < 1 || id > MAX_FP_ID) {
    Serial.println(F("DELETE_FAILED:Invalid ID. Must be 1-127."));
    return;
  }

  fingerprintCommandBusy = true;
  waitingForInput = true;

  bool ok = deleteFingerprint(id);

  if (ok) {
    Serial.print(F("DELETE_OK:"));
    Serial.println(id);
  } else {
    Serial.println(F("DELETE_FAILED:Scanner delete failed"));
  }

  waitingForInput = false;
  fingerprintCommandBusy = false;
}

// ============================================================
// Serial commands
// Supports:
// ENROLL:5
// DELETE:5
// Also keeps old single-letter commands for fallback/testing.
// ============================================================
void handleSerialCommands() {
  while (Serial.available()) {
    char c = (char)Serial.read();

    if (c == '\r') continue;

    if (c == '\n') {
      serialCommandBuffer.trim();

      if (serialCommandBuffer.length() > 0) {
        processSerialCommand(serialCommandBuffer);
      }

      serialCommandBuffer = "";
      return;
    }

    if (serialCommandBuffer.length() < 40) {
      serialCommandBuffer += c;
    } else {
      serialCommandBuffer = "";
      Serial.println(F("COMMAND_FAILED:Command too long"));
    }
  }
}

void processSerialCommand(String command) {
  command.trim();

  if (command.length() == 0) return;

  String upperCommand = command;
  upperCommand.toUpperCase();

  if (upperCommand.startsWith("ENROLL:")) {
    uint8_t id = (uint8_t)upperCommand.substring(7).toInt();
    runBridgeEnroll(id);
    return;
  }
  // Delete ALL fingerprints
  if (upperCommand == "DELETE_ALL") {
  fingerprintCommandBusy = true;
  waitingForInput = true;

  bool ok = deleteAllFingerprints();

  if (ok) {
    Serial.println(F("DELETE_ALL_OK"));
  } else {
    Serial.println(F("DELETE_ALL_FAILED"));
  }

  waitingForInput = false;
  fingerprintCommandBusy = false;
  return;
 }

  if (upperCommand.startsWith("DELETE:")) {
    uint8_t id = (uint8_t)upperCommand.substring(7).toInt();
    runBridgeDelete(id);
    return;
  }

  if (upperCommand.length() == 1) {
    char cmd = upperCommand.charAt(0);

    switch (cmd) {
      case 'E': {
        waitingForInput = true;
        Serial.print(F("Enter Staff ID to enroll (1-"));
        Serial.print(MAX_FP_ID);
        Serial.println(F("):"));

        uint8_t id = readIntFromSerial();
        waitingForInput = false;

        if (id >= 1 && id <= MAX_FP_ID) {
          enrollFingerprint(id);
        } else {
          Serial.println(F("[ERR] Invalid ID. Must be 1-127."));
        }
        break;
      }

      case 'D': {
        waitingForInput = true;
        Serial.println(F("Enter Staff ID to delete:"));

        uint8_t id = readIntFromSerial();
        waitingForInput = false;

        deleteFingerprint(id);
        break;
      }

      case 'L':
        finger.getParameters();
        Serial.print(F("[FP]  Staff enrolled: "));
        Serial.println(finger.templateCount);
        break;

      case 'P':
        viewPaymentLogs();
        break;

      case 'A':
        viewAttendanceLogs();
        break;

      case 'X':
        clearPaymentLogs();
        break;

      case 'Z':
        clearAttendanceLogs();
        break;

      case 'H':
        printHelp();
        break;

      default:
        break;
    }
  }
}

void printHelp() {
  Serial.println(F("\n----------- COMMANDS -----------"));
  Serial.println(F("Website/Python bridge commands:"));
  Serial.println(F("  ENROLL:5  - Enroll fingerprint ID 5"));
  Serial.println(F("  DELETE:5  - Delete fingerprint ID 5"));
  Serial.println(F(""));
  Serial.println(F("Manual fallback commands:"));
  Serial.println(F("  e  - Enroll staff fingerprint"));
  Serial.println(F("  d  - Delete staff fingerprint"));
  Serial.println(F("  l  - List enrolled staff count"));
  Serial.println(F("  p  - View payment log (QR)"));
  Serial.println(F("  a  - View attendance log (FP)"));
  Serial.println(F("  x  - Clear payment log"));
  Serial.println(F("  z  - Clear attendance log"));
  Serial.println(F("  h  - Show this help menu"));
  Serial.println(F("--------------------------------\n"));
}

uint8_t readIntFromSerial() {
  delay(50);

  while (Serial.available()) {
    Serial.read();
  }

  String s = "";
  unsigned long t = millis();

  while (millis() - t < 10000) {
    if (Serial.available()) {
      char c = Serial.read();

      if (c == '\n' || c == '\r') {
        if (s.length() > 0) break;
      } else if (c >= '0' && c <= '9') {
        s += c;
        t = millis();
      }
    }
  }

  delay(10);

  while (Serial.available()) {
    Serial.read();
  }

  return (uint8_t)s.toInt();
}