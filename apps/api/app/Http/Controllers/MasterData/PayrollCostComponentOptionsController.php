<?php

namespace App\Http\Controllers\MasterData;

use App\Exceptions\PayrollApiException;
use App\Http\Controllers\Controller;
use App\Models\ExpenseItem;
use App\Services\Payroll\PayrollApiService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class PayrollCostComponentOptionsController extends Controller
{
    public function __construct(private readonly PayrollApiService $payrollApi) {}

    public function index(Request $request): JsonResponse
    {
        $component = (string) $request->query('component', '');

        if (! in_array($component, ExpenseItem::optionedPayrollComponents(), true)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNSUPPORTED_COMPONENT',
                    'message' => 'Component tidak mendukung pilihan payroll.',
                ],
            ], 422);
        }

        try {
            $options = $this->payrollApi->fetchComponentOptions($component);
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
