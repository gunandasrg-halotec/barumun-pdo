<?php

namespace App\Services\Payroll;

use App\Exceptions\PayrollApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PayrollApiService
{
    /** @var array<int,string> */
    public const OPTION_COMPONENTS = [
        'base_payroll_total',
        'maintenance_total',
        'additional_wage_type_total',
    ];

    /**
     * @return array<int,array{component_key:string,label:string}>
     */
    public function fetchComponentOptions(
        string $component,
        ?string $filter = null,
        ?string $estateExternalId = null,
        ?string $search = null,
        ?int $limit = null,
    ): array
    {
        $response = $this->request('/internal/payroll-cost-component-options', array_filter([
            'component' => $component,
            'filter' => $filter,
            'estate_external_id' => $estateExternalId,
            'q' => $search,
            'limit' => $limit,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        $payload = $response->json();
        $options = data_get($payload, 'data.options', $payload['options'] ?? []);

        if (! is_array($options)) {
            throw new PayrollApiException('PAYROLL_INVALID_RESPONSE', 503, 'Payroll mengembalikan format opsi tidak valid.');
        }

        return collect($options)
            ->filter(fn ($option): bool => is_array($option))
            ->map(function (array $option): array {
                $key = (string) data_get($option, 'component_key', '');
                $label = (string) data_get($option, 'label', $option['name'] ?? '');

                return ['component_key' => $key, 'label' => $label];
            })
            ->filter(fn (array $option): bool => $option['component_key'] !== '')
            ->values()
            ->toArray();
    }

    /**
     * @param  array<string,mixed>  $query
     */
    public function fetchPayrollCost(array $query): Response
    {
        return $this->request('/internal/payroll-costs', $query);
    }

    private function request(string $path, array $query): Response
    {
        $baseUrl = rtrim((string) config('services.payroll_internal_api.base_url', ''), '/');
        $token = (string) config('services.payroll_internal_api.token', '');

        if ($baseUrl === '' || $token === '') {
            throw new PayrollApiException('PAYROLL_UNAVAILABLE', 503, 'Konfigurasi Payroll internal API belum lengkap.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($token)
                ->timeout(15)
                ->get($baseUrl.$path, $query);

            if (! $response->successful()) {
                $message = (string) data_get($response->json(), 'error', 'Payroll tidak dapat memproses permintaan.');

                $code = match ($response->status()) {
                    404, 422 => 'PAYROLL_VALIDATION_ERROR',
                    default => 'PAYROLL_UNAVAILABLE',
                };

                throw new PayrollApiException($code, 503, $message);
            }

            return $response;
        } catch (ConnectionException) {
            throw new PayrollApiException('PAYROLL_UNAVAILABLE', 503, 'Payroll tidak dapat dihubungi saat ini.');
        }
    }
}
