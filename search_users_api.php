<?php
header('Content-Type: application/json');

try {
    // Conexión a la base de datos
    $host = 'localhost';
    $username = 'admin';
    $password = '2eac4425e56ae74ac753a2f5906649bc1c0d6b19586a5ae4';
    $database = 'matchday_db';

    $conn = new mysqli($host, $username, $password, $database);

    if ($conn->connect_error) {
        throw new Exception('Error de conexión: ' . $conn->connect_error);
    }

    // Obtener datos POST
    $data = json_decode(file_get_contents('php://input'), true);
    
    $email = isset($data['email']) ? trim($data['email']) : '';
    $username = isset($data['username']) ? trim($data['username']) : '';

    // Validar que al menos uno de los campos tenga contenido
    if (empty($email) && empty($username)) {
        throw new Exception('Debe proporcionar un criterio de búsqueda');
    }

    // Construir la consulta SQL con prepared statement
    $query = "SELECT id, username, email, password_hash, avatar_url, display_name, total_points, created_at, updated_at, last_login, is_active FROM users WHERE 1=1";
    $params = [];
    $types = '';

    if (!empty($email)) {
        $query .= " AND email LIKE ?";
        $params[] = '%' . $email . '%';
        $types .= 's';
    }

    if (!empty($username)) {
        $query .= " AND (username LIKE ? OR display_name LIKE ?)";
        $params[] = '%' . $username . '%';
        $params[] = '%' . $username . '%';
        $types .= 'ss';
    }

    $query .= " ORDER BY id DESC LIMIT 100";

    // Preparar y ejecutar la consulta
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception('Error en la preparación de la consulta: ' . $conn->error);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        throw new Exception('Error en la ejecución de la consulta: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $users = [];

    while ($row = $result->fetch_assoc()) {
        $users[] = [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'email' => $row['email'],
            'avatar_url' => $row['avatar_url'],
            'display_name' => $row['display_name'],
            'total_points' => (int)$row['total_points'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'last_login' => $row['last_login'],
            'is_active' => (int)$row['is_active']
        ];
    }

    $stmt->close();
    $conn->close();

    echo json_encode([
        'success' => true,
        'users' => $users,
        'count' => count($users)
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
