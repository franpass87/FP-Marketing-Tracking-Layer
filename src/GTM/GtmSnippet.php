<?php

namespace FPTracking\GTM;

use FPTracking\Admin\Settings;

final class GtmSnippet {

    public function __construct(private readonly Settings $settings) {}

    public function output_head(): void {
        $gtm_id = $this->settings->get('gtm_id');
        if (empty($gtm_id)) {
            return;
        }
        $gtm_id = esc_attr($gtm_id);
        echo "<!-- Google Tag Manager -->\n";
        echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':\n";
        echo "new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],\n";
        echo "j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=\n";
        echo "'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);\n";
        echo "})(window,document,'script','dataLayer','" . $gtm_id . "');</script>\n";
        echo "<!-- End Google Tag Manager -->\n";
    }

    public function output_body(): void {
        $gtm_id = $this->settings->get('gtm_id');
        if (empty($gtm_id)) {
            return;
        }
        $gtm_id = esc_attr($gtm_id);
        echo "<!-- Google Tag Manager (noscript) -->\n";
        echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . $gtm_id . '"';
        echo ' height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
        echo "<!-- End Google Tag Manager (noscript) -->\n";
    }
}
