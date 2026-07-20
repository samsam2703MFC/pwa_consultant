<?php
namespace App\Consultant\app\Repositories\Dashboard;

use App\Consultant\core\Http\ApiClient;

/**
 * Dane pulpitu konsultanta (KPI, zadania dnia, alerty, sklepy).
 * Endpoint (zakładany): GET /consultant/dashboard?date=YYYY-MM-DD
 *
 * Backend nie jest jeszcze podłączony — gdy API zwróci pustą/nieudaną
 * odpowiedź, zwracamy [] i kontroler pozostaje przy danych demonstracyjnych.
 */
class DashboardRepository
{
    public function __construct(private ApiClient $apiClient) {}

    public function getSummary(string $date): array
    {
        $response = $this->apiClient->get('/consultant/dashboard?date=' . urlencode($date));

        return ($response['success'] && !empty($response['data']))
            ? $response['data']
            : [];
    }
}
