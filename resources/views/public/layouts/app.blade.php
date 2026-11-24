<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="@yield('description', 'Discover the botanical collections of Auroville Dry Herbarium, Auroville, Tamil Nadu, India.')">
    <title>@hasSection('title')@yield('title') · @endif Auroville Dry Herbarium</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Caslon+Text:ital,wght@0,400;1,400&display=swap" rel="stylesheet">
    @vite(['resources/css/public.css', 'resources/js/public.js'])
    @livewireStyles
</head>
<body class="herbarium @yield('page-class', 'page-editorial')">
    <a class="skip-link" href="#main-content">Skip to content</a>
    @include('public.partials.header')
    <main id="main-content" tabindex="-1">@yield('content')</main>
    @include('public.partials.footer')
    @livewireScripts
</body>
</html>
