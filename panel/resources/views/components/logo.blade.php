{{--
    OWPanel logo, the "Plakette" (E-058): a round badge like the goettlichIT
    logo, "ow" with "panel" in the accent colour. Follows light and dark
    mode through Tabler's colour variables.
--}}
@props(['size' => 48])
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 64 64" {{ $attributes }} aria-hidden="true" focusable="false">
    <circle cx="32" cy="32" r="30.5" fill="var(--tblr-bg-surface)" stroke="var(--tblr-border-color)" stroke-width="1.5"/>
    <text x="32" y="33" text-anchor="middle" font-family="'Inter Variable', Inter, system-ui, sans-serif" font-size="21" font-weight="600" fill="var(--tblr-body-color)">ow</text>
    <text x="32" y="46" text-anchor="middle" font-family="'Inter Variable', Inter, system-ui, sans-serif" font-size="10.5" font-weight="500" fill="var(--tblr-primary)">panel</text>
</svg>
