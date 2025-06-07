<?php
// Configuración de base de datos (ajusta estos valores)
$host = 'localhost';
$dbname = 'zero9111_landing';
$username = 'zero9111_jesusrey';
$password = 'o+[ZdH33O£RhD2/';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error en la conexión: " . $e->getMessage());
}

// Función para limpiar campos
function clean($value) {
    return htmlspecialchars(trim($value));
}

// Captura de datos
$form_name = 'investor_form';
$full_name = clean($_POST['full_name'] ?? '');
$email = clean($_POST['email'] ?? '');
$phone = clean($_POST['phone'] ?? '');
$entity_name = clean($_POST['entity_name'] ?? '');

$investor_types = isset($_POST['investorType']) ? implode(', ', $_POST['investorType']) : '';
$investor_type_other = clean($_POST['investorTypeOther'] ?? '');
if (!empty($investor_type_other)) {
    $investor_types .= ($investor_types ? ', ' : '') . "Other: $investor_type_other";
}

$accredited = isset($_POST['accredited']) ? 1 : 0;
$accreditation_link = clean($_POST['accreditation_link'] ?? '');

// Subida de archivo (opcional)
$accreditation_file = '';
if (isset($_FILES['accreditation_file']) && $_FILES['accreditation_file']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $filename = uniqid() . '_' . basename($_FILES['accreditation_file']['name']);
    $filePath = $uploadDir . $filename;
    move_uploaded_file($_FILES['accreditation_file']['tmp_name'], $filePath);
    $accreditation_file = $filePath;
}

$region = clean($_POST['region'] ?? '');
$region_other = clean($_POST['region_other'] ?? '');
$minimum_investment_confirmed = isset($_POST['minimumInvestment']) ? 1 : 0;

$investment_interests = isset($_POST['interests']) ? implode(', ', $_POST['interests']) : '';
$referral_source = clean($_POST['referralSource'] ?? '');
$message = clean($_POST['message'] ?? '');

$source_domain = $_SERVER['HTTP_HOST'] ?? 'unknown';

// Inserción en la base de datos
$sql = "INSERT INTO form_submissions (
    form_name, full_name, email, phone, entity_name,
    investor_types, accredited, accreditation_file, accreditation_link,
    region, region_other, minimum_investment_confirmed, investment_interests,
    referral_source, message, source_domain
) VALUES (
    :form_name, :full_name, :email, :phone, :entity_name,
    :investor_types, :accredited, :accreditation_file, :accreditation_link,
    :region, :region_other, :minimum_investment_confirmed, :investment_interests,
    :referral_source, :message, :source_domain
)";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':form_name' => $form_name,
    ':full_name' => $full_name,
    ':email' => $email,
    ':phone' => $phone,
    ':entity_name' => $entity_name,
    ':investor_types' => $investor_types,
    ':accredited' => $accredited,
    ':accreditation_file' => $accreditation_file,
    ':accreditation_link' => $accreditation_link,
    ':region' => $region,
    ':region_other' => $region_other,
    ':minimum_investment_confirmed' => $minimum_investment_confirmed,
    ':investment_interests' => $investment_interests,
    ':referral_source' => $referral_source,
    ':message' => $message,
    ':source_domain' => $source_domain
]);

// Redirección o respuesta
header("Location: index.html");
exit;
?>
