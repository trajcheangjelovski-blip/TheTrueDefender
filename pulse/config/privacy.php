<?php

return [
    // Deliberately no env bypass: enable delivery only as part of a verified CMP integration.
    // The site's first-party cookie preferences are NOT a Google-certified advertising CMP.
    'ad_delivery_enabled' => false,
];
