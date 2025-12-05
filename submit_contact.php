<?php
// ====================================
// CONFIGURACIÓN INICIAL
// ====================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// RUTAS A PHPMailer (Ajustadas a la estructura real)
require __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require __DIR__ . '/../PHPMailer-master/src/SMTP.php';
require __DIR__ . '/../PHPMailer-master/src/Exception.php';

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
    die("❌ Database connection error: " . $e->getMessage());
}

// ====================================
// PROCESAMIENTO DEL FORMULARIO
// ====================================
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // --- Anti-bot honeypot ---
    if (!empty($_POST['website'])) {
        die('Bot detected. Submission blocked.');
    }

    $timestamp = 0;
    if (isset($_POST["timestamp"]) && is_numeric($_POST["timestamp"])) {
        $timestamp = (int) $_POST["timestamp"];
    }

    // Calcula diferencia absoluta en segundos
    $delta = abs(time() - $timestamp);

    // Si el timestamp no existe, es menor a 3 segundos, o tiene más de 24 h → sospechoso
    if ($timestamp === 0 || $delta < 3 || $delta > 86400) {
        // Log para depuración (puedes comentarlo en producción)
        // echo "Server time: " . time() . "<br>";
        // echo "Timestamp received: " . $timestamp . "<br>";
        // echo "Delta: " . abs(time() - $timestamp) . " seconds<br>";
        die("Suspiciously fast submission. Possible bot detected.");
    }

    // Función de limpieza
    function clean($value) {
        return htmlspecialchars(trim($value ?? ''));
    }

    // Recoger datos
    $fullName     = clean($_POST["full_name"]);
    $email        = clean($_POST["email"]);
    $phoneNumber  = clean($_POST["phone"]);
    $entityName   = clean($_POST["entity_name"]);
    
    // Investor Type (Array -> String)
    $investorTypes = isset($_POST['investorType']) ? implode(', ', $_POST['investorType']) : '';
    $otherInvestorType = clean($_POST["investorTypeOther"]);

    // Accreditation
    $isAccredited = isset($_POST['accredited']) ? "Yes" : "No";
    $accreditationLink = clean($_POST["accreditation_link"]);
    
    // Manejo de archivo
    $accreditationFile = '';
    if (isset($_FILES['accreditation_file']) && $_FILES['accreditation_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $filename = uniqid() . '_' . basename($_FILES['accreditation_file']['name']);
        $filePath = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['accreditation_file']['tmp_name'], $filePath)) {
            $accreditationFile = $filePath;
        }
    }

    // Construir string de estado de acreditación
    $accreditationStatus = $isAccredited;
    if ($accreditationFile) $accreditationStatus .= " | File: " . basename($accreditationFile);
    if ($accreditationLink) $accreditationStatus .= " | Link: " . $accreditationLink;
    // Truncar si excede 200 chars
    if (strlen($accreditationStatus) > 200) {
        $accreditationStatus = substr($accreditationStatus, 0, 197) . '...';
    }

    // Region
    $regionResidence = clean($_POST["region"]);
    $otherRegionResidence = clean($_POST["region_other"]);

    // Investment Interest (Array -> String)
    $investmentInterest = isset($_POST['interests']) ? implode(', ', $_POST['interests']) : '';

    // Otros
    $hearAboutUs = clean($_POST["referralSource"]);
    $message     = clean($_POST["message"]);

    // Validar email real
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "<script>alert('Please enter a valid email address.'); window.history.back();</script>";
        exit;
    }

    if ($fullName && $email) {
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

            // ====================================
            // CONFIGURAR CORREO (PHPMailer)
            // ====================================
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; 
            $mail->Port = $smtpPort;
            $mail->CharSet = 'UTF-8';

            // ====================================
            // ENVÍO AL CLIENTE
            // ====================================
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
                
                <p><strong>Next Steps</strong></p>
                <p>We will reach out to schedule an introductory call or provide you with access to our current deal flow if you meet our criteria.</p>

                <p>We look forward to partnering with you.</p>

                <br><br>
                <p>Sincerely,</p>
                
                <p><strong>Marston Real Estate Team</strong><br>
                <a href='https://marstonre.com' style='color:#003f61;text-decoration:none;'>www.marstonre.com</a></p>
            </body>
            </html>
            ";

            $mail->send();

            // ====================================
            // ENVÍO INTERNO AL EQUIPO
            // ====================================
            $mail->clearAddresses();
            $mail->addAddress("info@zerotoplan.com"); // Usando el mismo correo de recepción por ahora
            // Si hay otro correo para Marston, se debería cambiar aquí.
            
            $mail->Subject = "📩 New Investor Form Submission - {$fullName}";
            $mail->Body = "
            <html>
            <body style='font-family:Arial,sans-serif;color:#333;'>
              <h3>New Investor Contact Received</h3>
              <p><strong>Name:</strong> {$fullName}</p>
              <p><strong>Email:</strong> {$email}</p>
              <p><strong>Phone:</strong> {$phoneNumber}</p>
              <p><strong>Entity:</strong> {$entityName}</p>
              <p><strong>Investor Type:</strong> {$investorTypes} " . ($otherInvestorType ? "($otherInvestorType)" : "") . "</p>
              <p><strong>Accreditation:</strong> {$accreditationStatus}</p>
              <p><strong>Region:</strong> {$regionResidence} " . ($otherRegionResidence ? "($otherRegionResidence)" : "") . "</p>
              <p><strong>Interests:</strong> {$investmentInterest}</p>
              <p><strong>Source:</strong> {$hearAboutUs}</p>
              <p><strong>Message:</strong></p>
              <blockquote style='border-left:3px solid #ccc;padding-left:10px;color:#555;'>{$message}</blockquote>
              <p><em>Submitted on " . date('Y-m-d H:i:s') . "</em><br></p>
            </body>
            </html>";

            $mail->send();

            // ====================================
            // CONFIRMACIÓN VISUAL AL USUARIO
            // ====================================
            echo "<script>
                alert('✅ Thank you! Your submission has been received.');
                window.location.href='index.html';
            </script>";

        } catch (Exception $e) {
            echo "<script>
                alert('⚠️ Mail error: " . addslashes($e->getMessage()) . "');
                window.history.back();
            </script>";
        } catch (PDOException $e) {
            echo "<script>
                alert('⚠️ Database error: " . addslashes($e->getMessage()) . "');
                window.history.back();
            </script>";
        }
    } else {
        echo "<script>alert('Please fill in all required fields.'); window.history.back();</script>";
    }
} else {
    echo "<script>alert('Invalid request.'); window.history.back();</script>";
}
?>
