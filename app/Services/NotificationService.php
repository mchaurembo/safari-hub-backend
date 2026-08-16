<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Resend\Laravel\Facades\Resend;
use Vonage\Client as VonageClient;
use Vonage\Client\Credentials\Basic as VonageBasic;
use Vonage\SMS\Message\SMS as VonageSMS;

/**
 * Notification channels (in priority order when phone is available):
 *   1. WhatsApp  – Meta Cloud API (conversation-based pricing, 1000 free/month)
 *   2. SMS       – Vonage (per-message cost, used as fallback if WhatsApp fails)
 *   3. Email     – Resend (always sent when email is available)
 *
 * Channel selection is controlled by NOTIFICATION_CHANNEL in .env:
 *   whatsapp_sms  → try WhatsApp first, fall back to SMS  (default)
 *   whatsapp      → WhatsApp only
 *   sms           → SMS only
 *   none          → skip phone notifications (email still sent)
 */
class NotificationService
{
    /* ─────────────────────────────────────────────────────────────────────
     |  PUBLIC CARGO EVENT METHODS
     ───────────────────────────────────────────────────────────────────── */

    /** Customer creates a request → notify driver */
    public function driverNewRequest(
        string  $driverName,
        ?string $driverEmail,
        ?string $driverPhone,
        string  $customerName,
        string  $pickupAddress,
        string  $destAddress,
        float   $distanceKm,
        string  $cargoDescription,
        ?float  $customerBudget,
        ?string $driverWhatsapp = null
    ): void {
        $budgetFmt  = $customerBudget ? 'TZS ' . number_format($customerBudget) : 'Not specified';
        $budgetHtml = $customerBudget
            ? '<strong>TZS ' . number_format($customerBudget) . '</strong>'
            : '<em>Not specified</em>';

        $rows = [
            ['Customer',     $customerName],
            ['Pickup',       $pickupAddress],
            ['Destination',  $destAddress],
            ['Distance',     "{$distanceKm} km"],
            ['Cargo',        $cargoDescription],
            ['Budget',       $budgetHtml],
        ];

        $html = $this->wrap(
            "Hi <strong>{$driverName}</strong>, you have a new cargo request waiting for your quote.",
            $this->table($rows),
            $this->callout('⏳ Log in to Safari Hub 360 (Trans Cargo) to send your quote', '#fa8c16', '#fff7e6'),
            '🚛 New Cargo Request'
        );

        $sms = "Safari Hub 360 (Trans Cargo): New cargo request from {$customerName}.\n"
             . "From: {$pickupAddress}\nTo: {$destAddress} ({$distanceKm}km).\n"
             . "Budget: {$budgetFmt}. Log in to quote.";

        $this->dispatch($driverName, $driverEmail, $driverPhone,
            "New Cargo Request from {$customerName}", $html, $sms,
            $driverWhatsapp ?? $driverPhone);
    }

    /** Driver sends a quote → notify customer */
    public function customerDriverQuoted(
        string  $customerName,
        ?string $customerEmail,
        ?string $customerPhone,
        string  $driverName,
        string  $pickupAddress,
        string  $destAddress,
        float   $distanceKm,
        float   $quotedPrice,
        ?string $customerWhatsapp = null
    ): void {
        $priceFmt = 'TZS ' . number_format($quotedPrice);

        $rows = [
            ['Driver',       $driverName],
            ['Pickup',       $pickupAddress],
            ['Destination',  $destAddress],
            ['Distance',     "{$distanceKm} km"],
            ['Quoted Price', "<strong style='color:#1677ff;font-size:18px;'>{$priceFmt}</strong>"],
        ];

        $html = $this->wrap(
            "Hi <strong>{$customerName}</strong>, your driver has sent a price quote for your cargo request.",
            $this->table($rows),
            $this->callout('✅ Log in to Safari Hub 360 (Trans Cargo) to accept or decline', '#52c41a', '#f6ffed'),
            '💬 Driver Quoted a Price'
        );

        $sms = "Safari Hub 360 (Trans Cargo): {$driverName} quoted {$priceFmt} for your cargo.\n"
             . "From: {$pickupAddress} → {$destAddress}.\n"
             . "Log in to accept or decline.";

        $this->dispatch($customerName, $customerEmail, $customerPhone,
            "Driver Quoted {$priceFmt} — Safari Hub 360", $html, $sms,
            $customerWhatsapp ?? $customerPhone);
    }

    /** Customer accepts quote → notify driver */
    public function driverQuoteAccepted(
        string  $driverName,
        ?string $driverEmail,
        ?string $driverPhone,
        string  $customerName,
        string  $pickupAddress,
        string  $destAddress,
        float   $quotedPrice,
        ?string $driverWhatsapp = null
    ): void {
        $priceFmt = 'TZS ' . number_format($quotedPrice);

        $rows = [
            ['Customer',     $customerName],
            ['Pickup',       $pickupAddress],
            ['Destination',  $destAddress],
            ['Agreed Price', "<strong style='color:#52c41a;font-size:18px;'>{$priceFmt}</strong>"],
        ];

        $html = $this->wrap(
            "Hi <strong>{$driverName}</strong>, the customer has <strong>accepted</strong> your quote. Proceed to pickup.",
            $this->table($rows),
            $this->callout('🚀 Head to the pickup location now', '#52c41a', '#f6ffed'),
            '✅ Quote Accepted'
        );

        $sms = "Trans-Cargo: {$customerName} accepted your quote of {$priceFmt}.\n"
             . "Pickup: {$pickupAddress}. Head there now.";

        $this->dispatch($driverName, $driverEmail, $driverPhone,
            "Quote Accepted — Trans-Cargo", $html, $sms,
            $driverWhatsapp ?? $driverPhone);
    }

    /** Customer declines quote → notify driver */
    public function driverQuoteDeclined(
        string  $driverName,
        ?string $driverEmail,
        ?string $driverPhone,
        string  $customerName,
        string  $pickupAddress,
        string  $destAddress,
        float   $quotedPrice,
        ?string $driverWhatsapp = null
    ): void {
        $priceFmt = 'TZS ' . number_format($quotedPrice);

        $rows = [
            ['Customer',    $customerName],
            ['Pickup',      $pickupAddress],
            ['Destination', $destAddress],
            ['Your Quote',  $priceFmt],
        ];

        $html = $this->wrap(
            "Hi <strong>{$driverName}</strong>, the customer has <strong>declined</strong> your quote of {$priceFmt}.",
            $this->table($rows),
            $this->callout('ℹ️ The request has been closed', '#8c8c8c', '#fafafa'),
            '❌ Quote Declined'
        );

        $sms = "Trans-Cargo: {$customerName} declined your quote of {$priceFmt} for the trip from {$pickupAddress}.";

        $this->dispatch($driverName, $driverEmail, $driverPhone,
            "Quote Declined — Trans-Cargo", $html, $sms,
            $driverWhatsapp ?? $driverPhone);
    }

    /** Driver starts trip → notify customer */
    public function customerTripStarted(
        string  $customerName,
        ?string $customerEmail,
        ?string $customerPhone,
        string  $driverName,
        string  $pickupAddress,
        string  $destAddress,
        ?string $customerWhatsapp = null
    ): void {
        $rows = [
            ['Driver',      $driverName],
            ['Pickup',      $pickupAddress],
            ['Destination', $destAddress],
            ['Status',      '<strong style="color:#1677ff;">In Progress</strong>'],
        ];

        $html = $this->wrap(
            "Hi <strong>{$customerName}</strong>, your cargo trip has started. The driver is on the way.",
            $this->table($rows),
            $this->callout('🚛 Your cargo is on the move', '#1677ff', '#e6f4ff'),
            '🚛 Trip Started'
        );

        $sms = "Trans-Cargo: Your cargo trip has started.\n"
             . "Driver: {$driverName}. From {$pickupAddress} to {$destAddress}.";

        $this->dispatch($customerName, $customerEmail, $customerPhone,
            "Your Cargo Trip Has Started — Trans-Cargo", $html, $sms,
            $customerWhatsapp ?? $customerPhone);
    }

    /** Driver marks delivered → notify customer */
    public function customerCargoDelivered(
        string  $customerName,
        ?string $customerEmail,
        ?string $customerPhone,
        string  $driverName,
        string  $destAddress,
        float   $quotedPrice,
        ?string $customerWhatsapp = null
    ): void {
        $priceFmt = 'TZS ' . number_format($quotedPrice);

        $rows = [
            ['Driver',      $driverName],
            ['Delivered To', $destAddress],
            ['Amount Due',  "<strong style='color:#52c41a;font-size:16px;'>{$priceFmt}</strong>"],
        ];

        $html = $this->wrap(
            "Hi <strong>{$customerName}</strong>, your cargo has been delivered. Please confirm receipt.",
            $this->table($rows),
            $this->callout('✅ Log in to confirm delivery and complete the trip', '#52c41a', '#f6ffed'),
            '📦 Cargo Delivered'
        );

        $sms = "Trans-Cargo: Your cargo has been delivered to {$destAddress} by {$driverName}.\n"
             . "Amount: {$priceFmt}. Log in to confirm.";

        $this->dispatch($customerName, $customerEmail, $customerPhone,
            "Cargo Delivered — Please Confirm", $html, $sms,
            $customerWhatsapp ?? $customerPhone);
    }

    /* ─────────────────────────────────────────────────────────────────────
     |  GARAGE SERVICE EVENTS
     ───────────────────────────────────────────────────────────────────── */

    /** Technician starts garage service → notify customer */
    public function customerGarageServiceStarted(
        string  $customerName,
        ?string $customerEmail,
        ?string $customerPhone,
        string  $garageName,
        string  $serviceName,
        string  $technicianName,
        ?string $vehicleReg = null,
        ?string $customerWhatsapp = null
    ): void {
        $rows = [
            ['Garage',      $garageName],
            ['Service',     $serviceName],
            ['Technician',  $technicianName],
            ['Vehicle',     $vehicleReg ?: '—'],
            ['Status',      '<strong style="color:#1677ff;">In Progress</strong>'],
        ];

        $html = $this->wrap(
            "Hi <strong>{$customerName}</strong>, work on your vehicle has started at <strong>{$garageName}</strong>.",
            $this->table($rows),
            $this->callout('🔧 Your service is now in progress', '#1677ff', '#e6f4ff'),
            '🔧 Service Started'
        );

        $vehicleLine = $vehicleReg ? " Vehicle: {$vehicleReg}." : '';
        $sms = "Safari Hub 360 (Garage): Your {$serviceName} has started at {$garageName}."
             . " Technician: {$technicianName}.{$vehicleLine}";

        $this->dispatchAllChannels($customerName, $customerEmail, $customerPhone,
            "Service Started — {$garageName}", $html, $sms,
            $customerWhatsapp ?? $customerPhone,
            [
                $customerName,
                $garageName,
                $serviceName,
                'STARTED',
                trim('Technician: '.$technicianName.($vehicleReg ? '. Vehicle: '.$vehicleReg : '').'. Work is in progress.'),
            ]);
    }

    /** Technician completes garage service → notify customer */
    public function customerGarageServiceCompleted(
        string  $customerName,
        ?string $customerEmail,
        ?string $customerPhone,
        string  $garageName,
        string  $serviceName,
        string  $technicianName,
        ?string $vehicleReg = null,
        ?float  $amount = null,
        ?string $customerWhatsapp = null
    ): void {
        $amountFmt = $amount !== null ? 'TZS ' . number_format($amount) : null;
        $amountHtml = $amountFmt
            ? "<strong style='color:#52c41a;font-size:16px;'>{$amountFmt}</strong>"
            : '—';

        $rows = [
            ['Garage',      $garageName],
            ['Service',     $serviceName],
            ['Technician',  $technicianName],
            ['Vehicle',     $vehicleReg ?: '—'],
            ['Amount',      $amountHtml],
            ['Status',      '<strong style="color:#52c41a;">Completed</strong>'],
        ];

        $html = $this->wrap(
            "Hi <strong>{$customerName}</strong>, your <strong>{$serviceName}</strong> at <strong>{$garageName}</strong> is complete.",
            $this->table($rows),
            $this->callout('✅ Your vehicle is ready for collection', '#52c41a', '#f6ffed'),
            '✅ Service Completed'
        );

        $vehicleLine = $vehicleReg ? " Vehicle: {$vehicleReg}." : '';
        $amountLine = $amountFmt ? " Amount: {$amountFmt}." : '';
        $sms = "Safari Hub 360 (Garage): Your {$serviceName} at {$garageName} is complete."
             . " Technician: {$technicianName}.{$vehicleLine}{$amountLine}";

        $detail = trim('Technician: '.$technicianName
            .($vehicleReg ? '. Vehicle: '.$vehicleReg : '')
            .($amountFmt ? '. Amount: '.$amountFmt : '')
            .'. Your vehicle is ready for collection.');

        $this->dispatchAllChannels($customerName, $customerEmail, $customerPhone,
            "Service Completed — {$garageName}", $html, $sms,
            $customerWhatsapp ?? $customerPhone,
            [
                $customerName,
                $garageName,
                $serviceName,
                'COMPLETED',
                $detail,
            ]);
    }

    /* ─────────────────────────────────────────────────────────────────────
     |  CORE DISPATCH
     ───────────────────────────────────────────────────────────────────── */

    /**
     * Garage notifications: send email + SMS + WhatsApp when each channel has a destination.
     * Unlike dispatch(), WhatsApp success does not skip SMS.
     */
    private function dispatchAllChannels(
        string  $name,
        ?string $email,
        ?string $phone,
        string  $subject,
        string  $html,
        string  $smsText,
        ?string $whatsappNumber = null,
        ?array  $whatsappBodyParams = null
    ): void {
        $this->clearProxyEnv();

        if ($email) {
            $this->sendEmail($name, $email, $subject, $html);
        }

        if ($phone) {
            $this->sendSms($phone, $smsText);
        }

        $waPhone = $whatsappNumber ?: $phone;
        if ($waPhone) {
            // Prefer professional safari_hub_garage_update (when Meta status=APPROVED).
            $sent = $this->sendWhatsAppTemplate($waPhone, $smsText, $whatsappBodyParams);

            // While custom template is PENDING, use approved sample with service details (not hello_world).
            if (! $sent) {
                $prevName = config('services.whatsapp.template_name');
                $prevParams = config('services.whatsapp.template_body_params');
                $fallbackParams = null;
                if (is_array($whatsappBodyParams) && count($whatsappBodyParams) >= 5) {
                    $fallbackParams = [
                        $whatsappBodyParams[0],
                        $whatsappBodyParams[2].' — '.$whatsappBodyParams[3],
                        $whatsappBodyParams[1].'. '.$whatsappBodyParams[4],
                    ];
                }
                config([
                    'services.whatsapp.template_name' => 'jaspers_market_order_confirmation_v1',
                    'services.whatsapp.template_body_params' => 3,
                ]);
                $sent = $this->sendWhatsAppTemplate($waPhone, $smsText, $fallbackParams);
                config([
                    'services.whatsapp.template_name' => $prevName,
                    'services.whatsapp.template_body_params' => $prevParams,
                ]);
            }

            if (! $sent) {
                Log::warning('WhatsApp garage notify failed (primary + fallback templates)');
            }
        }
    }

    private function dispatch(
        string  $name,
        ?string $email,
        ?string $phone,
        string  $subject,
        string  $html,
        string  $smsText,
        ?string $whatsappNumber = null   // use whatsapp_number if set, else falls back to $phone
    ): void {
        $this->clearProxyEnv();

        // Always send email when available
        if ($email) {
            $this->sendEmail($name, $email, $subject, $html);
        }

        // Resolve the best phone number for WhatsApp (dedicated WA number takes priority)
        $waPhone = $whatsappNumber ?? $phone;

        // Phone channel: controlled by NOTIFICATION_CHANNEL env
        if ($phone || $waPhone) {
            $channel = config('services.notification_channel', 'whatsapp_sms');

            if ($channel === 'whatsapp') {
                if ($waPhone) $this->sendWhatsApp($waPhone, $smsText);

            } elseif ($channel === 'sms') {
                if ($phone) $this->sendSms($phone, $smsText);

            } elseif ($channel === 'whatsapp_sms') {
                // Try WhatsApp first; fall back to SMS if it fails or is not configured
                $sent = $waPhone ? $this->sendWhatsApp($waPhone, $smsText) : false;
                if (!$sent && $phone) {
                    $this->sendSms($phone, $smsText);
                }
            }
            // 'none' → skip phone notifications
        }
    }

    /* ─────────────────────────────────────────────────────────────────────
     |  EMAIL via Resend
     ───────────────────────────────────────────────────────────────────── */

    private function sendEmail(string $name, string $email, string $subject, string $html): void
    {
        $apiKey = config('resend.api_key');

        if (!$apiKey || str_contains($apiKey, 'YOUR_')) {
            Log::warning("Resend not configured — skipping email to {$email}");
            return;
        }

        $to = app()->environment('local') ? 'mchaurembo@gmail.com' : $email;

        try {
            Resend::emails()->send([
                'from'    => config('mail.from.name', 'Trans-Cargo') . ' <' . config('mail.from.address', 'onboarding@resend.dev') . '>',
                'to'      => [$to],
                'subject' => $subject,
                'html'    => $html,
            ]);
            Log::info("Email sent to {$to}: {$subject}");
        } catch (\Exception $e) {
            Log::error("Email failed to {$to}: " . $e->getMessage());
        }
    }

    /* ─────────────────────────────────────────────────────────────────────
     |  WHATSAPP via Meta Cloud API (free tier: 1,000 conversations/month)
     |
     |  Setup steps:
     |   1. Go to https://developers.facebook.com → Create App → Business
     |   2. Add "WhatsApp" product to the app
     |   3. Under WhatsApp > API Setup, copy:
     |        - Phone Number ID  → WHATSAPP_PHONE_NUMBER_ID
     |        - Temporary token  → WHATSAPP_TOKEN  (or generate permanent one)
     |   4. Add your test phone number in the "To" field on the API Setup page
     |   5. Set NOTIFICATION_CHANNEL=whatsapp_sms in .env
     ───────────────────────────────────────────────────────────────────── */

    /**
     * Send a WhatsApp text message via Meta Cloud API.
     * Returns true on success, false on failure/not-configured.
     * Note: free-form text only delivers inside the 24h customer-care window.
     */
    private function sendWhatsApp(string $phone, string $text): bool
    {
        $token   = config('services.whatsapp.token');
        $phoneId = config('services.whatsapp.phone_number_id');

        if (!$token || !$phoneId || str_contains($token, 'YOUR_')) {
            Log::info("WhatsApp not configured — will fall back to SMS");
            return false;
        }

        $normalized = $this->normalizePhone($phone);
        // Meta Graph API expects digits only (no leading +)
        $to = ltrim($normalized, '+');
        $url        = "https://graph.facebook.com/v21.0/{$phoneId}/messages";

        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['preview_url' => false, 'body' => mb_substr($text, 0, 4096)],
        ]);

        return $this->postWhatsApp($url, $token, $payload, $to, 'text');
    }

    /**
     * Send an approved WhatsApp template (required to start / re-engage conversations).
     * Default Meta test template: hello_world (no body params).
     */
    private function sendWhatsAppTemplate(string $phone, string $fallbackText = '', ?array $bodyParams = null): bool
    {
        $token   = config('services.whatsapp.token');
        $phoneId = config('services.whatsapp.phone_number_id');
        $name    = config('services.whatsapp.template_name', 'hello_world');
        $lang    = config('services.whatsapp.template_lang', 'en_US');
        $paramCount = (int) config('services.whatsapp.template_body_params', 0);

        if (!$token || !$phoneId || str_contains($token, 'YOUR_') || !$name) {
            Log::info('WhatsApp template skipped — not configured');
            return false;
        }

        $to = ltrim($this->normalizePhone($phone), '+');
        $url = "https://graph.facebook.com/v21.0/{$phoneId}/messages";

        $template = [
            'name' => $name,
            'language' => ['code' => $lang],
        ];

        $params = [];
        if (is_array($bodyParams) && count($bodyParams) > 0) {
            foreach ($bodyParams as $value) {
                $text = trim(preg_replace('/\s+/', ' ', (string) $value) ?? (string) $value);
                $params[] = ['type' => 'text', 'text' => mb_substr($text !== '' ? $text : '-', 0, 1024)];
            }
        } elseif ($paramCount > 0 && $fallbackText !== '') {
            $primary = trim(preg_replace('/\s+/', ' ', $fallbackText) ?? $fallbackText);
            $primary = mb_substr($primary, 0, 1024);
            for ($i = 0; $i < $paramCount; $i++) {
                $params[] = [
                    'type' => 'text',
                    'text' => $i === 0 ? $primary : '-',
                ];
            }
        }

        if ($params !== []) {
            $template['components'] = [
                ['type' => 'body', 'parameters' => $params],
            ];
        }

        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ]);

        return $this->postWhatsApp($url, $token, $payload, $to, 'template:'.$name);
    }

    private function postWhatsApp(string $url, string $token, string $payload, string $to, string $kind): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer {$token}",
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            Log::error("WhatsApp cURL error ({$kind}) to {$to}: {$curlErr}");
            return false;
        }

        $decoded = json_decode($response, true);

        if ($httpCode === 200 && isset($decoded['messages'][0]['id'])) {
            Log::info("WhatsApp {$kind} sent to {$to} (msg_id: {$decoded['messages'][0]['id']})");
            return true;
        }

        $errMsg = $decoded['error']['message'] ?? $response;
        $errCode = $decoded['error']['code'] ?? null;
        Log::warning("WhatsApp {$kind} failed to {$to} (HTTP {$httpCode}" . ($errCode ? ", code {$errCode}" : '') . "): {$errMsg}");
        return false;
    }

    /* ─────────────────────────────────────────────────────────────────────
     |  SMS via Vonage (fallback)
     ───────────────────────────────────────────────────────────────────── */

    private function sendSms(string $phone, string $text): void
    {
        $key    = config('services.vonage.key');
        $secret = config('services.vonage.secret');
        $from   = config('services.vonage.from', 'TransCargo');

        if (!$key || !$secret) {
            Log::warning("Vonage not configured — skipping SMS to {$phone}");
            return;
        }

        $normalized = $this->normalizePhone($phone);

        try {
            $vonage = new VonageClient(new VonageBasic($key, $secret));
            $vonage->sms()->send(new VonageSMS($normalized, $from, $text));
            Log::info("SMS sent to {$normalized}");
        } catch (\Exception $e) {
            Log::error("SMS failed to {$normalized}: " . $e->getMessage());
        }
    }

    /* ─────────────────────────────────────────────────────────────────────
     |  HELPERS
     ───────────────────────────────────────────────────────────────────── */

    private function normalizePhone(string $phone): string
    {
        if (preg_match('/^0[67]\d{8}$/', $phone)) {
            return '+255' . substr($phone, 1);
        }
        if (preg_match('/^255[67]\d{8}$/', $phone)) {
            return '+' . $phone;
        }
        if (!str_starts_with($phone, '+')) {
            return '+' . $phone;
        }
        return $phone;
    }

    private function clearProxyEnv(): void
    {
        foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'no_proxy'] as $v) {
            putenv($v);
            unset($_ENV[$v], $_SERVER[$v]);
        }
    }

    /** Build an HTML table from [label, value] rows */
    private function table(array $rows): string
    {
        $html = "<table style='width:100%;border-collapse:collapse;margin:20px 0;font-size:14px;'>";
        foreach ($rows as $i => [$label, $value]) {
            $bg = $i % 2 === 0 ? '#f0f7ff' : '#ffffff';
            $html .= "<tr style='background:{$bg};'>
                <td style='padding:10px 14px;font-weight:600;color:#333;width:38%;border-bottom:1px solid #e8f0fe;'>{$label}</td>
                <td style='padding:10px 14px;color:#555;border-bottom:1px solid #e8f0fe;'>{$value}</td>
              </tr>";
        }
        $html .= "</table>";
        return $html;
    }

    /** Highlighted callout box */
    private function callout(string $text, string $borderColor, string $bgColor): string
    {
        return "<div style='text-align:center;margin:24px 0;'>
          <span style='display:inline-block;background:{$bgColor};border:2px solid {$borderColor};
            border-radius:8px;padding:12px 28px;font-size:15px;font-weight:700;color:{$borderColor};'>
            {$text}
          </span>
        </div>";
    }

    /** Wrap content in the branded email shell */
    private function wrap(string $intro, string $table, string $callout, string $header): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"></head>
        <body style="font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px;">
          <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;
                      overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
            <div style="background:#1677ff;padding:24px 32px;">
              <h1 style="color:#fff;margin:0;font-size:20px;">{$header}</h1>
            </div>
            <div style="padding:32px;">
              <p style="color:#333;font-size:15px;margin-top:0;">{$intro}</p>
              {$table}
              {$callout}
              <p style="color:#aaa;font-size:12px;margin-bottom:0;">
                Do not reply to this email. Log in to Trans-Cargo to take action.
              </p>
            </div>
            <div style="background:#f9f9f9;padding:14px 32px;text-align:center;">
              <p style="color:#bbb;font-size:11px;margin:0;">© Trans-Cargo · Automated Notification</p>
            </div>
          </div>
        </body>
        </html>
        HTML;
    }
}
