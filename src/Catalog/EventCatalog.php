<?php
declare(strict_types=1);

namespace FPTracking\Catalog;

/**
 * Central event catalog shared across tracking components.
 *
 * This class is the single source of truth for:
 * - supported FP events exposed in GTM export
 * - Meta standard event mapping
 * - server-side eligible events
 * - minimum required payload fields per event
 */
final class EventCatalog
{
    /**
     * All events fired by the FP tracking stack.
     *
     * @var array<string,array{label:string,type:string}>
     */
    public const EVENTS = [
        // WooCommerce
        'view_item'              => ['label' => 'View Item',              'type' => 'ga4'],
        'add_to_cart'            => ['label' => 'Add to Cart',            'type' => 'ga4+meta+ads'],
        'cart_abandoned'         => ['label' => 'Cart Abandoned',         'type' => 'ga4+meta+ads'],
        'begin_checkout'         => ['label' => 'Begin Checkout',         'type' => 'ga4+meta'],
        'purchase'               => ['label' => 'Purchase',               'type' => 'ga4+meta+ads'],

        // Lead / Contacts
        'generate_lead'          => ['label' => 'Generate Lead',          'type' => 'ga4+meta+ads'],
        'click_phone'            => ['label' => 'Phone Click',            'type' => 'ga4+meta'],
        'click_whatsapp'         => ['label' => 'WhatsApp Click',         'type' => 'ga4+meta'],
        'click_email'            => ['label' => 'Email Click',            'type' => 'ga4'],
        'click_map'              => ['label' => 'Map Click',              'type' => 'ga4'],
        'click_cta'              => ['label' => 'CTA Click',              'type' => 'ga4'],
        'click_social'           => ['label' => 'Social Click',           'type' => 'ga4'],
        'click_external_link'    => ['label' => 'External Link Click',    'type' => 'ga4'],
        'sign_up'                => ['label' => 'Sign Up',                'type' => 'ga4+meta'],

        // Engagement
        'scroll_depth'           => ['label' => 'Scroll Depth',           'type' => 'ga4'],
        'video_complete'         => ['label' => 'Video Complete',         'type' => 'ga4'],
        'file_download'          => ['label' => 'File Download',          'type' => 'ga4'],
        'search'                 => ['label' => 'Search',                 'type' => 'ga4'],

        // WordPress / forms ecosystem
        'contact_form_submit'    => ['label' => 'Contact Form Submit',    'type' => 'ga4'],
        'login'                  => ['label' => 'Login',                  'type' => 'ga4'],

        // FP-Forms
        'form_view'              => ['label' => 'Form View',              'type' => 'ga4'],
        'form_start'             => ['label' => 'Form Start',             'type' => 'ga4'],
        'form_step_complete'     => ['label' => 'Form Step Complete',     'type' => 'ga4'],
        'form_abandon'           => ['label' => 'Form Abandon',           'type' => 'ga4'],
        'form_submit_attempt'    => ['label' => 'Form Submit Attempt',    'type' => 'ga4'],
        'fp_form_submit_success' => ['label' => 'FP Form Submit Success', 'type' => 'ga4'],
        'form_payment_started'   => ['label' => 'Form Payment Started',   'type' => 'ga4+meta+ads'],
        'form_payment_completed' => ['label' => 'Form Payment Completed',   'type' => 'ga4+meta+ads'],

        // FP-Cart-Recovery
        'cart_recovery'            => ['label' => 'Cart Recovery (link clicked)', 'type' => 'ga4'],
        'cart_recovery_email_sent' => ['label' => 'Cart Recovery Email Sent',     'type' => 'ga4'],

        // FP-Forms-Accrediti
        'accrediti_request_created'  => ['label' => 'Accrediti Request Created',  'type' => 'ga4'],
        'accrediti_request_approved' => ['label' => 'Accrediti Request Approved', 'type' => 'ga4'],
        'accrediti_request_rejected' => ['label' => 'Accrediti Request Rejected', 'type' => 'ga4'],

        // FP-Restaurant-Reservations
        'booking_form_view'         => ['label' => 'Booking Form View',         'type' => 'ga4'],
        'booking_form_start'        => ['label' => 'Booking Form Start',        'type' => 'ga4'],
        'booking_step_complete'     => ['label' => 'Booking Step Complete',     'type' => 'ga4'],
        'booking_form_abandon'      => ['label' => 'Booking Form Abandon',      'type' => 'ga4'],
        'booking_submit_error'      => ['label' => 'Booking Submit Error',      'type' => 'ga4'],
        'booking_submitted'         => ['label' => 'Booking Submitted',         'type' => 'ga4+meta'],
        'booking_confirmed'         => ['label' => 'Booking Confirmed',         'type' => 'ga4+meta+ads'],
        'booking_payment_required'  => ['label' => 'Booking Payment Required',  'type' => 'ga4'],
        'booking_payment_completed' => ['label' => 'Booking Payment Completed', 'type' => 'ga4+meta+ads'],
        'booking_cancelled'         => ['label' => 'Booking Cancelled',         'type' => 'ga4'],
        'booking_no_show'           => ['label' => 'Booking No Show',           'type' => 'ga4'],
        'booking_visited'           => ['label' => 'Booking Visited',           'type' => 'ga4'],
        'booking_moved'             => ['label' => 'Booking Moved',             'type' => 'ga4'],
        'waitlist_joined'           => ['label' => 'Waitlist Joined',           'type' => 'ga4'],
        'waitlist_promoted'         => ['label' => 'Waitlist Promoted',         'type' => 'ga4'],
        'survey_submitted'          => ['label' => 'Survey Submitted',          'type' => 'ga4'],
        'event_ticket_purchase'     => ['label' => 'Event Ticket Purchase',     'type' => 'ga4+meta+ads'],

        // FP-Experiences
        'experience_view'             => ['label' => 'Experience View',             'type' => 'ga4'],
        'experience_checkout_view'    => ['label' => 'Experience Checkout View',    'type' => 'ga4'],
        'gift_redeem_view'            => ['label' => 'Gift Redeem View',            'type' => 'ga4'],
        'booking_start'               => ['label' => 'Booking Start',               'type' => 'ga4'],
        'booking_abandon'             => ['label' => 'Booking Abandon',             'type' => 'ga4'],
        'rtb_start'                   => ['label' => 'RTB Start',                   'type' => 'ga4'],
        'gift_start'                  => ['label' => 'Gift Start',                  'type' => 'ga4'],
        'experience_checkout_started' => ['label' => 'Experience Checkout Started', 'type' => 'ga4+meta'],
        'experience_paid'             => ['label' => 'Experience Paid',             'type' => 'ga4+meta+ads'],
        'experience_cancelled'        => ['label' => 'Experience Cancelled',        'type' => 'ga4'],
        'rtb_submitted'               => ['label' => 'RTB Submitted',               'type' => 'ga4+meta+ads'],
        'rtb_approved'                => ['label' => 'RTB Approved',                'type' => 'ga4+meta+ads'],
        'rtb_declined'                => ['label' => 'RTB Declined',                'type' => 'ga4'],
        'rtb_hold_expired'            => ['label' => 'RTB Hold Expired',            'type' => 'ga4'],
        'gift_purchased'              => ['label' => 'Gift Purchased',              'type' => 'ga4+meta+ads'],
        'gift_redeemed'               => ['label' => 'Gift Redeemed',               'type' => 'ga4'],

        // FP-Forms funnel
        'form_submit_error'           => ['label' => 'Form Submit Error',           'type' => 'ga4'],

        // FP-CTA-Bar
        'cta_bar_click'               => ['label' => 'CTA Bar Click',               'type' => 'ga4'],

        // FP-Bio-Standalone
        'bio_link_click'              => ['label' => 'Bio Link Click',              'type' => 'ga4'],

        // FP-Discount-Gift
        'discount_applied'            => ['label' => 'Discount Applied',            'type' => 'ga4'],
        'discount_code_attempted'     => ['label' => 'Discount Code Attempted',     'type' => 'ga4'],
        'discount_code_rejected'      => ['label' => 'Discount Code Rejected',      'type' => 'ga4'],
        'discount_removed'            => ['label' => 'Discount Removed',            'type' => 'ga4'],
        'gift_voucher_purchased'      => ['label' => 'Gift Voucher Purchased',      'type' => 'ga4'],
        'gift_voucher_redeemed'       => ['label' => 'Gift Voucher Redeemed',       'type' => 'ga4'],
        'gift_card_applied'           => ['label' => 'Gift Card Applied',           'type' => 'ga4'],
        'gift_card_redeemed'          => ['label' => 'Gift Card Redeemed',          'type' => 'ga4'],
        'gift_card_removed'           => ['label' => 'Gift Card Removed',           'type' => 'ga4'],
        'gift_card_issued'            => ['label' => 'Gift Card Issued',            'type' => 'ga4'],
        'gift_card_expiring_soon'     => ['label' => 'Gift Card Expiring Soon',     'type' => 'ga4'],
        'gift_card_expired'           => ['label' => 'Gift Card Expired',           'type' => 'ga4'],
    ];

    /**
     * FP event name -> Meta standard event map.
     *
     * @var array<string,string>
     */
    public const META_EVENT_MAP = [
        'purchase'                    => 'Purchase',
        'event_ticket_purchase'       => 'Purchase',
        'booking_confirmed'           => 'Purchase',
        'booking_payment_completed'   => 'Purchase',
        'experience_paid'             => 'Purchase',
        'rtb_approved'                => 'Purchase',
        'gift_purchased'              => 'Purchase',
        'gift_voucher_purchased'      => 'Purchase',
        'gift_card_redeemed'          => 'Purchase',
        'begin_checkout'              => 'InitiateCheckout',
        'booking_submitted'           => 'InitiateCheckout',
        'experience_checkout_started' => 'InitiateCheckout',
        'form_payment_started'        => 'InitiateCheckout',
        'form_payment_completed'      => 'Purchase',
        'add_to_cart'                 => 'AddToCart',
        'cart_abandoned'              => 'CartAbandoned',
        'generate_lead'               => 'Lead',
        'rtb_submitted'               => 'Lead',
        'click_phone'                 => 'Contact',
        'click_whatsapp'              => 'Contact',
        'sign_up'                     => 'CompleteRegistration',
    ];

    /**
     * High-value events dispatched server-side (GA4 MP, and Meta when mapped).
     *
     * @var list<string>
     */
    public const SERVER_SIDE_EVENTS = [
        'purchase',
        'add_to_cart',
        'cart_abandoned',
        'begin_checkout',
        'booking_confirmed',
        'booking_submitted',
        'booking_payment_completed',
        'event_ticket_purchase',
        'generate_lead',
        'form_payment_started',
        'form_payment_completed',
        'experience_checkout_started',
        'experience_paid',
        'rtb_submitted',
        'rtb_approved',
        'gift_purchased',
        'discount_applied',
        'discount_code_attempted',
        'discount_code_rejected',
        'discount_removed',
        'gift_voucher_purchased',
        'gift_voucher_redeemed',
        'gift_card_applied',
        'gift_card_redeemed',
        'gift_card_removed',
        'gift_card_issued',
        'gift_card_expiring_soon',
        'gift_card_expired',
        'cart_recovery',
        'cart_recovery_email_sent',
        'accrediti_request_created',
        'accrediti_request_approved',
        'accrediti_request_rejected',
    ];

    /**
     * Revenue events used to enrich Meta custom_data with contents.
     *
     * @var list<string>
     */
    public const META_REVENUE_EVENTS = [
        'purchase',
        'booking_confirmed',
        'booking_payment_completed',
        'event_ticket_purchase',
        'experience_paid',
        'rtb_approved',
        'gift_purchased',
        'gift_voucher_purchased',
        'form_payment_completed',
    ];

    /**
     * Required fields per event for validation warnings.
     *
     * @var array<string,list<string>>
     */
    public const REQUIRED_FIELDS = [
        'purchase'               => ['transaction_id', 'value', 'currency'],
        'add_to_cart'            => ['value', 'currency'],
        'cart_abandoned'         => ['value', 'currency'],
        'begin_checkout'         => ['value', 'currency'],
        'booking_confirmed'      => ['value', 'currency'],
        'booking_payment_completed' => ['value', 'currency'],
        'experience_paid'        => ['value', 'currency'],
        'generate_lead'          => ['form_id'],
        'form_payment_completed' => ['transaction_id', 'value', 'currency'],
        'cart_recovery'          => ['value', 'currency'],
        'cart_recovery_email_sent' => ['value', 'currency'],
        'accrediti_request_created'  => ['request_id', 'form_id'],
        'accrediti_request_approved' => ['request_id', 'form_id'],
        'accrediti_request_rejected' => ['request_id', 'form_id'],
        'discount_applied'       => ['coupon'],
        'discount_code_attempted'=> ['coupon'],
        'discount_code_rejected' => ['coupon'],
        'discount_removed'       => ['coupon'],
        'gift_voucher_purchased' => ['voucher_id'],
        'gift_voucher_redeemed'  => ['voucher_id'],
        'gift_card_applied'      => ['gift_card_code'],
        'gift_card_redeemed'     => ['gift_card_code'],
        'gift_card_removed'      => ['gift_card_code'],
        'gift_card_issued'       => ['gift_card_code'],
        'gift_card_expiring_soon'=> ['gift_card_code'],
        'gift_card_expired'      => ['gift_card_code'],
    ];

    /**
     * @return list<string>
     */
    public static function required_fields_for(string $event_name): array
    {
        return self::REQUIRED_FIELDS[$event_name] ?? [];
    }
}

