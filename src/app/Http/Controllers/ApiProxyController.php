<?php
namespace App\Consultant\app\Http\Controllers;

use App\Consultant\core\Http\ApiClient;

/**
 * Proxy między frontendem (JS) a API.
 * JWT jest w httpOnly cookie — JS nie może go czytać,
 * więc wszystkie fetch-e idą przez ten endpoint który dokłada token.
 *
 * GET /api-proxy?endpoint=/consultant/shops/summary
 */
class ApiProxyController extends Controller
{
    public function __construct(private ApiClient $apiClient) {}

    public function handle(): void
    {
        // Tylko AJAX
        if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
        ) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        $endpoint = $_GET['endpoint'] ?? '';

        // Whitelist dozwolonych prefiksów — do rozszerzenia w kolejnych iteracjach
        $allowed = [
            '/consultant/',
            '/shops/',
            '/attachments/',
        ];

        $ok = false;
        foreach ($allowed as $prefix) {
            if (str_starts_with($endpoint, $prefix)) {
                $ok = true;
                break;
            }
        }

        if (!$ok || empty($endpoint)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid endpoint']);
            exit;
        }

        // Przekaż dodatkowe GET params (date, period itp.)
        $pass = $_GET;
        unset($pass['url'], $pass['endpoint']);
        if (!empty($pass)) {
            $endpoint .= (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($pass);
        }

        $response = $this->apiClient->get($endpoint);

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if ($response['success']) {
            echo json_encode(['success' => true, 'data' => $response['data']]);
        } else {
            http_response_code(502);
            echo json_encode(['success' => false, 'error' => $response['error'] ?? 'API error']);
        }
        exit;
    }
}

