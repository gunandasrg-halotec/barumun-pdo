<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Services\Settings\SystemSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemSettingController extends Controller
{
    public function __construct(private readonly SystemSettingService $service) {}

    /** GET /settings */
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $settings = $this->service->listSettings($request->user()->company_id);

        return response()->json(['success' => true, 'data' => $settings]);
    }

    /** PUT /settings — body: {'wa_gateway_url': '...', 'reminder_day_of_month': '5', ...} */
    public function update(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            '*.value' => ['nullable'], // validasi per key dilakukan di service
        ]);

        // Terima flat key-value map
        $this->service->updateSettings($request->user()->company_id, $request->all(), $request->user());

        return response()->json(['success' => true, 'message' => 'Pengaturan berhasil disimpan.']);
    }

    /** POST /settings/wa-test */
    public function testWhatsApp(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $result = $this->service->testWhatsApp($request->user()->company_id, $request->user());

        $status = $result['success'] ? 200 : 502;

        return response()->json(['success' => $result['success'], 'data' => $result], $status);
    }

    /** GET /notification-templates */
    public function templates(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $templates = $this->service->listTemplates($request->user()->company_id);

        return response()->json(['success' => true, 'data' => $templates]);
    }

    /** PUT /notification-templates/{template} */
    public function updateTemplate(Request $request, NotificationTemplate $template): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'template_body' => ['required', 'string'],
            'is_active'     => ['sometimes', 'boolean'],
        ]);

        $updated = $this->service->updateTemplate($template, $data, $request->user());

        return response()->json(['success' => true, 'data' => $updated, 'message' => 'Template notifikasi berhasil diperbarui.']);
    }

    private function requireAdmin(Request $request): void
    {
        if (! $request->user()->hasRole(Role::ADMIN)) {
            abort(response()->json([
                'success' => false,
                'error'   => ['code' => 'FORBIDDEN', 'message' => 'Hanya ADMIN yang dapat mengakses pengaturan sistem.'],
            ], 403));
        }
    }
}
