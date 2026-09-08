<?php
/** Route: /fixtures */
declare(strict_types=1);

set_meta([
    'title' => 'Fixtures',
    'description' => 'Upcoming ' . club('club_name') . ' fixtures.',
]);

partial('fixtures_list', ['mode' => 'upcoming']);
