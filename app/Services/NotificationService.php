<?php

namespace App\Services;

use App\Support\MailRecipient;
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
    /* CHAPA brand palette (matches web/mobile) */
    private const BRAND_PRIMARY = '#7D1B28';

    private const BRAND_PRIMARY_DARK = '#5C1420';

    private const BRAND_GOLD = '#D4A017';

    private const BRAND_CREAM = '#FBF6E8';

    private const BRAND_PAGE = '#F7F5F4';

    private const BRAND_TEXT = '#1A1214';

    private const BRAND_MUTED = '#7A6E71';

    private const BRAND_BORDER = '#E5DCDE';

    private const BRAND_ROW_ALT = '#F3F0EF';

    private const BRAND_SUCCESS = '#1A7A4C';

    private const BRAND_SUCCESS_BG = '#E8F5EE';

    private const BRAND_WARNING = '#C9971A';

    private const BRAND_WARNING_BG = '#FBF6E8';

    private const BRAND_INFO = '#2C5F8A';

    private const BRAND_INFO_BG = '#EAF2F8';

    private const BRAND_MUTED_BG = '#F3F0EF';

    /* ─────────────────────────────────────────────────────────────────────
     |  PUBLIC CARGO EVENT METHODS
     ───────────────────────────────────────────────────────────────────── */

    /** Customer creates a request → notify driver */
    public function driverNewRequest(
        string $driverName,
        ?string $driverEmail,
        ?string $driverPhone,
        string $customerName,
        string $pickupAddress,
        string $destAddress,
        float $distanceKm,
        string $cargoDescription,
        ?float $customerBudget,
        ?string $driverWhatsapp = null
    ): void {
        $budgetFmt = $customerBudget ? 'TZS '.number_format($customerBudget) : 'Not specified';
        $budgetHtml = $customerBudget
            ? '<strong>TZS '.number_format($customerBudget).'</strong>'
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
            $this->callout('⏳ Log in to CHAPA to send your quote', self::BRAND_WARNING, self::BRAND_WARNING_BG),
            '🚛 New Cargo Request'
        );

        $sms = "CHAPA: New cargo request from {$customerName}.\n"
             ."From: {$pickupAddress}\nTo: {$destAddress} ({$distanceKm}km).\n"
             ."Budget: {$budgetFmt}. Log in to quote.";

        $this->dispatch($driverName, $driverEmail, $driverPhone,
            "New Cargo Request from {$customerName}", $html, $sms,
            $driverWhatsapp ?? $driverPhone);
    }

    /** Driver sends a quote → notify customer */
    public function customerDriverQuoted(
        string $customerName,
        ?string $customerEmail,
        ?string $customerPhone,
        string $driverName,
        string $pickupAddress,
        string $destAddress,
        float $distanceKm,
        float $quotedPrice,
        ?string $customerWhatsapp = null
    ): void {
        $priceFmt = 'TZS '.number_format($quotedPrice);
        $accent = self::BRAND_PRIMARY;

        $rows = [
            ['Driver',       $driverName],
            ['Pickup',       $pickupAddress],
            ['Destination',  $destAddress],
            ['Distance',     "{$distanceKm} km"],
            ['Quoted Price', "<strong style='color:{$accent};font-size:18px;'>{$priceFmt}</strong>"],
        ];

        $html = $this->wrap(
            "Hi <strong>{$customerName}</strong>, your driver has sent a price quote for your cargo request.",
            $this->table($rows),
            $this->callout('✅ Log in to CHAPA to accept or decline', self::BRAND_SUCCESS, self::BRAND_SUCCESS_BG),
            '💬 Driver Quoted a Price'
        );

        $sms = "CHAPA: {$driverName} quoted {$priceFmt} for your cargo.\n"
             ."From: {$pickupAddress} → {$destAddress}.\n"
             .'Log in to accept or decline.';

        $this->dispatch($customerName, $customerEmail, $customerPhone,
            "Driver Quoted {$priceFmt} — CHAPA", $html, $sms,
            $customerWhatsapp ?? $customerPhone);
    }

    /** Customer accepts quote → notify driver */
    public function driverQuoteAccepted(
        string $driverName,
        ?string $driverEmail,
        ?string $driverPhone,
        string $customerName,
        string $pickupAddress,
        string $destAddress,
        float $quotedPrice,
        ?string $driverWhatsapp = null
    ): void {
        $priceFmt = 'TZS '.number_format($quotedPrice);
        $accent = self::BRAND_SUCCESS;

        $rows = [
            ['Customer',     $customerName],
            ['Pickup',       $pickupAddress],
            ['Destination',  $destAddress],
            ['Agreed Price', "<strong style='color:{$accent};font-size:18px;'>{$priceFmt}</strong>"],
        ];

        $html = $this->wrap(
            "Hi <strong>{$driverName}</strong>, the customer has <strong>accepted</strong> your quote. Proceed to pickup.",
            $this->table($rows),
            $this->callout('🚀 Head to the pickup location now', self::BRAND_SUCCESS, self::BRAND_SUCCESS_BG),
            '✅ Quote Accepted'
        );

        $sms = "CHAPA: {$customerName} accepted your quote of {$priceFmt}.\n"
             ."Pickup: {$pickupAddress}. Head there now.";

        $this->dispatch($driverName, $driverEmail, $driverPhone,
            'Quote Accepted — CHAPA', $html, $sms,
            $driverWhatsapp ?? $driverPhone);
    }

    /** Customer declines quote → notify driver */
    public function driverQuoteDeclined(
        string $driverName,
        ?string $driverEmail,
        ?string $driverPhone,
        string $customerName,
        string $pickupAddress,
        string $destAddress,
        float $quotedPrice,
        ?string $driverWhatsapp = null
    ): void {
        $priceFmt = 'TZS '.number_format($quotedPrice);

        $rows = [
            ['Customer',    $customerName],
            ['Pickup',      $pickupAddress],
            ['Destination', $destAddress],
            ['Your Quote',  $priceFmt],
        ];

        $html = $this->wrap(
            "Hi <strong>{$driverName}</strong>, the customer has <strong>declined</strong> your quote of {$priceFmt}.",
            $this->table($rows),
            $this->callout('ℹ️ The request has been closed', self::BRAND_MUTED, self::BRAND_MUTED_BG),
            '❌ Quote Declined'
        );

        $sms = "CHAPA: {$customerName} declined your quote of {$priceFmt} for the trip from {$pickupAddress}.";

        $this->dispatch($driverName, $driverEmail, $driverPhone,
            'Quote Declined — CHAPA', $html, $sms,
            $driverWhatsapp ?? $driverPhone);
    }

    /** Driver starts trip → notify customer */
    public function customerTripStarted(
        string $customerName,
        ?string $customerEmail,
        ?string $customerPhone,
        string $driverName,
        string $pickupAddress,
        string $destAddress,
        ?string $customerWhatsapp = null
    ): void {
        $accent = self::BRAND_INFO;

        $rows = [
            ['Driver',      $driverName],
            ['Pickup',      $pickupAddress],
            ['Destination', $destAddress],
            ['Status',      "<strong style=\"color:{$accent};\">In Progress</strong>"],
        ];

        $html = $this->wrap(
            "Hi <strong>{$customerName}</strong>, your cargo trip has started. The driver is on the way.",
            $this->table($rows),
            $this->callout('🚛 Your cargo is on the move', self::BRAND_INFO, self::BRAND_INFO_BG),
            '🚛 Trip Started'
        );

        $sms = "CHAPA: Your cargo trip has started.\n"
             ."Driver: {$driverName}. From {$pickupAddress} to {$destAddress}.";

        $this->dispatch($customerName, $customerEmail, $customerPhone,
            'Your Cargo Trip Has Started — CHAPA', $html, $sms,
            $customerWhatsapp ?? $customerPhone);
    }

    /** Driver marks delivered → notify customer */
    public function customerCargoDelivered(
        string $customerName,
        ?string $customerEmail,
        ?string $customerPhone,
        string $driverName,
        string $destAddress,
        float $quotedPrice,
        ?string $customerWhatsapp = null
    ): void {
        $priceFmt = 'TZS '.number_format($quotedPrice);
        $accent = self::BRAND_SUCCESS;

        $rows = [
            ['Driver',      $driverName],
            ['Delivered To', $destAddress],
            ['Amount Due',  "<strong style='color:{$accent};font-size:16px;'>{$priceFmt}</strong>"],
        ];

        $html = $this->wrap(
            "Hi <strong>{$customerName}</strong>, your cargo has been delivered. Please confirm receipt.",
            $this->table($rows),
            $this->callout('✅ Log in to confirm delivery and complete the trip', self::BRAND_SUCCESS, self::BRAND_SUCCESS_BG),
            '📦 Cargo Delivered'
        );

        $sms = "CHAPA: Your cargo has been delivered to {$destAddress} by {$driverName}.\n"
             ."Amount: {$priceFmt}. Log in to confirm.";

        $this->dispatch($customerName, $customerEmail, $customerPhone,
            'Cargo Delivered — Please Confirm', $html, $sms,
            $customerWhatsapp ?? $customerPhone);
    }

    /* ─────────────────────────────────────────────────────────────────────
     |  GARAGE SERVICE EVENTS
     ───────────────────────────────────────────────────────────────────── */

    /** Technician starts garage service → notify customer */
    public function customerGarageServiceStarted(
        string $customerName,
        ?string $customerEmail,
        ?string $customerPhone,
        string $garageName,
        string $serviceName,
        string $technicianName,
        ?string $vehicleReg = null,
        ?string $customerWhatsapp = null
    ): void {
        $accent = self::BRAND_INFO;

        $rows = [
            ['Garage',      $garageName],
            ['Service',     $serviceName],
            ['Technician',  $technicianName],
            ['Vehicle',     $vehicleReg ?: '—'],
            ['Status',      "<strong style=\"color:{$accent};\">In Progress</strong>"],
        ];

        $html = $this->wrap(
            "Hi <strong>{$customerName}</strong>, work on your vehicle has started at <strong>{$garageName}</strong>.",
            $this->table($rows),
            $this->callout('🔧 Your service is now in progress', self::BRAND_PRIMARY, self::BRAND_CREAM),
            '🔧 Service Started'
        );

        $vehicleLine = $vehicleReg ? " Vehicle: {$vehicleReg}." : '';
        $sms = "CHAPA (Garage): Your {$serviceName} has started at {$garageName}."
             ." Technician: {$technicianName}.{$vehicleLine}";

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
        string $customerName,
        ?string $customerEmail,
        ?string $customerPhone,
        string $garageName,
        string $serviceName,
        string $technicianName,
        ?string $vehicleReg = null,
        ?float $amount = null,
        ?string $customerWhatsapp = null
    ): void {
        $amountFmt = $amount !== null ? 'TZS '.number_format($amount) : null;
        $success = self::BRAND_SUCCESS;
        $amountHtml = $amountFmt
            ? "<strong style='color:{$success};font-size:16px;'>{$amountFmt}</strong>"
            : '—';

        $rows = [
            ['Garage',      $garageName],
            ['Service',     $serviceName],
            ['Technician',  $technicianName],
            ['Vehicle',     $vehicleReg ?: '—'],
            ['Amount',      $amountHtml],
            ['Status',      "<strong style=\"color:{$success};\">Completed</strong>"],
        ];

        $html = $this->wrap(
            "Hi <strong>{$customerName}</strong>, your <strong>{$serviceName}</strong> at <strong>{$garageName}</strong> is complete.",
            $this->table($rows),
            $this->callout('✅ Your vehicle is ready for collection', self::BRAND_SUCCESS, self::BRAND_SUCCESS_BG),
            '✅ Service Completed'
        );

        $vehicleLine = $vehicleReg ? " Vehicle: {$vehicleReg}." : '';
        $amountLine = $amountFmt ? " Amount: {$amountFmt}." : '';
        $sms = "CHAPA (Garage): Your {$serviceName} at {$garageName} is complete."
             ." Technician: {$technicianName}.{$vehicleLine}{$amountLine}";

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

    /** Payment lifecycle notifications (customer). */
    public function paymentStatusChanged(
        string $customerName,
        ?string $customerEmail,
        ?string $customerPhone,
        string $event,
        string $paymentReference,
        string $amountFormatted,
        string $method,
        string $status,
        ?string $bookingReference = null,
        ?string $customerWhatsapp = null
    ): void {
        $titles = [
            'initiated' => 'Payment Initiated',
            'pending' => 'Payment Pending',
            'successful' => 'Payment Successful',
            'failed' => 'Payment Failed',
            'expired' => 'Payment Expired',
            'refund_requested' => 'Refund Requested',
            'refund_successful' => 'Refund Successful',
        ];
        $title = $titles[$event] ?? 'Payment Update';
        $success = in_array($event, ['successful', 'refund_successful'], true);
        $statusColor = $success
            ? self::BRAND_SUCCESS
            : ($event === 'failed' || $event === 'expired' ? self::BRAND_WARNING : self::BRAND_INFO);

        $rows = [
            ['Reference', $paymentReference],
            ['Amount', "<strong>{$amountFormatted}</strong>"],
            ['Method', $method],
            ['Status', "<strong style=\"color:{$statusColor};\">{$status}</strong>"],
        ];
        if ($bookingReference) {
            $rows[] = ['Booking', $bookingReference];
        }

        $html = $this->wrap(
            "Hi <strong>{$customerName}</strong>, here is your CHAPA payment update.",
            $this->table($rows),
            $this->callout(
                $success ? '✓ Keep this reference for your records' : 'Open CHAPA to retry or view status',
                $success ? self::BRAND_SUCCESS : self::BRAND_INFO,
                $success ? self::BRAND_SUCCESS_BG : self::BRAND_INFO_BG
            ),
            "💳 {$title}"
        );

        $sms = "CHAPA: {$title}. Ref {$paymentReference}. {$amountFormatted} via {$method}. Status: {$status}."
            .($bookingReference ? " Booking: {$bookingReference}." : '');

        $this->dispatch(
            $customerName,
            $customerEmail,
            $customerPhone,
            "{$title} — {$paymentReference}",
            $html,
            $sms,
            $customerWhatsapp
        );
    }

    /* ─────────────────────────────────────────────────────────────────────
     |  CORE DISPATCH
     ───────────────────────────────────────────────────────────────────── */

    /**
     * Garage notifications: send email + SMS + WhatsApp when each channel has a destination.
     * Unlike dispatch(), WhatsApp success does not skip SMS.
     */
    private function dispatchAllChannels(
        string $name,
        ?string $email,
        ?string $phone,
        string $subject,
        string $html,
        string $smsText,
        ?string $whatsappNumber = null,
        ?array $whatsappBodyParams = null
    ): void {
        $this->clearProxyEnv();

        if ($email) {
            $this->sendEmail($name, $email, $subject, $html);
        }

        if ($phone) {
            $this->sendSms($phone, $smsText);
        }

        $waPhone = $whatsappNumber ?: $phone;
        if (! $waPhone) {
            Log::warning('Garage WhatsApp skipped — customer has no phone/whatsapp_number');

            return;
        }

        // Map 5 garage fields into jaspers {{1}} {{2}} {{3}} when that template is active
        $templateParams = $whatsappBodyParams;
        if (is_array($whatsappBodyParams) && count($whatsappBodyParams) >= 5
            && config('services.whatsapp.template_name') === 'jaspers_market_order_confirmation_v1') {
            $templateParams = [
                $whatsappBodyParams[0],
                $whatsappBodyParams[2].' — '.$whatsappBodyParams[3],
                $whatsappBodyParams[1].'. '.$whatsappBodyParams[4],
            ];
        }

        $sent = $this->sendWhatsAppTemplate($waPhone, $smsText, $templateParams);

        // Fallback chain when primary template fails (wrong name, pending approval, etc.)
        if (! $sent) {
            $primary = (string) config('services.whatsapp.template_name');
            $prevParams = config('services.whatsapp.template_body_params');

            if ($primary !== 'jaspers_market_order_confirmation_v1') {
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
            }

            if (! $sent && $primary !== 'hello_world') {
                config([
                    'services.whatsapp.template_name' => 'hello_world',
                    'services.whatsapp.template_body_params' => 0,
                ]);
                $sent = $this->sendWhatsAppTemplate($waPhone, $smsText, null);
            }

            config([
                'services.whatsapp.template_name' => $primary,
                'services.whatsapp.template_body_params' => $prevParams,
            ]);
        }

        // Last resort: free-form text (works only inside Meta's 24h customer-care window)
        if (! $sent) {
            $sent = $this->sendWhatsApp($waPhone, $smsText);
        }

        if (! $sent) {
            Log::warning('WhatsApp garage notify failed (templates + text). Check WHATSAPP_TOKEN (Meta temp tokens expire ~24h) and recipient allow-list.', [
                'to' => $waPhone,
            ]);
        }
    }

    private function dispatch(
        string $name,
        ?string $email,
        ?string $phone,
        string $subject,
        string $html,
        string $smsText,
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
                if ($waPhone) {
                    $this->sendWhatsApp($waPhone, $smsText);
                }

            } elseif ($channel === 'sms') {
                if ($phone) {
                    $this->sendSms($phone, $smsText);
                }

            } elseif ($channel === 'whatsapp_sms') {
                // Try WhatsApp first; fall back to SMS if it fails or is not configured
                $sent = $waPhone ? $this->sendWhatsApp($waPhone, $smsText) : false;
                if (! $sent && $phone) {
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

        if (! $apiKey || str_contains($apiKey, 'YOUR_')) {
            Log::warning("Resend not configured — skipping email to {$email}");

            return;
        }

        $to = MailRecipient::address($email);

        try {
            Resend::emails()->send([
                'from' => config('mail.from.name', 'CHAPA').' <'.config('mail.from.address', 'onboarding@resend.dev').'>',
                'to' => [$to],
                'subject' => $subject,
                'html' => $html,
            ]);
            Log::info("Email sent to {$to}: {$subject}");
        } catch (\Exception $e) {
            Log::error("Email failed to {$to}: ".$e->getMessage());
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
        $token = config('services.whatsapp.token');
        $phoneId = config('services.whatsapp.phone_number_id');

        if (! $token || ! $phoneId || str_contains($token, 'YOUR_')) {
            Log::info('WhatsApp not configured — will fall back to SMS');

            return false;
        }

        $normalized = $this->normalizePhone($phone);
        // Meta Graph API expects digits only (no leading +)
        $to = ltrim($normalized, '+');
        $url = "https://graph.facebook.com/v21.0/{$phoneId}/messages";

        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => mb_substr($text, 0, 4096)],
        ]);

        return $this->postWhatsApp($url, $token, $payload, $to, 'text');
    }

    /**
     * Send an approved WhatsApp template (required to start / re-engage conversations).
     * Default Meta test template: hello_world (no body params).
     */
    private function sendWhatsAppTemplate(string $phone, string $fallbackText = '', ?array $bodyParams = null): bool
    {
        $token = config('services.whatsapp.token');
        $phoneId = config('services.whatsapp.phone_number_id');
        $name = config('services.whatsapp.template_name', 'hello_world');
        $lang = config('services.whatsapp.template_lang', 'en_US');
        $paramCount = (int) config('services.whatsapp.template_body_params', 0);

        if (! $token || ! $phoneId || str_contains($token, 'YOUR_') || ! $name) {
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
            $limit = $paramCount > 0 ? $paramCount : count($bodyParams);
            foreach (array_slice($bodyParams, 0, $limit) as $value) {
                $text = trim(preg_replace('/\s+/', ' ', (string) $value) ?? (string) $value);
                $params[] = ['type' => 'text', 'text' => mb_substr($text !== '' ? $text : '-', 0, 1024)];
            }
            // Pad if template expects more params than provided
            while ($paramCount > 0 && count($params) < $paramCount) {
                $params[] = ['type' => 'text', 'text' => '-'];
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
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$token}",
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
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
        Log::warning("WhatsApp {$kind} failed to {$to} (HTTP {$httpCode}".($errCode ? ", code {$errCode}" : '')."): {$errMsg}");
        if ((int) $httpCode === 401 || (int) $errCode === 190) {
            Log::error('WhatsApp Authentication Error — refresh WHATSAPP_TOKEN in backend/.env (Meta temporary tokens expire in ~24h), then run: php artisan config:clear');
        }

        return false;
    }

    /* ─────────────────────────────────────────────────────────────────────
     |  SMS via Vonage (fallback)
     ───────────────────────────────────────────────────────────────────── */

    private function sendSms(string $phone, string $text): void
    {
        $key = config('services.vonage.key');
        $secret = config('services.vonage.secret');
        $from = config('services.vonage.from', 'CHAPA');

        if (! $key || ! $secret) {
            Log::warning("Vonage not configured — skipping SMS to {$phone}");

            return;
        }

        $normalized = $this->normalizePhone($phone);

        try {
            $vonage = new VonageClient(new VonageBasic($key, $secret));
            $vonage->sms()->send(new VonageSMS($normalized, $from, $text));
            Log::info("SMS sent to {$normalized}");
        } catch (\Exception $e) {
            Log::error("SMS failed to {$normalized}: ".$e->getMessage());
        }
    }

    /* ─────────────────────────────────────────────────────────────────────
     |  HELPERS
     ───────────────────────────────────────────────────────────────────── */

    private function normalizePhone(string $phone): string
    {
        if (preg_match('/^0[67]\d{8}$/', $phone)) {
            return '+255'.substr($phone, 1);
        }
        if (preg_match('/^255[67]\d{8}$/', $phone)) {
            return '+'.$phone;
        }
        if (! str_starts_with($phone, '+')) {
            return '+'.$phone;
        }

        return $phone;
    }

    private function clearProxyEnv(): void
    {
        foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'no_proxy'] as $v) {
            if (\function_exists('putenv')) {
                \putenv($v);
            }
            unset($_ENV[$v], $_SERVER[$v]);
        }
    }

    /** Build an HTML table from [label, value] rows */
    private function table(array $rows): string
    {
        $alt = self::BRAND_ROW_ALT;
        $border = self::BRAND_BORDER;
        $text = self::BRAND_TEXT;
        $muted = self::BRAND_MUTED;

        $html = "<table style='width:100%;border-collapse:collapse;margin:20px 0;font-size:14px;border:1px solid {$border};border-radius:10px;overflow:hidden;'>";
        foreach ($rows as $i => [$label, $value]) {
            $bg = $i % 2 === 0 ? $alt : '#ffffff';
            $html .= "<tr style='background:{$bg};'>
                <td style='padding:10px 14px;font-weight:600;color:{$text};width:38%;border-bottom:1px solid {$border};'>{$label}</td>
                <td style='padding:10px 14px;color:{$muted};border-bottom:1px solid {$border};'>{$value}</td>
              </tr>";
        }
        $html .= '</table>';

        return $html;
    }

    /** Highlighted callout box */
    private function callout(string $text, string $borderColor, string $bgColor): string
    {
        return "<div style='text-align:center;margin:24px 0;'>
          <span style='display:inline-block;background:{$bgColor};border:2px solid {$borderColor};
            border-radius:12px;padding:12px 28px;font-size:15px;font-weight:700;color:{$borderColor};'>
            {$text}
          </span>
        </div>";
    }

    /** Wrap content in the CHAPA branded email shell */
    private function wrap(string $intro, string $table, string $callout, string $header): string
    {
        $primary = self::BRAND_PRIMARY;
        $primaryDark = self::BRAND_PRIMARY_DARK;
        $gold = self::BRAND_GOLD;
        $cream = self::BRAND_CREAM;
        $page = self::BRAND_PAGE;
        $text = self::BRAND_TEXT;
        $muted = self::BRAND_MUTED;
        $border = self::BRAND_BORDER;
        $rowAlt = self::BRAND_ROW_ALT;

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
        <body style="font-family:'Segoe UI',Arial,sans-serif;background:{$page};margin:0;padding:24px;">
          <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:16px;
                      overflow:hidden;box-shadow:0 8px 28px rgba(125,27,40,0.12);border:1px solid {$border};">
            <div style="background-color:{$primary};background:linear-gradient(135deg, {$primary} 0%, {$primaryDark} 70%, {$gold} 160%);padding:22px 28px;">
              <div style="font-size:12px;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:6px;font-weight:700;">
                <span style="color:#FFFFFF !important;">CHAPA</span>
              </div>
              <div style="margin:0;font-size:20px;font-weight:800;letter-spacing:-0.02em;line-height:1.35;">
                <span style="color:#FFFFFF !important;">{$header}</span>
              </div>
            </div>
            <div style="height:4px;background:linear-gradient(90deg, {$gold}, {$cream}, {$primary});"></div>
            <div style="padding:28px;">
              <p style="color:{$text};font-size:15px;line-height:1.55;margin-top:0;">{$intro}</p>
              {$table}
              {$callout}
              <p style="color:{$muted};font-size:12px;margin-bottom:0;line-height:1.5;">
                Do not reply to this email. Log in to CHAPA to take action.
              </p>
            </div>
            <div style="background:{$rowAlt};padding:14px 28px;text-align:center;border-top:1px solid {$border};">
              <p style="color:{$muted};font-size:11px;margin:0;">© CHAPA · Connecting People. Powering Opportunity.</p>
            </div>
          </div>
        </body>
        </html>
        HTML;
    }
}
