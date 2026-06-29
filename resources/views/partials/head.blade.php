<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

{{--<link rel="icon" href="{{ asset('favicon.png') }}" type="image/png" sizes="64x64">--}}
<link rel="icon" href="{{ asset('Gemini_Generated_Image_g2681vg2681vg268.png') }}" type="image/png" sizes="64x64">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}" sizes="180x180">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
