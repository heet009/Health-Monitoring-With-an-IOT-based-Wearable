<?php
require_once 'config.php';
loadEnv();

// Database connection settings
$servername = getenv('DB_HOST') ?: "localhost";
$username = getenv('DB_USER') ?: "iot_user";
$password = getenv('DB_PASS') ?: "iot@1122";
$dbname = getenv('DB_NAME') ?: "iotdata";

// Function to generate suggestions using the Groq API
function getSuggestionFromGroq($type, $value) {
    $url = 'https://api.groq.com/openai/v1/chat/completions';
    $apiKey = getenv('GROQ_API_KEY');

    if (!$apiKey) {
        return 'API key not configured.';
    }

    $messageContent = "Provide a health suggestion in 2 short sentences based on the following: $type is $value.";

    $data = [
        'messages' => [
            [
                'role' => 'user',
                'content' => $messageContent
            ]
        ],
        'model' => 'llama3-8b-8192'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        return 'Error: ' . curl_error($ch);
    }
    curl_close($ch);

    $responseData = json_decode($response, true);
    return $responseData['choices'][0]['message']['content'] ?? 'No suggestion available.';
}

// Connect to the database
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch the latest non-zero sensor data
$sql = "SELECT 
    (SELECT temperature FROM sensor_data WHERE temperature != 0 ORDER BY date DESC, time DESC LIMIT 1) as temperature,
    (SELECT steps FROM sensor_data WHERE steps != 0 ORDER BY date DESC, time DESC LIMIT 1) as steps,
    (SELECT heart_rate FROM sensor_data WHERE heart_rate != 0 ORDER BY date DESC, time DESC LIMIT 1) as heart_rate,
    (SELECT spo2 FROM sensor_data WHERE spo2 != 0 ORDER BY date DESC, time DESC LIMIT 1) as spo2,
    (SELECT fall FROM sensor_data WHERE fall = 1 ORDER BY date DESC, time DESC LIMIT 1) as fall
FROM dual";
$result = $conn->query($sql);

$latestTemperature = $latestSteps = $latestHeartRate = $latestSpo2 = null;
$latestFall = null;
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $latestTemperature = $row['temperature'];
    $latestSteps = $row['steps'];
    $latestHeartRate = $row['heart_rate'];
    $latestSpo2 = $row['spo2'];
    $latestFall = $row['fall'];
}

// Fetch all sensor data for charts
$sql = "SELECT temperature, steps, fall, heart_rate, spo2, CONCAT(date, ' ', time) AS timestamp FROM sensor_data ORDER BY date, time ASC";
$result = $conn->query($sql);

$data = [
    "temperature" => [],
    "steps" => [],
    "fall" => [],
    "heart_rate" => [],
    "spo2" => [],
    "timestamps" => [],
    "times" => []
];

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $data["temperature"][] = $row["temperature"];
        $data["steps"][] = $row["steps"];
        $data["fall"][] = $row["fall"];
        
        $data["heart_rate"][] = $row["heart_rate"];
        
        $data["spo2"][] = $row["spo2"];
        $data["timestamps"][] = $row["timestamp"];
        $data["times"][] = date('H:i', strtotime($row["timestamp"]));
    }
}

// Generate suggestions only for non-zero values
$temperatureTip = $latestTemperature ? getSuggestionFromGroq('Body Temperature in Celsius', $latestTemperature) : '';
$stepsTip = $latestSteps ? getSuggestionFromGroq('Steps', $latestSteps) : '';
$heartRateTip = $latestHeartRate ? getSuggestionFromGroq('Heart Rate', $latestHeartRate) : '';
$spo2Tip = $latestSpo2 ? getSuggestionFromGroq('SPO2', $latestSpo2) : '';

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fitness Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            color: white;
            margin: 0;
            padding: 0;
            height: auto;
            background: linear-gradient(45deg, #ff9a9e, #fad0c4, #fbc2eb, #a1c4fd, #c2e9fb);
            background-size: 300% 300%;
            animation: gradientAnimation 8s ease infinite;
        }

        @keyframes gradientAnimation {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        h1 {
            font-weight: bold;
            text-shadow: 2px 2px 10px rgba(0, 0, 0, 0.3);
        }

        .card {
            background: #fff;
            color: black;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            margin-bottom: 20px;
        }

        .card h3 {
            font-weight: 600;
        }

        .alert {
            background: linear-gradient(to right, #ff7e5f, #feb47b);
            color: white;
            border: none;
            font-size: 1.1em;
            border-radius: 10px;
            padding: 15px;
        }

        footer {
            margin-top: 50px;
            text-align: center;
            font-size: 0.9em;
            color: #ddd;
        }

        .metric-card {
            background: linear-gradient(145deg, #2a5298, #1e3c72);
            color: white;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease;
        }

        .metric-card:hover {
            transform: translateY(-5px);
        }

        .metric-card h4 {
            font-size: 1.2em;
            margin-bottom: 15px;
            color: #fff;
        }

        .metric-card h2 {
            font-size: 2.5em;
            margin: 10px 0;
            font-weight: bold;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .metric-card p {
            margin: 0;
            opacity: 0.8;
            font-size: 0.9em;
        }

        .progress {
            height: 8px;
            background-color: rgba(255,255,255,0.2);
            border-radius: 4px;
        }

        .progress-bar {
            background-color: #4CAF50;
            border-radius: 4px;
        }

        .status-indicator {
            margin-top: 10px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.9em;
            display: inline-block;
        }

        .status-indicator.normal {
            background-color: rgba(76, 175, 80, 0.3);
        }

        .status-indicator.warning {
            background-color: rgba(255, 152, 0, 0.3);
        }

        .tip-card {
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            color: white;
        }

        .tip-card:hover {
            transform: translateY(-5px);
        }

        .tip-card h4 {
            font-size: 1.2em;
            margin-bottom: 15px;
            font-weight: 600;
        }

        .tip-card p {
            font-size: 1em;
            line-height: 1.5;
            margin-bottom: 0;
        }

        .tip-card.temperature {
            background: linear-gradient(135deg, #FF6B6B, #FF8E53);
        }

        .tip-card.steps {
            background: linear-gradient(135deg, #4E65FF, #92EFFD);
        }

        .tip-card.heart {
            background: linear-gradient(135deg, #FF5E98, #FF9B9B);
        }

        .tip-card.spo2 {
            background: linear-gradient(135deg, #6EDCC4, #1AAB8B);
        }

        .alert-danger {
            background: linear-gradient(to right, #ff416c, #ff4b2b);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(255, 65, 108, 0.2);
        }

        .alert-danger .btn-close {
            filter: brightness(0) invert(1);
        }
    </style>
</head>

<body>
    <div class="container mt-5">
        <h1 class="text-center mb-4">Fitness Dashboard</h1>

        <?php if ($latestFall == 1): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <strong>⚠️ Fall Detected!</strong> A fall event has been detected. Please check on the person immediately.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="metric-card">
                    <h4>👣 Steps</h4>
                    <h2><?php echo $latestSteps; ?></h2>
                    <p>Daily Goal: 10,000</p>
                    <div class="progress mt-2">
                        <div class="progress-bar" role="progressbar" 
                             style="width: <?php echo min(($latestSteps/10000)*100, 100); ?>%" 
                             aria-valuenow="<?php echo $latestSteps; ?>" aria-valuemin="0" aria-valuemax="10000">
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="metric-card">
                    <h4>💗 Heart Rate</h4>
                    <h2><?php echo $latestHeartRate; ?> BPM</h2>
                    <p>Normal Range: 60-100</p>
                    <div class="status-indicator <?php echo ($latestHeartRate >= 60 && $latestHeartRate <= 100) ? 'normal' : 'warning'; ?>">
                        <?php echo ($latestHeartRate >= 60 && $latestHeartRate <= 100) ? '✅ Normal' : '⚠️ Attention'; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="metric-card">
                    <h4>🫁 SPO2</h4>
                    <h2><?php echo $latestSpo2; ?>%</h2>
                    <p>Normal Range: 95-100%</p>
                    <div class="status-indicator <?php echo ($latestSpo2 >= 95) ? 'normal' : 'warning'; ?>">
                        <?php echo ($latestSpo2 >= 95) ? '✅ Normal' : '⚠️ Attention'; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">Filter Data</h4>
                        <div>
                            <input type="date" id="dateFilter" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="row">
            <div class="col-md-6">
                <div class="card p-4">
                    <h3>Body Temperature Over Time</h3>
                    <canvas id="temperatureChart"></canvas>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card p-4">
                    <h3>Steps Over Time</h3>
                    <canvas id="stepsChart"></canvas>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="card p-4">
                    <h3>Heart Rate and SPO2 Over Time</h3>
                    <canvas id="healthChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Tips Section -->
        <div class="row mt-4">
            <h2 class="text-center mb-4">Health Insights & Recommendations</h2>
            <div class="col-md-6 mb-3">
                <div class="tip-card temperature">
                    <h4>🌡️ Temperature Insight</h4>
                    <p><?php echo $temperatureTip; ?></p>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="tip-card steps">
                    <h4>👣 Activity Insight</h4>
                    <p><?php echo $stepsTip; ?></p>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="tip-card heart">
                    <h4>💗 Heart Rate Insight</h4>
                    <p><?php echo $heartRateTip; ?></p>
                </div>
            </div>
            <div class="col-md-6 mb-3">
                <div class="tip-card spo2">
                    <h4>🫁 SPO2 Insight</h4>
                    <p><?php echo $spo2Tip; ?></p>
                </div>
            </div>
        </div>
    </div>

    <footer>
        &copy; 2024 Fitness Dashboard. All Rights Reserved.
    </footer>

    <script>
        const fitnessData = <?php echo json_encode($data); ?>;

        function filterDataByDate(date) {
            // Convert YYYY-MM-DD to DD-MM-YYYY
            const dateParts = date.split('-');
            const formattedDate = `${dateParts[2]}-${dateParts[1]}-${dateParts[0]}`; // Convert to DD-MM-YYYY
            
            console.log("Selected date (formatted):", formattedDate); // Debug log
            console.log("First timestamp:", fitnessData.timestamps[0]); // Debug log
            
            const filteredIndices = fitnessData.timestamps.map((timestamp, index) => {
                // Check if the timestamp starts with the formatted date
                return timestamp.startsWith(formattedDate) ? index : null;
            }).filter(index => index !== null);

            console.log("Filtered indices:", filteredIndices); // Debug log

            return {
                temperature: filteredIndices.map(i => fitnessData.temperature[i]),
                steps: filteredIndices.map(i => fitnessData.steps[i]),
                heart_rate: filteredIndices.map(i => fitnessData.heart_rate[i]),
                spo2: filteredIndices.map(i => fitnessData.spo2[i]),
                times: filteredIndices.map(i => fitnessData.times[i])
            };
        }

        let temperatureChart, stepsChart, healthChart;

        function createCharts(data) {
            const chartConfig = {
                responsive: true,
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        }
                    },
                    y: {
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true
                    }
                }
            };

            // Improved zero value filtering
            const filterZeros = (dataArray) => {
                return dataArray.map(value => value > 0 ? value : null);
            };

            if (temperatureChart) temperatureChart.destroy();
            if (stepsChart) stepsChart.destroy();
            if (healthChart) healthChart.destroy();

            temperatureChart = createChart('temperatureChart', data.times, 
                filterZeros(data.temperature), 'Body Temperature (°C)', 
                '#FFD700', 'rgba(255, 215, 0, 0.1)', chartConfig
            );
            
            stepsChart = createChart('stepsChart', data.times, 
                filterZeros(data.steps), 'Steps', 
                '#32CD32', 'rgba(50, 205, 50, 0.1) ', chartConfig
            );
            
            healthChart = new Chart(document.getElementById('healthChart').getContext('2d'), {
                type: 'line',
                data: {
                    labels: data.times,
                    datasets: [
                        {
                            label: '💗 Heart Rate (BPM)',
                            data: filterZeros(data.heart_rate),
                            borderColor: '#FF4500',
                            backgroundColor: 'rgba(255, 69, 0, 0.1)',
                            fill: true,
                            tension: 0,
                            spanGaps: true,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        },
                        {
                            label: '🫁 SPO2 (%)',
                            data: filterZeros(data.spo2),
                            borderColor: '#1E90FF ',
                            backgroundColor: 'rgba(30, 144, 255, 0.1) ',
                            fill: true,
                            tension: 0,
                            spanGaps: true,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }
                    ]
                },
                options: {
                    ...chartConfig,
                    plugins: {
                        legend: {
                            display: true,
                            labels: {
                                font: {
                                    size: 14
                                },
                                usePointStyle: true,
                                padding: 20
                            }
                        }
                    },
                    elements: {
                        line: {
                            borderWidth: 2
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    }
                }
            });
        }

        function createChart(elementId, labels, data, label, borderColor, bgColor, options) {
            return new Chart(document.getElementById(elementId).getContext('2d'), {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: label,
                        data: data,
                        borderColor: borderColor,
                        backgroundColor: bgColor,
                        fill: true,
                        tension: 0.4,
                        spanGaps: true // This will connect lines across null values
                    }]
                },
                options: {
                    ...options,
                    plugins: {
                        legend: {
                            display: true
                        }
                    }
                }
            });
        }

        // Initial chart creation with today's data
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('dateFilter').value = today;

        // Add console log to check the data
        console.log("All timestamps:", fitnessData.timestamps);
        console.log("Initial date:", today);

        createCharts(filterDataByDate(today));

        // Date filter event listener
        document.getElementById('dateFilter').addEventListener('change', (e) => {
            const filteredData = filterDataByDate(e.target.value);
            createCharts(filteredData);
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
