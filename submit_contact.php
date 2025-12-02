<?php
// ====================================
// CORS & HEADERS
// ====================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ====================================
// CONFIGURACIÓN INICIAL
// ====================================

// CREDENCIALES SMTP
$smtpHost = 'mail.atexgrp.com'; // Ajustar según tu dominio
$smtpUser = 'no-reply@atexgrp.com';
$smtpPass = 'TU_PASSWORD_SMTP'; // Cambiar por tu contraseña real
$smtpPort = 465; // o 587 si usas STARTTLS

// ====================================
// CONEXIÓN A LA BASE DE DATOS
// ====================================
$host = 'localhost';
$dbname = 'zero9111_landing';
$username = 'zero9111_jesusrey';
$password = 'o+[ZdH33O£RhD2/';

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli($host, $username, $password, $dbname);
    
    // Check connection (redundant with strict mode but safe)
    if ($conn->connect_error) {
        throw new \Exception("Connection failed: " . $conn->connect_error);
    }
    
    $conn->set_charset("latin1");
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection error: ' . $e->getMessage()]);
    exit();
}

// ====================================
// PROCESAMIENTO DEL FORMULARIO
// ====================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Get JSON data from request body
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
        exit();
    }

    // ... (Anti-bot and validation code remains the same) ...

    // --- Anti-bot honeypot ---
    if (!empty($data['website'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Bot detected. Submission blocked.']);
        exit();
    }

    // --- Timestamp validation ---
    $timestamp = 0;
    if (isset($data["timestamp"]) && is_numeric($data["timestamp"])) {
        $timestamp = (int) $data["timestamp"];
    }

    // Calcula diferencia absoluta en segundos
    $delta = abs(time() - $timestamp);

    // Si el timestamp no existe, es menor a 3 segundos, o tiene más de 24 h → sospechoso
    if ($timestamp === 0 || $delta < 3 || $delta > 86400) {
        http_response_code(403);
        echo json_encode([
            'success' => false, 
            'message' => 'Suspiciously fast submission. Possible bot detected.',
            'debug' => [
                'server_time' => time(),
                'timestamp_received' => $timestamp,
                'delta' => $delta
            ]
        ]);
        exit();
    }

    // ====================================
    // EXTRAER Y SANITIZAR DATOS
    // ====================================
    $full_name = $conn->real_escape_string(trim($data['full_name'] ?? ''));
    $email = $conn->real_escape_string(trim($data['email'] ?? ''));
    $phone_number = $conn->real_escape_string(trim($data['phone'] ?? ''));
    $entity_name = $conn->real_escape_string(trim($data['entity_name'] ?? ''));
    
    // Handle investor type
    $investor_type_array = $data['investorType'] ?? [];
    $investor_type = '';
    $other_investor_type = '';
    
    $valid_investor_types = [
        'Family Office', 'Private Equity', 'HNW', 'Institutional Investor',
        'Real Estate Syndicator', 'Wealth Advisor', 'International Investment Group', 'Other'
    ];
    
    foreach ($investor_type_array as $type) {
        if (in_array($type, $valid_investor_types)) {
            if ($type === 'Other') {
                $investor_type = 'Other';
                $other_investor_type = $conn->real_escape_string(trim($data['investorTypeOther'] ?? ''));
            } else {
                $investor_type = $conn->real_escape_string($type);
            }
            break;
        }
    }
    
    if (empty($investor_type)) {
        $investor_type = 'Other';
        $other_investor_type = $conn->real_escape_string(implode(', ', $investor_type_array));
    }
    
    // Accreditation status
    $acreditation_status = $conn->real_escape_string(trim($data['accreditation_status'] ?? ''));
    
    // Handle region
    $region_value = $data['region'] ?? '';
    $other_region_residence = '';
    
    $valid_regions = ['United States', 'South America', 'Middle East', 'Asia-Pacific', 'Canada', 'Other'];
    
    if ($region_value === 'Other') {
        $region_residence = 'Other';
        $other_region_residence = $conn->real_escape_string(trim($data['region_other'] ?? ''));
    } elseif (in_array($region_value, $valid_regions)) {
        $region_residence = $conn->real_escape_string($region_value);
    } else {
        $region_residence = 'Other';
        $other_region_residence = $conn->real_escape_string($region_value);
    }
    
    // Handle investment interest
    $interests_array = $data['interests'] ?? [];
    $investment_interest = '';
    
    $valid_interests = ['Ground-up', 'Mixed-Use', 'Workforce', 'Value-Add', 'Co-GP', 'Debt'];
    
    foreach ($interests_array as $interest) {
        if (in_array($interest, $valid_interests)) {
            $investment_interest = $conn->real_escape_string($interest);
            break;
        }
    }
    
    if (empty($investment_interest)) {
        $investment_interest = 'Ground-up';
    }
    
    // Referral and message
    $hear_about_us = $conn->real_escape_string(trim($data['referralSource'] ?? ''));
    $message = $conn->real_escape_string(trim($data['message'] ?? ''));
    
    // ====================================
    // VALIDACIONES
    // ====================================
    if (empty($full_name) || empty($email) || empty($phone_number) || empty($entity_name)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
        exit();
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
        exit();
    }

    try {
        // ====================================
        // INSERTAR EN BASE DE DATOS
        // ====================================
        $sql = "INSERT INTO atex_contact (
            full_name, email, phone_number, entity_name, 
            investor_type, other_investor_type, acreditation_status, 
            region_residence, other_region_residence, investment_interest, 
            hear_about_us, message
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new \Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param(
            "ssssssssssss",
            $full_name, $email, $phone_number, $entity_name,
            $investor_type, $other_investor_type, $acreditation_status,
            $region_residence, $other_region_residence, $investment_interest,
            $hear_about_us, $message
        );
        
        if (!$stmt->execute()) {
            throw new \Exception("Execute failed: " . $stmt->error);
        }
        
        $insert_id = $stmt->insert_id;
        $stmt->close();

        // ====================================
        // CONFIGURAR CORREO
        // ====================================
        
        // Check if PHPMailer exists
        $phpMailerPath = __DIR__ . '/PHPMailer-master/PHPMailer-master/src/PHPMailer.php';
        $usePHPMailer = file_exists($phpMailerPath);

        $emailSent = false;
        $internalEmailSent = false;

        if ($usePHPMailer) {
            // ... (Existing PHPMailer code) ...
            require_once __DIR__ . '/PHPMailer-master/PHPMailer-master/src/PHPMailer.php';
            require_once __DIR__ . '/PHPMailer-master/PHPMailer-master/src/SMTP.php';
            require_once __DIR__ . '/PHPMailer-master/PHPMailer-master/src/Exception.php';

            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            // ... configuration ...
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $smtpPort;
            $mail->CharSet = 'UTF-8';

            // Client Email
            $mail->setFrom($smtpUser, 'ATEX Group');
            $mail->addAddress($email, $full_name);
            $mail->Subject = "Thank you for your interest in ATEX Group";
            $mail->isHTML(true);
            
            // ... Body construction ...
            $investorTypeDisplay = $investor_type === 'Other' && $other_investor_type 
                ? "Other: {$other_investor_type}" 
                : $investor_type;
            
            $regionDisplay = $region_residence === 'Other' && $other_region_residence
                ? "Other: {$other_region_residence}"
                : $region_residence;

            $mail->Body = "
            <html>
            <body style='font-family: Arial, sans-serif; color:#333; max-width:600px; margin:0 auto;'>
                <div style='background: linear-gradient(135deg, #364350 0%, #2e3a44 100%); padding: 30px; text-align: center;'>
                    <h1 style='color: white; margin: 0;'>ATEX Group</h1>
                    <p style='color: #f0f0f0; margin: 10px 0 0 0;'>Alternative Capital Partners</p>
                </div>
                
                <div style='padding: 30px; background: #f9fbfc;'>
                    <h2 style='color: #364350;'>Dear {$full_name},</h2>
                    
                    <p>Thank you for your interest in <strong>ATEX Group</strong>. We have successfully received your investor inquiry.</p>
                    
                    <p>Our team will review your information and reach out to you shortly to discuss potential investment opportunities that align with your interests.</p>
                    
                    <div style='background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #364350;'>
                        <h3 style='margin-top: 0; color: #364350;'>Your Submission Details:</h3>
                        <p><strong>Entity Name:</strong> {$entity_name}</p>
                        <p><strong>Investor Type:</strong> {$investorTypeDisplay}</p>
                        <p><strong>Region:</strong> {$regionDisplay}</p>
                        <p><strong>Investment Interest:</strong> {$investment_interest}</p>
                    </div>
                    
                    <p>We appreciate your interest in partnering with us and look forward to exploring opportunities together.</p>
                    
                    <p style='margin-top: 30px;'>Best regards,<br><strong>ATEX Group Team</strong></p>
                </div>
                
                <div style='background: #364350; padding: 20px; text-align: center; color: white; font-size: 12px;'>
                    <p style='margin: 5px 0;'>ATEX Group</p>
                    <p style='margin: 5px 0;'>Website: <a href='https://atexgrp.com' style='color: #fff;'>www.atexgrp.com</a></p>
                    <p style='margin: 5px 0;'>Email: <a href='mailto:info@atexgrp.com' style='color: #fff;'>info@atexgrp.com</a></p>
                </div>
            </body>
            </html>
            ";
            
            $mail->send();
            $emailSent = true;

            // Internal Email
            $mail->clearAddresses();
            $mail->addAddress("info@atexgrp.com"); 
            $mail->Subject = "📩 New Investor Inquiry - {$full_name}";
            
            $interestsDisplay = is_array($interests_array) ? implode(', ', $interests_array) : 'None specified';
            $investorTypesDisplay = is_array($investor_type_array) ? implode(', ', $investor_type_array) : 'None specified';
            
            $mail->Body = "
            <html>
            <body style='font-family:Arial,sans-serif;color:#333;'>
                <h2 style='color:#364350;'>🎯 New Investor Contact Form Submission</h2>
                
                <h3>Contact Information</h3>
                <table style='border-collapse: collapse; width: 100%;'>
                    <tr>
                        <td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9; width: 200px;'><strong>Full Name:</strong></td>
                        <td style='padding: 8px; border: 1px solid #ddd;'>{$full_name}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9;'><strong>Email:</strong></td>
                        <td style='padding: 8px; border: 1px solid #ddd;'><a href='mailto:{$email}'>{$email}</a></td>
                    </tr>
                    <tr>
                        <td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9;'><strong>Phone:</strong></td>
                        <td style='padding: 8px; border: 1px solid #ddd;'>{$phone_number}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9;'><strong>Entity Name:</strong></td>
                        <td style='padding: 8px; border: 1px solid #ddd;'>{$entity_name}</td>
                    </tr>
                </table>
                
                <h3>Investment Profile</h3>
                <table style='border-collapse: collapse; width: 100%;'>
                    <tr>
                        <td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9; width: 200px;'><strong>Investor Type(s):</strong></td>
                        <td style='padding: 8px; border: 1px solid #ddd;'>{$investorTypesDisplay}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9;'><strong>Accreditation Status:</strong></td>
                        <td style='padding: 8px; border: 1px solid #ddd;'>{$acreditation_status}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9;'><strong>Region:</strong></td>
                        <td style='padding: 8px; border: 1px solid #ddd;'>{$regionDisplay}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px; border: 1px solid #ddd; background: #f9f9f9;'><strong>Investment Interests:</strong></td>
                        <td style='padding: 8px; border: 1px solid #ddd;'>{$interestsDisplay}</td>
                    </tr>
                </table>
                
                <h3>Additional Information</h3>
                <p><strong>How they heard about us:</strong></p>
                <blockquote style='border-left:3px solid #364350;padding-left:15px;color:#555; margin: 10px 0;'>{$hear_about_us}</blockquote>
                
                <p><strong>Message:</strong></p>
                <blockquote style='border-left:3px solid #364350;padding-left:15px;color:#555; margin: 10px 0;'>{$message}</blockquote>
                
                <hr style='margin: 30px 0; border: none; border-top: 2px solid #364350;'>
                <p style='color: #666;'><em>Submitted on " . date('Y-m-d H:i:s') . "</em></p>
                <p style='color: #666;'><em>Database ID: #{$insert_id}</em></p>
            </body>
            </html>";

            $mail->send();
            $internalEmailSent = true;

        } else {
            // Fallback to native PHP mail()
            $to = $email;
            $subject = "Thank you for your interest in ATEX Group";
            $headers = "From: no-reply@atexgrp.com\r\n";
            $headers .= "Reply-To: info@atexgrp.com\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

            $message_body = "
            <html>
            <body style='font-family: Arial, sans-serif; color:#333;'>
                <h2>Dear {$full_name},</h2>
                <p>Thank you for your interest in ATEX Group. We have received your inquiry.</p>
                <p>Our team will review your information and reach out to you shortly.</p>
                <p>Best regards,<br>ATEX Group Team</p>
            </body>
            </html>";

            $emailSent = mail($to, $subject, $message_body, $headers);

            // Internal notification
            $internal_to = "info@atexgrp.com";
            $internal_subject = "New Investor Inquiry - {$full_name}";
            $internal_message = "Name: {$full_name}\nEmail: {$email}\nPhone: {$phone_number}\nEntity: {$entity_name}\n\nCheck database for full details.";
            $internal_headers = "From: no-reply@atexgrp.com";

            $internalEmailSent = mail($internal_to, $internal_subject, $internal_message, $internal_headers);
        }

        // ====================================
        // RESPUESTA EXITOSA
        // ====================================
        $conn->close();
        echo json_encode([
            'success' => true,
            'message' => 'Thank you for contacting us! A confirmation email has been sent to your inbox.',
            'id' => $insert_id,
            'email_status' => $usePHPMailer ? 'PHPMailer' : 'Native mail()',
            'sent' => $emailSent
        ]);

    } catch (Exception $e) {
        // ... error handling ...
        error_log("Contact Form Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'An error occurred while processing your request. Please try again later.'
        ]);
    }

} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>
