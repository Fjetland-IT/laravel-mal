<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- SEO: sidene setter @section('title'), @section('description') og ev.
         @section('canonical'), @section('robots') og @section('og_image').
         Standardverdiene under gjelder ellers. --}}
    @php
        $seoFullTitle = \App\Support\Seo::decode($__env->yieldContent('title', config('app.name')));
        $seoTitle = \App\Support\Seo::title($seoFullTitle);
        $seoDescription = \App\Support\Seo::decode($__env->yieldContent('description', ''));
        $seoCanonical = $__env->yieldContent('canonical') ?: url()->current();
        $seoRobots = trim($__env->yieldContent('robots', 'index, follow, max-image-preview:large'));
        $seoImage = trim($__env->yieldContent('og_image', ''));
    @endphp
    <title>{{ $seoTitle }}</title>
    @if ($seoDescription !== '')
        <meta name="description" content="{{ $seoDescription }}">
    @endif
    <meta name="robots" content="{{ $seoRobots }}">
    <link rel="canonical" href="{{ $seoCanonical }}">

    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:locale" content="nb_NO">
    <meta property="og:title" content="{{ $seoFullTitle }}">
    @if ($seoDescription !== '')
        <meta property="og:description" content="{{ $seoDescription }}">
    @endif
    <meta property="og:url" content="{{ $seoCanonical }}">
    @if ($seoImage !== '')
        <meta property="og:image" content="{{ $seoImage }}">
        <meta name="twitter:card" content="summary_large_image">
    @endif

    <link rel="icon" href="/favicon.ico">
    <meta name="color-scheme" content="light dark">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white font-sans text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100">
    <main class="mx-auto max-w-3xl px-4 py-12">
        @yield('content')
    </main>
</body>
</html>
