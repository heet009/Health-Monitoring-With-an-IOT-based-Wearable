#include <Wire.h>
#include <Adafruit_MPU6050.h>
#include <Adafruit_Sensor.h>
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <MAX3010x.h>
#include "filters.h"

// WiFi credentials
char* ssid = "Heet_Laptop";
char* pass = "12345678";

// MPU6050 setup
Adafruit_MPU6050 mpu;

// MAX30102 setup
MAX30102 sensor;
const auto kSamplingRate = sensor.SAMPLING_RATE_400SPS;
const float kSamplingFrequency = 400.0;

// Finger detection threshold and cooldown
const unsigned long kFingerThreshold = 10000;
const unsigned int kFingerCooldownMs = 500;

// Edge detection threshold
const float kEdgeThreshold = -2000.0;

// Filters
const float kLowPassCutoff = 5.0;
const float kHighPassCutoff = 0.5;

// Averaging
const bool kEnableAveraging = false;
const int kAveragingSamples = 5;
const int kSampleThreshold = 5;

// Filters instances
LowPassFilter low_pass_filter_red(kLowPassCutoff, kSamplingFrequency);
LowPassFilter low_pass_filter_ir(kLowPassCutoff, kSamplingFrequency);
HighPassFilter high_pass_filter(kHighPassCutoff, kSamplingFrequency);
Differentiator differentiator(kSamplingFrequency);
MovingAverageFilter<kAveragingSamples> averager_bpm;
MovingAverageFilter<kAveragingSamples> averager_r;
MovingAverageFilter<kAveragingSamples> averager_spo2;

// Statistics for pulse oximetry
MinMaxAvgStatistic stat_red;
MinMaxAvgStatistic stat_ir;

// R value to SpO2 calibration factors
float kSpO2_A = 1.5958422;
float kSpO2_B = -34.6596622;
float kSpO2_C = 112.6898759;

// Variables for fall detection
bool fallDetected = false;

// Variables for heart rate and SpO2
int heartRate = 0;
int spo2 = 0;
bool fingerDetected = false;

// Timestamp variables
long last_heartbeat = 0;
long finger_timestamp = 0;
float last_diff = NAN;
bool crossed = false;
long crossed_time = 0;

// Step counter reset variables
int steps = 0;
int last_reset_day = -1;

// Temperature variable
float temperature = 25.0;

WiFiClient wifiClient;

void setup() {
  Serial.begin(9600);

  // WiFi connection
  Serial.println("Connecting to WiFi..");
  WiFi.begin(ssid, pass);
  while (WiFi.status() != WL_CONNECTED) {
    Serial.print(".");
    delay(500);
  }
  Serial.println("\nWiFi Connected");

  // Initialize MPU6050
  Serial.println("Initializing MPU6050...");
  if (!mpu.begin()) {
    Serial.println("MPU6050 initialization failed!");
    while (1);
  }
  Serial.println("MPU6050 initialized!");

  // Initialize MAX30102
  Serial.println("Initializing MAX30102...");
  if (!sensor.begin() || !sensor.setSamplingRate(kSamplingRate)) {
    Serial.println("MAX30102 initialization failed!");
    while (1);
  }
  Serial.println("MAX30102 initialized!");

  mpu.setAccelerometerRange(MPU6050_RANGE_8_G);
  Serial.print("Accelerometer range set to: ");
  switch (mpu.getAccelerometerRange()) {
  case MPU6050_RANGE_2_G:
    Serial.println("+-2G");
    break;
  case MPU6050_RANGE_4_G:
    Serial.println("+-4G");
    break;
  case MPU6050_RANGE_8_G:
    Serial.println("+-8G");
    break;
  case MPU6050_RANGE_16_G:
    Serial.println("+-16G");
    break;
  }
  mpu.setGyroRange(MPU6050_RANGE_500_DEG);
  Serial.print("Gyro range set to: ");
  switch (mpu.getGyroRange()) {
  case MPU6050_RANGE_250_DEG:
    Serial.println("+- 250 deg/s");
    break;
  case MPU6050_RANGE_500_DEG:
    Serial.println("+- 500 deg/s");
    break;
  case MPU6050_RANGE_1000_DEG:
    Serial.println("+- 1000 deg/s");
    break;
  case MPU6050_RANGE_2000_DEG:
    Serial.println("+- 2000 deg/s");
    break;
  }

  mpu.setFilterBandwidth(MPU6050_BAND_21_HZ);
  Serial.print("Filter bandwidth set to: ");
  switch (mpu.getFilterBandwidth()) {
  case MPU6050_BAND_260_HZ:
    Serial.println("260 Hz");
    break;
  case MPU6050_BAND_184_HZ:
    Serial.println("184 Hz");
    break;
  case MPU6050_BAND_94_HZ:
    Serial.println("94 Hz");
    break;
  case MPU6050_BAND_44_HZ:
    Serial.println("44 Hz");
    break;
  case MPU6050_BAND_21_HZ:
    Serial.println("21 Hz");
    break;
  case MPU6050_BAND_10_HZ:
    Serial.println("10 Hz");
    break;
  case MPU6050_BAND_5_HZ:
    Serial.println("5 Hz");
    break;
  }

  Serial.println("");
  delay(100);
}

// Define variables to track timings
unsigned long lastSendTime = 0;      // For 15-second general upload
unsigned long lastHeartRateSend = 0; // For 10-second heart rate upload
unsigned long lastStepTime = 0;      // For step debounce (milliseconds)

// Debounce interval for steps (200ms)
const unsigned long stepDebounceInterval = 300;

// Define variables for averaging heart rate
float accumulatedHeartRate = 0.0f;
int heartRateCount = 0;
float averageHeartRate = 0.0f;

int hr = 0;

// Threshold for step detection
const float stepThreshold = 12.0; // Adjust this value based on your sensor sensitivity
const float noiseThreshold = 2.0; // Threshold for noise, to avoid small fluctuations
float lastAccMagnitude = 0.0;

void loop() {
  // --- Step Counter Reset at Midnight ---
  time_t now = time(nullptr);
  struct tm *timeInfo = localtime(&now);

  if (timeInfo->tm_hour == 0 && timeInfo->tm_min == 0 && last_reset_day != timeInfo->tm_mday) {
    steps = 0; // Reset steps
    last_reset_day = timeInfo->tm_mday; // Update last reset day
    Serial.println("Steps counter reset at midnight");
  }


  sensors_event_t a, g, temp;
  mpu.getEvent(&a, &g, &temp);

  // Calculate acceleration magnitude for fall detection
  float accMagnitude = sqrt(a.acceleration.x * a.acceleration.x +
                             a.acceleration.y * a.acceleration.y +
                             a.acceleration.z * a.acceleration.z);

  //Serial.print("Acceleration Magnitude : ");
  //Serial.println(accMagnitude);

  // Fall detection threshold
  if (accMagnitude > 30.0) {
    fallDetected = true;
  } else {
    fallDetected = false;
  }

  // --- Step Detection ---
  // Only process the step detection if the magnitude exceeds the noise threshold
  if (accMagnitude > noiseThreshold) {
    unsigned long currentStepTime = millis();

    // Only count a step if enough time has passed (to avoid multiple counts for a single step)
    if ((accMagnitude > stepThreshold) && ((currentStepTime - lastStepTime) > stepDebounceInterval)) {
      steps++; // Increment steps
      lastStepTime = currentStepTime; // Update last step time
      Serial.print("Step detected. Total steps: ");
      Serial.println(steps);
      Serial.print("Magnitude : ");
      Serial.println(accMagnitude);
    }
  }

  // Simulate temperature value (replace with actual sensor if available)
  temperature = temp.temperature;

  // --- Heart Rate and SpO2 ---
  auto sample = sensor.readSample(1000);
  float current_value_red = sample.red;
  float current_value_ir = sample.ir;

  if (sample.red > kFingerThreshold) {
    if (millis() - finger_timestamp > kFingerCooldownMs) {
      fingerDetected = true;
    }
  } else {
    differentiator.reset();
    averager_bpm.reset();
    averager_r.reset();
    averager_spo2.reset();
    low_pass_filter_red.reset();
    low_pass_filter_ir.reset();
    high_pass_filter.reset();
    stat_red.reset();
    stat_ir.reset();

    fingerDetected = false;
    finger_timestamp = millis();
  }

  if (fingerDetected) {
    current_value_red = low_pass_filter_red.process(current_value_red);
    current_value_ir = low_pass_filter_ir.process(current_value_ir);

    stat_red.process(current_value_red);
    stat_ir.process(current_value_ir);

    float current_value = high_pass_filter.process(current_value_red);
    float current_diff = differentiator.process(current_value);

    if (!isnan(current_diff) && !isnan(last_diff)) {
      if (last_diff > 0 && current_diff < 0) {
        crossed = true;
        crossed_time = millis();
      }

      if (current_diff > 0) {
        crossed = false;
      }

      if (crossed && current_diff < kEdgeThreshold) {
        if (last_heartbeat != 0 && crossed_time - last_heartbeat > 300) {
          int bpm = 60000 / (crossed_time - last_heartbeat);

          float rred = (stat_red.maximum() - stat_red.minimum()) / stat_red.average();
          float rir = (stat_ir.maximum() - stat_ir.minimum()) / stat_ir.average();
          float r = rred / rir;
          float calculated_spo2 = kSpO2_A * r * r + kSpO2_B * r + kSpO2_C;
          calculated_spo2 = min(calculated_spo2, 100.0f);

          if (bpm > 50 && bpm < 150) {
            // Accumulate heart rate data for averaging
            Serial.print("Heart Rate (current, bpm): ");
            hr = bpm;
            Serial.println(bpm);
            Serial.print("SpO2 (current, %): ");
            spo2 = calculated_spo2;
            Serial.println(spo2); 

          }

          stat_red.reset();
          stat_ir.reset();
        }

        crossed = false;
        last_heartbeat = crossed_time;
      }
    }

    last_diff = current_diff;
  }

  // --- Send General Data to Server Every 15 Seconds ---
  unsigned long currentMillis = millis(); // Get the current time

  if (currentMillis - lastSendTime >= 15000) { // Check if 15 seconds have passed
    lastSendTime = currentMillis; // Update the last send time

    HTTPClient client;
    String api = "http://3.29.31.191/proj_insert.php?fall=" + String(fallDetected ? 1 : 0) +
                 "&heart_rate=" + String(hr) + // Send the averaged heart rate
                 "&spo2=" + String(spo2) +
                 "&temperature=" + String(temperature) +
                 "&steps=" + String(steps);

    client.begin(wifiClient, api);
    int code = client.GET();           // Send GET request
    String response = client.getString(); // Get response from server

    // Print server response
    Serial.print("Status Code: "); Serial.println(code);
    Serial.println(response);

    client.end();

    // Reset heart rate and SpO2 after uploading
    hr = 0;
    heartRateCount = 0;
    spo2 = 0;
    fallDetected = false;
  }
}




