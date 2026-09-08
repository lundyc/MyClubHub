<?php
/** Route: /results */
declare(strict_types=1);

set_meta([
    'title' => 'Results',
    'description' => club('club_name') . ' match results.',
]);

partial('fixtures_list', ['mode' => 'results']);
