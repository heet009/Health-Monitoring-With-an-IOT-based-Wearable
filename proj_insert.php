<?php
    $host = "localhost";
    $user = "iot_user";
    $password = "iot@1122";
    $db = "iotdata";

    // Connect to the database
    $conn = mysqli_connect($host, $user, $password, $db);

    if ($conn) {
        // Check if all required data is received
        if (isset($_GET['fall']) && isset($_GET['heart_rate']) && isset($_GET['spo2']) && 
            isset($_GET['temperature']) && isset($_GET['steps'])) {
            
            $response = array();
            $response['status'] = "success";
            $response['message'] = "Connected to AWS database";

            // Get parameters from the URL
            $fall = intval($_GET['fall']);
            $heart_rate = intval($_GET['heart_rate']);
            $spo2 = floatval($_GET['spo2']);
            $temperature = floatval($_GET['temperature']);
            $steps = intval($_GET['steps']);

            // Get current date and time
            date_default_timezone_set('Asia/Dubai'); // Set timezone
            $date = date('d-m-Y');
            $time = date('H:i:s');

            // Include received data in the response
            $response['data'] = array(
                'fall' => $fall,
                'heart_rate' => $heart_rate,
                'spo2' => $spo2,
                'temperature' => $temperature,
                'steps' => $steps,
                'date' => $date,
                'time' => $time
            );

            // Insert data into the database
            $sql = "INSERT INTO sensor_data (fall, heart_rate, spo2, temperature, steps, date, time) 
                    VALUES ($fall, $heart_rate, $spo2, $temperature, $steps, '$date', '$time')";
            $result = mysqli_query($conn, $sql);

            if ($result) {
                $response['db_status'] = "Data Inserted";
            } else {
                $response['db_status'] = "Data Not Inserted";
                $response['status'] = "error";
            }
        } else {
            $response = array(
                'status' => "error",
                'message' => "Data Not Received"
            );
        }
    } else {
        $response = array(
            'status' => "error",
            'message' => "Not Connected to the Database"
        );
    }

    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode($response);
?>
