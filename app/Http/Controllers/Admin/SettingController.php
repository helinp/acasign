<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function edit(): View
    {
        return view('admin.settings', ['entityName' => Setting::value('entity_name'), 'logoPath' => Setting::logoPath()]);
    }

    public function update(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'entity_name' => ['nullable', 'string', 'max:120'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);
        Setting::put('entity_name', filled($data['entity_name'] ?? null) ? trim($data['entity_name']) : null);

        $current = Setting::logoPath();
        if ($request->hasFile('logo') || $request->boolean('remove_logo')) {
            if ($current) {
                Storage::disk('local')->delete($current);
            }
            $path = $request->hasFile('logo')
                ? $request->file('logo')->storeAs('branding', 'logo-'.now()->timestamp.'.'.$request->file('logo')->extension(), 'local')
                : null;
            Setting::put('logo_path', $path);
        }
        $audit->record('admin.settings.updated', null, $request->user(), ['entity_name' => Setting::value('entity_name'), 'logo' => (bool) Setting::logoPath()], $request);

        return back()->with('success', 'Réglages enregistrés.');
    }
}
