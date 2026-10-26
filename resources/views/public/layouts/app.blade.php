<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Auroville Dry Herbarium</title>
    @vite(['resources/css/public.css'])
    @livewireStyles
</head>
<body class="bg-stone-50 text-stone-900 antialiased">
    <header class="border-b border-stone-200">
        <nav class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-6 py-5">
            <a href="{{ route('public.home') }}" class="text-2xl font-serif">Auroville Dry Herbarium</a>
            <a href="{{ route('admin.dashboard') }}" class="text-sm underline">Administration</a>
        </nav>
    </header>
    <main class="mx-auto max-w-7xl px-6 py-10">@yield('content')</main>
    @livewireScripts
</body>
</html>
