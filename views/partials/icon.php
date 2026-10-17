<?php
$paths = [
    'grid' => 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
    'chat' => 'M4 5h16v11H8l-4 4z',
    'users' => 'M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 6.5M18 14a6 6 0 0 1 3.5 6',
    'phone' => 'M7 2h10a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1zM11 18h2',
    'user' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0',
    'team' => 'M12 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM5 12a2.5 2.5 0 1 0 0-5M19 12a2.5 2.5 0 1 0 0-5M6 20a6 6 0 0 1 12 0M1.5 19a4 4 0 0 1 4-4M22.5 19a4 4 0 0 0-4-4',
    'shield' => 'M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z',
    'key' => 'M14 10a4 4 0 1 0-3.9 4L8 16v2H6v2H3v-3l7-7',
    'list' => 'M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01',
    'building' => 'M4 21V5l8-3v19M12 9h8v12M7 8h2M7 12h2M7 16h2M15 13h2M15 17h2M2 21h20',
    'megaphone' => 'M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1zM15 8.5a4 4 0 0 1 0 7M18 6a8 8 0 0 1 0 12',
    'template' => 'M5 3h14a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zM8 8h8M8 12h8M8 16h5',
    'bolt' => 'M13 2L4 14h7l-1 8 9-12h-7z',
    'pulse' => 'M3 12h4l3-8 4 16 3-8h4',
];
$d = $paths[$name] ?? $paths['grid'];
?><svg class="nav-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="<?= e($d) ?>" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
