<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform-wide manual payment details (EcoCash + WhatsApp) that the POS
 * app shows to merchants whose subscription has expired.
 */
class PaymentSettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Settings/Payments', [
            'payment' => Setting::paymentInfo(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ecocash_number' => 'nullable|string|max:30',
            'ecocash_name' => 'nullable|string|max:100',
            'whatsapp_number' => 'nullable|string|max:30',
            'instructions' => 'nullable|string|max:500',
        ]);

        Setting::set(Setting::PAYMENT_ECOCASH_NUMBER, $data['ecocash_number'] ?? null);
        Setting::set(Setting::PAYMENT_ECOCASH_NAME, $data['ecocash_name'] ?? null);
        Setting::set(Setting::PAYMENT_WHATSAPP_NUMBER, $data['whatsapp_number'] ?? null);
        Setting::set(Setting::PAYMENT_INSTRUCTIONS, $data['instructions'] ?? null);

        return redirect()
            ->route('settings.payments.edit')
            ->with('success', 'Payment settings saved. Devices will pick them up on their next subscription check.');
    }
}
