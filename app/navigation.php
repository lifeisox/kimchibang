<?php
function menuIcon(string $name): string {
 $paths=['home'=>'<path d="M3 11 12 3l9 8v10h-6v-7H9v7H3Z"/>','new'=>'<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M12 8v8m-4-4h8"/>','orders'=>'<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M8 9h8M8 13h8M8 17h5"/>','customers'=>'<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3m1-16a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 6"/>','production'=>'<path d="M3 21V8h5v5l6-4v4l7-4v12ZM5 8V3h3v5M7 17h1m4 0h1m4 0h1"/>','sales'=>'<path d="M4 3v18h17M8 16v-5m5 5V7m5 9V4"/>','products'=>'<path d="m3 7 9-4 9 4v11l-9 4-9-4Zm0 0 9 4 9-4M12 11v11M7 5l9 4"/>','excel-preview'=>'<path d="M5 3h10l4 4v14H5Zm10 0v5h4M8 12l5 5m0-5-5 5"/>','expenses'=>'<path d="M6 3h12v18l-3-2-3 2-3-2-3 2ZM9 7h6m-6 4h6m-6 4h3"/>','report'=>'<path d="M5 3h14v18H5Zm3 5h8m-8 4h8m-8 4h5"/>'];
 $paths['delivery']='<path d="M3 6h11v11H3Zm11 4h4l3 4v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>';
 $paths['payment']='<rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M5 9h1m12 6h1"/>';
 $paths['accounts']='<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V6a4 4 0 0 1 8 0v4m-4 5v2"/>';
 $paths['new-order']=$paths['new'];
 return '<svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name]??$paths['products']).'</svg>';
}
