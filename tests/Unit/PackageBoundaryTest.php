<?php

declare(strict_types=1);

// pollora/meta runs with or without Laravel: nothing in it may reach Illuminate or the framework.
arch('the package does not use Laravel')
    ->expect('Pollora\Meta')
    ->not->toUse(['Illuminate', 'Laravel', 'Pollora\Support', 'Pollora\Models', 'Pollora\Doctor']);

arch('the domain stays free of WordPress, Hook and Discovery')
    ->expect('Pollora\Meta\Domain')
    ->not->toUse(['Pollora\Hook', 'Pollora\Discovery', 'WP_Error', 'WP_REST_Request']);
