<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Mail\SiteEnquiryReceived;
use App\Models\GeneratedPage;
use App\Models\SiteEnquiry;
use App\Services\Site\SiteHostResolver;
use App\Support\EnquiryFieldLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Public endpoint behind the lead_form / contact_form sections. Always
 * stores the enquiry against the host-resolved site; emails the owner
 * only when sites.enquiry_notification_email is set, so demo/preview
 * submissions can never notify a real business.
 *
 * Same security posture as SiteReviewSubmitController: server-side host
 * resolution (no client-supplied site id), throttle:site-enquiries,
 * honeypot that fakes success, CSRF-exempt (public pages are static
 * HTML with no session).
 */
class SiteEnquirySubmitController extends Controller
{
    /** Form fields that are handled explicitly, not stored in payload. */
    private const RESERVED_FIELDS = ['name', 'email', 'website', 'page_type'];

    private const MAX_PAYLOAD_FIELDS = 40;

    public function __construct(protected SiteHostResolver $hostResolver) {}

    public function __invoke(Request $request): JsonResponse
    {
        $site = $this->hostResolver->resolve($request);
        abort_unless((bool) $site, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'page_type' => ['nullable', 'string', 'max:40'],
        ]);

        if (($validated['website'] ?? '') !== '') {
            return response()->json(['status' => 'ok']);
        }

        $page = $validated['page_type'] ?? null
            ? GeneratedPage::where('site_id', $site->id)
                ->where('page_type', $validated['page_type'])
                ->first()
            : null;

        $payload = $this->payloadFrom($request);
        $labels = array_intersect_key(
            EnquiryFieldLabels::forPage($page),
            $payload ?? [],
        );

        $customer = auth('customer')->user();
        $customerId = ($customer && (int) $customer->site_id === (int) $site->id)
            ? $customer->id
            : null;

        $enquiry = SiteEnquiry::create([
            'site_id' => $site->id,
            'customer_id' => $customerId,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'payload' => $payload,
            'field_labels' => $labels === [] ? null : $labels,
            'page_type' => $validated['page_type'] ?? null,
            'ip_hash' => hash('sha256', (string) $request->ip()),
        ]);

        if ($site->enquiry_notification_email) {
            Mail::to($site->enquiry_notification_email)->send(new SiteEnquiryReceived($enquiry));
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * The generated extra fields vary per site, so everything beyond
     * the fixed fields is captured as scalar key/values, size-capped.
     *
     * @return array<string, string>|null
     */
    private function payloadFrom(Request $request): ?array
    {
        $payload = [];
        foreach ($request->except(self::RESERVED_FIELDS) as $field => $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $payload[mb_substr((string) $field, 0, 60)] = mb_substr((string) $value, 0, 2000);
            if (count($payload) >= self::MAX_PAYLOAD_FIELDS) {
                break;
            }
        }

        return $payload === [] ? null : $payload;
    }
}
