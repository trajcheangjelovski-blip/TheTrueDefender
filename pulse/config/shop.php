<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Shop / storefront visibility
    |--------------------------------------------------------------------------
    | When false, the whole commerce surface is hidden from the public site:
    | the "Free Gifts" nav + footer links, the cart icon and cart drawer, and
    | the homepage products section. The /shop, /cart and /checkout routes still
    | exist (nothing is deleted) but nothing links to them. Flip SHOP_ENABLED=true
    | in .env (then `docker compose restart app`) to bring the storefront back.
    |
    | Turned off while the site's focus is kept squarely on the journalism.
    */
    'enabled' => filter_var(env('SHOP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];
