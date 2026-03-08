<?php

namespace FPTracking\GTM;

use FPTracking\Admin\Settings;

final class ClaritySnippet {

    public function __construct(private readonly Settings $settings) {}

    public function output_head(): void {
        $project_id = $this->settings->get('clarity_project_id');
        if (empty($project_id)) {
            return;
        }
        $project_id = esc_attr($project_id);
        echo "<!-- Microsoft Clarity -->\n";
        echo "<script type=\"text/javascript\">(function(c,l,a,r,i,t,y){";
        echo "c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};";
        echo "t=l.createElement(r);t.async=1;t.src='https://www.clarity.ms/tag/'+i;";
        echo "y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);";
        echo "})(window,document,'clarity','script','" . $project_id . "');</script>\n";
    }
}
