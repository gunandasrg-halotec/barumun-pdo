<?php

namespace App\Http\Controllers\MasterData;

use App\Exceptions\PayrollApiException;
use App\Http\Controllers\Controller;
use App\Models\ExpenseItem;
use App\Services\Payroll\PayrollApiService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\JsonResponse;

class PayrollCostComponentOptionsController extends Controller
{
    public function __construct(private readonly PayrollApiService $payrollApi) {}

    public function index(Request $request): JsonResponse
    {
        $validated = validator($request->query(), [
            'component' => ['required', 'string'],
            'filter' => ['nullable', 'string', Rule::in(['blocks'])],
            'estate_external_id' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])->after(function ($validator) use ($request): void {
            if ($request->query('filter') === 'blocks' && ! filled($request->query('estate_external_id'))) {
                $validator->errors()->add('estate_external_id', 'estate_external_id wajib diisi untuk filter block.');
            }
        })->validate();

        $component = (string) ($validated['component'] ?? '');
        $filter = $validated['filter'] ?? null;
        $estateExternalId = $validated['estate_external_id'] ?? null;
        $search = $validated['q'] ?? null;
        $limit = $validated['limit'] ?? null;

        if (! in_array($component, ExpenseItem::optionedPayrollComponents(), true)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNSUPPORTED_COMPONENT',
                    'message' => 'Component tidak mendukung pilihan payroll.',
                ],
            ], 422);
        }

        if ($filter === 'blocks' && ! ExpenseItem::supportsMaintenanceBlockSelectors($component)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNSUPPORTED_COMPONENT',
                    'message' => 'Component tidak mendukung pilihan block payroll.',
                ],
            ], 422);
        }

        try {
            $options = $this->payrollApi->fetchComponentOptions($component, $filter, $estateExternalId, $search, $limit);
        } catch (PayrollApiException $exception) {
            $status = $exception->httpStatus;
            if ($exception->errorCode === 'PAYROLL_VALIDATION_ERROR') {
                $status = 422;
            }

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ],
            ], $status);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'component' => $component,
                'options' => $options,
            ],
        ]);
    }
}
