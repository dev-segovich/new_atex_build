<?php
// ====================================
// CONFIGURACIÓN INICIAL
// ====================================

// CORS Headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Verificar dependencias y rutas posibles para PHPMailer
$possiblePaths = [
    __DIR__ . '/../PHPMailer-master/src/PHPMailer.php', // Local Dev (public/../PHPMailer...)
    __DIR__ . '/PHPMailer-master/src/PHPMailer.php',    // Prod (root/PHPMailer...)
];

$phpMailerSrcDir = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        // La ruta a 'src' es el directorio del archivo encontrado
        $phpMailerSrcDir = dirname($path); 
        break;
    }
}

if (!$phpMailerSrcDir) {
    http_response_code(500);
    // Devuelve paths buscados solo para debug por ahora
    echo json_encode(['success' => false, 'message' => 'Server Error: PHPMailer library missing. Checked common paths.']);
    exit;
}

// Cargar clases
require $phpMailerSrcDir . '/PHPMailer.php';
require $phpMailerSrcDir . '/SMTP.php';
require $phpMailerSrcDir . '/Exception.php';

// CREDENCIALES SMTP
$smtpHost = 'mail.zerotoplan.com';
$smtpUser = 'no-reply@zerotoplan.com';
$smtpPass = '5)}dQ&%jli4j!8bc'; 
$smtpPort = 465; // SSL

// ====================================
// CONEXIÓN A LA BASE DE DATOS
// ====================================
$host = 'localhost';
$dbname = 'zero9111_landing';
$username = 'zero9111_jesusrey';
$password = 'o+[ZdH33O£RhD2/';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => "Database connection error: " . $e->getMessage()]);
    exit;
}

// ====================================
// PROCESAMIENTO DEL FORMULARIO
// ====================================
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Leer el body JSON si es enviado como application/json
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    // Si no es JSON, intentar leer de $_POST (por si acaso se envía como form-data)
    if (!$data) {
        $data = $_POST;
    }

    // --- Anti-bot honeypot ---
    if (!empty($data['website'])) {
        echo json_encode(['success' => false, 'message' => 'Bot detected. Submission blocked.']);
        exit;
    }

    $timestamp = 0;
    if (isset($data["timestamp"]) && is_numeric($data["timestamp"])) {
        $timestamp = (int) $data["timestamp"];
    }

    // Calcula diferencia absoluta en segundos
    $delta = abs(time() - $timestamp);

    // Si el timestamp no existe, es menor a 3 segundos, o tiene más de 24 h → sospechoso
    // NOTA: Se ha comentado la restricción de tiempo para pruebas, descomentar en producción si se desea
    /*
    if ($timestamp === 0 || $delta < 3 || $delta > 86400) {
        echo json_encode(['success' => false, 'message' => 'Suspiciously fast submission. Possible bot detected.']);
        exit;
    }
    */

    // Función de limpieza
    function clean($value) {
        return htmlspecialchars(trim($value ?? ''));
    }

    // Recoger datos
    $fullName     = clean($data["full_name"] ?? '');
    $email        = clean($data["email"] ?? '');
    $phoneNumber  = clean($data["phone"] ?? '');
    $entityName   = clean($data["entity_name"] ?? '');
    
    // Investor Type (Array -> String)
    $investorTypesRaw = $data['investorType'] ?? null;
    $investorTypes = is_array($investorTypesRaw) ? implode(', ', $investorTypesRaw) : ($investorTypesRaw ?? '');
    
    $otherInvestorType = clean($data["investorTypeOther"] ?? '');

    // Accreditation
    $isAccredited = ($data['accreditation_status'] ?? '') === 'yes' ? "Yes" : "No"; // Ajustado para match con frontend
    $accreditationLink = clean($data["accreditation_link"] ?? '');
    
    // Manejo de archivo (Si viene por $_FILES en form-data estándar)
    // NOTA: nextjs fetch con JSON body NO envía archivos automáticamente.
    // Para envío de archivos se requiere FormData y no enviar Content-Type application/json explícito.
    // El frontend actual usa JSON.stringify(data), por lo que el archivo NO llegará.
    // Asumiremos que el archivo no se está enviando correctamente o se debe manejar aparte.
    // Por ahora, omitimos la lógica de archivo para evitar errores si no está presente en $_FILES.
    
    $accreditationFile = '';
    // Logica de archivo omitida porque el frontend envía JSON stringify.
    
    // Construir string de estado de acreditación
    $accreditationStatus = $isAccredited;
    if ($accreditationLink) $accreditationStatus .= " | Link: " . $accreditationLink;
    
    // Region
    $regionResidence = clean($data["region"] ?? '');
    $otherRegionResidence = clean($data["region_other"] ?? '');

    // Investment Interest
    $interestsRaw = $data['interests'] ?? null;
    $investmentInterest = is_array($interestsRaw) ? implode(', ', $interestsRaw) : ($interestsRaw ?? '');

    // Otros
    $hearAboutUs = clean($data["referralSource"] ?? '');
    $message     = clean($data["message"] ?? '');

    // Validar email real
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
        exit;
    }

    if ($fullName && $email) {
        
        $dbSuccess = false;
        $mailSuccess = false;
        $dbError = null;
        $mailError = null;

        // ====================================
        // PROCESO 1: BASE DE DATOS (Independiente)
        // ====================================
        try {
            // Insertar en base de datos (Tabla marston_contact)
            $stmt = $pdo->prepare("
                INSERT INTO marston_contact (
                    full_name, email, phone_number, entity_name, 
                    investor_type, other_investor_type, acreditation_status, 
                    region_residence, other_region_residence, investment_interest, 
                    hear_about_us, message
                )
                VALUES (
                    :full_name, :email, :phone_number, :entity_name, 
                    :investor_type, :other_investor_type, :acreditation_status, 
                    :region_residence, :other_region_residence, :investment_interest, 
                    :hear_about_us, :message
                )
            ");
            
            $stmt->execute([
                ":full_name" => $fullName,
                ":email" => $email,
                ":phone_number" => $phoneNumber,
                ":entity_name" => $entityName,
                ":investor_type" => $investorTypes,
                ":other_investor_type" => $otherInvestorType,
                ":acreditation_status" => $accreditationStatus,
                ":region_residence" => $regionResidence,
                ":other_region_residence" => $otherRegionResidence,
                ":investment_interest" => $investmentInterest,
                ":hear_about_us" => $hearAboutUs,
                ":message" => $message
            ]);

            $dbSuccess = true;

        } catch (PDOException $e) {
            $dbError = $e->getMessage();
            error_log("Database Error: " . $dbError);
        }

        // ====================================
        // PROCESO 2: CORREO ELECTRÓNICO (Independiente)
        // ====================================
        try {
            // CONFIGURAR CORREO (PHPMailer)
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; 
            $mail->Port = $smtpPort;
            $mail->CharSet = 'UTF-8';

            // ENVÍO AL CLIENTE
            $mail->setFrom($smtpUser, 'Marston Real Estate');
            $mail->addAddress($email, $fullName);
            $mail->Subject = "Welcome to Marston: Your Request is Confirmed";
            $mail->isHTML(true);

            $mail->Body = "
            <html>
            <body style='font-family: Arial, sans-serif; color:#333;'>
                <h2>Dear {$fullName},</h2>
                <p>Thank you for contacting <strong>Marston Real Estate</strong>.</p>
                <p>We are pleased to confirm the successful submission of your investor intake form. Our team is reviewing your details and will be in contact with you shortly to discuss exclusive opportunities.</p>
                <br>
                <p><strong>Marston Real Estate Team</strong></p>
            </body>
            </html>
            ";

            $mail->send();

            // ENVÍO INTERNO AL EQUIPO
            $mail->clearAddresses();
            $mail->addAddress("info@zerotoplan.com"); 
            
            $mail->Subject = "📩 New Investor Form: {$fullName}";
            $mail->Body = "
            <html>
            <body style='font-family:Arial,sans-serif;color:#333;'>
              <h3>New Investor Contact</h3>
              <p><strong>Name:</strong> {$fullName}</p>
              <p><strong>Email:</strong> {$email}</p>
              <p><strong>Phone:</strong> {$phoneNumber}</p>
              <p><strong>Type:</strong> {$investorTypes}</p>
              <p><strong>Message:</strong> {$message}</p>
            </body>
            </html>";

            $mail->send();

            $mailSuccess = true;

        } catch (Exception $e) {
            $mailError = $e->getMessage();
            error_log("Mail Error: " . $mailError);
        }

        // ====================================
        // RESPUESTA AL USUARIO
        // ====================================
        
        if ($dbSuccess) {
            echo json_encode(['success' => true, 'message' => 'Form submitted successfully']);
        } else {
             // Si falló la DB, retornamos error aunque el mail se haya enviado (o decidimos returning success warning)
             // Para simplificar, error si falla DB.
             http_response_code(500);
             echo json_encode(['success' => false, 'message' => 'Database error occurred: ' . $dbError]);
        }

    } else {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>
